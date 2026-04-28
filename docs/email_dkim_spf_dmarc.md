# Configuration email anti-spam Ocre Immo

Audit M/2026/04/29/9.

## Diagnostic actuel (28 avril 2026)

```
$ dig +short TXT ocre.immo
"v=spf1 include:mx.ovh.com ip4:46.225.215.148 -all"

$ dig +short TXT _dmarc.ocre.immo
"v=DMARC1; p=quarantine; rua=mailto:dmarc@ocre.immo; aspf=s; adkim=s"

$ dig +short TXT default._domainkey.ocre.immo
(vide → DKIM manquant)
```

| Élément | Statut | Note |
|---|---|---|
| SPF | ✅ | inclut OVH + IP VPS, `-all` strict |
| DMARC | ✅ | `p=quarantine` strict, rua configuré |
| DKIM | ❌ | Aucune clé DKIM publiée dans le DNS |

## Pourquoi le DKIM manque

Les emails sortants sont envoyés via msmtp → smtp.gmail.com (compte philippe.ciftci@gmail.com). Gmail signe avec sa propre clé DKIM `gmail.com` mais le `From: notif@ocre.immo` est en désalignement → DMARC échoue.

## Options recommandées

### Option 1 (RECO) : SMTP relais avec DKIM intégré

Utiliser un service SMTP transactionnel qui signe automatiquement DKIM pour le domaine `ocre.immo` :

- **Brevo** (gratuit jusqu'à 300 emails/jour) — recommandé France
- **Mailgun** (5000 emails gratuits / 3 mois)
- **Postmark** (payant mais fiable, $15/mois)
- **OVH Email Pro** (déjà inclus si abonnement domaine)

Étapes Brevo :
1. Créer compte sur app.brevo.com
2. Ajouter domaine `ocre.immo`
3. Ajouter clés DKIM fournies (3 entrées DNS chez OVH)
4. Vérifier domaine dans Brevo
5. Récupérer SMTP credentials
6. Mettre à jour `/root/.msmtprc` :
   ```
   account ocre_brevo
   host smtp-relay.brevo.com
   port 587
   from notif@ocre.immo
   user <brevo-login>
   password <brevo-key>
   account default : ocre_brevo
   ```

### Option 2 : DKIM maison via OpenDKIM

Plus complexe, demande :
- `apt install opendkim opendkim-tools`
- Génération clé : `opendkim-genkey -t -s default -d ocre.immo`
- Publication clé publique en TXT `default._domainkey.ocre.immo`
- Configuration milter Postfix
- Postfix relay vers gmail.com en authentifié

Réservé si Philippe veut souveraineté totale.

## DNS à ajouter pour Option 1 (Brevo exemple)

```
Type    Nom                              Valeur
TXT     mail._domainkey.ocre.immo        k=rsa; p=<KEY>
TXT     brevo._domainkey.ocre.immo       k=rsa; p=<KEY>
CNAME   brevo1._domainkey.ocre.immo      brevo1._domainkey.brevo.com
```

## Vérification après config

```bash
# Test DKIM signature
echo "Test" | mail -s "DKIM check" check-auth@verifier.port25.com
# Réponse email donne SPF/DKIM/DMARC tous en "pass"

# Ou via mail-tester.com (URL temporaire dans réponse)
```

## ACTION REQUISE Philippe

1. Choisir option (Brevo recommandé)
2. Créer compte + ajouter domaine `ocre.immo`
3. Ajouter les 3 entrées DNS chez OVH (zone DNS du domaine)
4. Fournir credentials SMTP au admin → mise à jour `/root/.msmtprc`
5. Test avec mail-tester.com pour score 10/10
