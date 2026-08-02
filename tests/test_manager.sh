#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
test_dir="$(mktemp -d)"
trap 'rm -rf -- "$test_dir"' EXIT

cat > "$test_dir/fake-gost" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "$test_dir/fake-gost"

export GOST_SNI_ALLOW_NON_ROOT=1
export GOST_SNI_SKIP_SYSTEMD=1
export GOST_SNI_STATE_DIR="$test_dir/state"
export GOST_SNI_GOST_BIN="$test_dir/fake-gost"
export NO_COLOR=1
manager="$repo_dir/manager/gost-sni"

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
assert_contains() { grep -Fq -- "$2" "$1" || fail "$1 does not contain: $2"; }
assert_not_contains() { ! grep -Fq -- "$2" "$1" || fail "$1 unexpectedly contains: $2"; }

"$manager" init
"$manager" domain add Example.COM '*.second-example.net'
"$manager" forward add 443 104.16.1.2 443 cloudflare-443
"$manager" forward add 8443 2606:4700::1111 443 cloudflare-v6

config="$test_dir/state/gost.yml"
assert_contains "$config" 'addr: ":443"'
assert_contains "$config" 'addr: "104.16.1.2:443"'
assert_contains "$config" 'addr: "[2606:4700::1111]:443"'
assert_contains "$config" 'whitelist: true'
assert_contains "$config" '- ".example.com"'
assert_contains "$config" '- ".second-example.net"'
# These are intentionally literal matcher expressions from the generated YAML.
# shellcheck disable=SC2016
assert_contains "$config" 'HostRegexp(`^([a-z0-9-]+\.)*example\.com$`)'
# shellcheck disable=SC2016
assert_contains "$config" 'HostRegexp(`^([a-z0-9-]+\.)*second-example\.net$`)'

# Two domains must be applied to both forwarding services.
[[ "$(grep -Fc 'HostRegexp(' "$config")" == 4 ]] || fail 'global domains were not applied to every forward'

if "$manager" domain add 'bad.example.com/path' >/dev/null 2>&1; then
    fail 'invalid domain was accepted'
fi
if "$manager" forward add 443 1.1.1.1 >/dev/null 2>&1; then
    fail 'duplicate listen port was accepted'
fi

"$manager" domain delete example.com
assert_not_contains "$config" '.example.com'
[[ "$(grep -Fc 'HostRegexp(' "$config")" == 2 ]] || fail 'domain deletion did not update every forward'

"$manager" forward delete cloudflare-v6
assert_not_contains "$config" '2606:4700::1111'

printf 'All manager tests passed.\n'
