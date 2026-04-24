Export Ocre Immo — Consultation hors connexion
================================================

Ce dossier contient un export complet de tes dossiers Ocre Immo à la date
indiquée dans data.json (champ `exported_at`).

Contenu :
- data.json          → toutes les données (dossiers, staged, archivés).
- photos/            → toutes les photos référencées, nommées par UUID.
- viewer.html        → mini-application de consultation (ouvre-le dans un navigateur).
- viewer.css         → styles.
- viewer.js          → logique (vanilla JS, pas de framework).
- README.txt        → ce fichier.

Comment consulter
-----------------

1. iOS / iPad (Safari) : dézippe l'archive dans l'app Fichiers, puis ouvre
   viewer.html. Safari le rend correctement.

2. Mac / Windows / Linux : certains navigateurs desktop bloquent l'ouverture
   directe de fichiers locaux pour des raisons de sécurité. Si viewer.html
   affiche une erreur de chargement, lance un mini-serveur local depuis le
   dossier dézippé :
       python3 -m http.server
   Puis ouvre http://localhost:8000/viewer.html dans ton navigateur.

Le viewer est 100 % statique : pas de connexion internet requise (sauf pour
les polices Google Fonts, qui dégradent gracieusement en système si offline).

Réimporter dans un compte Ocre Immo
-----------------------------------

Si tu as un compte Ocre Immo, connecte-toi puis utilise la fonction
« Importer un ZIP » dans le dashboard Admin (disponible dès la version
v18.33+). Les dossiers sont marqués avec le flag `imported_from_zip=true`
pour traçabilité, et les photos sont recopiées dans /uploads/ de ton compte.

Confidentialité
---------------

Ce ZIP contient des données personnelles (contacts, adresses, téléphones).
Partage-le uniquement avec des personnes de confiance.

---
Généré par Ocre Immo · https://app.ocre.immo
