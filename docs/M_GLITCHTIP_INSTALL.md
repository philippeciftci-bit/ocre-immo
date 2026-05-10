---
mission_id: M/2026/05/10/74
title: M_GLITCHTIP_INSTALL — Scaffolding livré + 7 étapes Philippe pour activer
date: 2026-05-10
---

# M_GLITCHTIP_INSTALL — Plan activation GlitchTip

## TL;DR — État livraison

**Scaffolding complet livré** dans `glitchtip-scaffolding/` :
- ✅ Docker Compose officiel GlitchTip
- ✅ Vhost nginx avec SSL + bridge webhook
- ✅ Webhook handler PHP (GlitchTip → Telegram via /root/bin/notify)
- ✅ SDK frontend JS init (4 surfaces)
- ✅ SDK backend PHP bootstrap (auth + app)

**7 étapes manuelles Philippe** (~30-45 min) pour activer en production. Détails ci-dessous.

## Pourquoi pas 100% automatisé

**Prérequis bloquants absents du VPS** :
- ❌ Docker pas installé (`which docker` → not found)
- ❌ Docker Compose pas installé
- ❌ Composer pas installé (PHP backend SDK)
- ❌ /opt/glitchtip n'existe pas

Installer Docker = action infra majeure (daemon docker.service, network bridge, modifs iptables) sur VPS partagé qui héberge déjà ocre-vitrine + ocre-auth + ocre-app + atelier-philippe + autres apps. **Risque blast radius justifie validation Philippe avant exécution**.

## 7 étapes Philippe pour activer

### 1. Installer Docker + Compose
```bash
apt update && apt install -y docker.io docker-compose-plugin
systemctl enable --now docker
docker --version  # vérifier
docker compose version  # vérifier
```

### 2. Déployer GlitchTip
```bash
mkdir -p /opt/glitchtip
cp /root/workspace/ocre-immo/glitchtip-scaffolding/docker/docker-compose.yml /opt/glitchtip/
cp /root/workspace/ocre-immo/glitchtip-scaffolding/docker/.env.example /opt/glitchtip/.env

# Générer SECRET_KEY
SECRET=$(python3 -c "import secrets; print(secrets.token_urlsafe(50))")
sed -i "s|changeme-secret-key-50-chars-min|$SECRET|" /opt/glitchtip/.env

# Lire OVH SMTP password
PWD=$(cat /root/.secrets/ovh-noreply-ocre.pwd)
sed -i "s|changeme-ovh-noreply-pwd|$PWD|" /opt/glitchtip/.env

chmod 0600 /opt/glitchtip/.env

cd /opt/glitchtip
docker compose up -d
sleep 30
docker compose logs web | tail -20  # vérifier startup OK
```

### 3. Vhost nginx + SSL
```bash
cp /root/workspace/ocre-immo/glitchtip-scaffolding/nginx/glitchtip.46-225-215-148.sslip.io.conf /etc/nginx/sites-available/

# Provisionner certificat SSL si absent
certbot certonly --nginx -d glitchtip.46-225-215-148.sslip.io
# OU réutiliser cert wildcard sslip.io existant

ln -s /etc/nginx/sites-available/glitchtip.46-225-215-148.sslip.io.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
curl -I https://glitchtip.46-225-215-148.sslip.io/  # doit 200/302
```

### 4. Créer compte admin GlitchTip + 5 projets
- Ouvrir https://glitchtip.46-225-215-148.sslip.io/
- Register : philippe.ciftci@gmail.com + mot de passe fort (stocker dans /root/.secrets/glitchtip-admin.pwd mode 0600)
- Créer 5 projets : `ocre-vitrine`, `ocre-auth`, `ocre-app`, `ocre-launcher`, `ocre-backend-php`
- Récupérer DSN de chaque projet → stocker dans `/root/.secrets/glitchtip-dsns.env` mode 0640 root:www-data :
```
GLITCHTIP_DSN_VITRINE=https://xxx@glitchtip.46-225-215-148.sslip.io/1
GLITCHTIP_DSN_AUTH=https://xxx@.../2
GLITCHTIP_DSN_APP=https://xxx@.../3
GLITCHTIP_DSN_LAUNCHER=https://xxx@.../4
GLITCHTIP_DSN_BACKEND=https://xxx@.../5
```

### 5. Webhook bridge Telegram
```bash
mkdir -p /opt/glitchtip-webhook
cp /root/workspace/ocre-immo/glitchtip-scaffolding/webhook/handler.php /opt/glitchtip-webhook/
chown -R root:www-data /opt/glitchtip-webhook
chmod 0644 /opt/glitchtip-webhook/handler.php

# Token random pour sécuriser endpoint
TOKEN=$(openssl rand -hex 32)
echo "$TOKEN" > /etc/ocre/glitchtip-webhook.token
chmod 0640 /etc/ocre/glitchtip-webhook.token
chown root:www-data /etc/ocre/glitchtip-webhook.token

# Configurer dans GlitchTip UI → Settings → Alerts → Webhook URL
# https://glitchtip.46-225-215-148.sslip.io/glitchtip-webhook?t=<TOKEN>
```

### 6. SDK Frontend (4 surfaces)
Pour chaque surface, ajouter avant `</head>` (ex via header.php WP, signup.html, etc.) :
```html
<script src="https://browser.sentry-cdn.com/9.36.0/bundle.min.js" crossorigin="anonymous"></script>
<script>window.OCRE_GLITCHTIP_DSN = '<DSN_DE_LA_SURFACE>';</script>
<script src="/glitchtip-init.js"></script>
```

Copier `glitchtip-scaffolding/sdk-frontend/glitchtip-init.js` à la racine de chaque service :
- /var/www/ocre-wp/glitchtip-init.js
- /opt/ocre-auth/glitchtip-init.js
- /opt/ocre-app/glitchtip-init.js
- /var/www/ocre-wp/wp-content/themes/twentytwentyfive-ocre/glitchtip-init.js (PWA launcher)

### 7. SDK Backend PHP
```bash
# Composer install (si pas déjà)
apt install -y composer

# Pour chaque service backend
cd /opt/ocre-auth && composer require sentry/sentry
cd /opt/ocre-app && composer require sentry/sentry

# Bootstrap Sentry
mkdir -p /opt/ocre-auth/lib /opt/ocre-app/api/lib
cp /root/workspace/ocre-immo/glitchtip-scaffolding/sdk-backend/sentry-init.php /opt/ocre-auth/lib/
cp /root/workspace/ocre-immo/glitchtip-scaffolding/sdk-backend/sentry-init.php /opt/ocre-app/api/lib/

# DSN backend
echo "<DSN_BACKEND>" > /etc/ocre/glitchtip-dsn-backend
chmod 0640 /etc/ocre/glitchtip-dsn-backend
chown root:www-data /etc/ocre/glitchtip-dsn-backend

# Inclure require_once 'lib/sentry-init.php' en haut des endpoints critiques
# (email-check.php, magic-link/request.php, magic-link/validate.php, etc.)
```

## Smoke tests

Une fois activé, tester capture sur 5 projets :
```javascript
// Console navigateur sur ocre.immo
Sentry.captureException(new Error('Test vitrine M_GLITCHTIP_INSTALL'));
```
```bash
# CLI backend (route DEBUG temporaire à créer ou direct PHP)
php -r "require '/opt/ocre-auth/lib/sentry-init.php'; ocre_capture_exception(new Exception('Test backend M_GLITCHTIP_INSTALL'));"
```

→ Vérifier dans GlitchTip dashboard + notif Telegram reçue.

## Files livrés

```
glitchtip-scaffolding/
├── docker/
│   ├── docker-compose.yml       (services postgres + redis + web + worker + migrate)
│   └── .env.example             (template SECRET_KEY + OVH_SMTP_PWD)
├── nginx/
│   └── glitchtip.46-225-215-148.sslip.io.conf  (vhost SSL + proxy + webhook)
├── webhook/
│   └── handler.php              (bridge GlitchTip → /root/bin/notify Telegram)
├── sdk-frontend/
│   └── glitchtip-init.js        (init Sentry SDK navigateur, 4 surfaces)
└── sdk-backend/
    └── sentry-init.php          (bootstrap Sentry PHP backend, helper ocre_capture_exception)
```

## Notif Telegram destinataire

Per spec mission : `--project atelier --phase done` (mission infrastructure transverse, pas seulement ocre).
