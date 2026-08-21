#!/usr/bin/env python3
from __future__ import annotations

import socketserver
import sys


class Handler(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        data = self.request.recv(65536)
        self.request.sendall(PREFIX + data)


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if len(sys.argv) != 3:
    raise SystemExit("usage: tcp_backend.py PORT PREFIX")
PORT = int(sys.argv[1])
PREFIX = sys.argv[2].encode() + b":"
with Server(("127.0.0.1", PORT), Handler) as server:
    server.serve_forever()
