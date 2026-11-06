#!/usr/bin/python3
"""Chromium screenshots plus --rich-html JSON snapshots (session via stdin)."""
import base64
from contextlib import contextmanager
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlsplit
from urllib.request import ProxyHandler, Request, build_opener


class AuthenticationRequired(RuntimeError):
    """The game returned a sign-in page instead of an authenticated screen."""


class Driver:
    def __init__(self, port):
        self.base = f"http://127.0.0.1:{port}"
        self.http = build_opener(ProxyHandler({}))

    def call(self, method, path, data=None):
        body = None if data is None else json.dumps(data).encode()
        request = Request(self.base + path, data=body, method=method,
                          headers={"Content-Type": "application/json"})
        with self.http.open(request, timeout=35) as response:
            value = json.load(response).get("value")
        if isinstance(value, dict) and "error" in value:
            raise RuntimeError("WebDriver command failed")
        return value


@contextmanager
def browser(url, cookies, preload=None):
    parsed = urlsplit(url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError("Game URL must be HTTP(S) without embedded credentials")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    client = Driver(port)
    session = None
    with tempfile.TemporaryDirectory(prefix="enxbot-chromium-") as profile:
        process = subprocess.Popen(
            ["/usr/bin/chromedriver", f"--port={port}", "--allowed-ips=127.0.0.1"],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            deadline = time.monotonic() + 10
            while True:
                if process.poll() is not None:
                    raise RuntimeError("ChromeDriver exited during startup")
                try:
                    client.call("GET", "/status")
                    break
                except OSError:
                    if time.monotonic() >= deadline:
                        raise RuntimeError("ChromeDriver startup timed out") from None
                    time.sleep(0.05)
            result = client.call("POST", "/session", {"capabilities": {"alwaysMatch": {
                "browserName": "chrome",
                "goog:chromeOptions": {"binary": "/usr/bin/chromium", "args": [
                    "--headless", "--no-sandbox", "--disable-dev-shm-usage",
                    # Legacy Encounter binds cookies to the User-Agent used at login.
                    # PHP's Encx client uses encx-cli; Chromium must use it too.
                    "--user-agent=encx-cli",
                    "--disable-background-networking", "--no-first-run",
                    "--window-size=768,1024", f"--user-data-dir={profile}"]}
            }}})
            session = "/session/" + result["sessionId"]
            client.call("POST", session + "/timeouts", {"pageLoad": 30000, "script": 5000})
            def cdp(command, params):
                return client.call("POST", session + "/goog/cdp/execute", {"cmd": command, "params": params})
            cdp("Emulation.setDeviceMetricsOverride", {
                "width": 768, "height": 1024, "deviceScaleFactor": 1, "mobile": False})
            if preload:
                cdp("Page.addScriptToEvaluateOnNewDocument", {"source": preload})
            cdp("Network.setCookies", {"cookies": cookies})
            client.call("POST", session + "/url", {"url": url})
            time.sleep(0.2)
            page = client.call("POST", session + "/execute/sync", {
                "script": """
                    return {
                        path: location.pathname.toLowerCase(),
                        loginForm: !!document.querySelector(
                            'input[name="Login"], input[name="txtLogin"]') &&
                            !!document.querySelector('input[type="password"]')
                    };
                """, "args": []})
            if page["path"] in ("/login.aspx", "/simplelogin.aspx", "/login/signin") or page["loginForm"]:
                raise AuthenticationRequired()
            yield client, session, cdp
        finally:
            if session:
                try:
                    client.call("DELETE", session)
                except Exception:
                    pass
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()


def render(args):
    if len(args) != 7 or Path(args[0]).name != "enscreen.js":
        raise ValueError("Usage: screenshot-runner enscreen.js URL output.png GUID stoken Domain atoken")
    _, url, output, guid, stoken, domain_cookie, atoken = args
    target = Path(output)
    if target.suffix.lower() != ".png":
        raise ValueError("Screenshot output must be a PNG file")
    target.unlink(missing_ok=True)
    host = urlsplit(url).hostname or ""
    cookie_domain = ".en.cx" if host == "en.cx" or host.endswith(".en.cx") else host
    cookies = [{"name": name, "value": value, "domain": cookie_domain, "path": "/"}
               for name, value in dict(GUID=guid, stoken=stoken, Domain=domain_cookie,
                                       atoken=atoken, lang="ru").items()]
    with browser(url, cookies) as (_, _, cdp):
        png = base64.b64decode(cdp("Page.captureScreenshot", {
            "format": "png", "captureBeyondViewport": True,
            "clip": {"x": 0, "y": 0, "width": 768,
                     "height": min(30000, max(1024, cdp("Page.getLayoutMetrics", {})["cssContentSize"]["height"])),
                     "scale": 1}})["data"], validate=True)
        if not png.startswith(b"\x89PNG\r\n\x1a\n"):
            raise RuntimeError("Chromium returned an invalid PNG")
        target.write_bytes(png)


TASK_SELECTOR = '.content > .task, [data-testid="level-task"], .level-task, .task-text, #taskText, #task, .task'
RICH_PRELOAD = r"""
(() => {
  const state = window.__enxRich = {pending: 0, timers: 0, changed: Date.now()};
  const originalFetch = window.fetch;
  window.fetch = function(...args) {
    const method = String(args[1]?.method || args[0]?.method || 'GET').toUpperCase();
    if (!['GET','HEAD','OPTIONS'].includes(method)) return Promise.reject(new Error('Read-only render'));
    state.pending++;
    return originalFetch.apply(this, args).finally(() => state.pending--);
  };
  const send = XMLHttpRequest.prototype.send;
  const open = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function(method, ...args) {
    this.__enxMethod = String(method).toUpperCase(); return open.call(this, method, ...args);
  };
  XMLHttpRequest.prototype.send = function(...args) {
    if (!['GET','HEAD','OPTIONS'].includes(this.__enxMethod)) throw new Error('Read-only render');
    state.pending++;
    this.addEventListener('loadend', () => state.pending--, {once: true});
    try {return send.apply(this, args);} catch (error) {state.pending--; throw error;}
  };
  const timer = window.setTimeout, cancel = window.clearTimeout, timers = new Set();
  window.setTimeout = function(callback, delay, ...args) {
    const tracked = typeof callback === 'function' && delay <= 8000 &&
      document.currentScript?.closest('.task, .task-text, .level-task, #task, #taskText');
    if (!tracked) return timer(callback, delay, ...args);
    state.timers++;
    let id = timer(() => {
      if (timers.delete(id)) state.timers--;
      callback(...args);
    }, delay);
    timers.add(id); return id;
  };
  window.clearTimeout = function(id) {
    if (timers.delete(id)) state.timers--;
    return cancel(id);
  };
  document.addEventListener('submit', event => event.preventDefault(), true);
  HTMLFormElement.prototype.submit = HTMLFormElement.prototype.requestSubmit = () => {throw new Error('Read-only render');};
  navigator.sendBeacon = () => false;
  new MutationObserver(() => state.changed = Date.now()).observe(document, {subtree: true, childList: true, characterData: true});
})();
"""


def rich_cookies(url, saved):
    if isinstance(saved, dict):
        saved = saved.get('cookies', [])
    if not isinstance(saved, list):
        raise ValueError('Invalid saved session')
    result = []
    for item in saved:
        if not isinstance(item, dict) or not item.get('name'):
            continue
        cookie = {'name': str(item['name']), 'value': str(item.get('value', '')),
                  'path': item.get('path') or '/', 'secure': bool(item.get('secure', False)),
                  'httpOnly': bool(item.get('httpOnly', False))}
        if item.get('domain'):
            cookie['domain'] = item['domain']
        else:
            cookie['url'] = url
        result.append(cookie)
    return result


def render_rich(payload):
    url = payload['url']
    with browser(url, rich_cookies(url, payload.get('cookies', [])), RICH_PRELOAD) as (client, session, _):
        started = time.monotonic()
        while True:
            state = client.call('POST', session + '/execute/sync', {'script': r"""
                const root = document.querySelector(arguments[0]);
                return {auth: /\/(?:simplelogin\.aspx|login\.aspx|login\/signin)\/?$/i.test(location.pathname) ||
                    (!!document.querySelector('input[name="Login"], input[name="txtLogin"]') && !!document.querySelector('input[type="password"]')),
                    found: !!root, ready: document.readyState === 'complete' &&
                    !window.__enxRich.pending && !window.__enxRich.timers &&
                    Date.now() - window.__enxRich.changed >= 500};
                """, 'args': [TASK_SELECTOR]})
            if state['auth']:
                raise AuthenticationRequired()
            if state['found'] and state['ready'] and time.monotonic() - started >= 1:
                break
            if time.monotonic() - started >= 8:
                raise TimeoutError('Task did not settle')
            time.sleep(0.1)
        snapshot = client.call('POST', session + '/execute/sync', {'script': r"""
            const root = document.querySelector(arguments[0]);
            const level = document.querySelector('input[name="LevelId"]');
            if (level && arguments[1] && Number(level.value) !== Number(arguments[1])) throw new Error('Level changed');
            const images = [];
            function copy(node) {
              if (node.nodeType === Node.TEXT_NODE) return node.cloneNode();
              if (node.nodeType !== Node.ELEMENT_NODE) return null;
              const tag = node.tagName.toLowerCase(), style = getComputedStyle(node);
              // A closed <details> remains a collapsible block, including its content.
              if (['script','style','noscript','template','input','button'].includes(tag) ||
                  node.hidden || style.display === 'none' || style.visibility === 'hidden') return null;
              if ((['canvas','iframe','svg'].includes(tag) || (tag === 'img' && /^(data:|blob:)/.test(node.src))) && node.getBoundingClientRect().width > 0) {
                if (images.length >= 10) throw new Error('Too many drawn images');
                const id = 'render' + images.length, image = document.createElement('img');
                images.push({id, element: node});
                image.src = 'https://enxbot.invalid/snapshot/' + id + '.png';
                return image;
              }
              const clone = tag === 'form' ? document.createElement('div') : node.cloneNode(false);
              for (const name of ['href','src']) if (node.hasAttribute(name)) clone.setAttribute(name, node[name]);
              for (const child of node.childNodes) {const rendered = copy(child); if (rendered) clone.append(rendered);}
              return clone;
            }
            const html = document.createElement('div');
            for (const child of root.childNodes) {const rendered = copy(child); if (rendered) html.append(rendered);}
            if (html.innerHTML.length > 1024*1024) throw new Error('Task too large');
            return {html: html.innerHTML, images};
            """, 'args': [TASK_SELECTOR, payload.get('level_id', 0)]})
        total = 0
        for item in snapshot['images']:
            element = item.pop('element')['element-6066-11e4-a52e-4f735466cecf']
            encoded = client.call('GET', session + '/element/' + element + '/screenshot')
            png = base64.b64decode(encoded, validate=True)
            total += len(png)
            if not png.startswith(b'\x89PNG\r\n\x1a\n') or total > 8 * 1024 * 1024:
                raise ValueError('Task graphics too large')
            item['png'] = encoded
        return snapshot


def main():
    if sys.argv[1:] == ["--version"]:
        return subprocess.call(["/usr/bin/chromium", "--version"])
    def interrupted(signum, frame):
        raise SystemExit(128 + signum)
    def timed_out(signum, frame):
        raise TimeoutError()
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    signal.signal(signal.SIGALRM, timed_out)
    rich = sys.argv[1:] == ['--rich-html']
    signal.alarm(40 if rich else 90)
    try:
        if rich:
            print(json.dumps(render_rich(json.load(sys.stdin)), ensure_ascii=False))
        else:
            render(sys.argv[1:])
        return 0
    except AuthenticationRequired:
        print("Screenshot authentication required", file=sys.stderr)
        return 2
    except Exception as error:
        # WebDriver exceptions can contain URLs and credentials: never log their payloads.
        print(f"Screenshot failed ({type(error).__name__})", file=sys.stderr)
        return 1
    finally:
        signal.alarm(0)


if __name__ == "__main__":
    sys.exit(main())
