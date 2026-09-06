#!/usr/bin/python3
"""Chromium adapter: enscreen.js URL output.png GUID stoken Domain atoken."""
import base64
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


def render(args):
    if len(args) != 7 or Path(args[0]).name != "enscreen.js":
        raise ValueError("Usage: screenshot-runner enscreen.js URL output.png GUID stoken Domain atoken")
    _, url, output, guid, stoken, domain_cookie, atoken = args
    parsed = urlsplit(url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError("Screenshot URL must be HTTP(S) without embedded credentials")
    target = Path(output)
    if target.suffix.lower() != ".png":
        raise ValueError("Screenshot output must be a PNG file")
    # Never leave a previous image to be mistaken for success after a failed invocation.
    target.unlink(missing_ok=True)
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
                    "--disable-background-networking", "--no-first-run",
                    "--window-size=768,1024", f"--user-data-dir={profile}"]}
            }}})
            session = "/session/" + result["sessionId"]
            client.call("POST", session + "/timeouts", {"pageLoad": 30000, "script": 5000})
            def cdp(command, params):
                return client.call("POST", session + "/goog/cdp/execute", {"cmd": command, "params": params})
            cdp("Emulation.setDeviceMetricsOverride", {
                "width": 768, "height": 1024, "deviceScaleFactor": 1, "mobile": False})
            # Set cookies before the first request. Share .en.cx cookies with its subdomains;
            # otherwise scope authentication to exactly the requested host (including local tests).
            cookie_domain = ".en.cx" if parsed.hostname == "en.cx" or parsed.hostname.endswith(".en.cx") else parsed.hostname
            cookies = dict(GUID=guid, stoken=stoken, Domain=domain_cookie, atoken=atoken, lang="ru")
            cdp("Network.setCookies", {"cookies": [
                {"name": name, "value": value, "domain": cookie_domain, "path": "/"}
                for name, value in cookies.items()]})
            client.call("POST", session + "/url", {"url": url})
            time.sleep(0.2)
            png = base64.b64decode(cdp("Page.captureScreenshot", {
                "format": "png", "captureBeyondViewport": True,
                "clip": {"x": 0, "y": 0, "width": 768,
                         "height": min(30000, max(1024, cdp("Page.getLayoutMetrics", {})["cssContentSize"]["height"])),
                         "scale": 1}})["data"], validate=True)
            if not png.startswith(b"\x89PNG\r\n\x1a\n"):
                raise RuntimeError("Chromium returned an invalid PNG")
            target.write_bytes(png)
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


def main():
    if sys.argv[1:] == ["--version"]:
        return subprocess.call(["/usr/bin/chromium", "--version"])
    def interrupted(signum, frame):
        raise SystemExit(128 + signum)
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    signal.alarm(90)
    try:
        render(sys.argv[1:])
        return 0
    except Exception as error:
        # WebDriver exceptions can contain URLs and credentials: never log their payloads.
        print(f"Screenshot failed ({type(error).__name__})", file=sys.stderr)
        return 1
    finally:
        signal.alarm(0)


if __name__ == "__main__":
    sys.exit(main())
