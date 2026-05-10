#!/usr/bin/env python3
# M116b — Mock receiver webhook pour tests E2E.
# Accepte POST /receive avec X-Ocre-Signature HMAC-SHA256, verify, store.
# GET /received retourne les 100 derniers events.
import http.server, json, os, sys, hashlib, hmac
from urllib.parse import urlparse

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8890
SECRET = os.environ.get('WEBHOOK_TEST_SECRET', 'test_secret_hardcoded_dev')
STORE = []  # list of dicts

class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        sys.stdout.write('[wh-mock] ' + (fmt % args) + '\n'); sys.stdout.flush()
    def _send(self, status, body):
        self.send_response(status); self.send_header('Content-Type', 'application/json'); self.end_headers()
        self.wfile.write(json.dumps(body).encode('utf-8'))
    def do_POST(self):
        if urlparse(self.path).path != '/receive':
            return self._send(404, {'error': 'not_found'})
        n = int(self.headers.get('Content-Length', 0))
        raw = self.rfile.read(n) if n > 0 else b''
        sig_header = self.headers.get('X-Ocre-Signature', '')
        event_type = self.headers.get('X-Ocre-Event', 'unknown')
        # Verify signature
        valid = False
        if sig_header.startswith('sha256='):
            expected = hmac.new(SECRET.encode(), raw, hashlib.sha256).hexdigest()
            valid = hmac.compare_digest(expected, sig_header[7:])
        try:
            payload = json.loads(raw.decode('utf-8'))
        except Exception:
            payload = {'_raw': raw[:200].decode('utf-8', 'replace')}
        STORE.append({'event': event_type, 'signature_valid': valid, 'payload': payload})
        if len(STORE) > 100: STORE.pop(0)
        if not valid:
            return self._send(401, {'error': 'invalid_signature', 'received': len(STORE)})
        return self._send(200, {'ok': True, 'received': len(STORE)})
    def do_GET(self):
        p = urlparse(self.path).path
        if p == '/received': return self._send(200, {'count': len(STORE), 'events': STORE[-20:]})
        if p == '/health': return self._send(200, {'ok': True, 'service': 'webhook-mock-receiver', 'port': PORT})
        if p == '/clear': STORE.clear(); return self._send(200, {'cleared': True})
        return self._send(404, {'error': 'not_found'})

def main():
    httpd = http.server.ThreadingHTTPServer(('127.0.0.1', PORT), H)
    sys.stdout.write(f'[wh-mock] listening on http://127.0.0.1:{PORT}\n'); sys.stdout.flush()
    httpd.serve_forever()
if __name__ == '__main__': main()
