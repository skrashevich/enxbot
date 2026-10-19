"""Exercise screenshot authentication against a local server using Chromium."""
import http.server
from http.cookies import SimpleCookie
import importlib.machinery
import importlib.util
import os
from pathlib import Path
import struct
import subprocess
import sys
import tempfile
import threading
import unittest


RUNNER = Path(os.environ.get(
    'ENXBOT_SCREENSHOT_RUNNER', Path(__file__).resolve().parents[1] / 'screenshot-runner.py'))
loader = importlib.machinery.SourceFileLoader('enxbot_screenshot', str(RUNNER))
runner = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
loader.exec_module(runner)


class GamePage(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        cookies = SimpleCookie(self.headers.get('Cookie', ''))
        authorized = (self.headers.get('User-Agent') == 'encx-cli' and
                      cookies.get('GUID') is not None and cookies['GUID'].value == 'test-guid' and
                      cookies.get('stoken') is not None and cookies['stoken'].value == 'test-stoken')
        if self.path == '/game':
            self.server.authorized = authorized
        if self.path == '/login.aspx':
            content = b'<h1>Please sign in</h1>'
        elif self.path == '/form' or not authorized:
            content = b'<form><input name="Login"><input type="password"></form>'
        else:
            content = b'<h1>Authenticated game screen</h1>'
        self.send_response(200)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.send_header('Content-Length', str(len(content)))
        self.end_headers()
        self.wfile.write(content)

    def log_message(self, *args):
        pass


class ScreenshotAuthenticationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = http.server.HTTPServer(('127.0.0.1', 0), GamePage)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=5)

    def arguments(self, path, output):
        return ['enscreen.js', f'http://127.0.0.1:{self.server.server_port}{path}',
                str(output), 'test-guid', 'test-stoken', '', '']

    def test_session_cookies_and_user_agent_match_login(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'screen.png'
            runner.render(self.arguments('/game', output))
            png = output.read_bytes()
            self.assertEqual(png[:8], b'\x89PNG\r\n\x1a\n')
            width, height = struct.unpack('>II', png[16:24])
            self.assertEqual(width, 768)
            self.assertGreaterEqual(height, 1024)
            self.assertTrue(self.server.authorized)

    def test_login_route_is_rejected_without_form(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'screen.png'
            output.write_bytes(b'old screenshot')
            with self.assertRaises(runner.AuthenticationRequired):
                runner.render(self.arguments('/login.aspx', output))
            self.assertFalse(output.exists())

    def test_login_form_reports_authentication_exit_code(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'screen.png'
            output.write_bytes(b'old screenshot')
            result = subprocess.run([sys.executable, str(RUNNER), *self.arguments('/form', output)],
                                    capture_output=True, text=True, timeout=50)
            self.assertEqual(result.returncode, 2)
            self.assertFalse(output.exists())
            self.assertEqual(result.stderr.strip(), 'Screenshot authentication required')


if __name__ == '__main__':
    unittest.main()
