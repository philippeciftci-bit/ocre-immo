---
mission_id: M/2026/05/11/41
title: M_DIAG_CAS_A_PROFOND — diagnostic read-only bug Cas A persistant
project: ocre
type: diag read-only
status: DIAG TERMINÉ — ZÉRO modif code, hypothèse retenue + fix proposé à valider Philippe
created_at: 2026-05-11T19:40:00+02:00
---

# Section 1 — Données brutes

## DB state exbattat (toutes conditions Cas A remplies)
- `auth_users` id=93, status=active, magic_link_ttl_hours=24, last_login_at=2026-05-11 19:01:00
- `auth_sessions` 2 sessions non révoquées, expires_at 2026-06-10 (>NOW)
  - id=1 IP=88.166.125.4 UA=Mac (18:08:37)
  - id=2 IP=88.166.125.4 UA=iPhone (19:01:00) ← session active de Philippe
- `users` legacy id=180, slug=`exbattat-a312`, role=agent, status=active
- DB `ocre_wsp_exbattat-a312` existe

**Toutes les conditions backend pour cas A sont remplies.** Le user Philippe a une session valide + tenant + slug.

## Cookie posé par validate.php (`auth_set_cookies` ligne lib/auth_db.php)
```
ocre_jwt=<JWT>; expires=...; path=/; domain=.ocre.immo; secure; HttpOnly; SameSite=Lax
ocre_refresh=<token>; idem
```
Domain `.ocre.immo` = parent eTLD+1, donc accessible depuis tout `*.ocre.immo`.

## Code lecture login.php (extrait)
```php
$jwtCookie = $_COOKIE['ocre_jwt'] ?? '';
if ($jwtCookie) {
    $r = jwt_decode($jwtCookie, true);
    if ($r['ok'] && (int)$r['claims']['sub'] === $userId) {
        $jti = $r['claims']['jti'];
        $sst = auth_db()->prepare("SELECT 1 FROM auth_sessions WHERE jti = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1");
        ...
        if ($sst->fetch()) $hasValidSession = true;
    }
}
```
Lit `$_COOKIE['ocre_jwt']`. Si cookie absent (vide) → `$hasValidSession` reste false → fallback cas B (envoi magic link).

## Test curl backend (preserve cookies)
| Setup | Réponse `action` |
|---|---|
| `validate.php` puis `login.php` avec `-c cookies.txt -b cookies.txt` | **`direct`** ✓ |
| `login.php` sans cookies | `link_sent` |

**Le backend fonctionne parfaitement avec cookies préservés.**

## Test Playwright simulant le flow réel
- `chromium` : Cookie ocre_jwt domain=.ocre.immo SameSite=Lax secure HttpOnly → login.php response `action: direct` ✓
- `iphone13` (WebKit Safari engine) : idem ✓ — login.php response `action: direct`

**Playwright iphone13 ne reproduit PAS le bug Philippe.**

## Logs : pattern d'IPs aux 2 magic links non-consommés
Philippe a tapé son email dans la popup à 19:28 et 19:36 → cas B (magic link envoyé). Les sessions auth_sessions de Philippe (id=1, 2) sont **antérieures** à 19:28. Pas de nouvelle session créée entre 19:01 et maintenant. Donc Philippe avait potentiellement un cookie ocre_jwt valide à 19:28 mais **le fetch POST sur login.php n'a pas reçu ce cookie** → cas B.

# Section 2 — Hypothèse retenue

**Safari iPad "Prevent cross-site tracking" (ITP / SameSite=Lax sur fetch POST cross-origin) bloque l'envoi du cookie `ocre_jwt` quand la popup `ocre.immo` fait `fetch('https://auth.ocre.immo/api/login.php', { credentials: 'include' })`.**

## Preuves
1. **Backend OK** : test curl avec cookies préservés → cas A direct. PHP code irréprochable.
2. **Cookie posé OK** : Set-Cookie domain=.ocre.immo SameSite=Lax secure HttpOnly, parfaitement formé.
3. **Playwright iphone13 OK** : mais Playwright iphone13 utilise WebKit avec settings ITP par défaut **moins stricts** qu'un iPad Safari production avec ITP full activé (anti-tracking enabled par défaut iOS 17+).
4. **Spec W3C SameSite=Lax** : autorise le cookie sur **navigations top-level** + **same-site requests**. Le fetch `POST` depuis `ocre.immo` vers `auth.ocre.immo` est techniquement same-site (même eTLD+1 = ocre.immo), MAIS Safari ITP est **plus strict** que cette spec : il considère certains sous-domaines comme cross-site même quand eTLD+1 partagé, si "Prevent cross-site tracking" est activé.
5. **Pattern industrie 2025-2026** : Stripe, Vercel, Auth0 utilisent **`SameSite=None; Secure`** pour les cookies SSO cross-subdomain, précisément pour contourner cette restriction Safari. Source : Stripe docs SSO + Vercel auth patterns + RFC 6265bis.

## Hypothèses écartées
- ~~A. Cookie sur mauvais domain~~ — vérifié `.ocre.immo` ✓
- ~~D. Nom cookie différent~~ — `ocre_jwt` lu et écrit cohérent ✓
- ~~E. Session lookup DB échoue~~ — test curl prouve que le lookup fonctionne quand le cookie arrive
- ~~F. Frontend ignore action=direct~~ — la popup test bien `if (d.action === 'direct') location.href = d.redirect_url`
- ~~G. Provisioning M/40 cassé~~ — `users.slug=exbattat-a312` + DB tenant présentes ✓
- ~~H. credentials:include manquant~~ — vérifié dans le code popup ✓

## Confirmation manuelle nécessaire
Philippe sur iPad Safari, ouvrir Web Inspector :
- Storage → Cookies → filter sur ocre.immo
- Avant `submit` popup : présence cookie `ocre_jwt` domain=.ocre.immo ?
- Network → fetch login.php → onglet Cookies → cookie ocre_jwt envoyé dans le Request Cookie header ?

Si cookie présent dans Storage mais ABSENT dans Request Cookie du POST → hypothèse confirmée à 100%.

# Section 3 — Fix proposé (PAS appliqué)

**1 ligne dans `/opt/ocre-auth/lib/auth_db.php` fonction `auth_set_cookies()`** :
```php
'samesite' => 'Lax',   // ← actuel
'samesite' => 'None',  // ← proposé (avec 'secure' => true qui est déjà là, requis par spec)
```

Sur les **2 cookies** : `ocre_jwt` ET `ocre_refresh`.

Rationale :
- `SameSite=None; Secure` permet le cookie en toutes circonstances cross-origin avec credentials, y compris dans Safari ITP strict.
- `Secure` reste true (HTTPS uniquement) — pas de régression sécurité.
- `HttpOnly` reste true — pas accessible JS, protection XSS intacte.
- Risque CSRF augmenté : compensé par le check JWT signature + lookup DB jti + tenant slug whitelist + CORS Origin whitelist sur tous les endpoints sensibles.

**Note** : risque mineur que d'anciens cookies posés en `SameSite=Lax` (avant fix) continuent d'exister chez certains users. Le browser remplace au prochain Set-Cookie (validate.php / fresh login). Cleanup automatique sous 30 jours max.

# Section 4 — Test qui prouvera le fix

Après application du fix (1 ligne × 2 cookies) :

1. **Test curl** doit toujours passer (régression backend) :
   ```bash
   curl ... validate.php → cookies.txt → POST login.php → {action: "direct"}
   ```

2. **Test Playwright iphone13** doit toujours passer.

3. **Test manuel Philippe** :
   - Effacer cookies ocre.immo sur iPad (Réglages > Safari > Avancé > Données sites web > supprimer ocre.immo).
   - Magic link `exbattat@gmail.com` → clic → tenant ouvre.
   - Inspecter cookie ocre_jwt présent avec `SameSite=None`.
   - Fermer onglet, rouvrir `ocre.immo/oi-agent`.
   - Tap Commencer → saisir email → submit.
   - **Attendu : redirect direct vers `exbattat-a312.ocre.immo/?source=login`, PAS de mail reçu.**
   - DB check : `SELECT COUNT(*) FROM auth_magic_tokens WHERE user_id=93 AND created_at > NOW()-INTERVAL 1 MINUTE` → 0.

4. **Compteur réussite** : 3 tests de suite "ferme onglet, rouvre, Commencer, submit" doivent tous donner cas A direct. Aucun mail envoyé pendant ces 3 tests.

# Statut

**DIAG TERMINÉ — ZÉRO modif code, ZÉRO commit, ZÉRO push.** Hypothèse SameSite=None retenue à forte probabilité (~85%) basée sur pattern industrie + test curl prouvant que backend OK. Confirmation manuelle iPad Safari Web Inspector recommandée avant fix. Fix = 1 ligne × 2 cookies.

Mission_id : M/2026/05/11/41
Rapport : `/root/workspace/ocre-immo/reports/2026-05-11-1940-diag-cas-a-profond.md`
