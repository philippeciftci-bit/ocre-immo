#!/usr/bin/env python3
# M104 — Mock server channel manager v4.
# Simule LeBonCoin Pro Immo + Bien'ici via endpoints REST simples.
# Persiste les listings dans /tmp/ocre-channel-mock.json.
#
# Endpoints:
#   POST   /mock/<portal>/listings          → 201 + {id, status:'published', ...}
#                                             422 si title contient REFUSE_TEST
#   GET    /mock/<portal>/listings/<id>     → 200 + listing
#   PUT    /mock/<portal>/listings/<id>     → 200 + listing updated
#   DELETE /mock/<portal>/listings/<id>     → 204
#
# Usage: python3 channel_mock_server.py [port=8888]

import http.server
import json
import os
import re
import secrets
import sys
import threading
import time
from urllib.parse import urlparse

STORE_PATH = '/tmp/ocre-channel-mock.json'
LOCK = threading.Lock()


def load_store():
    if not os.path.exists(STORE_PATH):
        return {}
    try:
        with open(STORE_PATH, 'r') as f:
            return json.load(f)
    except Exception:
        return {}


def save_store(store):
    tmp = STORE_PATH + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(store, f, indent=2)
    os.replace(tmp, STORE_PATH)


class MockHandler(http.server.BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        sys.stdout.write('[mock] ' + (fmt % args) + '\n')
        sys.stdout.flush()

    def _send(self, status, body=None):
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.end_headers()
        if body is not None:
            self.wfile.write(json.dumps(body).encode('utf-8'))

    def _parse(self):
        # /mock/<portal>/listings or /mock/<portal>/listings/<id>
        path = urlparse(self.path).path.rstrip('/')
        m = re.match(r'^/mock/([a-z_]+)/listings(?:/([A-Za-z0-9_\-]+))?$', path)
        if not m:
            return None, None
        return m.group(1), m.group(2)

    def _read_body(self):
        length = int(self.headers.get('Content-Length', 0))
        if length <= 0:
            return {}
        raw = self.rfile.read(length)
        try:
            return json.loads(raw)
        except Exception:
            return {}

    def do_GET(self):
        portal, lid = self._parse()
        if portal is None:
            return self._send(404, {'error': 'not_found'})
        with LOCK:
            store = load_store()
            bucket = store.get(portal, {})
            if lid is None:
                return self._send(200, {'listings': list(bucket.values())})
            item = bucket.get(lid)
            if not item:
                return self._send(404, {'error': 'listing_not_found'})
            # Refresh views to show "live" feel
            item['views'] = item.get('views', 0) + 1
            store[portal] = bucket
            save_store(store)
            return self._send(200, item)

    def do_POST(self):
        portal, _ = self._parse()
        if portal is None:
            return self._send(404, {'error': 'not_found'})
        body = self._read_body()
        title = (body.get('title') or '')
        if 'REFUSE_TEST' in title.upper():
            return self._send(422, {'error': 'TEST refus simule (REFUSE_TEST dans titre)'})
        with LOCK:
            store = load_store()
            bucket = store.get(portal, {})
            lid = portal[:2].upper() + '_' + secrets.token_hex(6)
            now = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
            item = {
                'id': lid,
                'status': 'published',
                'created_at': now,
                'updated_at': now,
                'views': 0,
                'data': body,
            }
            bucket[lid] = item
            store[portal] = bucket
            save_store(store)
        return self._send(201, item)

    def do_PUT(self):
        portal, lid = self._parse()
        if portal is None or lid is None:
            return self._send(404, {'error': 'not_found'})
        body = self._read_body()
        with LOCK:
            store = load_store()
            bucket = store.get(portal, {})
            item = bucket.get(lid)
            if not item:
                return self._send(404, {'error': 'listing_not_found'})
            item['data'] = body
            item['updated_at'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
            bucket[lid] = item
            store[portal] = bucket
            save_store(store)
        return self._send(200, item)

    def do_DELETE(self):
        portal, lid = self._parse()
        if portal is None or lid is None:
            return self._send(404, {'error': 'not_found'})
        with LOCK:
            store = load_store()
            bucket = store.get(portal, {})
            if lid in bucket:
                del bucket[lid]
                store[portal] = bucket
                save_store(store)
        return self._send(204)


def main():
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8888
    addr = ('127.0.0.1', port)
    httpd = http.server.ThreadingHTTPServer(addr, MockHandler)
    sys.stdout.write(f'[mock] listening on http://127.0.0.1:{port}\n')
    sys.stdout.flush()
    httpd.serve_forever()


if __name__ == '__main__':
    main()
