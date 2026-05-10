---
mission_id: M/2026/05/10/61
title: M_OAUTH_GOOGLE_CREDENTIALS_AUTO — Configuration credentials Google OAuth réels
date: 2026-05-10
---

# M_OAUTH_GOOGLE_CREDENTIALS_AUTO — Plan d'activation OAuth Google réel

## TL;DR

**Endpoints déjà compatibles** (livrés M_OCRE_AGENT_SIGNUP_V1) — détection auto :
- Si `/root/.secrets/google-oauth.env` présent → **mode PROD** (vrai OAuth Google)
- Sinon → **mode MOCK** (consent fake livré M_OAUTH_DIAGNOSTIC_FIX)

**Action Philippe** = 5 minutes manuelles dans Google Cloud Console + 1 commande SSH.

## Pourquoi pas 100% automatisé

L'API Google Cloud **n'expose pas** la création de "OAuth client ID" type Web application via REST/CLI. C'est volontaire chez Google pour des raisons de sécurité (validation OAuth consent screen + ownership domaine). Tous les projets prod (Stripe, Notion, etc.) passent par la console UI une fois.

`gcloud projects create` automatise le projet, mais pas les credentials OAuth.

## Étapes manuelles Philippe (~5 min)

### 1. Créer projet Google Cloud
URL : https://console.cloud.google.com/projectcreate
- **Nom** : `Ocre Immo Auth`
- **ID** : `ocre-immo-auth` (ou auto-généré)
- Cliquer **Créer**

### 2. Configurer OAuth consent screen
URL : https://console.cloud.google.com/apis/credentials/consent
- **User Type** : External
- **App name** : `Ocre Immo`
- **Support email** : `philippe.ciftci@gmail.com`
- **Developer contact** : `philippe.ciftci@gmail.com`
- **Save and Continue** × 3 (Scopes skip / Test users skip / Summary back)

### 3. Créer OAuth client ID
URL : https://console.cloud.google.com/apis/credentials
- **+ CREATE CREDENTIALS** → **OAuth client ID**
- **Type** : Web application
- **Name** : `Ocre Web`
- **Authorized JavaScript origins** :
  - `https://ocre.immo`
  - `https://auth.ocre.immo`
- **Authorized redirect URIs** :
  - `https://auth.ocre.immo/api/oauth/google/callback.php`
- **Create**

### 4. Copier Client ID + Client Secret affichés (popup modal)

### 5. SSH VPS et finaliser
```bash
/root/bin/finalize-google-oauth.sh "<CLIENT_ID>" "<CLIENT_SECRET>"
```

Le script :
- Écrit `/root/.secrets/google-oauth.env` mode 600 root:root
- Test que `init.php` redirige vers `accounts.google.com` (mode PROD activé)
- Affiche confirmation

## Endpoints déjà compatibles (rappel)

`/opt/ocre-auth/api/oauth/google/init.php` :
- Lit `/root/.secrets/google-oauth.env` via `oauth_load_env('google')`
- Si `GOOGLE_CLIENT_ID` présent → redirect `accounts.google.com/o/oauth2/v2/auth?client_id=...&redirect_uri=...&scope=openid+email+profile&response_type=code`
- Si absent → fallback mock consent page

`/opt/ocre-auth/api/oauth/google/callback.php` :
- Si mode PROD : POST `oauth2.googleapis.com/token` exchange code → access_token, GET `googleapis.com/oauth2/v3/userinfo` Bearer → email/given_name/family_name/sub
- Si mock : lit email/first_name/last_name depuis $_GET (consent picker M_OAUTH_MOCK_ACCOUNT_PICKER)
- Dans tous cas : `oauth_upsert_user` + `oauth_complete_login` (JWT 30j HS256 + cookies + redirect ocre.immo?login=success)

## Plan Apple (reporté M_OAUTH_APPLE_REAL)

- **Coût** : Apple Developer Program **99 €/an** (carte bancaire requise).
- **Étapes** :
  1. https://developer.apple.com/account/resources/identifiers/list/serviceId → créer Service ID `com.ocre.immo.web`
  2. App ID associé `com.ocre.immo.app` + Sign In with Apple capability
  3. Générer Sign In with Apple Key (`.p8` file) sur https://developer.apple.com/account/resources/authkeys/list
  4. Récupérer : Team ID + Key ID + Service ID + .p8 contents
  5. `/root/.secrets/apple-oauth.env` :
     ```
     APPLE_TEAM_ID=XXXXXXXXXX
     APPLE_KEY_ID=XXXXXXXXXX
     APPLE_SERVICE_ID=com.ocre.immo.web
     APPLE_PRIVATE_KEY_PATH=/root/.secrets/apple-AuthKey.p8
     ```
  6. **Implémenter génération JWT ES256 client_secret signé p8** (lib `firebase/php-jwt` ou implémentation manuelle openssl_sign EC).
  7. Modifier `/opt/ocre-auth/api/oauth/apple/callback.php` pour parser `id_token` (JWT signé Apple) + extraire email + sub.

**Effort total** : ~2-3h (JWT ES256 + tests) + 99 €/an Philippe. Reporté tant que pas de demande explicite agent immo.

## Plan Facebook (reporté M_OAUTH_FACEBOOK_REAL)

- **Coût** : gratuit.
- **Étapes** (~10 min) :
  1. https://developers.facebook.com/apps/create → app type "Consumer"
  2. App name : `Ocre Immo`
  3. Add Product → **Facebook Login** → Web platform
  4. Settings → Valid OAuth Redirect URIs : `https://auth.ocre.immo/api/oauth/facebook/callback.php`
  5. Copier App ID + App Secret
  6. `/root/.secrets/facebook-oauth.env` :
     ```
     FB_APP_ID=xxxxxxxxxxxxxx
     FB_APP_SECRET=xxxxxxxxxxxxxx
     ```
  7. Endpoints `init.php` + `callback.php` déjà compatibles (M_OCRE_AGENT_SIGNUP_V1).

**Effort total** : ~10 min Philippe. Pas de coût. Reportable mais facile à activer rapidement quand demandé.

## Magic link email

Déjà 100 % fonctionnel en prod (OVH SMTP `ssl0.ovh.net:465` AUTH LOGIN, validé E2E
M_OAUTH_DIAGNOSTIC_FIX-2). Aucune action Philippe requise.

## Critère de réussite atteint

✅ Endpoints Google OAuth code production-ready avec fallback mock automatique.
✅ Script `/root/bin/finalize-google-oauth.sh` activation 1 commande.
✅ Guide Cloud Console pas-à-pas (5 min Philippe).
✅ Plan Apple + Facebook documenté avec coûts + étapes précises.
