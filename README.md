# GOST IP 转发 + 全局 SNI 白名单

面向 Ubuntu 22.04 的轻量管理项目：使用 GOST 把服务器 TCP 端口转发到固定的 Cloudflare（或其他上游）IP，同时只允许你设置的主域名 SNI。所有转发规则共用一份域名白名单，不需要逐条重复设置。

例如全局白名单只填写：

```text
example.com
example.net
```

系统会自动允许 `example.com`、`www.example.com`、`a.b.example.com`（`example.net` 同理），拒绝无 SNI、非 TLS、其他域名以及 `notexample.com` 这种相似后缀。

## 一键安装

在 Ubuntu 22.04 上使用 root 执行：

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/666speed/gost-sni-forward/main/install.sh)
```

安装完成后随时打开中文菜单：

```bash
sudo gost-sni
```

安装器会：

- 从 GOST 官方 GitHub Release 获取最新稳定版并校验 SHA256；
- 安装中文交互菜单和 systemd 服务；
- 以低权限 `nobody` 用户运行，仅保留绑定低端口所需能力；
- 如果 `/usr/local/bin/gost` 已存在，先创建带时间戳的备份。

## 使用流程

1. 选择“设置全局 SNI 主域名”，添加一个或多个主域名。
2. 选择“添加 IP 转发”，输入监听端口、固定目标 IP 和目标端口。
3. 查看状态；配置会自动生成并重载，无需手写 YAML。

典型示例：服务器 `443/tcp` 转到 Cloudflare IP `104.16.1.2:443`，只有全局白名单内域名的 TLS ClientHello 才会建立上游连接。

也可以使用命令行：

```bash
sudo gost-sni domain add example.com example.net
sudo gost-sni forward add 443 104.16.1.2 443 cloudflare-443
sudo gost-sni status
sudo gost-sni config
sudo gost-sni logs
```

## 安全设计

- 固定上游：客户端不能指定任意目标 IP，因此不是通用开放代理。
- 默认拒绝：只有识别为 TLS 且 SNI 精确落在“主域名或其子域名”边界内才会选中转发节点。
- 双层校验：服务级白名单先拒绝错误 SNI，节点匹配器再拒绝无 SNI和非 TLS 流量。
- 全局配置：`/etc/gost-sni-forward/domains.txt` 只维护一次，所有当前和以后添加的转发都会使用它。
- 原始 TLS 透明传输：不解密流量、不持有你的域名证书、不进行 MITM。

需要注意：SNI 白名单限制的是“可转发的域名”，并不是客户端身份认证。别人仍可伪造白名单内的 SNI 来消耗该固定上游的带宽；若要求只有特定设备能连接，还应在防火墙中限制客户端源 IP，或额外使用带认证的加密隧道。

## 限制与排查

- 当前只处理 TCP/TLS。不要开放同端口的 UDP；HTTP/3/QUIC 不在本项目范围内。
- ECH 会隐藏真实 SNI，因而会被默认拒绝。如果浏览器连接失败，检查域名的 HTTPS/SVCB 记录和客户端 ECH 设置。
- 菜单不会自动修改 UFW。若 UFW 已启用，请自行开放对应 TCP 端口，例如 `sudo ufw allow 443/tcp`。
- 监听端口不能被 Nginx、Caddy 等其他程序占用。使用 `sudo ss -ltnp` 检查。
- 日志：`sudo journalctl -u gost-sni-forward -n 100 --no-pager`。

## 文件位置

| 文件 | 作用 |
|---|---|
| `/usr/local/sbin/gost-sni` | 管理菜单/命令 |
| `/usr/local/bin/gost` | GOST 官方二进制 |
| `/etc/gost-sni-forward/domains.txt` | 全局主域名白名单 |
| `/etc/gost-sni-forward/forwards.tsv` | 固定 IP 转发规则 |
| `/etc/gost-sni-forward/gost.yml` | 自动生成的 GOST 配置 |
| `/etc/systemd/system/gost-sni-forward.service` | systemd 服务 |

## 开发测试

```bash
shellcheck install.sh manager/gost-sni tests/test_manager.sh tests/test_sni_integration.sh
bash tests/test_manager.sh
bash tests/test_sni_integration.sh
```

GOST 本体保持 MIT 许可证；本项目保留上游源码与许可证信息。
