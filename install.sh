#!/usr/bin/env bash
set -Eeuo pipefail

# gost-sni-forward installer for Ubuntu 22.04.
# The repository/ref can be overridden for forks and release testing.
readonly PROJECT_REPO="${GOST_SNI_REPO:-666speed/gost-sni-forward}"
readonly PROJECT_REF="${GOST_SNI_REF:-main}"
readonly RAW_BASE="https://raw.githubusercontent.com/${PROJECT_REPO}/${PROJECT_REF}"
readonly INSTALL_ROOT="/usr/local/lib/gost-sni-forward"
readonly MANAGER_BIN="/usr/local/bin/gost"
readonly COMPAT_MANAGER_BIN="/usr/local/sbin/gost-sni"
readonly CORE_BIN="$INSTALL_ROOT/gost-core"
readonly STATE_DIR="/etc/gost-sni-forward"
readonly UNIT_FILE="/etc/systemd/system/gost-sni-forward.service"
readonly GOST_TAG="${GOST_SNI_GOST_VERSION:-v3.2.6}"

NO_MENU=false
FORCE_OS=false
for arg in "$@"; do
    case "$arg" in
        --no-menu|--upgrade) NO_MENU=true ;;
        --force) FORCE_OS=true ;;
        -h|--help)
            printf '用法: sudo bash install.sh [--no-menu] [--force]\n'
            exit 0
            ;;
        *) printf '未知参数: %s\n' "$arg" >&2; exit 2 ;;
    esac
done

if (( EUID != 0 )); then
    printf '错误：请使用 root 权限运行。\n' >&2
    exit 1
fi

if [[ ! -r /etc/os-release ]]; then
    printf '错误：无法识别操作系统。\n' >&2
    exit 1
fi
# shellcheck disable=SC1091
source /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "22.04" ]]; then
    if [[ "$FORCE_OS" != true ]]; then
        printf '错误：本安装器面向 Ubuntu 22.04，当前为 %s %s。确需继续可加 --force。\n' \
            "${ID:-unknown}" "${VERSION_ID:-unknown}" >&2
        exit 1
    fi
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl tar iproute2 util-linux >/dev/null

case "$(uname -m)" in
    x86_64) asset_arch="amd64" ;;
    aarch64|arm64) asset_arch="arm64" ;;
    i386|i686) asset_arch="386" ;;
    armv7l) asset_arch="armv7" ;;
    *) printf '错误：暂不支持 CPU 架构 %s。\n' "$(uname -m)" >&2; exit 1 ;;
esac

tmp_dir="$(mktemp -d)"
cleanup() { rm -rf -- "$tmp_dir"; }
trap cleanup EXIT

if [[ ! "$GOST_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    printf '错误：无效的 gost 版本号：%s。\n' "$GOST_TAG" >&2
    exit 1
fi
gost_version="${GOST_TAG#v}"
asset="gost_${gost_version}_linux_${asset_arch}.tar.gz"
release_base="https://github.com/go-gost/gost/releases/download/${GOST_TAG}"

printf '正在下载已严格测试的 gost %s (%s)...\n' "$GOST_TAG" "$asset_arch"
curl -fL --retry 3 -o "$tmp_dir/$asset" "$release_base/$asset"
curl -fL --retry 3 -o "$tmp_dir/checksums.txt" "$release_base/checksums.txt"
expected_sum="$(awk -v file="$asset" '$2 == file || $2 == "*" file {print $1; exit}' "$tmp_dir/checksums.txt")"
actual_sum="$(sha256sum "$tmp_dir/$asset" | awk '{print $1}')"
if [[ -z "$expected_sum" || "$expected_sum" != "$actual_sum" ]]; then
    printf '错误：gost 安装包 SHA256 校验失败。\n' >&2
    exit 1
fi
tar -xzf "$tmp_dir/$asset" -C "$tmp_dir" gost

install -d -m 0755 "$INSTALL_ROOT" "$STATE_DIR" "$(dirname "$MANAGER_BIN")" "$(dirname "$COMPAT_MANAGER_BIN")"
if [[ ! -e "$INSTALL_ROOT/original-gost.path" && ! -e "$INSTALL_ROOT/no-original-gost" ]]; then
    if [[ -x "$COMPAT_MANAGER_BIN" && -f "$UNIT_FILE" && -x "$MANAGER_BIN" ]]; then
        # Migration from an earlier gost-sni-forward release: /usr/local/bin/gost
        # is this project's old core binary, not a user-owned executable.
        : > "$INSTALL_ROOT/no-original-gost"
    elif [[ -x "$MANAGER_BIN" ]]; then
        backup_path="${MANAGER_BIN}.before-gost-sni.$(date +%Y%m%d%H%M%S)"
        cp -a -- "$MANAGER_BIN" "$backup_path"
        printf '%s\n' "$backup_path" > "$INSTALL_ROOT/original-gost.path"
        printf '已备份原有 gost 到 %s\n' "$backup_path"
    else
        : > "$INSTALL_ROOT/no-original-gost"
    fi
fi
install -m 0755 "$tmp_dir/gost" "$CORE_BIN"

script_dir=""
if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
    if ! script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)"; then
        script_dir=""
    fi
fi

install_project_file() {
    local relative="$1" destination="$2" mode="$3"
    if [[ -n "$script_dir" && -f "$script_dir/$relative" ]]; then
        install -m "$mode" "$script_dir/$relative" "$destination"
    else
        curl -fsSL --retry 3 "$RAW_BASE/$relative" -o "$tmp_dir/$(basename "$relative")"
        install -m "$mode" "$tmp_dir/$(basename "$relative")" "$destination"
    fi
}

install_project_file manager/gost-sni  "$MANAGER_BIN" 0755
install_project_file systemd/gost-sni-forward.service "$UNIT_FILE" 0644
install_project_file install.sh "$INSTALL_ROOT/install.sh" 0644
ln -sfn "$MANAGER_BIN" "$COMPAT_MANAGER_BIN"

touch "$STATE_DIR/domains.txt" "$STATE_DIR/forwards.tsv"
chmod 0600 "$STATE_DIR/domains.txt" "$STATE_DIR/forwards.tsv"

"$MANAGER_BIN" init
systemctl daemon-reload

printf '\n安装完成：gost 核心 %s\n' "$GOST_TAG"
printf '管理菜单：直接输入 gost（普通 sudo 用户会自动提权）\n'
printf '配置目录：%s\n' "$STATE_DIR"
printf '请先在菜单中添加主域名和 TCP IPv4 转发，服务随后会自动启动。\n'

if [[ "$NO_MENU" != true && -t 0 && -t 1 ]]; then
    exec "$MANAGER_BIN" menu
fi
