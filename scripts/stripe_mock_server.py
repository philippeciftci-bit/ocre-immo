#!/usr/bin/env python3
# M107 — Mock Stripe API server pour tests local channel premium.
# Endpoints minimal pour /v1/customers, /v1/subscriptions, /v1/checkout/sessions.
# + endpoint /webhook/simulate qui POST l event simulated vers le backend Ocre.

import http.server
import json
import os
import secrets
import sys
import threading
import time
import urllib.parse
import urllib.request

STORE_PATH = '/tmp/ocre-stripe-mock.json'
LOCK = threading.Lock()
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8889
BACKEND_WEBHOOK_URL = os.environ.get('BACKEND_WEBHOOK_URL', 'https://exbat-tat-ad7d.ocre.immo/api/billing/webhook.php')


def load_store():
    if not os.path.exists(STORE_PATH):
        return {'customers': {}, 'subscriptions': {}, 'sessions': {}, 'invoices': {}}
    try:
        with open(STORE_PATH) as f:
            return json.load(f)
    except Exception:
        return {'customers': {}, 'subscriptions': {}, 'sessions': {}, 'invoices': {}}


def save_store(s):
    tmp = STORE_PATH + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(s, f, indent=2)
    os.replace(tmp, STORE_PATH)


def parse_form(raw):
    return dict(urllib.parse.parse_qsl(raw.decode('utf-8'), keep_blank_values=True))


def gen_id(prefix):
    return prefix + '_' + secrets.token_hex(8)


def post_webhook(event):
    """Forward event to the backend Ocre webhook endpoint (simule signature en mode mock)."""
    try:
        body = json.dumps(event).encode('utf-8')
        req = urllib.request.Request(
            BACKEND_WEBHOOK_URL,
            data=body,
            headers={'Content-Type': 'application/json', 'Stripe-Signature': 't=mock,v1=mock'},
            method='POST',
        )
        ctx = None
        if BACKEND_WEBHOOK_URL.startswith('https://'):
            import ssl
            ctx = ssl._create_unverified_context()
        with urllib.request.urlopen(req, timeout=10, context=ctx) as r:
            return r.status, r.read().decode('utf-8', 'replace')
    except Exception as e:
        return 0, str(e)


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        sys.stdout.write('[stripe-mock] ' + (fmt % args) + '\n')
        sys.stdout.flush()

    def _send(self, status, body):
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(body).encode('utf-8'))

    def _read(self):
        n = int(self.headers.get('Content-Length', 0))
        return self.rfile.read(n) if n > 0 else b''

    def do_POST(self):
        path = urllib.parse.urlparse(self.path).path
        raw = self._read()
        form = parse_form(raw) if raw else {}

        if path == '/v1/customers':
            with LOCK:
                store = load_store()
                cid = gen_id('cus')
                cust = {
                    'id': cid,
                    'email': form.get('email', ''),
                    'metadata': {k.replace('metadata[', '').rstrip(']'): v for k, v in form.items() if k.startswith('metadata[')},
                    'created': int(time.time()),
                }
                store['customers'][cid] = cust
                save_store(store)
            return self._send(200, cust)

        if path == '/v1/checkout/sessions':
            with LOCK:
                store = load_store()
                sid = gen_id('cs')
                meta = {k.replace('metadata[', '').rstrip(']'): v for k, v in form.items() if k.startswith('metadata[')}
                sess = {
                    'id': sid,
                    'url': f'http://127.0.0.1:{PORT}/mock/checkout?session={sid}',
                    'customer': form.get('customer'),
                    'mode': form.get('mode', 'subscription'),
                    'metadata': meta,
                    'created': int(time.time()),
                }
                store['sessions'][sid] = sess
                save_store(store)
            return self._send(200, sess)

        # Mock checkout success URL : POST /mock/checkout/complete?session=cs_xxx
        if path == '/mock/checkout/complete':
            qs = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(self.path).query))
            sid = qs.get('session', '')
            with LOCK:
                store = load_store()
                sess = store['sessions'].get(sid)
                if not sess:
                    return self._send(404, {'error': 'session_not_found'})
                # Cree subscription
                subId = gen_id('sub')
                now = int(time.time())
                sub = {
                    'id': subId,
                    'customer': sess['customer'],
                    'status': 'active',
                    'current_period_start': now,
                    'current_period_end': now + 30 * 86400,
                    'cancel_at_period_end': False,
                    'metadata': sess['metadata'],
                }
                store['subscriptions'][subId] = sub
                save_store(store)
            # Trigger webhook event customer.subscription.created
            event = {
                'id': gen_id('evt'),
                'type': 'customer.subscription.created',
                'data': {'object': sub},
                'created': now,
            }
            wcode, wbody = post_webhook(event)
            return self._send(200, {'ok': True, 'subscription': sub, 'webhook_status': wcode, 'webhook_body': wbody[:200]})

        # POST /v1/subscriptions/{id} → update (cancel_at_period_end)
        if path.startswith('/v1/subscriptions/'):
            subId = path.rsplit('/', 1)[1]
            with LOCK:
                store = load_store()
                sub = store['subscriptions'].get(subId)
                if not sub:
                    return self._send(404, {'error': 'subscription_not_found'})
                if 'cancel_at_period_end' in form:
                    sub['cancel_at_period_end'] = form['cancel_at_period_end'] == 'true'
                store['subscriptions'][subId] = sub
                save_store(store)
            event = {'id': gen_id('evt'), 'type': 'customer.subscription.updated', 'data': {'object': sub}, 'created': int(time.time())}
            post_webhook(event)
            return self._send(200, sub)

        # POST /webhook/simulate?type=invoice.paid&tenant=xxx
        if path == '/webhook/simulate':
            qs = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(self.path).query))
            evtType = qs.get('type', 'invoice.paid')
            tenant = qs.get('tenant', '')
            amount = int(qs.get('amount', '9900'))  # EUR cents
            obj = {
                'id': gen_id('in'),
                'amount_paid' if evtType == 'invoice.paid' else 'amount_due': amount,
                'metadata': {'tenant_slug': tenant},
                'hosted_invoice_url': f'https://stripe.com/invoice/mock_{tenant}',
            }
            event = {'id': gen_id('evt'), 'type': evtType, 'data': {'object': obj}, 'created': int(time.time())}
            wcode, wbody = post_webhook(event)
            return self._send(200, {'ok': True, 'event': event, 'webhook_status': wcode, 'webhook_body': wbody[:200]})

        return self._send(404, {'error': 'not_found', 'path': path})

    def do_GET(self):
        path = urllib.parse.urlparse(self.path).path
        if path == '/health':
            return self._send(200, {'ok': True, 'service': 'ocre-stripe-mock', 'port': PORT})
        if path == '/mock/checkout':
            qs = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(self.path).query))
            sid = qs.get('session', '')
            html = f'<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;text-align:center"><h1>Stripe Mock Checkout</h1><p>Session: <code>{sid}</code></p><p>Cliquez pour simuler le paiement réussi :</p><form method="POST" action="/mock/checkout/complete?session={sid}"><button type="submit" style="background:#635bff;color:#fff;padding:12px 24px;border:0;border-radius:6px;font-size:16px;cursor:pointer">Payer 99 EUR HT</button></form></body></html>'
            self.send_response(200)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.end_headers()
            self.wfile.write(html.encode('utf-8'))
            return
        return self._send(404, {'error': 'not_found'})


def main():
    httpd = http.server.ThreadingHTTPServer(('127.0.0.1', PORT), Handler)
    sys.stdout.write(f'[stripe-mock] listening on http://127.0.0.1:{PORT}\n')
    sys.stdout.flush()
    httpd.serve_forever()


if __name__ == '__main__':
    main()
