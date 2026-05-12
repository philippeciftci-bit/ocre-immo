---
mission_id: M/2026/05/10/65
title: M_OCRE_MAGIC_LINK_DIAG — Cause racine perm /root/.secrets en HTTP
date: 2026-05-10
---

# M_OCRE_MAGIC_LINK_DIAG — Cause racine + fix

## TL;DR
`/root/.secrets/ovh-noreply-ocre.pwd` était `600 root:root` → www-ocre PHP-FPM **ne pouvait pas lire** en HTTP → `email_send` retournait FALSE silencieusement.

Tests CLI précédents (en root) renvoyaient TRUE → trompaient le diagnostic. **Toujours tester via HTTP, pas CLI.**

## Fix
```bash
chgrp ocre-secrets /root/.secrets/ovh-noreply-ocre.pwd
chmod 0640 /root/.secrets/ovh-noreply-ocre.pwd
```

www-ocre est membre du group `ocre-secrets` (gid 1000) → peut lire en HTTP.

## Preuve
`/var/log/ocre-magic-link.log` :
```
[2026-05-10T13:57:20] to=philippe.ciftci@gmail.com app=agent user_id=11 email_send=FALSE  (avant fix)
[2026-05-10T13:59:27] to=philippe.ciftci@gmail.com app=agent user_id=11 email_send=TRUE   (après fix)
```

## ZERO trace service externe confirmé
Backend auth.ocre.immo magic link 100% OVH SMTP exclusif (`stream_socket_client SSL ssl0.ovh.net:465`).
