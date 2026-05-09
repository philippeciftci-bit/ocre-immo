#!/usr/bin/env python3
"""M/2026/05/09/42 — M88 : helper push send via pywebpush.
Stdin : JSON {endpoint, p256dh, auth, payload, vapid_priv_pem, vapid_subject}
Stdout : JSON {ok, status_code, body?, err?}
"""
import sys, json, traceback

try:
    from pywebpush import webpush, WebPushException
except Exception as e:
    print(json.dumps({'ok': False, 'err': 'pywebpush_import_failed', 'detail': str(e)}))
    sys.exit(0)

try:
    raw = sys.stdin.read()
    inp = json.loads(raw)
    endpoint = inp['endpoint']
    p256dh = inp['p256dh']
    auth = inp['auth']
    payload = inp['payload']  # already JSON-encoded string
    vapid_priv_pem = inp['vapid_priv_pem']
    vapid_subject = inp.get('vapid_subject', 'mailto:support@ocre.immo')

    sub_info = {'endpoint': endpoint, 'keys': {'p256dh': p256dh, 'auth': auth}}
    resp = webpush(
        subscription_info=sub_info,
        data=payload,
        vapid_private_key=vapid_priv_pem,
        vapid_claims={'sub': vapid_subject},
        ttl=86400,
    )
    print(json.dumps({'ok': True, 'status_code': resp.status_code}))
except WebPushException as we:
    code = getattr(we.response, 'status_code', 0) if getattr(we, 'response', None) is not None else 0
    body = ''
    try:
        body = we.response.text if getattr(we, 'response', None) is not None else ''
    except Exception:
        pass
    print(json.dumps({'ok': False, 'err': 'webpush_failed', 'status_code': code, 'body': body[:500]}))
except Exception as e:
    print(json.dumps({'ok': False, 'err': 'exception', 'detail': str(e), 'trace': traceback.format_exc()[:500]}))
