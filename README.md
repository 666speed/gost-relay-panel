# GOST Relay Web 主控

面向自用服务器的轻量 TCP 转发控制面：在宝塔的 **Nginx + PHP + MySQL** 上部署一个中文 Web 主控，通过服务器组生成一键安装命令，把 Ubuntu / Debian 节点接入后统一管理多条 GOST 转发规则。

> 当前版本只做 **原始 TCP + IPv4**。没有 SNI 白名单、TLS 嗅探、UDP、IPv6、用户商城、套餐或计费系统。

## 功能

- 中文 Web 控制台：运行概览、服务器组、节点状态、TCP 转发规则。
- 每个服务器组生成独立安装令牌和一键节点命令。
- 一个服务器组可接入多台节点；组内节点共享同一份规则。
- 每条规则为 `一个监听端口 -> 一个固定 IPv4:端口`，数据库和节点双重拒绝重复监听端口。
- 规则支持新增、编辑、暂停、启用、删除，并显示节点配置同步版本。
- 节点主动通过 HTTPS 拉取，不要求主控 SSH 节点，也不需要节点开放管理端口。
- 主控离线不会中断已运行的转发；网络恢复后节点自动继续同步。
- GOST 和节点代理均由 systemd 开机启动、异常退出无限重启。
- 新配置先经 GOST 校验，再原子替换；启动失败自动恢复上一份配置。
- 固定使用并校验 GOST `v3.2.6` 官方安装包 SHA256。

## 架构

```text
浏览器 ──HTTPS──> 宝塔 Nginx ──PHP──> MySQL
                         ▲
                         │ HTTPS 轮询（默认 15 秒）
                         │
               Ubuntu / Debian 节点代理
                         │
                         └── systemd ──> GOST TCP IPv4 转发
```

规则由节点主动拉取。MySQL 只保存主控数据；实际数据流量不经过 Web 主控，直接由各节点上的 GOST 处理。

## 一、宝塔部署 Web 主控

要求：

- Nginx；
- PHP `8.1+`，启用 `pdo_mysql`、`openssl`、`session`；
- MySQL `5.7+` 或 MariaDB `10.4+`；
- 一个已配置有效证书的 HTTPS 域名；
- 面板必须部署在域名根路径，不能放在 `/relay` 之类子目录。

步骤：

1. 在宝塔新建 PHP 网站和 MySQL 数据库。
2. 把本仓库 `panel` 目录中的内容上传到网站目录。
3. 在宝塔网站设置中把“运行目录”设为 `/public`。
4. 给 PHP 网站用户写入 `panel/config` 的权限。初始化完成后，`config.php` 权限应保持 `600`。
5. 设置 Nginx 伪静态：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include fastcgi.conf;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
}
```

宝塔已自动生成 PHP FastCGI 配置时，只添加 `try_files` 规则和 `HTTP_AUTHORIZATION` 参数，不要重复创建 `fastcgi_pass`。完整参考见 [`panel/nginx.conf.example`](panel/nginx.conf.example)。

6. 使用浏览器访问 `https://你的面板域名/setup.php`，填写数据库和管理员信息。
7. 登录后进入“服务器组”，创建组并复制节点安装命令。

## 二、接入 Ubuntu / Debian 节点

服务器组会生成类似命令：

```bash
bash <(curl -fLSs https://relay.example.com/download/node-install.sh) rel_nodeclient "-t 服务器组令牌 -u https://relay.example.com"
```

在节点上使用 root 执行。安装器会：

- 识别 Ubuntu / Debian 与 CPU 架构；
- 安装 Python 3、curl、证书和网络工具；
- 下载并校验官方 GOST；
- 向主控登记，保存独立节点凭据；
- 安装 `gost-relay.service` 与 `gost-relay-agent.service`；
- 开机自动启动并立即同步规则。

节点运维命令：

```bash
relayctl status     # 查看 GOST 和节点代理状态
relayctl logs       # 查看最近日志
relayctl follow     # 实时跟踪日志
relayctl sync       # 立即同步
relayctl config     # 查看当前 GOST 配置
relayctl update     # 更新节点组件
relayctl uninstall  # 卸载并停止全部本机转发
```

兼容目标：Ubuntu 20.04 / 22.04 / 24.04，Debian 11 / 12 / 13；支持 amd64、arm64、386、armv7。

## 三、添加 TCP 转发

Web 主控中打开“TCP 转发”并填写：

- 规则名称；
- 服务器组；
- 节点 TCP 监听端口；
- 固定目标 IPv4；
- 目标端口。

示例：组内所有节点监听 `443/tcp`，转发到 `104.16.1.2:443`。

同一个服务器组内，每个监听端口只能出现一次。不同服务器组可以使用相同端口，因为它们部署在不同节点范围。

## 安全说明

删除 SNI 白名单后，转发端口就是普通 TCP 端口。**任何能访问节点监听端口的人都能使用该固定目标转发。** 如果只允许自己使用，应同时采取至少一种措施：

- 在云厂商安全组或 UFW 中只允许自己的公网源 IP；
- 将节点放入 WireGuard / Tailscale 等私网，只监听可控入口；
- 在目标协议自身启用可靠身份认证。

主控必须使用可信 HTTPS 证书。节点安装令牌只用于首次登记；登记成功后，每台节点使用独立随机密钥。轮换组令牌不会影响已上线节点。撤销节点后，节点下一次同步会收到空规则并清空本机转发；无法联系主控时，应直接在节点执行 `relayctl uninstall`。

## 稳定性设计

- 节点轮询失败采用带抖动的指数退避，最长 60 秒；不会删除当前工作配置。
- GOST 服务与代理服务设置 `Restart=always` 和无限启动重试。
- 配置应用包含语法校验、同目录原子替换、启动状态检查和失败回滚。
- GOST 以 `nobody` 运行，仅保留绑定低端口能力，转发服务限制为 `AF_INET`。
- `LimitNOFILE=1048576`、`TasksMax=infinity`，适合大量长连接；实际容量仍取决于节点 CPU、内存、内核参数和带宽。
- 规则全部由一个 GOST 进程承载，避免每条规则一个进程带来的管理开销。

## 开发与测试

```bash
shellcheck install.sh node/node-install.sh node/relayctl tests/test_tcp_integration.sh
python3 -m unittest -v tests/test_agent.py tests/test_project_scope.py
bash tests/test_tcp_integration.sh
find panel tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

CI 还会启动 MySQL 和 PHP Web 主控，完成真实节点登记、鉴权同步、一键安装、systemd 异常恢复、无 UDP/IPv6 监听以及多条 TCP 转发测试。

GOST 本体遵循其上游 MIT 许可证。本项目保留上游源码与许可证信息。
