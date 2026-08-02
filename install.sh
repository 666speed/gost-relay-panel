#!/usr/bin/env bash
set -Eeuo pipefail

# gost-sni-forward installer for Ubuntu 22.04.
# The repository/ref can be overridden for forks and release testing.
readonly PROJECT_REPO="${GOST_SNI_REPO:-666speed/gost-sni-forward}"
readonly PROJECT_REF="${GOST_SNI_REF:-main}"
readonly RAW_BASE="https://raw.githubusercontent.com/${PROJECT_REPO}/${PROJECT_REF}"
readonly INSTALL_ROOT="/usr/local/lib/gost-sni-forward"
readonly MANAGER_BIN="/usr/local/sbin/gost-sni"
readonly GOST_BIN="/usr/local/bin/gost"
readonly STATE_DIR="/etc/gost-sni-forward"
readonly UNIT_FILE="/etc/systemd/system/gost-sni-forward.service"

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
apt-get install -y -qq ca-certificates curl tar >/dev/null

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

printf '正在获取 gost 最新稳定版本...\n'
release_json="$(curl -fsSL --retry 3 https://api.github.com/repos/go-gost/gost/releases/latest)"
gost_tag="$(sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' <<<"$release_json" | head -n1)"
if [[ ! "$gost_tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    printf '错误：无法解析 gost 最新版本。\n' >&2
    exit 1
fi
gost_version="${gost_tag#v}"
asset="gost_${gost_version}_linux_${asset_arch}.tar.gz"
release_base="https://github.com/go-gost/gost/releases/download/${gost_tag}"

printf '正在下载 gost %s (%s)...\n' "$gost_tag" "$asset_arch"
curl -fL --retry 3 -o "$tmp_dir/$asset" "$release_base/$asset"
curl -fL --retry 3 -o "$tmp_dir/checksums.txt" "$release_base/checksums.txt"
expected_sum="$(awk -v file="$asset" '$2 == file || $2 == "*" file {print $1; exit}' "$tmp_dir/checksums.txt")"
actual_sum="$(sha256sum "$tmp_dir/$asset" | awk '{print $1}')"
if [[ -z "$expected_sum" || "$expected_sum" != "$actual_sum" ]]; then
    printf '错误：gost 安装包 SHA256 校验失败。\n' >&2
    exit 1
fi
tar -xzf "$tmp_dir/$asset" -C "$tmp_dir" gost

install -d -m 0755 "$INSTALL_ROOT" "$STATE_DIR"
if [[ -x "$GOST_BIN" && ! -e "$INSTALL_ROOT/original-gost.path" ]]; then
    backup_path="${GOST_BIN}.before-gost-sni.$(date +%Y%m%d%H%M%S)"
    cp -a -- "$GOST_BIN" "$backup_path"
    printf '%s\n' "$backup_path" > "$INSTALL_ROOT/original-gost.path"
    printf '已备份原有 gost 到 %s\n' "$backup_path"
fi
install -m 0755 "$tmp_dir/gost" "$GOST_BIN"

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

touch "$STATE_DIR/domains.txt" "$STATE_DIR/forwards.tsv"
chmod 0600 "$STATE_DIR/domains.txt" "$STATE_DIR/forwards.tsv"

"$MANAGER_BIN" init
systemctl daemon-reload
systemctl enable --now gost-sni-forward.service >/dev/null

printf '\n安装完成：gost %s\n' "$gost_tag"
printf '管理菜单：sudo gost-sni\n'
printf '配置目录：%s\n' "$STATE_DIR"

if [[ "$NO_MENU" != true && -t 0 && -t 1 ]]; then
    exec "$MANAGER_BIN" menu
fi
