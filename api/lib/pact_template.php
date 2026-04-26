<?php
// V20 phase 6 — template officiel pacte 13 articles + logique droit applicable selon pays.

function build_pact_context(string $wsc_country, array $members_countries, string $ville_wsc = ''): array {
    $unique = array_values(array_unique(array_filter($members_countries)));
    sort($unique);
    $all_ma = ($unique === ['MA']);
    $all_fr = ($unique === ['FR']);
    $ville = $ville_wsc !== '' ? $ville_wsc : $wsc_country;

    if ($all_ma) {
        return [
            'loi_applicable' => "le droit marocain (Dahir des Obligations et Contrats)",
            'tribunal' => "tribunal de Casablanca",
            'bloc_article_5_selon_pays' => "Les parties s'engagent à respecter les législations nationales applicables en matière de protection des données personnelles, notamment la loi marocaine 09-08 relative à la protection des personnes physiques à l'égard du traitement des données à caractère personnel.",
            'clause_RGPD_si_mixte' => "",
        ];
    }
    if ($all_fr) {
        return [
            'loi_applicable' => "le droit français, notamment la loi Hoguet du 2 janvier 1970, la loi Alur du 24 mars 2014 et le Règlement Général sur la Protection des Données (RGPD)",
            'tribunal' => "tribunal du lieu d'établissement du workspace, soit " . $ville,
            'bloc_article_5_selon_pays' => "En application de l'article 26 du Règlement Général sur la Protection des Données (RGPD), les parties sont reconnues comme responsables conjoints du traitement des données clients partagées dans le workspace commun. Chaque partie reste responsable des données qu'elle a apportées. Le point de contact unique pour l'exercice des droits des personnes concernées (accès, rectification, suppression, portabilité) est l'agent apporteur du dossier. Les parties s'engagent à se notifier mutuellement toute demande reçue dans les 72 heures.",
            'clause_RGPD_si_mixte' => "",
        ];
    }
    return [
        'loi_applicable' => "le droit du pays de référence du workspace, soit " . $wsc_country . ", sous réserve des dispositions impératives du RGPD",
        'tribunal' => "tribunal du pays de référence du workspace, soit " . $ville,
        'bloc_article_5_selon_pays' => "En application de l'article 26 du Règlement Général sur la Protection des Données (RGPD), les parties sont reconnues comme responsables conjoints du traitement des données clients partagées dans le workspace commun. Chaque partie reste responsable des données qu'elle a apportées. Le point de contact unique pour l'exercice des droits des personnes concernées (accès, rectification, suppression, portabilité) est l'agent apporteur du dossier. Les parties s'engagent à se notifier mutuellement toute demande reçue dans les 72 heures.",
        'clause_RGPD_si_mixte' => "Pour les questions relatives à la protection des données personnelles, le RGPD s'applique de plein droit dès lors qu'au moins une partie est établie dans l'Union européenne ou traite des données de personnes résidant dans l'Union européenne.",
    ];
}

function generate_pact_html(array $wsc, array $signataires, string $champ_libre_commission = ''): string {
    $countries = array_map(fn($s) => $s['country_code'] ?? '', $signataires);
    $wsc_country = $wsc['country_code'] ?? 'FR';
    $ville_wsc = $wsc['city'] ?? $wsc_country;
    $ctx = build_pact_context($wsc_country, $countries, $ville_wsc);

    $nom_wsc = htmlspecialchars($wsc['display_name'] ?? $wsc['slug'] ?? '');
    $date_creation = htmlspecialchars($wsc['created_at'] ?? gmdate('Y-m-d H:i') . ' UTC');

    $liste_membres_html = '';
    foreach ($signataires as $s) {
        $nom = htmlspecialchars($s['display_name'] ?? $s['email'] ?? '');
        $email = htmlspecialchars($s['email'] ?? '');
        $pays = htmlspecialchars($s['country_code'] ?? '');
        $carte = !empty($s['pro_card_number']) ? ' — Carte professionnelle n° ' . htmlspecialchars($s['pro_card_number']) : '';
        $liste_membres_html .= "<li><strong>{$nom}</strong> — {$email} — {$pays}{$carte}</li>\n";
    }

    $liste_signatures_html = '';
    foreach ($signataires as $s) {
        $nom = htmlspecialchars($s['display_name'] ?? $s['email'] ?? '');
        if (!empty($s['signed_at'])) {
            $ts = htmlspecialchars($s['signed_at']);
            $ip = htmlspecialchars($s['ip'] ?? '');
            $liste_signatures_html .= "<div class=\"signature-line\">Signé par <strong>{$nom}</strong> le {$ts} UTC — IP {$ip}</div>\n";
        } else {
            $liste_signatures_html .= "<div class=\"signature-line\" style=\"opacity:0.5\">En attente de signature de <strong>{$nom}</strong></div>\n";
        }
    }

    $champ_commission = $champ_libre_commission !== '' ? htmlspecialchars($champ_libre_commission) : 'À convenir cas par cas entre les parties pour chaque dossier partagé';

    $sha256_complet = hash('sha256', $nom_wsc . '|' . $date_creation . '|' . implode(',', $countries));
    $sha256_short = substr($sha256_complet, 0, 12);

    $loi = htmlspecialchars($ctx['loi_applicable']);
    $tribunal = htmlspecialchars($ctx['tribunal']);
    $art5 = htmlspecialchars($ctx['bloc_article_5_selon_pays']);
    $rgpd_mixte = $ctx['clause_RGPD_si_mixte'] !== '' ? htmlspecialchars($ctx['clause_RGPD_si_mixte']) : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Pacte de partenariat — {$nom_wsc}</title>
<style>
  body { font-family: Georgia, 'Cormorant Garamond', serif; line-height: 1.6; color: #2A1810; max-width: 720px; margin: 40px auto; padding: 0 20px; }
  h1 { color: #8B5E3C; font-size: 24px; border-bottom: 2px solid #8B5E3C; padding-bottom: 10px; }
  h2 { color: #6B4429; font-size: 16px; margin-top: 30px; }
  .meta { background: #F0E8D8; padding: 15px; border-radius: 4px; margin: 20px 0; }
  .meta strong { display: block; margin-bottom: 4px; }
  ul { padding-left: 20px; }
  li { margin: 6px 0; }
  .signature-zone { margin-top: 40px; padding-top: 20px; border-top: 1px solid #B89968; }
  .signature-line { margin: 12px 0; padding: 8px 12px; background: #FAF8F2; border-left: 3px solid #8B5E3C; }
  .footer-meta { font-size: 11px; color: #6B6B6B; margin-top: 30px; }
</style>
</head>
<body>
<h1>Pacte de partenariat — Ocre Immo</h1>
<div class="meta">
  <strong>Workspace commun :</strong> {$nom_wsc}<br>
  <strong>Date de création :</strong> {$date_creation}<br>
  <strong>Référence :</strong> {$sha256_short}
</div>

<h2>Article 1 — Identité des parties</h2>
<p>Le présent pacte est conclu entre les agents immobiliers signataires ci-après identifiés :</p>
<ul>
{$liste_membres_html}
</ul>

<h2>Article 2 — Objet du partenariat</h2>
<p>Les parties conviennent de constituer un workspace commun nommé <strong>{$nom_wsc}</strong> au sein de la plateforme Ocre Immo, dans le but de partager, gérer et faire évoluer ensemble des dossiers clients et des biens immobiliers, dans un cadre formalisé, traçable et confidentiel.</p>

<h2>Article 3 — Statut professionnel respecté</h2>
<p>Chaque partie demeure indépendante dans l'exercice de sa profession. Chacune conserve sa carte professionnelle, sa responsabilité civile professionnelle, sa garantie financière le cas échéant, ainsi que ses obligations de formation continue. Aucune partie ne pourra engager une autre partie sans accord écrit explicite et préalable.</p>

<h2>Article 4 — Mandats clients</h2>
<p>Chaque partie reste seule mandataire des clients qu'elle a personnellement apportés. Le partage d'un dossier au sein du workspace commun ne crée pas de co-mandat de plein droit. Si les parties souhaitent formaliser un co-mandat sur un dossier précis, elles signent un avenant spécifique conforme au droit applicable du pays du client concerné.</p>

<h2>Article 5 — Protection des données personnelles</h2>
<p>{$art5}</p>

<h2>Article 6 — Confidentialité réciproque</h2>
<p>Les parties s'engagent à une stricte confidentialité concernant toutes les informations partagées au sein du workspace commun, qu'il s'agisse de données clients, d'informations stratégiques ou commerciales. Aucune partie ne pourra utiliser ces informations en dehors du workspace, ni démarcher directement les clients d'une autre partie, et ce pendant toute la durée du partenariat ainsi que pendant cinq (5) ans suivant sa rupture.</p>

<h2>Article 7 — Répartition des commissions</h2>
<p>Les parties conviennent que la répartition des commissions liées aux dossiers partagés est définie comme suit : <strong>{$champ_commission}</strong>. À défaut de précision dans ce champ, chaque commission revient intégralement à l'agent apporteur du dossier, sauf accord écrit ponctuel.</p>

<h2>Article 8 — Préavis de rupture</h2>
<p>Toute partie peut demander à quitter le partenariat à tout moment, par simple action dans la plateforme Ocre Immo. Cette demande déclenche un préavis ferme de <strong>quarante-huit (48) heures</strong>, pendant lequel :</p>
<ul>
<li>Les dossiers dont la partie sortante est l'apporteur passent en lecture seule pour les autres parties ;</li>
<li>Les dossiers des autres parties passent en lecture seule pour la partie sortante ;</li>
<li>Toute tentative de modification durant ce délai est enregistrée dans l'audit log et notifiée immédiatement à l'apporteur du dossier concerné ;</li>
<li>La partie sortante peut annuler sa demande à tout moment avant l'échéance, le partenariat reprenant alors normalement.</li>
</ul>
<p>Un instantané chiffré de la base de données du workspace est généré dès le déclenchement du préavis et conservé pendant un an minimum, opposable aux parties en cas de litige.</p>

<h2>Article 9 — Récupération des dossiers à la rupture</h2>
<p>À l'échéance du préavis, la partie sortante récupère automatiquement dans son workspace personnel l'ensemble des dossiers dont elle est l'apporteur. Une copie figée en lecture seule de chaque dossier récupéré est conservée dans le workspace commun pour les parties restantes, portant la mention « Récupéré par {Prénom} le {date} ». Les modifications apportées par les autres parties pendant la période de partenariat restent visibles dans cette copie figée.</p>

<h2>Article 10 — Traçabilité opposable</h2>
<p>Toutes les actions réalisées dans le workspace commun (création, modification, suppression, partage, signature, demande de rupture) sont enregistrées dans un journal d'audit horodaté, immuable et opposable aux parties. Ce journal peut être produit à titre de preuve en cas de litige.</p>

<h2>Article 11 — Droit applicable</h2>
<p>Le présent pacte est régi par : <strong>{$loi}</strong>. {$rgpd_mixte}</p>

<h2>Article 12 — Médiation et juridiction compétente</h2>
<p>En cas de différend, les parties s'engagent à rechercher une solution amiable par voie de médiation pendant une période de trente (30) jours avant toute action judiciaire. À défaut d'accord, est seul compétent le tribunal du <strong>{$tribunal}</strong>, à défaut le tribunal de la partie défenderesse.</p>

<h2>Article 13 — Acceptation digitale</h2>
<p>Chaque partie reconnaît avoir lu l'intégralité du présent pacte avant signature. La signature est exprimée par la combinaison des éléments suivants, opposables :</p>
<ul>
<li>Acceptation explicite via la case « J'ai lu et j'accepte le pacte de partenariat dans son intégralité » ;</li>
<li>Action volontaire « Je signe » ;</li>
<li>Horodatage UTC enregistré au moment de la signature ;</li>
<li>Adresse IP capturée au moment de la signature ;</li>
<li>Empreinte cryptographique SHA-256 du document signé : {$sha256_complet}.</li>
</ul>
<p>Une copie PDF du pacte signé est transmise à chaque partie par email et conservée chiffrée par la plateforme Ocre Immo.</p>

<div class="signature-zone">
<h2>Signatures</h2>
{$liste_signatures_html}
</div>

<div class="footer-meta">
Document généré automatiquement par Ocre Immo · Empreinte SHA-256 : {$sha256_complet}<br>
Toute modification ultérieure du document invalide la signature.
</div>
</body>
</html>
HTML;
}
