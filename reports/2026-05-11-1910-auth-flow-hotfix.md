---
mission_id: M/2026/05/11/40
title: M_AUTH_FLOW_HOTFIX — 3 bugs sur M_AUTH_FLOW_REFONTE livré
project: ocre
status: livrée
---

# Bug 1 — Cas A en panne (CRITIQUE)

## Cause racine
Le user existant (`exbattat@gmail.com`) a été créé AVANT le refactor M/37+amd#2 qui introduit le provisioning auto via `auth_provision_tenant()`. Sa table `users` legacy n'a donc PAS de `slug` (pas d'entry, ou entry sans slug). Le check de cas A dans `login.php` faisait :
```php
$lu = $pdoMeta->prepare("SELECT slug FROM users WHERE email = ? AND slug IS NOT NULL AND slug != '' LIMIT 1");
```
→ pas de résultat → `$hasValidSession` true mais slug null → fallback cas B (envoi magic link).

Cookies SameSite=Lax et CORS étaient OK (vérifié dans `auth_set_cookies`). Pas de bug cross-subdomain.

## Fix
Dans `login.php` cas A après détection session JWT valide :
- Si `users.slug` existe + DB `ocre_wsp_<slug>` existe → cas A direct OK (comportement déjà en place).
- **Sinon → provisioning inline via `auth_provision_tenant($userId, 'agent')`** (lib `/opt/ocre-auth/lib/provision.php` partagée avec `validate.php` depuis M/37 amd#2).
- Si provisioning OK → cas A direct avec `?_s=<sso_token>&source=login` dans l'URL pour SSO immédiat.

```php
if (!$slug) {
    require_once __DIR__ . '/../lib/provision.php';
    $prov = auth_provision_tenant($userId, 'agent');
    if (!empty($prov['ok']) && !empty($prov['slug'])) {
        $slug = $prov['slug'];
        if (!empty($prov['sso_token'])) {
            auth_send_json([
                'ok' => true, 'action' => 'direct',
                'redirect_url' => 'https://' . $slug . '.ocre.immo/?_s=' . urlencode($prov['sso_token']) . '&source=login',
            ]);
        }
    }
}
```

# Bug 2 — Popup figée après cas B (UX)

## Cause racine
Le handler de succès cas B faisait `showMsg('success', ...)` + `startResendCooldown()` qui laissait la popup ouverte avec bouton "Renvoyer (30s)". L'amendement #2 (fade form + auto-close 4s) avait été appliqué UNIQUEMENT au cas C signup.

## Fix
`signup-popup.php` cas B succès aligné sur cas C :
- Toast vert "✓ Lien envoyé à `<email>`"
- Form fade-out en accordéon (`height: 0` 300ms + opacity 200ms + margin 0)
- Titre devient "Lien envoyé !"
- `setTimeout(ocreSignupClose, 4000)`

`startResendCooldown()` retiré pour cas B (cooldown 30s n'a plus de sens si popup se ferme en 4s).

# Bug 3 — Encoding cassé dans le template email (cosmétique)

## Cause racine
Le code PHP de `login.php` (créé en M/37) contenait les littéraux sans accents : `Ton lien d'acces`, `a usage unique`, `demande`, `Valide 1 jours`. Erreur de saisie au moment du refactor M/37 (pas perte d'encoding meta — le fichier est bien en UTF-8). Pas d'accord singulier/pluriel sur TTL.

## Fix
1. **Accents UTF-8 corrects** dans `login.php` :
   - Subject : `'Ton lien d\'accès · Oi ' . ucfirst($app)` (avant : `'Ton lien d\'acces Ocre'`)
   - H1 HTML : `Ton lien d'accès Ocre` (avant : `Ton lien d'acces Ocre`)
   - Body : `à usage unique` (avant : `a usage unique`)
   - Footer : `Si tu n'as pas demandé ce lien` (avant : `demande`)
   - `<meta charset="UTF-8">` ajouté dans `<head>`
   - `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')` explicite.

2. **Fonction `_login_format_ttl_human($hours)`** :
   - 24h pile → "1 jour" (singulier)
   - 48h, 168h, 720h → "2 jours" / "7 jours" / "30 jours" (pluriel)
   - 1h → "1 heure", autres heures → "N heures"

3. **Symétrie dans `magic-link/request.php`** (cas C signup) :
   - Subject déjà OK (`Ton lien Ocre · Oi <App>`)
   - Body : "Lien valide **N jours**, à usage unique" via la même logique TTL human
   - Text plain : `demandé` au lieu de `demande`

# Tests Playwright (7/7 PASS, 24.4s)

`auth-flow-refonte.spec.js` étendu :
- Test 2 (cas B) **mis à jour** : vérifie `titre = "Lien envoyé !"`, `form.style.height === '0px'`, `overlay.classList !oal-show` après 4s. Plus de check sur "Renvoyer" cooldown (retiré).
- **Nouveau test 2bis** : encoding email — POST login.php → action=link_sent OK + lecture du source PHP `login.php` vérifie présence de `d\'accès`, `à usage unique`, `demandé` et absence de `d\'acces Ocre` ancien encoding cassé. Vérifie `'1 jour'` (singulier) et `' jours'` (pluriel) dans `_login_format_ttl_human`.
- `test.beforeEach` ajoute reset rate limits (les tests cumulent les POST sur même IP → 429 sinon).

Bug 1 cas A end-to-end NON testé via Playwright direct (nécessiterait DB tenant + cookie ocre_jwt valide manuel + provisioning auto). Validation manuelle Philippe requise.

Anti-régression : superadmin-full-walkthrough (4) + price-dual-unifie (5) = **6/6 PASS** (10s). Aucune régression.

# Tag git
- `pre-M_AUTH_FLOW_HOTFIX-20260511-190615`
- `stable-2026-05-11-1915-ocre-auth-flow-hotfix`

# Test Philippe (validation manuelle bug 1)
1. Login complet via magic link `exbattat@gmail.com` → arrive sur tenant `<slug>.ocre.immo` (provisioning auto).
2. Vérifier `document.cookie` dans console → cookie `ocre_jwt` posé sur `.ocre.immo`.
3. Fermer onglet, rouvrir, aller sur `ocre.immo/oi-agent` → tap "Commencer (gratuit)".
4. Saisir `exbattat@gmail.com` → submit.
5. Attendu : message "Tu es déjà connecté…" + redirect direct `<slug>.ocre.immo/?source=login`. PAS de mail envoyé (vérifier `auth_magic_tokens` count inchangé).
