#!/usr/bin/env bash
set -Eeuo pipefail

readonly INSTALL_ROOT="/usr/local/lib/gost-relay-agent"
readonly STATE_DIR="/etc/gost-relay-agent"
readonly AGENT_CONFIG="$STATE_DIR/agent.json"
readonly GOST_CONFIG="$STATE_DIR/gost.yml"
readonly CORE_BIN="$INSTALL_ROOT/gost-core"
readonly AGENT_BIN="$INSTALL_ROOT/gost-relay-agent.py"
readonly AGENT_UNIT="/etc/systemd/system/gost-relay-agent.service"
readonly RELAY_UNIT="/etc/systemd/system/gost-relay.service"
readonly RELAYCTL="/usr/local/bin/relayctl"
readonly GOST_TAG="${GOST_RELAY_GOST_VERSION:-v3.2.6}"

mode="${1:-rel_nodeclient}"
case "$mode" in
    rel_nodeclient|upgrade|check|uninstall) shift || true ;;
    -h|--help)
        printf '用法：node-install.sh rel_nodeclient "-t 令牌 -u https://面板地址 [-n 节点名]"\n'
        printf '      node-install.sh check | upgrade | uninstall\n'
        exit 0
        ;;
    *)
        mode="rel_nodeclient"
        ;;
esac

if (( $# == 1 )) && [[ "$1" == *' -u '* || "$1" == -u\ * ]]; then
    # The panel intentionally emits all client options as one quoted argument.
    read -r -a split_options <<<"$1"
    set -- "${split_options[@]}"
fi

token=""
panel_url=""
node_name="$(hostname 2>/dev/null || printf 'relay-node')"
assume_yes=false
while (( $# > 0 )); do
    case "$1" in
        -t|--token) token="${2:-}"; shift 2 ;;
        -u|--url) panel_url="${2:-}"; shift 2 ;;
        -n|--name) node_name="${2:-}"; shift 2 ;;
        --yes) assume_yes=true; shift ;;
        *) printf '错误：未知参数 %s\n' "$1" >&2; exit 2 ;;
    esac
done

if [[ ! -r /etc/os-release ]]; then
    printf '错误：无法识别操作系统。\n' >&2
    exit 1
fi
# shellcheck disable=SC1091
source /etc/os-release
case "${ID:-}" in
    ubuntu|debian) ;;
    *) printf '错误：只支持 Ubuntu 或 Debian，当前为 %s。\n' "${ID:-unknown}" >&2; exit 1 ;;
esac
command -v apt-get >/dev/null 2>&1 || { printf '错误：未找到 apt-get。\n' >&2; exit 1; }

case "$(uname -m)" in
    x86_64) asset_arch="amd64" ;;
    aarch64|arm64) asset_arch="arm64" ;;
    i386|i686) asset_arch="386" ;;
    armv7l) asset_arch="armv7" ;;
    *) printf '错误：暂不支持 CPU 架构 %s。\n' "$(uname -m)" >&2; exit 1 ;;
esac

if [[ "$mode" == check ]]; then
    printf '兼容性检查通过：%s %s / %s / systemd + Python 3 将在安装时配置。\n' "${ID}" "${VERSION_ID:-unknown}" "$asset_arch"
    exit 0
fi

if (( EUID != 0 )); then
    printf '错误：请使用 root 权限执行节点安装命令。\n' >&2
    exit 1
fi

if [[ "$mode" == uninstall ]]; then
    if [[ "$assume_yes" != true ]]; then
        read -r -p '确认卸载节点代理和全部本机转发配置？输入 YES：' answer
        [[ "$answer" == YES ]] || { printf '已取消。\n'; exit 0; }
    fi
    systemctl disable --now gost-relay-agent.service gost-relay.service >/dev/null 2>&1 || true
    rm -f -- "$AGENT_UNIT" "$RELAY_UNIT" "$RELAYCTL"
    systemctl daemon-reload
    rm -rf -- "$STATE_DIR" "$INSTALL_ROOT"
    printf 'GOST Relay 节点已卸载，本机转发已停止。\n'
    exit 0
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl tar python3 iproute2 util-linux >/dev/null

if [[ -z "$panel_url" && -r "$AGENT_CONFIG" ]]; then
    panel_url="$(python3 -c 'import json; print(json.load(open("/etc/gost-relay-agent/agent.json", encoding="utf-8"))["panel_url"])')"
fi
panel_url="${panel_url%/}"
if [[ ! "$panel_url" =~ ^https://[^[:space:]/]+$ && ! "$panel_url" =~ ^http://(127\.0\.0\.1|localhost)(:[0-9]+)?$ ]]; then
    printf '错误：面板必须使用域名根目录 HTTPS 地址。\n' >&2
    exit 1
fi
if [[ ! -r "$AGENT_CONFIG" && ${#token} -lt 32 ]]; then
    printf '错误：首次安装需要有效的服务器组令牌。\n' >&2
    exit 1
fi
if [[ -z "$node_name" || ${#node_name} -gt 100 ]]; then
    printf '错误：节点名称需要 1-100 个字符。\n' >&2
    exit 1
fi

tmp_dir="$(mktemp -d)"
cleanup() { rm -rf -- "$tmp_dir"; }
trap cleanup EXIT

gost_version="${GOST_TAG#v}"
asset="gost_${gost_version}_linux_${asset_arch}.tar.gz"
release_base="https://github.com/go-gost/gost/releases/download/${GOST_TAG}"
printf '下载并校验 GOST %s (%s)...\n' "$GOST_TAG" "$asset_arch"
curl -fL --retry 3 --retry-delay 2 -o "$tmp_dir/$asset" "$release_base/$asset"
curl -fL --retry 3 --retry-delay 2 -o "$tmp_dir/checksums.txt" "$release_base/checksums.txt"
expected_sum="$(awk -v file="$asset" '$2 == file || $2 == "*" file {print $1; exit}' "$tmp_dir/checksums.txt")"
actual_sum="$(sha256sum "$tmp_dir/$asset" | awk '{print $1}')"
[[ -n "$expected_sum" && "$expected_sum" == "$actual_sum" ]] || { printf '错误：GOST SHA256 校验失败。\n' >&2; exit 1; }
tar -xzf "$tmp_dir/$asset" -C "$tmp_dir" gost

printf '从主控下载节点组件...\n'
curl -fL --retry 3 --retry-delay 2 -o "$tmp_dir/agent.py" "$panel_url/download/agent.py"
curl -fL --retry 3 --retry-delay 2 -o "$tmp_dir/gost-relay.service" "$panel_url/download/gost-relay.service"
curl -fL --retry 3 --retry-delay 2 -o "$tmp_dir/gost-relay-agent.service" "$panel_url/download/gost-relay-agent.service"
curl -fL --retry 3 --retry-delay 2 -o "$tmp_dir/relayctl" "$panel_url/download/relayctl"
python3 -m py_compile "$tmp_dir/agent.py"
bash -n "$tmp_dir/relayctl"

install -d -m 0755 "$INSTALL_ROOT"
install -d -m 0750 -o root -g nogroup "$STATE_DIR"
install -m 0755 "$tmp_dir/gost" "$CORE_BIN"
install -m 0755 "$tmp_dir/agent.py" "$AGENT_BIN"
install -m 0644 "$tmp_dir/gost-relay.service" "$RELAY_UNIT"
install -m 0644 "$tmp_dir/gost-relay-agent.service" "$AGENT_UNIT"
install -m 0755 "$tmp_dir/relayctl" "$RELAYCTL"
install -m 0755 "${BASH_SOURCE[0]}" "$INSTALL_ROOT/node-install.sh"

if [[ ! -f "$GOST_CONFIG" ]]; then
    printf '# Managed by GOST Relay agent.\nservices: []\n\nlog:\n  output: stderr\n  level: info\n  format: text\n' > "$GOST_CONFIG"
fi
chmod 0644 "$GOST_CONFIG"
chown root:nogroup "$STATE_DIR" "$GOST_CONFIG"

# Disable and quarantine the previous SNI manager if this server was upgraded
# from the old single-node project. It must not remain an accidental fallback.
if [[ -f /etc/systemd/system/gost-sni-forward.service ]]; then
    legacy_backup="/var/backups/gost-sni-forward-$(date +%Y%m%d%H%M%S)"
    install -d -m 0700 /var/backups
    install -d -m 0700 "$legacy_backup"
    systemctl disable --now gost-sni-forward.service >/dev/null 2>&1 || true
    cp -a -- /etc/systemd/system/gost-sni-forward.service "$legacy_backup/" || true
    rm -f -- /etc/systemd/system/gost-sni-forward.service
    if [[ -d /etc/gost-sni-forward ]]; then
        cp -a -- /etc/gost-sni-forward "$legacy_backup/" || true
        rm -rf -- /etc/gost-sni-forward
    fi
    if [[ -f /usr/local/bin/gost ]] && grep -Fq 'GOST_SNI_STATE_DIR' /usr/local/bin/gost; then
        cp -a -- /usr/local/bin/gost "$legacy_backup/old-gost-manager"
        rm -f -- /usr/local/bin/gost
    fi
    rm -f -- /usr/local/sbin/gost-sni
    systemctl daemon-reload
    printf '已停用旧版 SNI 管理器；旧文件已备份到 %s。\n' "$legacy_backup"
fi

if [[ ! -r "$AGENT_CONFIG" ]]; then
    "$AGENT_BIN" enroll --token "$token" --url "$panel_url" --name "$node_name"
fi
chmod 0600 "$AGENT_CONFIG"

systemctl daemon-reload
systemctl enable gost-relay.service >/dev/null
systemctl enable gost-relay-agent.service >/dev/null
systemctl restart gost-relay-agent.service
sleep 2
systemctl is-active --quiet gost-relay.service gost-relay-agent.service

printf '\n安装完成：节点 %s 已由 %s 控制。\n' "$node_name" "$panel_url"
printf '状态：relayctl status\n日志：relayctl logs\n立即同步：relayctl sync\n'
