# M118 — Architecture SSO Ocre — Analyse + décision

**Mission_id** : M/2026/05/08/25 (analyse). Décision Philippe attendue avant implémentation.

> Source ROADMAP : "Architecture SSO Ocre (auth partagé multi-modules) — source OI_VISION.md ligne 50". `OI_VISION.md` n'existe pas dans le repo : analyse construite depuis l'inspection du code existant + intent ROADMAP.

## 1. État actuel (audit)

**Auth** : multi-tenant V20 (`api/auth_v20.php` 368 lignes, `api/auth.php` redirige). Magic-link via `api/auth_magic.php`.

**Token** : `localStorage['ocre_token']` (256 hex chars). Envoyé en header `X-Session-Token` sur chaque requête API.

**Modules / sous-domaines** :
- `app.ocre.immo` (index.html — agents app principale, React 18 inline JSX)
- `signup.ocre.immo` (signup.html, inscription/)
- `admin.ocre.immo`, `superadmin/`
- `activation/` (post-signup activation flow)
- `vitrine.ocre.immo` (site marketing)
- `agent.ocre.immo` (à créer, M117 — Rebrand UI Oi Agent)
- `export-template/` (viewer PDF, statique)

**Pain point identifié** : pas de SSO. Si un user clique un lien vers `agent.ocre.immo` depuis `app.ocre.immo`, il doit re-login (localStorage est par origine).

## 2. Options évaluées

### A. Cookie partagé `.ocre.immo` (RECOMMANDÉE)
- Token écrit en cookie `Domain=.ocre.immo; Secure; HttpOnly; SameSite=Lax; Path=/`.
- Fallback lecture localStorage pour back-compat 4 semaines.
- Backend : `auth.php` lit cookie OU header (transition douce).
- **Coût** : ~2h. Modif minimale, no infra change.
- **Risque** : HttpOnly empêche le JS frontend de lire le token → migration progressive (cookie pour transport, sessionId court en localStorage pour UX) OU supprimer toutes les lectures direct du token côté JS (déjà très limité au boot login).

### B. JWT centralisé via `auth.ocre.immo` IdP
- Service IdP dédié, autres modules verifient JWT signé.
- **Coût** : 1-2 sprints. Refonte backend, rotation de clés, refresh tokens.
- **Quand l'envisager** : si on intègre un module externe (white-label B2B, mobile native).
- **Risque** : sur-ingénierie pour besoin actuel.

### C. Reverse-proxy `auth_request` nginx
- nginx valide le token au edge avant de proxypass.
- **Coût** : 1-2j. Modif nginx + endpoint `/api/auth_check.php`.
- **Avantage** : auth devient infrastructure, modules ne s'en préoccupent plus.
- **Risque** : ajoute une dépendance opérationnelle ; debug plus complexe.

## 3. Recommandation

**Option A** (cookie partagé) pour M118.1 immédiate. Évolution vers B uniquement si requis par roadmap commerciale future (white-label).

## 4. Découpage sous-tâches

- **M118.1** — Cookie `.ocre.immo` (Set-Cookie sur login, lecture côté backend en plus du header). Effort 1h30. Prereqs : aucun.
- **M118.2** — Frontend : 4 endroits où le token est écrit/lu (`localStorage.setItem('ocre_token', ...)`) — wrapper `setOcreToken()` qui écrit cookie + localStorage (transition). Effort 30min. Prereqs : M118.1.
- **M118.3** — Test E2E cross-subdomain : login `app.ocre.immo` → navigation `agent.ocre.immo` (à créer M117) → pas de re-login. Effort 30min. Prereqs : M118.1 + M118.2 + M117 livrée.
- **M118.4** — Logout cascade : Set-Cookie `Max-Age=0` sur `.ocre.immo`. Effort 15min. Prereqs : M118.1.
- **M118.5** — Cleanup transition 4 semaines après M118.1 livrée : retirer fallback localStorage. Effort 15min. Prereqs : 4 semaines après M118.1.

## 5. Bloqueurs / questions Philippe

1. **M117 prerequis réel ?** : SSO peut être livré sans rebrand UI (cookie = backend infra, indépendant du nom du produit affiché). On peut commencer M118.1+M118.2 dès maintenant. M118.3 nécessite M117 (vhost agent.ocre.immo créé).
2. **HttpOnly oui/non ?** : si oui, l'app frontend ne peut plus lire le token. Acceptable si tous les fetch passent les credentials cookie automatiquement. À vérifier sur les listeners SW + uploads multipart.
3. **Activation/inscription flows** : ces vhosts produisent un token ; doivent-ils le mettre en cookie partagé dès l'activation pour que le redirect post-activation soit déjà SSO ? OUI recommandé.
4. **Multi-tenant** : un user qui change de tenant (impersonation, multi-comptes) doit invalider l'ancien cookie. Logique déjà couverte par auth_v20 ; le cookie suit le token.

## 6. Décision attendue

Valider :
- (a) Option A cookie `.ocre.immo` ✓ / ✗
- (b) Découper M118 en M118.1..M118.5 dans ROADMAP ✓ / ✗
- (c) Lancer M118.1 + M118.2 sans attendre M117 ✓ / ✗ (cookie indépendant du rebrand)

Si tout vert → mission technique M118.1 dispatchable via autopilot dès la prochaine validation.
