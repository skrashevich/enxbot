"""Exercise screenshot authentication against a local server using Chromium."""
import http.server
from http.cookies import SimpleCookie
import importlib.machinery
import importlib.util
import json
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
    def do_POST(self):
        self.server.posts += 1
        self.send_response(400)
        self.end_headers()

    def do_GET(self):
        cookies = SimpleCookie(self.headers.get('Cookie', ''))
        authorized = (self.headers.get('User-Agent') == 'encx-cli' and
                      cookies.get('GUID') is not None and cookies['GUID'].value == 'test-guid' and
                      cookies.get('stoken') is not None and cookies['stoken'].value == 'test-stoken')
        if self.path == '/game':
            self.server.authorized = authorized
        content_type = 'text/html; charset=utf-8'
        if self.path == '/rich' and not authorized:
            content = b'<form><input name="Login"><input type="password"></form>'
        elif self.path == '/task.js':
            content_type = 'application/javascript'
            content = b"""
              document.write('<p>Written by JavaScript: ' + window.taskDependency + '</p>');
              const canvas = document.querySelector('canvas');
              canvas.getContext('2d').fillRect(0, 0, 20, 20);
              setTimeout(() => fetch('/task-data').then(r => r.text()).then(text => {
                  document.querySelector('#result').innerHTML = '<b>' + text + '</b>';
              }), 1200);
              fetch('/must-not-submit', {method:'POST', body:'answer'}).catch(() => {});
              try {document.querySelector('form').submit();} catch(e) {}
            """
        elif self.path == '/task-data':
            content = 'Асинхронный текст'.encode()
        elif self.path == '/rich':
            content = b"""<html><head><script>window.taskDependency='context ready';</script></head>
              <body><input name="LevelId" value="777" type="hidden">
              <h1>Game navigation, not task text</h1><form method="post"><input name="LevelAction.Answer"></form>
              <div class="content"><div class="task"><p style="display:none">Hidden spoiler</p>
              <form><p>Form instructions</p><input name="answer"></form>
              <table><tr><td id="result">Loading</td></tr></table>
              <canvas width="30" height="30"></canvas><iframe src="/frame" width="120" height="80"></iframe>
              <svg width="20" height="20"><circle r="5" cx="10" cy="10"/></svg>
              <script src="/task.js"></script></div></div></body></html>"""
        elif self.path == '/rich-missing':
            content = b'<h1>No task container</h1>'
        elif self.path == '/login.aspx':
            content = b'<h1>Please sign in</h1>'
        elif self.path == '/form' or not authorized:
            content = b'<form><input name="Login"><input type="password"></form>'
        else:
            content = b'<h1>Authenticated game screen</h1>'
        self.send_response(200)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(content)))
        self.end_headers()
        self.wfile.write(content)

    def log_message(self, *args):
        pass


class ScreenshotAuthenticationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), GamePage)
        cls.server.posts = 0
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

    def rich_payload(self, path='/rich'):
        return {'url': f'http://127.0.0.1:{self.server.server_port}{path}', 'level_id': 777,
                'cookies': [{'name': 'GUID', 'value': 'test-guid'},
                            {'name': 'stoken', 'value': 'test-stoken', 'httpOnly': True}]}

    def test_rich_html_executes_scripts_and_waits_for_async_task_only(self):
        self.server.posts = 0
        process = subprocess.run([sys.executable, str(RUNNER), '--rich-html'],
                                 input=json.dumps(self.rich_payload()), capture_output=True,
                                 text=True, timeout=50)
        self.assertEqual(process.returncode, 0, process.stderr)
        result = json.loads(process.stdout)
        self.assertIn('Written by JavaScript: context ready', result['html'])
        self.assertIn('<b>Асинхронный текст</b>', result['html'])
        self.assertNotIn('Loading', result['html'])
        self.assertNotIn('Hidden spoiler', result['html'])
        self.assertNotIn('Game navigation', result['html'])
        self.assertIn('Form instructions', result['html'])
        self.assertNotIn('<form', result['html'])
        self.assertNotIn('<script', result['html'])
        self.assertNotIn('test-stoken', json.dumps(result))
        self.assertEqual(self.server.posts, 0)
        self.assertEqual(len(result['images']), 3)
        for image in result['images']:
            import base64
            self.assertTrue(base64.b64decode(image['png']).startswith(b'\x89PNG\r\n\x1a\n'))
            self.assertIn(image['id'] + '.png', result['html'])

    def test_rich_html_rejects_changed_level(self):
        payload = self.rich_payload()
        payload['level_id'] = 778
        with self.assertRaises(Exception):
            runner.render_rich(payload)

    def test_rich_html_reports_authentication_through_stdin_protocol(self):
        result = subprocess.run([sys.executable, str(RUNNER), '--rich-html'],
                                input=json.dumps(self.rich_payload('/form')), capture_output=True,
                                text=True, timeout=50)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, '')
        self.assertNotIn('test-stoken', result.stderr)

    def test_rich_html_missing_task_is_bounded(self):
        with self.assertRaises(TimeoutError):
            runner.render_rich(self.rich_payload('/rich-missing'))


if __name__ == '__main__':
    unittest.main()
