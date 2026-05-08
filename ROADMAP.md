# Roadmap Ocre Immo

> **Source de vérité** : items pending pour l'app Oi Agent et l'écosystème Ocre.
> **Versionnée git** : append-only, tag `roadmap-vN` à chaque modif majeure.
> **Lue par** `/root/bin/autopilot-planner` (M/2026/05/08/17) qui génère les missions automatiquement quand le backlog passe sous seuil.

## Statut courant — 2026-05-08

- **App Oi Agent** : déployée prod 5 tenants (zefk + 4 réels), V18.x, Service Worker v423
- **Design tokens** : chantier 2.x quasi-complet (2.1, 2.2, 2.3, 2.5, 2.6, 2.7.1-4 livrés ; 2.8 BLOCKED 3536 occ ; 2.4 vitrine reportée)
- **Multi-tenant** : DB centrale `ocre_meta` + `ocre_wsp_<slug>` figée M84
- **Wizard signup** : flow `agents_register → activate → _provision` figé M5/M6/M7
- **CHARTER.md** : v1 livrée (M/2026/05/08/14, tag `charter-v1`)
- **Bugs M9/M10/M11** : auto-save / autocomplete / upload corrigés
- **Notif convention** : `--phase {start|blocked|done|error}` durci M/2026/05/07/4

---

## v18.x (en cours) — Finalisations chantier tokens + bugs prod

- [x] ~~**DESIGN-TOKENS.2.8.1** — font-size tokenization~~ — **livrée M/2026/05/08/19** (1316 substitutions, 21 nouveaux tokens font-size dans tokens.css)

- [x] ~~**DESIGN-TOKENS.2.8.2** — spacing tokenization~~ — **livrée M/2026/05/08/20** (1502 substitutions PHASE 1 literals simples, 13 nouveaux tokens spacing)

- [x] ~~**DESIGN-TOKENS.2.8.3** — box-shadow tokenization~~ — **livrée M/2026/05/08/22** (4 nouveaux tokens warm-ocre/brown : --shadow-warm-sm/md/card/card-soft, 2 substitutions PHASE 1 dans superadmin/reset-password + inscription/index. Périmètre strict : seuls les fichiers chargeant tokens.css ont été tokenizés ; 404/maintenance/viewer.css/activation/inscription-confirmee préservés inline car standalone)

- [x] ~~**DESIGN-TOKENS.2.8.4** — font-family tokenization (Cormorant + DM Sans)~~ — **livrée M/2026/05/08/24** (token --font-script ajouté pour Caveat ; ~127 substitutions PHASE 1 dans 6 fichiers token-loaded → var(--font-serif)/sans/script. Snowflakes laissés inline (DM Sans+Helvetica Neue, fontFamily JSX avec quotes singles externes, Cormorant Garamond+Georgia,serif déjà couvert par --font-serif). SW v426 → v427.)

- [ ] **DESIGN-TOKENS.2.4** — Vitrine WordPress ocre.immo (reportée)
  - priorité: low
  - prereqs: vitrine WP déployée prod
  - effort: 1-2h
  - bloquant: vitrine n'est pas encore en prod, hors scope app

---

## v19 — Rebrand Oi Agent + SSO Ocre

- [ ] **M117** — Rebrand UI Ocre Immo → Oi Agent + bascule URL agent.ocre.immo
  - priorité: high
  - prereqs: aucun
  - effort: 2-3h
  - source: OI_VISION.md ligne 71

- [~] **M118** — Architecture SSO Ocre (auth partagé multi-modules) — **analyse livrée M/2026/05/08/25** (`docs/M118_SSO_ANALYSIS.md`). Décision Philippe attendue avant implémentation. Découpage proposé : M118.1 (cookie .ocre.immo backend) + M118.2 (frontend wrapper setOcreToken) + M118.3 (E2E cross-subdomain, prereq M117) + M118.4 (logout cascade) + M118.5 (cleanup transition). Source OI_VISION.md absente du repo, analyse construite depuis audit code.
  - priorité: high
  - prereqs: M117 livrée pour M118.3 ; M118.1+.2 indépendants
  - effort: 2-3h cumul total des 5 sous-tâches
  - source: docs/M118_SSO_ANALYSIS.md (M/2026/05/08/25)

- [ ] **M114.2** — UI onboarding Telegram (naming Oi Agent)
  - priorité: medium
  - prereqs: M114 livrée (✓ noté M/2026/05/08)
  - effort: 1-2h

- [ ] **M114.3** — 3 templates notifs match/pdf/reminder
  - priorité: medium
  - prereqs: M114.2 livrée
  - effort: 1-2h

---

## Backlog moyen terme

- [ ] **Channel manager v4** — sync Airbnb/Booking pour Oi Book futur
  - priorité: low
  - prereqs: Oi Book lancé (futur)

- [ ] **Check-in WhatsApp** `checkin.ocre.immo`
  - priorité: low
  - prereqs: Oi Book lancé

- [ ] **Vitrine WordPress** `ocre.immo`
  - priorité: medium
  - effort: 4-6h
  - source: doc-deploy ocre-vitrine-LIVE.md

- [ ] **Pages Aide/FAQ** dans app Oi Agent
  - priorité: medium
  - effort: 2-3h

- [ ] **Réglages tenant** (panel agent : nom agence, logo, couleurs métier)
  - priorité: medium
  - effort: 3-4h

- [ ] **Option A export ZIP autonome** (v18.32 backlog historique)
  - priorité: low
  - effort: 2h

- [ ] **M115** — PWA Oi Agent (installable, splash, manifest)
  - priorité: medium
  - effort: 1h30
  - statut: NOTE M/2026/05/07/12 livrée — vérifier conformité OI_VISION

- [ ] **M116** — Template PWA atelier réutilisable
  - priorité: low
  - effort: 1h
  - prereqs: M115 conforme

- [ ] **M119** — PWA launcher Oi (`app.ocre.immo`)
  - priorité: low
  - prereqs: 2+ modules Oi déployés (Oi Scan ou Oi Book)

---

## Idées long terme

- [ ] Oi Scan (autonome, planifié)
- [ ] Oi Book (planning courte durée)
- [ ] Oi Demande (symétrique côté demandeurs)

---

## Dettes techniques identifiées

- [ ] **`/opt/ocre-agent-landing/*` hors repo** — landing page Oi Agent gérée hors git
  - priorité: medium
  - source: rapport DESIGN-TOKENS.2.5
  - effort: 2-3h migration

- [ ] **Tests E2E utilisateurs récurrents** — création fiche, autocomplete, upload, signup
  - priorité: high
  - source: post-bugs M9/M10/M11 leçon
  - effort: 3-4h (Playwright ou Cypress + 5 parcours golden)

- [ ] **Inbox `/var/lib/atelier/mission_inbox/` accumule 169+ .md historiques**
  - priorité: medium
  - source: M/2026/05/08/16 limite documentée
  - bloquant: tant qu'un .md y traîne, autopilot skip "inbox non-vide"
  - effort: 30min (script archive après pickup CC)

- [ ] **Décision archi `#fff` blanc cards** (482 occ)
  - priorité: low
  - source: M/2026/05/08/13 hors scope tokenization
  - bloquant: Philippe Niveau 3 (choix archi)

- [ ] **Quality-gate stats agrégées 24h** dans `autopilot-status.txt`
  - priorité: low
  - source: M/2026/05/08/16 limite MVP
  - effort: 1h

---

## Tests utilisateur en attente Philippe

- [ ] Tester `app.ocre.immo` signup → activate → workspace (E2E client)
- [ ] Tester création dossier post-fix M9 (idempotence UUID)
- [ ] Tester autocomplete adresse FR post-fix M10
- [ ] Tester upload photos post-fix M11 (PHP FPM 25M)
- [ ] Tester rendu Albâtre #FCFAF6 sur les 5 tenants prod
- [ ] Tester PWA install M115 sur iPhone
- [ ] Tester signup → activation → wizard → premier dossier (M5/M6/M7 chaîne)

---

## Items résolus par missions livrées (référence)

- [x] M5 — Inscription agents (M/2026/05/08, signup_status → status)
- [x] M6 — Activation tokens (M/2026/05/08, naming canonique users)
- [x] M7 — Provisioning workspace (M/2026/05/08, mode unique M84)
- [x] M9 — Auto-save fiche (M/2026/05/08, idempotence UUID)
- [x] M10 — Autocomplete adresse FR (M/2026/05/08)
- [x] M11 — Upload photos (M/2026/05/08, PHP FPM 25M)
- [x] M14 — CHARTER.md + quality-gate + autopilot infra (M/2026/05/08/14, tag charter-v1)
- [x] M15 — Activation timer autopilot (M/2026/05/08/15, active+enabled)
- [x] M16 — Mode verbose autopilot transparence (M/2026/05/08/16, 4 hooks notifs ℹ️)
- [x] DESIGN-TOKENS 2.1, 2.2, 2.3, 2.5, 2.6, 2.7.1-4 (chantier ~2200 substitutions cumulées)
- [x] M114 — Bot Telegram `@ocreimmo_bot` (notif sortantes)

---

## Versioning

Append-only. Modifications majeures = tag `roadmap-vN`. Items résolus déplacés en bas dans la section "Items résolus par missions livrées" pour conserver l'historique sans encombrer la roadmap active.

Hook auto-update CC : à chaque mission livrée, CC ajoute (a) les nouvelles dettes/TODOs détectés, (b) les checks `[x]` sur les items couverts, dans le commit de la mission elle-même.

## Bugs UI/UX (test utilisateur Philippe 2026-05-08)

- [x] ~~**Bug suppression dossier UI ne rafraichit pas apres confirmation**~~ — **livrée M/2026/05/08/23** (root cause = race entre DELETE en flight et refetch focus/visibilitychange ligne 11171 qui re-injectait la fiche. Fix : `window.__ocreDeletedIds` Set marque l id avant navigation, refetch filtre les ids supprimés, handleDelete await DELETE puis re-filtre + toast résultat réel. Couvre form-view delete (handleDelete) + swipe-delete home (doDelete). SW v425 → v426.)
- [x] ~~**Refonte signup étape 3 — phone country-code + suppression WhatsApp**~~ — **livrée M/2026/05/08/26** (composant `PhoneInput` country-code + indicatif + numéro local pour cohérence UX avec Section I app tenant — 20 pays FR+francophonie+Maghreb+Golfe. Suppression complète WhatsApp Pro field + toggle Canaux Notifications + state whatsapp/channels_whatsapp + recap + payload register. Mention informative "Notifications PWA actives automatiquement après installation" sous Email. Compteur 10/20 retiré. Backend agents_register.php tolère payload legacy silencieusement, pas de migration DB. Périmètre étape 3 strict.)
- [x] ~~**Refonte page Compte créé — pastilles état réel + alerting super-admin si email fail**~~ — **livrée M/2026/05/08/27** (page `inscription/confirmee/` : suppression "Vérification carte pro et SIRET" + "Première connexion + onboarding" (étapes non implémentées), garde uniquement "Inscription enregistrée" + "Email de confirmation". Pastille email conditionnée sur `?email_sent=1|0` URL param écrit par backend. Si fail → icône rouge "!" + message "Un problème... Notre équipe a été alertée". Backend `agents_register.php` : `_alert_email_failure()` log persistant `/var/log/ocre-signup-errors.log` + Telegram `notify --phase error --priority high` + email super-admin best-effort. Message remerciement "Merci pour votre inscription. Votre dossier rejoint le réseau d'agents Ocre. Nous reviendrons vers vous *très prochainement*." entre Bienvenue et card. Smoke test Telegram alerting OK.)
- [x] ~~**URGENCE — Flow activation compte cassé (bouton email + page set-password + boucle login)**~~ — **livrée M/2026/05/08/28** (4 fix : (1) email template `_send_activation_email` : bouton "Activer mon compte" en bulletproof button HTML email (`<table bgcolor="#10B981">`) compatible Gmail iOS qui neutralisait le `<a style>` custom. Couleur succès #10B981. (2) `agents_activate.php` : redirect vers `/set-password.html?token=X` au lieu de `/login/?activated=1`. Workspace toujours provisionné, mais flip status='active' déplacé vers `agents_set_password.php`. (3) Nouvelle page `/set-password.html` (vanilla JS, charte tokens.css, validation 8c+1maj+1num) + endpoint `/api/agents_set_password.php` qui valide token, hash BCRYPT cost 12, UPDATE password_hash + status=active + clear token, crée session (auto-login), retourne {ok, token, redirect:/}. (4) `auth_v20.php` action=login : reject status='pending_activation' avec 403 + code PENDING_ACTIVATION + message clair → casse la boucle infinie observée par Philippe.)
- [x] ~~**URGENCE — SMTP signup fiable PHPMailer (mails non reçus + alerting silencieux)**~~ — **livrée M/2026/05/08/29** (root cause : `ocre_send_email()` utilisait `/usr/sbin/sendmail` qui acceptait le mail (exit 0 = OK) mais Postfix ne délivrait pas réellement → ✅ ✅ pastilles UI mensonge + alerting `_alert_email_failure` jamais déclenché car retour `true`. Fix : refonte `lib/email_sender.php` avec nouvelle `send_mail()` qui tente Resend HTTP API en priorité (clé déjà configurée dans `_smtp_config.php` hors-git), fallback sendmail si Resend KO. Retourne `{ok, error, message_id, provider}`. Shim `ocre_send_email()` retourne bool pour rétro-compat zero-touche du code appelant. Smoke test E2E réel philippe.ciftci@gmail.com : provider=resend, message_id=8db89a82-... Logs `/var/log/ocre/email-sender.log` distinguent `SENT_RESEND` vs `SENT_SENDMAIL` vs `FAIL_*`. Sender actuel : `onboarding@resend.dev` (domain ocre.immo non vérifié chez Resend — TODO M+1 vérification DNS).)
- [x] ~~**Refonte présentation Console super-admin (hiérarchie + sections + contextualisation)**~~ — **livrée M/2026/05/08/35** (Refonte structure dashboard `/superadmin/index.html` charte Ocre stricte. (1) Header `<div class="sa-header">` : titre Cormorant 32px italic + sous-titre dynamique sous forme "<TabLabel> · <Subtitle>" qui change selon onglet actif (5 sous-titres mappés dans `TAB_SUBTITLES`). À droite : badge mode lecture/écriture (gris/rouge) + toggle écriture + Atelier (live) + recherche + déconnexion via `.sa-link`. (2) Tabs unifiés `<div class="sa-tabs">` : conteneur fond blanc bordure ronde 12px, chaque onglet `.sa-tab` 9/16px borderRadius 8px, `.active` accent ocre fond+texte blanc. (3) Vue d'ensemble regroupée en 2 sections thématiques avec titres uppercase ls 0.08em : "Espaces & Utilisateurs" (Workspaces / WSP actifs / WSC actifs / Users) et "Activité temps réel" (Sessions actives / Pending ruptures / Actions super-admin 24h). Cards `.sa-card` : padding 20/22, border-top 3px coloré par accent (ocre/success/warning/muted selon contexte), valeur Cormorant 36px font-weight 500 tabular-nums, label uppercase 11px ls 0.05em, description italique 12px. Pending ruptures bordure passe en warning si >0. (4) Zone technique Reset déplacée bas Vue d'ensemble : `.sa-tech-zone` fond `bg-tint-soft` discret, label uppercase, warning italique brun, bouton Reset TOTAL `#B91C1C` 11/20 (plus grosse/criarde). Suppression `.reset-total-zone` rouge épais. (5) Responsive 760px : header empilé, cards 2 colonnes, tabs flex-equal. Backend zero-touche. Sub-titles updatés sans reload via `document.getElementById('sa-subtitle').textContent`.)
- [x] ~~**FIX URGENT — Reset TOTAL ne supprime pas réellement les workspaces (DROP DATABASE manquant)**~~ — **livrée M/2026/05/08/34** (Refonte `api/superadmin_cleanup.php` action `reset_total` : (1) DROP DATABASE pour TOUS les workspaces meta (sans filtre `archived_at IS NULL` qui laissait 3 archived non purgés en M33). (2) Scan + DROP des DBs `ocre_wsp_*` orphelines (sans entrée meta). (3) DELETE workspaces + workspace_members + pact_signatures complets. (4) DELETE users WHERE role != 'super_admin' AND id != superadmin_courant_id (préserve Philippe absolument). (5) DELETE sessions WHERE user_id != superadmin_courant_id (préserve la session active de Philippe pour ne pas le déconnecter). (6) DELETE pending_activation. (7) TRUNCATE super_admin_events. (8) ALTER TABLE AUTO_INCREMENT=1 sur 5 tables (workspaces/workspace_members/pact_signatures/sessions/super_admin_events). Report détaillé `{workspaces_dropped, workspaces_meta_deleted, orphan_dbs_dropped, users_deleted, sessions_deleted, pending_deleted, audit_truncated, auto_increment_reset, errors[]}`. Frontend : modale alert détaillée avec décompte par catégorie + erreurs (top 5) + `window.location.reload()` après fermeture pour rafraîchir compteurs Vue d'ensemble. Préserve la session active du super-admin courant.)
- [x] ~~**Fix bouton Atelier live + Outils nettoyage super-admin (suppression batch + reset)**~~ — **livrée M/2026/05/08/33** ((1) Fix bouton "⚡ Atelier (live) MVP" header super-admin : `href="/atelier-live.html"` cassé → `href="/superadmin/atelier-live.html"` (le fichier existe sous /superadmin/, le path absolu n'y pointait pas). (2) Toggle "🔒 Activer écriture" dans header banner (par défaut OFF, persisté `localStorage.ocre_sa_write_mode`) : actions destructives bloquées tant que OFF avec alert "Activer le toggle". (3) Sélection multiple Pending+Workspaces : checkbox header "all" + checkbox par ligne, action bar sticky bottom "X sélectionnés [Supprimer la sélection] [Désélectionner]". (4) Reset par onglet : bouton 🔥 rouge bordure dans zone dédiée bas de chaque tab (Pending, Workspaces, Audit). Confirmation par `prompt("RESET")` saisie texte. (5) Reset TOTAL provisoire dans Vue d'ensemble : encadré rouge + double confirmation (confirm + prompt "RESET TOTAL") + cumul reset_pending + reset_workspaces (DROP DATABASE par tenant) + TRUNCATE super_admin_events. (6) Endpoint `api/superadmin_cleanup.php` 6 actions (delete_workspaces / delete_users_batch / reset_pending / reset_workspaces / reset_audit / reset_total). (7) Audit trail strict : log `/var/log/ocre-superadmin-actions.log` (timestamp + sa_id + action + payload JSON) + Telegram notify high `[OCRE] Super-admin action destructive` pour chaque batch/reset.)
- [x] ~~**Refonte compacte tableaux dashboard super-admin (Inscriptions en attente + audit autres)**~~ — **livrée M/2026/05/08/32** (densité Linear/Stripe/Notion appliquée à `/superadmin/index.html` : `th, td` padding 6/10px, ligne 44px desktop / 52px mobile, fontSize 13px. Bordure gauche colorée 4px (`tr.state-{green,orange,red}`) au lieu de fond pleine largeur. Header tableau bg `--color-bg-tint-warm` + uppercase 11px letter-spacing 0.05em. Colonnes refactorées : Agent (max-width 180px ellipsis) / Email (240px ellipsis) / Téléphone E.164 normalisé via `fmtPhoneE164()` / Inscrit "DD/MM HH:MM" via `fmtDateCompact()` + tooltip ISO + age (Xh) / Tentatives centrées tabular-nums / Provider/Statut compact. Actions : 5 boutons icône 28x28 sans label, tooltips natifs (`🔄 Renvoyer le mail`, `✅ Activer manuellement`, `📋 Historique`, `📞 Note`, `🗑️ Supprimer`), couleurs tokens. Hover ligne `--color-bg-tint-warm`. Mobile : table scrollable horizontal. Tableaux Workspaces/Clients/Audit alignés sur même densité (dates compactes DD/MM HH:MM, `col-date`/`col-name`/`col-email` classes partagées, ellipsis cohérent). Suppression colonne Agence/État/séparée du tableau pending (intégrée bordure gauche). Périmètre strict CSS/HTML — backend zero-touche.)
- [x] ~~**URGENCE — Migration Resend → OVH SMTP exclusif (élimination dépendance tierce)**~~ — **livrée M/2026/05/08/31** (Philippe rejette Resend définitivement, OVH uniquement. Réécriture `api/lib/email_sender.php` : PHPMailer (3 fichiers téléchargés v6.10.0 dans `api/lib/PHPMailer/`) + SMTP authentifié `ssl0.ovh.net:465 SSL/TLS direct`. Suppression toute trace Resend (commentaires + `_smtp_config.php` migré). Password lu depuis `/root/.secrets/ovh-noreply-ocre.pwd` (perms 600 root:root, déjà en place côté serveur depuis 2026-05-06) avec fallback inline. PAS DE FALLBACK sendmail silencieux : si OVH SMTP fail → ok=false + error détaillé → alerting `_alert_email_failure` se déclenche normalement. DNS ocre.immo déjà OK (SPF mx.ovh.com + DKIM atelier + DMARC). Smoke tests E2E réussis : (1) philippe.ciftci@gmail.com OK provider=ovh_smtp, (2) philippe.ciftci+m31test@gmail.com (alias externe) OK = bug Resend "owner only" éliminé, (3) test+m31@ocre-test.fr échoue proprement avec "Recipient address rejected: Domain not found" = comportement strict attendu. Création `api/_smtp_config.example.php` committable. M30 architecture résiliente conservée intégralement.)
- [x] ~~**URGENCE STRATÉGIQUE — Architecture résiliente inscription (super-admin centric)**~~ — **livrée M/2026/05/08/30** (5 livrables : (1) ALTER TABLE users +5 colonnes (activation_attempts_count, last_activation_attempt_at, last_activation_provider, last_activation_status, superadmin_notes) — backup mysqldump avant. (2) `agents_register.php` enrichi : détection re-inscription pending → token regen + attempts++ + alerte Telegram orange/rouge selon seuil. Validation préventive `_email_predelivery_checks()` : DNS MX + temp-mail blacklist + typo classique (gmial.com etc.) avec alerting. (3) Endpoint `superadmin_pending_activations.php` (6 actions : list/resend/activate_manual/add_note/delete/history). Génère mdp temporaire 12 chars random pour activation manuelle. (4) Section "Inscriptions en attente" dans `superadmin/index.html` (table avec pastilles 🟢🟠🔴, 5 boutons d'action, modals confirm). (5) Cron `ocre-activation-retry.timer` (1h) → script bash + PHP one-shot qui retente envoi via wrapper send_mail (rotation provider) + escalade rouge automatique si attempts>3 ou age>24h, dedup flag par uid. **Diagnostic critique découvert lors des tests** : Resend API renvoie HTTP 403 sur tous emails != propriétaire compte tant que domaine ocre.immo n'est pas vérifié chez Resend → fallback sendmail qui drop silencieusement = root cause exbatta01@gmail.com / exbatta+test01@gmail.com non reçus. Action Philippe escaladée Telegram : verify ocre.immo sur resend.com/domains.)

## Améliorations UX (test utilisateur Philippe 2026-05-08)

- [ ] **Uniformiser systeme photos Piece d identite Section I avec Section V Photos**
  - priorité: medium
  - prereqs: aucun
  - effort: 1 mission
  - source: test utilisateur Philippe 2026-05-08
  - description: remplacer "Ajouter un fichier" + vignette unique Section I par systeme Section V Photos (grid vignettes, bouton "+", compteur, bouton Selectionner). Max 3 photos au lieu de 30. Compteur "PIECE IDENTITE X/3". Si 3 photos atteintes, "+" disparait. Upload meme backend Section V. Preserver fichiers actuels. Test E2E : Section I upload 3 photos, verifier 3/3 + "+" disparu, supprimer 1 via Selectionner, verifier 2/3 + "+" reapparu.

- [ ] **Refonte DualCurrencyPair Variante B drapeau filigrane**
  - priorité: medium
  - prereqs: aucun
  - effort: 1 mission
  - source: test utilisateur Philippe 2026-05-08, maquette devise-maquettes.html validee
  - description: Layout 3 cols grid 1fr 0.7fr 1fr gap 8px (6px mobile <600px). Drapeau emoji background filigrane opacity 0.18 size 56px desktop / 44px mobile, position extremite externe (gauche col gauche, droite col droite). Padding-left/right 56px (42px mobile). Code devise EUR/MAD font-weight 600 var(--color-text-muted) letter-spacing 0.04em. Champ saisie font 16px (15px mobile) var(--color-text-primary) font-weight 500. Col milieu var(--color-bg-tint-warm) avec label "1 EUR =" + champ taux editable var(--color-accent) + label "MAD". Decisions tranchees : (1) Override taux GLOBAL fiche (modif propagation tous DualCurrencyPair fiche). (2) Feedback visuel point ocre 6px a droite champ taux quand != 10.84. (3) Persistance via colonne custom_exchange_rate table clients workspace (ALTER idempotent NULL si defaut). (4) Pas de padlock (v2). Perimetre : tous DualCurrencyPair tenant (Section III Budget min/max/Liquide, Section VI Frais agence, autres champs duo). Preserver champs uniques. Test E2E : Section III Budget min saisir 100000 EUR -> 1084000 MAD + taux 10.84 + drapeaux filigrane. Modifier taux 11.00 -> propagation + point ocre. Recharger -> taux conserve. Responsive iPhone 375/390/414/428.

