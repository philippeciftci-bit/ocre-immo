# Setup WhatsApp Cloud API Meta — Ocre Immo

Document Philippe pour activer les notifications WhatsApp transactionnelles. Backend déjà livré (M93). Cette page contient les étapes externes Meta + le remplissage des credentials côté VPS.

## 1. Pré-requis

- Numéro de téléphone dédié, **différent** de ton numéro personnel (Meta exige un numéro qui ne soit pas déjà actif sur WhatsApp). Acheter un eSIM data (~3-5 €/mois) ou utiliser un numéro Twilio temporaire.
- SIRET Ocre Immo + RIB (pour KYC business).
- Accès Business Manager Meta (compte Facebook personnel).

## 2. Création du Business Manager

1. Aller sur https://business.facebook.com → "Créer un compte".
2. Renseigner : nom légal "Ocre Immo", email pro `contact@ocre.immo`, ton nom complet.
3. Confirmer l'email.

## 3. Vérification entreprise (KYC, 1-2 semaines)

Dans Business Manager > Paramètres entreprise > Sécurité > Centre de vérification :
1. Soumettre les documents : SIRET (Avis de situation INSEE OCR-lisible), RIB, justificatif domicile pro.
2. Vérification téléphonique automatique sur le numéro renseigné.
3. Attendre validation Meta (1-2 semaines en moyenne).

Tant que cette étape n'est pas complétée, tu es limité à **250 conversations/24h** en mode test.

## 4. Création WhatsApp Business Account (WABA)

Business Manager > Comptes > WhatsApp > Ajouter > **Créer un nouveau compte**.

- Nom du compte : "Ocre Immo Notifications"
- Catégorie : "Real estate" (si dispo) ou "Other"

## 5. Réservation du numéro de téléphone

Dans la WABA :
1. **Ajouter un numéro de téléphone** → renseigner le numéro dédié.
2. Vérification par SMS ou appel vocal.
3. Confirmer.

Une fois validé, tu obtiens le **Phone Number ID** (numérique long, ex: `1234567890123456`). **À noter**.

## 6. Création de l'App Meta + produit WhatsApp

1. https://developers.facebook.com/apps → "Créer une app" → type "Business".
2. Renseigner : nom "Ocre Immo API", email contact.
3. Dans le dashboard de l'app : **Add product** → "WhatsApp" → Setup.
4. Lier à la WABA créée à l'étape 4.

## 7. Génération du token permanent

Par défaut, Meta génère un token temporaire (1h). Pour un token permanent :

1. Dans l'app Meta > **Business Settings** > **System Users** → "Add" → nom "ocre-cron-system" → rôle Admin.
2. **Generate New Token** → sélectionner l'app Ocre Immo + permissions :
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
3. Token affiché **une seule fois** → copier immédiatement (commence par `EAA...`, ~200 chars). **À noter dans un coffre-fort**.

## 8. Configuration du webhook

Dans l'app Meta > WhatsApp > Configuration :

1. **Callback URL** : `https://signup.ocre.immo/api/whatsapp_webhook.php`
2. **Verify Token** : générer un random hex 32 chars sur ton ordi : `openssl rand -hex 32`. Copier la valeur. **À noter**.
3. Cliquer **Verify and Save** → Meta envoie un GET de handshake → si le token côté VPS correspond, le webhook est validé.
4. **Subscribe to fields** : cocher au minimum `messages`, `message_template_status_update`. Cocher aussi `account_alerts`, `phone_number_quality_update` si proposés.

## 9. Renseigner les credentials côté VPS

SSH sur le VPS atelier (ou via Termius), créer le fichier secret **dans `/etc/ocre/`** (lisible par PHP-FPM www-ocre via le groupe `www-data`) :

```bash
sudo -i
mkdir -p /etc/ocre && chown root:www-data /etc/ocre && chmod 750 /etc/ocre
cat > /etc/ocre/whatsapp-meta.env <<'ENV'
WHATSAPP_TOKEN=EAA...le_token_permanent_a_l_etape_7
WHATSAPP_PHONE_ID=1234567890123456
WHATSAPP_WEBHOOK_VERIFY_TOKEN=le_random_hex_de_l_etape_8
WHATSAPP_WABA_ID=987654321098765
ENV
chown root:www-data /etc/ocre/whatsapp-meta.env
chmod 640 /etc/ocre/whatsapp-meta.env
```

Vérifier :
```bash
cat /etc/ocre/whatsapp-meta.env  # doit contenir 4 lignes KEY=VALUE
ls -la /etc/ocre/whatsapp-meta.env  # mode -rw-r----- root www-data
runuser -u www-ocre -- cat /etc/ocre/whatsapp-meta.env  # doit afficher le contenu (test acces PHP-FPM)
```

## 10. Templates de messages à valider par Meta

Dans Business Manager > WhatsApp > Models de messages, créer 4 templates **en français (`fr`)**. Chaque template prend 24-48h pour validation Meta.

### Template 1 : `inscription_confirmee`
**Catégorie** : UTILITY (transactionnel)
**Body** :
```
Bonjour {{1}},

Votre inscription Ocre Immo est confirmée. Activez votre compte en cliquant sur ce lien : {{2}}

Lien valide 7 jours.

Répondez STOP pour vous désabonner.
— Ocre Immo
```
Variables : `{{1}}` = prénom, `{{2}}` = URL activation.

### Template 2 : `nouveau_matching`
**Catégorie** : UTILITY
**Body** :
```
Bonjour {{1}},

Nouveau bien correspondant à votre recherche : {{2}} à {{3}}, {{4}}.

Voir la fiche : {{5}}

Répondez STOP pour vous désabonner.
— Ocre Immo
```
Variables : `{{1}}` = prénom, `{{2}}` = type bien, `{{3}}` = ville, `{{4}}` = prix, `{{5}}` = URL fiche.

### Template 3 : `rappel_visite_j1`
**Catégorie** : UTILITY
**Body** :
```
Rappel : visite demain à {{1}}, {{2}}.

Confirmer ou reporter : {{3}}

Répondez STOP pour vous désabonner.
— Ocre Immo
```
Variables : `{{1}}` = heure, `{{2}}` = adresse bien, `{{3}}` = URL confirmation.

### Template 4 : `message_confrere`
**Catégorie** : UTILITY
**Body** :
```
{{1}} vous a envoyé un message via Pacte digital.

Lire : {{2}}

Répondez STOP pour vous désabonner.
— Ocre Immo
```
Variables : `{{1}}` = prénom confrère, `{{2}}` = URL message.

**Important** : la mention "Répondez STOP pour vous désabonner" en pied de chaque template est **obligatoire RGPD/Meta** pour les templates UTILITY transactionnels marketing-light.

## 11. Test E2E une fois tout en place

Mode stub (sans credentials) — déjà testable maintenant :
```bash
TOKEN=$(cat /etc/ocre/internal-cron.token)
curl -X POST -H "X-Internal-Token: $TOKEN" -H "Content-Type: application/json" \
  -d '{"phone":"+33651325177","template":"inscription_confirmee","params":["Phil","https://signup.ocre.immo/activation/?token=abc"]}' \
  https://signup.ocre.immo/api/whatsapp_send.php
# -> 200 {"ok":true,"status":"stub","event_id":N,"stub":true}
```

Mode réel (après tu as complété les étapes 1-10) :
```bash
# meme curl, mais cette fois "status":"sent","provider_message_id":"wamid.HBgL..."
```

Vérifier réception côté téléphone destinataire (le numéro doit avoir accepté de recevoir des messages WhatsApp d'un compte non sauvegardé en contacts ; en mode test pré-KYC, le numéro destinataire doit être ajouté à la liste blanche dans Business Manager).

## 12. Conformité RGPD

- **Consentement explicite** : toggle WhatsApp wizard étape 3 (déjà en place M83.2 + M86).
- **Opt-out automatique** : le webhook traite `STOP` / `ARRET` / `UNSUBSCRIBE` / `DESABONNEMENT` / `DESINSCRIPTION` → `UPDATE users SET notif_whatsapp_enabled = 0`.
- **Footer chaque message** : "Répondez STOP pour vous désabonner" inclus dans chaque template.
- **Stockage minimal** : table `ocre_meta.whatsapp_events` stocke phone, template_name, status, timestamps. Pas de contenu de message conservé (les params sont stockés en JSON pour audit, à durcir si besoin).

## 13. Quotas Meta gratuits

- **1000 conversations / mois gratuites** (couvre largement les besoins agents Ocre Immo).
- Au-delà : facturation au volume (~0.04 €/message UTILITY pour la France, à confirmer dans la doc Meta tarifs).
- Conversations marketing/promotion : payantes dès la première (non-utilisées par Ocre Immo, on est en transactionnel UTILITY uniquement).

## 14. Support / debug

- Logs côté VPS : `/var/log/ocre/whatsapp.log` (toutes les actions send + webhook).
- Table `ocre_meta.whatsapp_events` : historique complet par event.
- Meta Business Manager > WhatsApp > Insights : statistiques globales (livraison, lecture, opt-out).

---

**Status backend Ocre Immo (M93)** : code livré, mode stub fonctionnel. Une fois étapes 1-10 complétées et `/root/.secrets/whatsapp-meta.env` rempli, l'API bascule automatiquement en mode réel. Mission **M93bis** prévue pour test E2E réel après config Meta.
