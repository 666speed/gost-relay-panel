# GOST Relay Web Control Plane

A small self-hosted control plane for managing raw TCP/IPv4 GOST forwarding across Ubuntu and Debian nodes. The web panel is designed for a standard BT/aaPanel Nginx + PHP + MySQL stack.

This version deliberately has no SNI allowlist or TLS sniffing. It does not create UDP or IPv6 listeners. Each rule maps one TCP listening port to one fixed IPv4 address and port.

## Highlights

- Chinese web UI for server groups, node health, and forwarding rules.
- Per-group enrollment command; every enrolled node receives that group's rules.
- HTTPS pull model: nodes require no inbound management port and keep their last working rules if the panel is unavailable.
- Atomic, validated GOST configuration updates with automatic rollback.
- systemd boot startup and unlimited restart attempts for both GOST and the node agent.
- Tested GOST `v3.2.6` release with SHA256 verification.
- Ubuntu 20.04/22.04/24.04 and Debian 11/12/13; amd64, arm64, 386, and armv7.

See [README.md](README.md) for the complete BT/aaPanel deployment and node installation guide.

## Security warning

Without SNI filtering, anyone who can reach a node's listening port can use that fixed TCP forward. Restrict source addresses with a cloud firewall/UFW, expose it only over a private network, or use authentication in the forwarded protocol.
