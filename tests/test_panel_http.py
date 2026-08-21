#!/usr/bin/env python3
from __future__ import annotations

import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
import unittest

BASE_URL = os.environ.get("PANEL_URL", "http://127.0.0.1:18080").rstrip("/")
TOKEN = os.environ.get("ENROLL_TOKEN", "ci-enrollment-token-1234567890-ABCDEFGH")


def json_request(path: str, payload: dict[str, object], headers: dict[str, str] | None = None) -> tuple[int, dict[str, object]]:
    request_headers = {"Content-Type": "application/json", "Accept": "application/json"}
    if headers:
        request_headers.update(headers)
    request = urllib.request.Request(
        BASE_URL + path,
        data=json.dumps(payload).encode(),
        headers=request_headers,
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            return response.status, json.loads(response.read())
    except urllib.error.HTTPError as exc:
        return exc.code, json.loads(exc.read())


class PanelHttpTests(unittest.TestCase):
    node_id = ""
    node_secret = ""

    def test_01_health(self) -> None:
        with urllib.request.urlopen(BASE_URL + "/health", timeout=10) as response:
            payload = json.loads(response.read())
        self.assertEqual(response.status, 200)
        self.assertTrue(payload["ok"])

    def test_02_rejects_invalid_enrollment(self) -> None:
        status, payload = json_request("/api/v1/enroll", {"token": "x" * 40, "name": "bad"})
        self.assertEqual(status, 401)
        self.assertEqual(payload["error"], "invalid_token")

    def test_03_enrolls_and_syncs_authenticated_node(self) -> None:
        status, payload = json_request("/api/v1/enroll", {
            "token": TOKEN,
            "name": "ci-node",
            "hostname": "ci-host",
            "os": "Ubuntu 22.04",
            "architecture": "x86_64",
            "agent_version": "1.0.0",
        })
        self.assertEqual(status, 201)
        self.__class__.node_id = str(payload["node_id"])
        self.__class__.node_secret = str(payload["node_secret"])
        status, sync = json_request("/api/v1/sync", {
            "applied_revision": 0,
            "last_error": "",
            "hostname": "ci-host",
            "os": "Ubuntu 22.04",
            "architecture": "x86_64",
            "agent_version": "1.0.0",
        }, {
            "X-Node-ID": self.node_id,
            "Authorization": "Bearer " + self.node_secret,
        })
        self.assertEqual(status, 200)
        self.assertEqual(sync["desired_revision"], 2)
        self.assertEqual(sync["rules"], [{
            "id": 1,
            "name": "CI Rule",
            "listen_port": 19445,
            "target_ipv4": "127.0.0.1",
            "target_port": 19443,
        }])

    def test_04_rejects_wrong_node_secret(self) -> None:
        status, payload = json_request("/api/v1/sync", {}, {
            "X-Node-ID": self.node_id,
            "Authorization": "Bearer wrong-secret",
        })
        self.assertEqual(status, 401)
        self.assertEqual(payload["error"], "unauthorized")

    def test_05_revoked_node_receives_empty_rules(self) -> None:
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        login_html = opener.open(BASE_URL + "/login", timeout=10).read().decode()
        token_match = re.search(r'name="_csrf" value="([a-f0-9]{64})"', login_html)
        self.assertIsNotNone(token_match)
        csrf = token_match.group(1)
        login_body = urllib.parse.urlencode({
            "_csrf": csrf,
            "username": "admin",
            "password": "correct-horse-battery-staple",
        }).encode()
        opener.open(urllib.request.Request(BASE_URL + "/login", data=login_body, method="POST"), timeout=10)
        revoke_body = urllib.parse.urlencode({"_csrf": csrf, "id": self.node_id}).encode()
        response = opener.open(urllib.request.Request(BASE_URL + "/nodes/delete", data=revoke_body, method="POST"), timeout=10)
        self.assertTrue(response.geturl().endswith("/nodes"))
        status, payload = json_request("/api/v1/sync", {
            "applied_revision": 2,
            "last_error": "",
        }, {
            "X-Node-ID": self.node_id,
            "Authorization": "Bearer " + self.node_secret,
        })
        self.assertEqual(status, 200)
        self.assertTrue(payload["revoked"])
        self.assertEqual(payload["desired_revision"], 0)
        self.assertEqual(payload["rules"], [])

    def test_06_admin_login_and_csrf(self) -> None:
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        login_html = opener.open(BASE_URL + "/login", timeout=10).read().decode()
        token_match = re.search(r'name="_csrf" value="([a-f0-9]{64})"', login_html)
        self.assertIsNotNone(token_match)
        body = urllib.parse.urlencode({
            "_csrf": token_match.group(1),
            "username": "admin",
            "password": "correct-horse-battery-staple",
        }).encode()
        response = opener.open(urllib.request.Request(BASE_URL + "/login", data=body, method="POST"), timeout=10)
        dashboard = response.read().decode()
        self.assertEqual(response.geturl(), BASE_URL + "/")
        self.assertIn("所有节点，一处管理", dashboard)
        for path, marker in [('/groups', '添加服务器组'), ('/nodes', '节点状态'), ('/forwards', 'TCP FORWARD')]:
            page = opener.open(BASE_URL + path, timeout=10).read().decode()
            self.assertIn(marker, page)

    def test_07_public_installer_and_agent_downloads(self) -> None:
        installer = urllib.request.urlopen(BASE_URL + "/download/node-install.sh", timeout=10).read().decode()
        agent = urllib.request.urlopen(BASE_URL + "/download/agent.py", timeout=10).read().decode()
        self.assertTrue(installer.startswith("#!/usr/bin/env bash"))
        self.assertIn("rel_nodeclient", installer)
        self.assertTrue(agent.startswith("#!/usr/bin/env python3"))
        self.assertIn("render_gost_config", agent)


if __name__ == "__main__":
    unittest.main()
