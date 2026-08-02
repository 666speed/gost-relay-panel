#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
test_dir="$(mktemp -d)"
trap 'rm -rf -- "$test_dir"' EXIT

cat > "$test_dir/fake-gost" <<'EOF'
#!/usr/bin/env bash
[[ ! -e "${FAKE_GOST_FAIL_FILE:-/nonexistent}" ]]
EOF
chmod +x "$test_dir/fake-gost"

export GOST_SNI_ALLOW_NON_ROOT=1
export GOST_SNI_SKIP_SYSTEMD=1
export GOST_SNI_STATE_DIR="$test_dir/state"
export GOST_SNI_CORE_BIN="$test_dir/fake-gost"
export GOST_SNI_INSTALL_ROOT="$test_dir/install"
export GOST_SNI_MANAGER_BIN="$test_dir/bin/gost"
export GOST_SNI_COMPAT_MANAGER_BIN="$test_dir/sbin/gost-sni"
export NO_COLOR=1
manager="$repo_dir/manager/gost-sni"

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
assert_contains() { grep -Fq -- "$2" "$1" || fail "$1 does not contain: $2"; }
assert_not_contains() { ! grep -Fq -- "$2" "$1" || fail "$1 unexpectedly contains: $2"; }

"$manager" init
"$manager" domain add Example.COM '*.second-example.net'
"$manager" forward add 443 104.16.1.2 443 cloudflare-443
"$manager" forward add 8443 104.17.2.3 2053 cloudflare-8443

config="$test_dir/state/gost.yml"
assert_contains "$config" 'addr: "0.0.0.0:443"'
assert_contains "$config" 'addr: "104.16.1.2:443"'
assert_contains "$config" 'addr: "0.0.0.0:8443"'
assert_contains "$config" 'addr: "104.17.2.3:2053"'
assert_contains "$config" 'whitelist: true'
assert_contains "$config" '- ".example.com"'
assert_contains "$config" '- ".second-example.net"'
# These are intentionally literal matcher expressions from the generated YAML.
# shellcheck disable=SC2016
assert_contains "$config" 'HostRegexp(`^([a-z0-9-]+\.)*example\.com$`)'
# shellcheck disable=SC2016
assert_contains "$config" 'HostRegexp(`^([a-z0-9-]+\.)*second-example\.net$`)'
if grep -Eiq 'type:[[:space:]]*udp' "$config"; then
    fail 'UDP service unexpectedly present in generated config'
fi
[[ "$(grep -Fc 'name: "cloudflare-443"' "$config")" == 1 ]] || fail 'listen port did not create exactly one service'
[[ "$(grep -Fc 'addr: "104.16.1.2:443"' "$config")" == 2 ]] || fail 'first listen port has inconsistent targets'
[[ "$(grep -Fc 'addr: "104.17.2.3:2053"' "$config")" == 2 ]] || fail 'second listen port has inconsistent targets'

# Two domains must be applied to both forwarding services.
[[ "$(grep -Fc 'HostRegexp(' "$config")" == 4 ]] || fail 'global domains were not applied to every forward'

if "$manager" domain add 'bad.example.com/path' >/dev/null 2>&1; then
    fail 'invalid domain was accepted'
fi
if "$manager" forward add 443 1.1.1.1 >/dev/null 2>&1; then
    fail 'duplicate listen port was accepted'
fi
if "$manager" forward add 0443 1.1.1.1 >/dev/null 2>&1; then
    fail 'listen port with ambiguous leading zero was accepted'
fi
if "$manager" forward add 9443 1.1.1.1 0443 >/dev/null 2>&1; then
    fail 'target port with ambiguous leading zero was accepted'
fi
if "$manager" forward add 9443 2606:4700::1111 >/dev/null 2>&1; then
    fail 'IPv6 target was accepted'
fi
if "$manager" forward add 9443 cloudflare.com >/dev/null 2>&1; then
    fail 'hostname target was accepted'
fi
if "$manager" forward add 9443 999.1.1.1 >/dev/null 2>&1; then
    fail 'invalid IPv4 target was accepted'
fi
if "$manager" forward add 9443 01.1.1.1 >/dev/null 2>&1; then
    fail 'IPv4 target with ambiguous leading zero was accepted'
fi
if "$manager" forward add 9443 0.0.0.0 >/dev/null 2>&1; then
    fail 'unspecified IPv4 target was accepted'
fi

# A rejected core validation must restore both state and generated config.
before_sum="$(sha256sum "$config" | awk '{print $1}')"
export FAKE_GOST_FAIL_FILE="$test_dir/fail-validation"
touch "$FAKE_GOST_FAIL_FILE"
if "$manager" domain add rollback.example >/dev/null 2>&1; then
    fail 'failed core validation was accepted'
fi
rm -f -- "$FAKE_GOST_FAIL_FILE"
unset FAKE_GOST_FAIL_FILE
after_sum="$(sha256sum "$config" | awk '{print $1}')"
[[ "$before_sum" == "$after_sum" ]] || fail 'config rollback failed'
assert_not_contains "$test_dir/state/domains.txt" 'rollback.example'

"$manager" domain delete example.com
assert_not_contains "$config" '.example.com'
[[ "$(grep -Fc 'HostRegexp(' "$config")" == 2 ]] || fail 'domain deletion did not update every forward'

"$manager" forward delete cloudflare-8443
assert_not_contains "$config" '104.17.2.3:2053'

printf 'All manager tests passed.\n'
