# Matrice permissions par rôle (M114 + M114b)

Système : 4 rôles ENUM `auth_team_members.role` ('owner','manager','collaborator','viewer').

Helper PHP : `api/lib/permissions.php` avec `requireRole($allowed, $user)` + `canEditDossier($user, $dossier)`.

## Matrice complète (M114b documentation)

| Endpoint / Action | Owner | Manager | Collaborator | Viewer |
|---|:-:|:-:|:-:|:-:|
| **Dossiers (clients.php)** | | | | |
| GET list dossiers | ✅ | ✅ | ✅ ses propres + équipe en read | ✅ read-only |
| POST create dossier | ✅ | ✅ | ✅ (created_by=user_id) | ❌ |
| PUT update dossier | ✅ | ✅ | ✅ ses propres uniquement (canEditDossier) | ❌ |
| DELETE dossier | ✅ | ✅ | ❌ | ❌ |
| **Pacts** (futur M120+) | | | | |
| POST create pact | ✅ | ✅ | ❌ | ❌ |
| DELETE pact | ✅ | ❌ | ❌ | ❌ |
| **Photos / Documents** | | | | |
| POST photo_upload sur dossier X | si Edit X | si Edit X | ses propres dossiers | ❌ |
| **Team (M114)** | | | | |
| POST team/invite | ✅ | ✅ | ❌ | ❌ |
| GET team/list | ✅ | ✅ | ✅ | ✅ |
| POST team/change-role | ✅ | ❌ | ❌ | ❌ |
| POST team/remove | ✅ | ❌ | ❌ | ❌ |
| **Billing (M107)** | | | | |
| GET billing/status | ✅ | ✅ | ❌ | ❌ |
| POST billing/subscribe | ✅ | ❌ | ❌ | ❌ |
| POST billing/cancel | ✅ | ❌ | ❌ | ❌ |
| POST billing/resume | ✅ | ❌ | ❌ | ❌ |
| **Channel Manager (M104)** | | | | |
| GET channel/portals | ✅ | ✅ | ✅ | ✅ |
| GET channel/status | ✅ | ✅ | ✅ | ✅ |
| POST channel/publish | ✅ | ✅ | ✅ ses propres dossiers | ❌ |
| POST channel/unpublish | ✅ | ✅ | ✅ ses propres dossiers | ❌ |
| POST channel/sync | ✅ | ✅ | ✅ ses propres dossiers | ❌ |
| **Webhooks (M116)** | | | | |
| GET webhooks/list | ✅ | ✅ | ❌ | ❌ |
| POST webhooks/create | ✅ | ✅ | ❌ | ❌ |
| POST webhooks/update | ✅ | ✅ | ❌ | ❌ |
| POST webhooks/delete | ✅ | ✅ | ❌ | ❌ |
| POST webhooks/test | ✅ | ✅ | ❌ | ❌ |
| **Dashboard (M112)** | | | | |
| GET dashboard/agent | ✅ | ✅ vue tenant complet | ✅ filtré ses propres | ✅ read-only |
| **Calendar (M118)** | | | | |
| GET calendar/export.ics | ✅ | ✅ | ✅ | ❌ |
| POST calendar/token | ✅ | ✅ | ✅ | ❌ |
| GET calendar/feed?token | public (token signed) | public | public | public |
| POST calendar/google/oauth/init | ✅ | ✅ | ✅ | ❌ |
| **Export (M111)** | | | | |
| POST dossiers/export | ✅ | ✅ | ✅ ses propres dossiers | ✅ read-only |
| **i18n (M113)** | | | | |
| POST i18n/set_lang | tous | tous | tous | tous |
| GET i18n/get_strings | public | public | public | public |

## Implémentation par phase

### M114b — Phase B+C livré (helper + documentation)

✅ Helper `api/lib/permissions.php` créé avec `requireRole` + `canEditDossier` + `getMyRole`  
✅ Matrice complète documentée dans ce fichier (PERMISSIONS_MATRIX.md)  

### REPORTÉS en M114c (chantier risqué cross-cutting)

⚠ **Phase D : application requireRole() dans tous les endpoints existants**
- Application sur endpoints M104+ (channel, billing, webhooks, team, calendar, dashboard, export, i18n) : peu de risque car endpoints récents et bien isolés
- Application sur `clients.php` (700+ lignes monolithique) : **risque régression élevé** car endpoint historique cross-actions create/update/delete/archive/staged. Nécessite tests régression complets sur SPA M77-M82 Section III adaptive
- Estimation : 4-5h avec tests Playwright régression

⚠ **Phase E : UI masquage boutons selon rôle**
- SPA monolithique 23857 lignes → identifier tous les boutons + ajouter check `if (myRole === 'owner') {...}`
- Bandeau "Connecté en tant que [Rôle]" pour non-owner (sous bandeau SSO M99 si présent)
- Pages standalones M104+ : pattern data-role-required="owner" sur boutons sensibles, css `[data-role-required]:not([data-my-role-allowed]) { display:none }`
- Estimation : 3-4h

⚠ **Phase F : Tests E2E par rôle**
- Login owner/manager/collaborator/viewer en parallèle (4 tenants tests ou 4 users sur même tenant)
- Tests Playwright matricerl par endpoint × rôle
- Estimation : 3-4h

## Plan d'application incrémentale

Pour limiter risque régression, application en sub-missions ciblées :
- **M114c** : application requireRole sur endpoints M104+ (low risk) + UI masquage pages standalones (low risk)
- **M114d** : intégration dans clients.php avec tests régression Section III complets
- **M114e** : tests E2E 4 rôles + bandeau rôle SPA

## Helper code reference

```php
// Usage dans un endpoint
require_once __DIR__ . '/../lib/permissions.php';
$user = getCurrentUserDualMode();
if (!$user) jsonError('Non authentifie', 401);
requireRole(['owner', 'manager'], $user);
// ... endpoint logic ...

// Pour permissions par-dossier
$dossier = fetch_dossier($id);
if (!canEditDossier($user, $dossier)) jsonError('forbidden', 403);
```
