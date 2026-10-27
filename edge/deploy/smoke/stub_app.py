"""A stand-in for the NiceTryBot app on :8080, just enough for deploy/smoke/run.sh.

GET  /caddy/ask?domain=     200 for site.localhost, else 404 (no token, like the app)
GET  /caddy/origins         site.localhost -> http://whoami:80
GET  /caddy/<other lists>   empty lists in the shapes the edge's modules expect
POST /caddy/ingest          prints each NDJSON line ("stub-app: line {...}"); the first
                            STUB_FAIL_FIRST_INGESTS batches get a 503, to show Vector retrying
anything else               a small "panel" page

Every /caddy/* call but ask must carry the bearer token; refusals are printed.
"""
import json
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

TOKEN = os.environ["NICETRY_EDGE_TOKEN"]
FAIL_FIRST = int(os.environ.get("STUB_FAIL_FIRST_INGESTS", "0"))
state = {"ingests": 0}

ORIGIN = {
    "host": "site.localhost",
    "site_host": "site.localhost",
    "scheme": "http",
    "upstream": "whoami:80",
    "server_name": "",
    "host_header": "site.localhost",
    "forwarded_host_header": None,
    "extra_forwarded_host_headers": [],
    "secret": "smoke-origin-secret",
    "timeout": 0,
    "hsts": None,
    "cache_assets": False,
}

LISTS = {
    "/caddy/origins": {"origins": [ORIGIN]},
    "/caddy/response-captures": {"captures": []},
    "/caddy/gym-triggers": {"triggers": []},
    "/caddy/site-headers": {"sites": []},
    "/caddy/live-tail": {"hosts": []},
    "/caddy/lockdowns": {"lockdowns": []},
}


def say(message):
    sys.stderr.write("stub-app: %s\n" % message)
    sys.stderr.flush()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass

    def send(self, status, body, content_type="application/json"):
        data = body.encode()
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def authed(self):
        if self.headers.get("Authorization") == "Bearer " + TOKEN:
            return True
        say("refused %s %s (no or wrong token)" % (self.command, self.path))
        self.send(401, '{"message":"Unauthenticated."}')
        return False

    def do_GET(self):
        url = urlparse(self.path)
        if url.path == "/caddy/ask":
            domain = parse_qs(url.query).get("domain", [""])[0]
            say("ask %s" % domain)
            return self.send(200 if domain == "site.localhost" else 404, "")
        if url.path.startswith("/caddy/"):
            if not self.authed():
                return
            return self.send(200, json.dumps(LISTS[url.path])) if url.path in LISTS else self.send(404, "{}")
        say("panel %s host=%s proto=%s" % (url.path, self.headers.get("Host"), self.headers.get("X-Forwarded-Proto")))
        return self.send(200, "<!doctype html><title>Panel</title><p>panel stub</p>\n", "text/html")

    def do_POST(self):
        if urlparse(self.path).path != "/caddy/ingest":
            return self.send(405, "{}")
        if not self.authed():
            return
        body = self.rfile.read(int(self.headers.get("Content-Length", "0"))).decode()
        state["ingests"] += 1
        if state["ingests"] <= FAIL_FIRST:
            say("ingest #%d answered 503 on purpose" % state["ingests"])
            return self.send(503, '{"message":"down for the test"}')
        lines = [line for line in body.splitlines() if line.strip()]
        say("ingest #%d: %d lines, %s" % (state["ingests"], len(lines), self.headers.get("Content-Type")))
        for line in lines:
            say("line " + line)
        return self.send(200, json.dumps({"imported": len(lines), "skipped": 0, "duplicates": 0}))


ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
