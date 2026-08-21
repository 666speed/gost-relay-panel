#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


class ProjectScopeTests(unittest.TestCase):
    def test_legacy_sni_manager_is_removed(self) -> None:
        self.assertFalse((ROOT / "manager" / "gost-sni").exists())
        self.assertFalse((ROOT / "systemd" / "gost-sni-forward.service").exists())
        self.assertFalse((ROOT / "gost.yml").exists())

    def test_forward_runtime_is_tcp_ipv4_only(self) -> None:
        service = (ROOT / "node" / "gost-relay.service").read_text(encoding="utf-8")
        agent = (ROOT / "node" / "gost-relay-agent.py").read_text(encoding="utf-8")
        self.assertIn("RestrictAddressFamilies=AF_INET", service)
        self.assertNotIn("AF_INET6", service)
        self.assertIn('"      type: tcp"', agent)
        self.assertNotIn('"      type: udp"', agent)
        self.assertNotIn("HostRegexp", agent)
        self.assertNotIn("sniffing:", agent)

    def test_database_enforces_one_listener_per_group(self) -> None:
        schema = (ROOT / "panel" / "database" / "schema.sql").read_text(encoding="utf-8")
        self.assertIn("UNIQUE KEY uq_forward_group_port (group_id, listen_port)", schema)


if __name__ == "__main__":
    unittest.main()
