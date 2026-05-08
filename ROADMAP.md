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

- [ ] **DESIGN-TOKENS.2.8.2** — spacing (padding/margin/gap) tokenization
  - priorité: high
  - prereqs: 2.8.1 livrée
  - effort: 2-3h

- [ ] **DESIGN-TOKENS.2.8.3** — box-shadow tokenization
  - priorité: medium
  - prereqs: 2.8.2 livrée
  - effort: 1-2h

- [ ] **DESIGN-TOKENS.2.8.4** — font-family tokenization (Cormorant + DM Sans)
  - priorité: medium
  - prereqs: 2.8.3 livrée
  - effort: 1h (volume faible)

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

- [ ] **M118** — Architecture SSO Ocre (auth partagé multi-modules)
  - priorité: high
  - prereqs: M117 livrée
  - effort: 2-3h
  - source: OI_VISION.md ligne 50

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

- [ ] **Bug suppression dossier UI ne rafraichit pas apres confirmation**
  - priorité: high
  - prereqs: aucun
  - effort: 1 mission
  - source: test utilisateur Philippe 2026-05-08
  - description: 1) clic supprimer 2) confirmation s affiche 3) confirme 4) le dossier reste visible page accueil 5) 2e tentative 6) message dossier introuvable 7) disparait enfin. Diagnostic : DELETE backend 200 mais front ne met pas a jour state local, 2e clic retourne 404 gere par refresh. Fix : apres DELETE 200, retirer fiche du state local OU refetch + toast "Dossier supprime". Test E2E : creer fiche test, supprimer, verifier disparition immediate sans 2e clic.

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

