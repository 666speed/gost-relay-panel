#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
test_dir="$(mktemp -d)"
gost_pid=''
tls_pid=''
cleanup() {
    [[ -z "$gost_pid" ]] || kill "$gost_pid" 2>/dev/null || true
    [[ -z "$tls_pid" ]] || kill "$tls_pid" 2>/dev/null || true
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

openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=example.com' \
    -keyout "$test_dir/key.pem" -out "$test_dir/cert.pem" >/dev/null 2>&1
openssl s_server -quiet -accept 127.0.0.1:19443 \
    -key "$test_dir/key.pem" -cert "$test_dir/cert.pem" >"$test_dir/tls.log" 2>&1 &
tls_pid=$!

export GOST_SNI_ALLOW_NON_ROOT=1 GOST_SNI_SKIP_SYSTEMD=1 NO_COLOR=1
export GOST_SNI_STATE_DIR="$test_dir/state"
export GOST_SNI_GOST_BIN="$test_dir/gost"
manager="$repo_dir/manager/gost-sni"
"$manager" init
"$manager" domain add example.com
"$manager" forward add 18443 127.0.0.1 19443 integration

"$test_dir/gost" -C "$test_dir/state/gost.yml" >"$test_dir/gost.log" 2>&1 &
gost_pid=$!

for _ in {1..30}; do
    if (echo >/dev/tcp/127.0.0.1/18443) 2>/dev/null; then break; fi
    sleep 0.1
done
kill -0 "$gost_pid"

tls_allowed() {
    local sni="$1"
    timeout 5 openssl s_client -brief -connect 127.0.0.1:18443 -servername "$sni" \
        </dev/null >/dev/null 2>&1
}

tls_rejected() {
    local sni="$1"
    if tls_allowed "$sni"; then
        printf 'SNI should have been rejected: %s\n' "$sni" >&2
        return 1
    fi
}

tls_allowed example.com
tls_allowed www.example.com
tls_allowed a.b.example.com
tls_rejected notexample.com
tls_rejected example.net

if timeout 5 openssl s_client -brief -connect 127.0.0.1:18443 \
    -noservername </dev/null >/dev/null 2>&1; then
    printf 'TLS without SNI should have been rejected\n' >&2
    exit 1
fi

printf 'Real GOST SNI integration tests passed.\n'
