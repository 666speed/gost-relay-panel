#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
test_dir="$(mktemp -d)"
gost_pid=""
backend_one_pid=""
backend_two_pid=""
cleanup() {
    [[ -z "$gost_pid" ]] || kill "$gost_pid" 2>/dev/null || true
    [[ -z "$backend_one_pid" ]] || kill "$backend_one_pid" 2>/dev/null || true
    [[ -z "$backend_two_pid" ]] || kill "$backend_two_pid" 2>/dev/null || true
    rm -rf -- "$test_dir"
}
trap cleanup EXIT

gost_version="3.2.6"
asset="gost_${gost_version}_linux_amd64.tar.gz"
base="https://github.com/go-gost/gost/releases/download/v${gost_version}"
curl -fsSL --retry 3 "$base/$asset" -o "$test_dir/$asset"
curl -fsSL --retry 3 "$base/checksums.txt" -o "$test_dir/checksums.txt"
expected="$(awk -v file="$asset" '$2 == file || $2 == "*" file {print $1; exit}' "$test_dir/checksums.txt")"
actual="$(sha256sum "$test_dir/$asset" | awk '{print $1}')"
[[ -n "$expected" && "$expected" == "$actual" ]]
tar -xzf "$test_dir/$asset" -C "$test_dir" gost

printf '%s\n' '[{"id":1,"name":"one","listen_port":18443,"target_ipv4":"127.0.0.1","target_port":19443},{"id":2,"name":"two","listen_port":18444,"target_ipv4":"127.0.0.1","target_port":19444}]' > "$test_dir/rules.json"
python3 "$repo_dir/node/gost-relay-agent.py" render --rules "$test_dir/rules.json" --output "$test_dir/gost.yml"
grep -Fq 'addr: "0.0.0.0:18443"' "$test_dir/gost.yml"
grep -Fq 'addr: "127.0.0.1:19443"' "$test_dir/gost.yml"
if grep -Eiq 'type:[[:space:]]*udp|sniffing|HostRegexp|bypass' "$test_dir/gost.yml"; then
    printf 'Unexpected filtered or non-TCP configuration generated.\n' >&2
    exit 1
fi

python3 "$repo_dir/tests/tcp_backend.py" 19443 backend-one &
backend_one_pid=$!
python3 "$repo_dir/tests/tcp_backend.py" 19444 backend-two &
backend_two_pid=$!
"$test_dir/gost" -C "$test_dir/gost.yml" > "$test_dir/gost.log" 2>&1 &
gost_pid=$!

for _ in {1..30}; do
    if ss -ltnH 'sport = :18443' | grep -q . && ss -ltnH 'sport = :18444' | grep -q .; then
        break
    fi
    sleep 0.2
done
kill -0 "$gost_pid"

python3 - <<'PY'
import socket
for port, expected in [(18443, b"backend-one:arbitrary-raw-tcp"), (18444, b"backend-two:arbitrary-raw-tcp")]:
    with socket.create_connection(("127.0.0.1", port), timeout=5) as client:
        client.sendall(b"arbitrary-raw-tcp")
        actual = client.recv(1024)
    assert actual == expected, (port, actual)
PY

test -z "$(ss -lunH 'sport = :18443')"
test -z "$(ss -lunH 'sport = :18444')"
test -z "$(ss -ltn6H 'sport = :18443')"
test -z "$(ss -ltn6H 'sport = :18444')"
printf 'Real GOST raw TCP IPv4 integration tests passed.\n'
