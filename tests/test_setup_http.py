#!/usr/bin/env python3
from __future__ import annotations

import http.cookiejar
import os
import re
import urllib.parse
import urllib.request
import unittest

BASE_URL = os.environ.get("SETUP_URL", "http://127.0.0.1:18083").rstrip("/")


class SetupWizardTests(unittest.TestCase):
    def test_initialization_writes_config_and_redirects_to_login(self) -> None:
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        setup_html = opener.open(BASE_URL + "/setup.php", timeout=10).read().decode()
        match = re.search(r'name="_csrf" value="([a-f0-9]{64})"', setup_html)
        self.assertIsNotNone(match)
        fields = {
            "_csrf": match.group(1),
            "db_host": os.environ.get("MYSQL_HOST", "127.0.0.1"),
            "db_port": os.environ.get("MYSQL_PORT", "3307"),
            "db_name": os.environ.get("SETUP_DATABASE", "gost_relay_setup"),
            "db_user": os.environ.get("MYSQL_USER", "relay"),
            "db_password": os.environ.get("MYSQL_PASSWORD", "relay-password"),
            "app_url": BASE_URL,
            "admin_user": "setup-admin",
            "admin_password": "setup-admin-password-123",
            "admin_confirm": "setup-admin-password-123",
        }
        request = urllib.request.Request(BASE_URL + "/setup.php", data=urllib.parse.urlencode(fields).encode(), method="POST")
        response = opener.open(request, timeout=10)
        self.assertTrue(response.geturl().endswith("/login?installed=1"))
        self.assertIn("初始化完成", response.read().decode())
        with urllib.request.urlopen(BASE_URL + "/health", timeout=10) as health:
            self.assertEqual(health.status, 200)


if __name__ == "__main__":
    unittest.main()
