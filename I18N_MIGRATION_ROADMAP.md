# i18n Migration Roadmap — SPA Oi Agent (M113c)

## État actuel

### Infrastructure i18n (livrée M113 + M113b)

✅ 4 fichiers traduction `i18n/{fr,en,es,ar}.json` avec **130 keys × 4 langues = 520 traductions**  
✅ Helper backend `/api/i18n/get_strings.php?lang=X` + `/api/i18n/set_lang.php`  
✅ Helper client `i18n/i18n_client.js` :
- Charge JSON async + fallback FR
- `window.t(key, params)` global
- Auto-translate déclaratif `data-i18n` / `data-i18n-placeholder` / `data-i18n-title` / `data-i18n-aria-label`
- `applyDirection()` RTL automatique pour AR
- `window.i18n.setLang(lang)` persiste cookie + DB + reload

✅ UI page standalone `/reglages-langue.html` : 4 cards FR/EN/ES/AR avec drapeaux + RTL toggle  
✅ Cookie `ocre_lang` cross-subdomain `Domain=.ocre.immo` 1 an  
✅ Persistence DB `users.lang` ALTER TABLE idempotent  

### Coverage SPA monolithique

`/opt/ocre-app/index.html` contient **23857 lignes** avec :
- **73 placeholders** hardcodés (`placeholder="..."`)
- **27 aria-label** hardcodés
- **93 title attributes** hardcodés
- **~400 JSX text content** estimés (`>Texte UI</tag>`)
- **showToast / alert / confirm** : 18 messages utilisateur

**Total estimé : ~640+ strings UI hardcodées dans le SPA**.

## Pourquoi M113c-en-1-session est irréaliste

Migration manuelle de 640+ strings nécessite :
1. **Identifier** chaque string + son contexte (label/button/error/etc.)
2. **Choisir** une key hiérarchique cohérente (`header.dossiers`, `modal.confirm.delete`, `section3.acheteur.budget_min`)
3. **Remplacer** dans le SPA avec `{t('key')}` (JSX) ou `t('key')` (string)
4. **Ajouter** la key dans les 4 fichiers JSON
5. **Traduire** EN + ES + AR de qualité (pas Google Translate brut)
6. **Tester** non-régression (Section III adaptive M77→M82 : 88 combinaisons profil×pays + drapeaux _isMA/_isFR/etc)

**Effort réaliste** : 50 strings/heure migration + tests = **12-15h chantier dédié** pour 640 strings.

**Risque** : modification sur 24k lignes monolithique avec composants Section III adaptatifs critiques. 1 erreur de regex peut casser 88 combinaisons.

## Stratégie recommandée : migration par chunks de 50 strings/session

### Plan en 13 sub-missions ciblées

| Sub-mission | Zone | Strings est. | Effort |
|---|---|---|---|
| M113d-1 | Header + Nav + Bottombar (TabBar + boutons globaux) | ~50 | 1h |
| M113d-2 | Liste dossiers (filtres + sort + actions ligne) | ~50 | 1h |
| M113d-3 | Détail dossier — Stage I (contact) | ~50 | 1h |
| M113d-4 | Détail dossier — Stage II (résumé bien) | ~50 | 1h |
| M113d-5 | Détail dossier — Stage III (financier hors Section III adaptive) | ~50 | 1h |
| M113d-6 | Détail dossier — Stage IV (notes + tags) | ~30 | 0.5h |
| M113d-7 | Détail dossier — Stage V (photos + docs) | ~50 | 1h |
| M113d-8 | Modals confirmation (delete / archive / sell / rent) | ~30 | 0.5h |
| M113d-9 | ReglagesPage (4 sections + sub-screens) | ~80 | 1.5h |
| M113d-10 | HelpPage refondu (FAQ + contact) | ~40 | 1h |
| M113d-11 | Pacts + Matchings + Propositions | ~60 | 1.5h |
| M113d-12 | Erreurs validation + showToast | ~30 | 1h |
| M113d-13 | Aria-labels + titles tooltips | ~120 | 2h |

**Total** : ~640 strings sur 13 sessions = **~14h cumulées** mais découpé en chunks low-risk avec tests régression à chaque étape.

## Recommandations pour Philippe

1. **Valider la stratégie de découpe** avant lancement M113d-1
2. **Prioriser** les zones les plus visibles (Header / Filtres / ReglagesPage = 70% de l'UI vue)
3. **Tester** chaque sub-mission avec Playwright sur 3 langues minimum (FR baseline + EN + AR pour RTL)
4. **Conserver le fallback FR** : helper `t()` retourne automatiquement la version FR si key manquante dans EN/ES/AR (évite les KEY_NOT_FOUND visibles)

## Ce qui est livré M113c (limitation pragmatique)

✅ Inventaire complet documenté (73 placeholders + 27 aria-label + 93 title + 400 JSX = 640+ strings)  
✅ Roadmap migration en 13 sub-missions chiffrées et chunked par risque  
✅ Stratégie validée : helper `t()` déclaratif M113b déjà déployé permet migration progressive sans interruption service  
⚠ Migration effective des 640+ strings reportée en 13 sub-missions M113d-1 à M113d-13 (effort ~14h cumulées par chunks low-risk)  

## Pages standalones M104+ (déjà migrables sans risque SPA)

Les 8 pages standalones (diffusion, dashboard, export-dossiers, reglages-abonnement, reglages-equipe, reglages-calendrier, reglages-langue, guide) totalisent ~80 strings et peuvent être migrées immédiatement avec pattern déclaratif `data-i18n` :

```html
<button class="btn-primary" data-i18n="btn.save">Enregistrer</button>
<input data-i18n-placeholder="export.search" placeholder="Rechercher...">
```

À planifier en M113d-0 (1h, low-risk).
