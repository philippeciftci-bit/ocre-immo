---
mission_id: M/2026/05/14/12
title: Audit Codex Phase 1 stabilisation Ocre Immo
date: 2026-05-14T00:35:00Z
project: ocre
type: external_audit
agent: Codex CLI v0.130.0 (gpt-5-codex, sandbox=read-only)
status: done
tokens_used: 596312
---

# Audit Codex Phase 1 stabilisation Ocre Immo (M/2026/05/14/12)

> Mission M/CODEX-AUDIT-PHASE1. Codex CLI exec non-interactif sur `/root/workspace/ocre-immo`.
> Périmètre : 10 commits 13-14 mai 2026 (`4ea5f56` → `691ee54`), migrations V001-V012,
> scripts, sw.js, index.html sections modifiées, AGENTS.md, CLAUDE.md.
> Tokens consommés : **596 312** (≈ \$X.XX sur crédit OpenAI selon tarif gpt-5-codex).

---

**Synthese Executive**  
- Score global : **B-** — avancées structurant la stabilisation, mais 4 anomalies critiques à corriger.  
- Risques majeurs : drift lexical (`tenant_slug`), défaillance corrélation ID pour `fetch(Request)`, retry réseau non idempotent, journalisation client silencieusement cassable.  
- Santé migrations : pipeline cohérent mais absence de garde-fou checksum côté tables héritées.  
- Front : hardening utile (error codes, lint), mais certains garde-fous n’empêchent pas les régressions (ListView continue de planter).  
- Recommandation immédiate : patcher le retry réseau et la dérive vocabulaire avant déploiement prod.  

**Critique par Commit**  
- `4ea5f56` : met en place le socle migrations + logging client, mais introduit `tenant_slug` neuf (`migrations/versions/V011__add_realtime_events.sql:8`) contraire au lexique. `index.html` amène un retry générique potentiellement non idempotent.  
- `0142481` : bonne instrumentation schema drift + request ID. Cependant `api/config.php:8` conserve terminologie « tenant » dans les commentaires, et le middleware ne protège pas les appels via `fetch(Request)` (voir plus bas).  
- `732121f` : fix fonctionnel validé, rien à signaler.  
- `cba8207` : rétablit le placeholder build, conforme.  
- `d49c8c7` : migration V012 bien cadrée, ajout du hook migrate smoke pertinent.  
- `165c702` : enrichissements précieux (checksum, health). Néanmoins `index.html:225` introduit un retry qui relance **toutes** les requêtes POST, et `api/health.php:55` considère DRIFT même si `current` < `required` de plus de deux versions sans déclencher CRITICAL.  
- `1700195` : documentation complète, cohérente avec AGENTS/CLAUDE.  
- `ece3dc1` : lint + enum codes solides. Validation runtime `ListView` insuffisante (`index.html:19988` logge mais ne stoppe pas l’exécution).  
- `c294d09` : retouche UI conforme.  
- `691ee54` : housekeeping ok.  

**Risques Securite**  
- **P0** : `window.fetch` monkey-patch n’insère pas `X-Request-Id` si le code appelle `fetch(new Request(...))`, car `injectRequestId` modifie seulement `opts` (« headers instanceof Headers ») sans muter l’objet `Request`. Cela peut neutraliser le traçage sécurité. (`index.html:298`).  
- `log_client_error.php` écrit dans `/var/log/ocre` sans vérifier `file_put_contents` : en cas de permissions refusées, les logs disparaissent silencieusement, rendant l’alerte Telegram inopérante (fail-open).  

**Risques Data**  
- **P0** : retry réseau (`index.html:225`) rejoue un POST même si le serveur a persisté l’opération mais la réponse a été perdue. Duplication de dossiers possible.  
- `migrations/versions/V011__add_realtime_events.sql:8` introduit une nouvelle colonne nommée `tenant_slug`. Cela crée un futur chantier de migration lexicale (SSOT brisée).  
- `api/health.php:55` classe `current<required` comme simple DRIFT tant qu’il n’y a pas ≥3 pending, retardant l’alerte sur un wsp réellement bloqué.  

**Risques Perf**  
- `doFetchWithRetry` applique une attente 500 ms puis relance, même pour ressources lourdes POST/PUT → double charge réseau côté serveur si la connexion échoue tardivement.  
- `api/log_client_error.php` construit la ligne JSON et `@file_put_contents` pour chaque 5xx : acceptable, aucun batch. Pas de fuite repérée.  

**Dette Technique**  
- Vocabulaire interdit : nouveau champ `tenant_slug` et multiples commentaires introduits dans les commits récents (ex. `scripts/provision-tenant.sh:1`) violent la règle AGENTS.md.  
- `ListView` garde-fou : console error sans fallback, la réparation reste fragile (`index.html:19988`).  
- `api/config.php` conserve des mentions « tenant » en commentaires et documentation — divergence par rapport au lexique cible wsp/espace de travail.  
- `api/log_client_error.php` repose sur suppression d’erreurs (`@`) : dette de monitoring.  

**Coherence SSOT**  
- Registry composants inchangé, hook pre-commit appliqué.  
- Versioning entier respecté : SW `v546`→`v551`, migrations `V001`→`V012`.  
- Expand-contract non violé (toutes nouvelles colonnes NULL ou nouvelles tables).  
- Incohérence majeure lexique : nouvelles occurrences `tenant_slug`. Nécessite correction.  

**Recommandations Priorisées**  
- **P0 (24 h)**  
  - Corriger `doFetchWithRetry` pour limiter les retries aux requêtes idempotentes (GET/HEAD) et éviter duplication sur POST (`index.html:225`).  
  - Renommer `tenant_slug` → `wsp_slug` dans `migrations/versions/V011__add_realtime_events.sql` et pipelines associés avant propagation.  
  - Ajuster `api/health.php` pour marquer CRITICAL lorsque `schema_version_current` < `SCHEMA_VERSION_REQUIRED`, même sans 3 pending (`api/health.php:55`).  
- **P1 (≤1 semaine)**  
  - Modifier le monkey-patch pour gérer `fetch(Request)` en recréant une requête clonée avec header `X-Request-Id` (`index.html:298`).  
  - Supprimer usage des opérateurs `@` dans `api/log_client_error.php` et retourner 500 si la journalisation échoue (`api/log_client_error.php:33`).  
  - Dans `ListView`, fallback `setClients` à un no-op pour éviter crash total et déclencher seulement le logging (`index.html:19988`).  
- **P2 (≤3 mois)**  
  - Réaligner commentaires et scripts sur vocabulaire wsp/espace de travail (`scripts/provision-tenant.sh:1`, `api/config.php:4`).  
  - Étendre `ocre-migrate` pour checksum legacy (avant V001) et écrire les valeurs dans `_schema_migrations.checksum`.  
  - Ajouter un test automatisé couvrant la création d’un `fetch(new Request(...))` pour s’assurer du traçage request-id.  

STATUS: READY

---

## Trace exécution

- Prompt envoyé : `/tmp/codex-audit-prompt.md` (3 811 bytes)
- Codex CLI v0.130.0, model `gpt-5-codex`, sandbox `read-only`, approval `never`, workdir `/root/workspace/ocre-immo`.
- Session id : 019e23db-fc18-7510-9a31-fcf083cfd512.
- Durée : ~7 minutes (lancement 00:35, fin 00:42).
- Tokens consommés : **596 312** (variations 14k tokens sur test trivial → 596k sur audit complet, OK pour le périmètre).
- Output brut : `/tmp/codex-audit-output.txt` (195 560 bytes).

## Suite à donner

Implémenter les **3 P0** dans une mission séparée (effort medium, ~30 min code) :

1. `doFetchWithRetry` : limiter retry aux méthodes idempotentes (GET/HEAD), pas de retry sur POST/PUT/DELETE (`index.html:225`).
2. Renommer `tenant_slug` → `wsp_slug` dans `migrations/versions/V011__add_realtime_events.sql` ET dans toutes les tables wsp qui ont reçu la colonne. Pattern expand-contract (Phase 2 lexique anticipée sur ce point).
3. `api/health.php` : passer `schema_status='CRITICAL'` dès que `schema_version_current < SCHEMA_VERSION_REQUIRED` (au lieu d'attendre ≥3 pending).

Puis P1 (1 semaine) et P2 (3 mois) selon priorisation Philippe.

---

*Audit externe Codex CLI. Rapport généré et committé automatiquement via mission M/2026/05/14/12.*
