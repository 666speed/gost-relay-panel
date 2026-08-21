#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import threading
import unittest
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("gost_relay_agent", ROOT / "node" / "gost-relay-agent.py")
assert SPEC and SPEC.loader
agent = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = agent
SPEC.loader.exec_module(agent)


class AgentRenderTests(unittest.TestCase):
    def test_renders_raw_tcp_ipv4_rules(self) -> None:
        rules = [
            {"id": 7, "name": "first", "listen_port": 443, "target_ipv4": "104.16.1.2", "target_port": 443},
            {"id": 9, "name": "second", "listen_port": 8443, "target_ipv4": "104.17.2.3", "target_port": 2053},
        ]
        rendered = agent.render_gost_config(rules)
        self.assertIn('addr: "0.0.0.0:443"', rendered)
        self.assertIn('addr: "104.16.1.2:443"', rendered)
        self.assertIn('addr: "0.0.0.0:8443"', rendered)
        self.assertEqual(rendered.count("type: tcp"), 4)
        lowered = rendered.lower()
        self.assertNotIn("sniff", lowered)
        self.assertNotIn("bypass", lowered)
        self.assertNotIn("type: udp", lowered)
        self.assertNotIn("::", rendered)

    def test_empty_rules_keep_valid_config(self) -> None:
        rendered = agent.render_gost_config([])
        self.assertIn("services: []", rendered)

    def test_rejects_non_ipv4_and_ambiguous_inputs(self) -> None:
        template = {"id": 1, "name": "bad", "listen_port": 443, "target_ipv4": "1.1.1.1", "target_port": 443}
        for target in ["example.com", "2606:4700::1111", "0.0.0.0", "224.0.0.1", "999.1.1.1"]:
            with self.subTest(target=target), self.assertRaises(agent.AgentError):
                agent.render_gost_config([{**template, "target_ipv4": target}])
        for value in [0, 65536, "443", True]:
            with self.subTest(port=value), self.assertRaises(agent.AgentError):
                agent.render_gost_config([{**template, "listen_port": value}])

    def test_rejects_duplicate_listeners_and_ids(self) -> None:
        first = {"id": 1, "name": "one", "listen_port": 443, "target_ipv4": "1.1.1.1", "target_port": 443}
        with self.assertRaises(agent.AgentError):
            agent.render_gost_config([first, {**first, "id": 2}])
        with self.assertRaises(agent.AgentError):
            agent.render_gost_config([first, {**first, "listen_port": 8443}])

    def test_handles_thousands_of_rules_deterministically(self) -> None:
        rules = [
            {"id": index, "name": f"rule-{index}", "listen_port": 10000 + index, "target_ipv4": "127.0.0.1", "target_port": 20000 + index}
            for index in range(1, 3001)
        ]
        first = agent.render_gost_config(rules)
        second = agent.render_gost_config(list(reversed(rules)))
        self.assertEqual(first, second)
        self.assertEqual(first.count("type: tcp"), 6000)

    def test_failed_restart_restores_previous_config(self) -> None:
        rules = [{"id": 1, "name": "one", "listen_port": 443, "target_ipv4": "1.1.1.1", "target_port": 443}]
        original_config, original_command, original_active = agent.GOST_CONFIG, agent.run_command, agent.service_is_active
        try:
            with tempfile.TemporaryDirectory() as directory:
                agent.GOST_CONFIG = Path(directory) / "gost.yml"
                agent.GOST_CONFIG.write_text("services: []\n# previous\n", encoding="utf-8")
                calls: list[list[str]] = []

                def fake_command(command: list[str], *, check: bool = True) -> subprocess.CompletedProcess[str]:
                    calls.append(command)
                    if command[:2] == ["systemctl", "restart"]:
                        return subprocess.CompletedProcess(command, 1, "", "restart failed")
                    return subprocess.CompletedProcess(command, 0, "", "")

                agent.run_command = fake_command
                agent.service_is_active = lambda: False
                with self.assertRaises(agent.AgentError):
                    agent.apply_rules(rules)
                self.assertEqual(agent.GOST_CONFIG.read_text(encoding="utf-8"), "services: []\n# previous\n")
                self.assertGreaterEqual(sum(1 for call in calls if call[:2] == ["systemctl", "restart"]), 2)
        finally:
            agent.GOST_CONFIG, agent.run_command, agent.service_is_active = original_config, original_command, original_active


class _SyncHandler(BaseHTTPRequestHandler):
    body: dict[str, object] = {}
    headers_seen: dict[str, str] = {}

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("Content-Length", "0"))
        self.__class__.body = json.loads(self.rfile.read(length))
        self.__class__.headers_seen = {key.lower(): value for key, value in self.headers.items()}
        response = {
            "ok": True,
            "desired_revision": 4,
            "poll_interval": 15,
            "rules": [{"id": 2, "name": "sync", "listen_port": 9443, "target_ipv4": "127.0.0.1", "target_port": 19443}],
        }
        encoded = json.dumps(response).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def log_message(self, _format: str, *_args: object) -> None:
        pass


class AgentSyncTests(unittest.TestCase):
    def test_authenticated_sync_applies_new_revision(self) -> None:
        server = ThreadingHTTPServer(("127.0.0.1", 0), _SyncHandler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        original_state, original_config, original_apply = agent.STATE_FILE, agent.GOST_CONFIG, agent.apply_rules
        try:
            with tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                agent.STATE_FILE = root / "state.json"
                agent.GOST_CONFIG = root / "gost.yml"
                applied: list[object] = []

                def fake_apply(rules: object) -> str:
                    applied.append(rules)
                    agent.GOST_CONFIG.write_text("services: []\n", encoding="utf-8")
                    return agent.rules_digest(rules)

                agent.apply_rules = fake_apply
                interval = agent.sync_once({
                    "panel_url": f"http://127.0.0.1:{server.server_port}",
                    "node_id": "11111111-1111-4111-8111-111111111111",
                    "node_secret": "s" * 43,
                })
                state = json.loads(agent.STATE_FILE.read_text(encoding="utf-8"))
                self.assertEqual(interval, 15)
                self.assertEqual(state["applied_revision"], 4)
                self.assertEqual(len(applied), 1)
                self.assertEqual(_SyncHandler.headers_seen["x-node-id"], "11111111-1111-4111-8111-111111111111")
                self.assertEqual(_SyncHandler.headers_seen["authorization"], "Bearer " + "s" * 43)
                self.assertIn("agent_version", _SyncHandler.body)
        finally:
            agent.STATE_FILE, agent.GOST_CONFIG, agent.apply_rules = original_state, original_config, original_apply
            server.shutdown()
            server.server_close()


if __name__ == "__main__":
    unittest.main()
