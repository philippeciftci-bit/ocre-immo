#!/usr/bin/env python3
# M118b — Mock Google OAuth + Calendar API server for STUB MODE.
import http.server, json, sys, secrets, time
from urllib.parse import urlparse, parse_qs

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8891

class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        sys.stdout.write('[gmock] ' + (fmt % args) + '\n'); sys.stdout.flush()
    def _send(self, status, body, ct='application/json'):
        self.send_response(status); self.send_header('Content-Type', ct + '; charset=utf-8'); self.end_headers()
        self.wfile.write(body.encode('utf-8') if isinstance(body, str) else json.dumps(body).encode('utf-8'))
    def do_GET(self):
        u = urlparse(self.path); qs = parse_qs(u.query)
        if u.path == '/health': return self._send(200, {'ok': True, 'service': 'google-oauth-mock', 'port': PORT})
        if u.path == '/mock/google-oauth/consent':
            state = (qs.get('state', [''])[0])
            redirect_uri = (qs.get('redirect_uri', [''])[0])
            html = f'''<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;text-align:center;background:#f8f9fa">
<h1 style="color:#1a73e8">Google · Mock OAuth Consent</h1>
<p>Oi Agent demande l'accès à votre Google Calendar</p>
<p>Scope : <code>{qs.get('scope',[''])[0]}</code></p>
<form method="GET" action="{redirect_uri}">
  <input type="hidden" name="state" value="{state}">
  <input type="hidden" name="code" value="mock_code_{secrets.token_hex(8)}">
  <button type="submit" style="background:#1a73e8;color:#fff;padding:14px 28px;border:0;border-radius:6px;font-size:16px;cursor:pointer">Autoriser</button>
</form>
</body></html>'''
            return self._send(200, html, 'text/html')
        if u.path == '/mock/google-calendar/events':
            now = int(time.time())
            events = [
                {'id': 'g_evt_1', 'summary': 'Mock Visite Marrakech', 'start': {'dateTime': time.strftime('%Y-%m-%dT10:00:00Z', time.gmtime(now+86400))}, 'end': {'dateTime': time.strftime('%Y-%m-%dT11:00:00Z', time.gmtime(now+86400+3600))}, 'location': 'Riad Médina'},
                {'id': 'g_evt_2', 'summary': 'Mock Signature mandat', 'start': {'dateTime': time.strftime('%Y-%m-%dT15:00:00Z', time.gmtime(now+172800))}, 'end': {'dateTime': time.strftime('%Y-%m-%dT15:30:00Z', time.gmtime(now+172800+1800))}, 'location': 'Office Casablanca'},
                {'id': 'g_evt_3', 'summary': 'Mock RDV mandant', 'start': {'dateTime': time.strftime('%Y-%m-%dT14:00:00Z', time.gmtime(now+259200))}, 'end': {'dateTime': time.strftime('%Y-%m-%dT15:00:00Z', time.gmtime(now+259200+3600))}},
            ]
            return self._send(200, {'kind': 'calendar#events', 'items': events})
        return self._send(404, {'error': 'not_found'})
    def do_POST(self):
        u = urlparse(self.path)
        n = int(self.headers.get('Content-Length', 0))
        raw = self.rfile.read(n) if n > 0 else b''
        if u.path == '/mock/google-oauth/token':
            return self._send(200, {
                'access_token': 'ya29.mock_' + secrets.token_hex(20),
                'refresh_token': '1//mock_refresh_' + secrets.token_hex(20),
                'expires_in': 3600,
                'token_type': 'Bearer',
                'scope': 'https://www.googleapis.com/auth/calendar',
                'email': 'agent.mock@google.com',
            })
        if u.path == '/mock/google-calendar/events':
            try: body = json.loads(raw)
            except: body = {}
            return self._send(200, {'id': 'g_evt_' + secrets.token_hex(6), 'status': 'confirmed', 'created': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'data': body})
        return self._send(404, {'error': 'not_found'})

def main():
    httpd = http.server.ThreadingHTTPServer(('127.0.0.1', PORT), H)
    sys.stdout.write(f'[gmock] listening on http://127.0.0.1:{PORT}\n'); sys.stdout.flush()
    httpd.serve_forever()
if __name__ == '__main__': main()
