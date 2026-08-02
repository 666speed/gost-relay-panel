# GOST Fixed-IP Forwarding with a Global SNI Allowlist

An Ubuntu 22.04 manager for forwarding TCP/TLS traffic to fixed Cloudflare (or other upstream) IP addresses with GOST. Every forwarding rule shares one global list of base domains.

Adding `example.com` permits `example.com` and any depth of subdomain such as `www.example.com` or `a.b.example.com`. Missing SNI, non-TLS traffic, unrelated domains, and suffix lookalikes such as `notexample.com` are rejected before an upstream connection is selected.

## One-line install

Run as root on Ubuntu 22.04:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/666speed/gost-sni-forward/main/install.sh)
```

Open the Chinese interactive menu at any time:

```bash
sudo gost-sni
```

Or use the CLI directly:

```bash
sudo gost-sni domain add example.com example.net
sudo gost-sni forward add 443 104.16.1.2 443 cloudflare-443
sudo gost-sni status
```

The installer downloads the latest stable official GOST release, verifies its SHA256 checksum, installs a hardened systemd unit, and backs up an existing `/usr/local/bin/gost` before replacing it.

## Security model

- The upstream IP and port are fixed; clients cannot request arbitrary destinations.
- A service-level whitelist and a node-level TLS/host matcher provide fail-closed SNI filtering.
- TLS is passed through unchanged. The manager does not terminate TLS, store certificates, or perform MITM.
- Domain configuration lives in `/etc/gost-sni-forward/domains.txt` and is automatically applied to every forwarding rule.

An SNI allowlist restricts the domains that can be forwarded; it does not authenticate client devices. An attacker can still spoof an allowed SNI and consume bandwidth toward the fixed upstream. Restrict client source IPs with a firewall or use an authenticated tunnel if device-level access control is required.

## Scope

This project handles TCP/TLS only. QUIC/HTTP3 over UDP and ECH-hidden hostnames are deliberately rejected/out of scope. The menu does not modify UFW automatically.

See [README.md](README.md) for the full Chinese guide.
