<?php
// V17.12 / V17.14 — extraction structurée d'une note vocale.
// Claude Haiku si clé dispo, sinon fallback PHP heuristique (prénoms, villes, mots-clés, regex budget).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? '';
$input = getInput();

const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';

// V18.4 — prompt classification d'intention pour assistant vocal multi-actions.
const SYSTEM_PROMPT_V2 = <<<'PROMPT'
Tu es un assistant vocal pour un agent immobilier. Analyse la transcription FRANÇAIS et retourne UNIQUEMENT un JSON valide :
{
  "intent": "creer_dossier" | "creer_rdv" | "creer_todo" | "noter_interaction" | "recherche_dossier",
  "data": { ... selon l'intent ... }
}

Règles détection intent :
- "j'ai rencontré X", "nouveau client X", "X cherche Y", "X veut acheter/vendre/louer" → creer_dossier (data = champs dossier comme avant)
- "rappelle-moi de", "il faut que je", "ne pas oublier de", "tâche :" → creer_todo (data = {client_ref, title, due_relatif, priority})
- "rendez-vous avec X", "visite demain", "appeler X lundi", "RDV X", "programme un rendez-vous" → creer_rdv (data = {client_ref, type:'rdv'|'appel'|'visite'|'email', titre, when_relatif, location, notes, reminder_min_before})
- "appelé X aujourd'hui", "X m'a dit que", "visité le bien de X", "envoyé email à X" → noter_interaction (data = {client_ref, kind:'note'|'appel_entrant'|'appel_sortant'|'email_envoye'|'email_recu'|'visite'|'sms', content})
- "dossier de X", "où en est X", "trouve X" → recherche_dossier (data = {client_ref})

Pour creer_rdv / creer_todo / noter_interaction :
- "client_ref" = nom OU prénom mentionné (ex: "Marc", "Sophie Dupont", "M. Belkhayat")
- "when_relatif" / "due_relatif" : transforme en datetime ISO (YYYY-MM-DDTHH:MM:00) en utilisant la date_ref_iso fournie. Ex: "demain 15h" + date_ref=2026-04-23 → "2026-04-24T15:00:00". "lundi prochain 10h" → calcule le lundi suivant.
- priority : "urgent"/"vite" → high ; "quand j'aurai le temps" → low ; sinon medium
- reminder_min_before : défaut 60. "1h avant" → 60. "30 min avant" → 30. "1 jour avant" → 1440.

Pour creer_dossier, utilise le schéma enrichi V18.6 : {prenom,nom,societe_nom,profil_type,profil,types_bien,pays_bien,ville_bien,quartier_bien,usage_bien,adresse_rue,code_postal,ville_residence,pays_residence,nationalite,budget_min,budget_max,devise,tel,email,notes_libres}.

Règles V18.6 : adresse_rue ("18 rue X"), code_postal (5 chiffres), ville_residence (où il vit, distinct de ville_bien où il cherche), usage_bien (airbnb/investissement_locatif/residence_principale/residence_secondaire/saisonnier), profil_type=Société dès qu'une raison sociale est mentionnée. Si "Airbnb" → profil="Investisseur" + usage_bien="airbnb".

Tolérance fautes reconnaissance vocale (Maroc/France) :
- 'Arade'/'arabe' contexte MA → Riad
- 'Régali'/'régalie' → Gueliz
- 'Marakèche'/'Maraqèche' → Marrakech
- 'monsieur X'/'madame Y' → nom de famille
- Numéros FR → +33XXXXXXXXX, MA → +212XXXXXXXXX

EXEMPLES :
Input: "Rappelle-moi d'appeler Marc demain"
Output: {"intent":"creer_todo","data":{"client_ref":"Marc","title":"Appeler Marc","due_relatif":"demain","priority":"medium"}}

Input: "Visite du riad avec Sophie Dupont lundi 15h"
Output: {"intent":"creer_rdv","data":{"client_ref":"Sophie Dupont","type":"visite","titre":"Visite du riad","when_relatif":"lundi 15h","reminder_min_before":60}}

Input: "Appelé Ahmed ce matin il confirme la visite de jeudi"
Output: {"intent":"noter_interaction","data":{"client_ref":"Ahmed","kind":"appel_sortant","content":"Confirme la visite de jeudi"}}

Input: "Marc Belkhayat cherche un riad à Marrakech Gueliz 2 millions de dirhams"
Output: {"intent":"creer_dossier","data":{"prenom":"Marc","nom":"Belkhayat","profil":"Acheteur","types_bien":["Riad"],"pays_bien":"MA","ville_bien":"Marrakech","quartier_bien":"Gueliz","budget_max":2000000,"devise":"MAD"}}
PROMPT;

const SYSTEM_PROMPT = <<<'PROMPT'
Tu extrais des informations structurées d'une note vocale d'un agent immobilier (français). Tu retournes UNIQUEMENT un JSON valide, sans texte autour, sans markdown.

Règles :
- Un prénom seul (Marc, Sophie, Ahmed) → prenom
- Prénom + Nom (Marc Dupont) → prenom + nom
- 'monsieur X' / 'madame Y' → nom=X/Y
- 'cherche'/'veut acheter'/'recherche' → profil=Acheteur
- 'vend'/'met en vente' → profil=Vendeur
- 'veut louer' (côté locataire) → profil=Locataire
- 'loue'/'propose à la location' → profil=Bailleur
- 'investit'/'investisseur' → profil=Investisseur
- Villes MA (Marrakech Casablanca Rabat Tanger Agadir Fès Essaouira) → pays_bien=MA
- Villes FR (Paris Lyon Nantes Bordeaux Marseille Nice Toulouse) → pays_bien=FR
- '500k'/'500 000'/'500.000' → 500000 (nombre pur)
- 'euros'/'€' → devise=EUR ; 'dirhams'/'MAD'/'DH' → devise=MAD
- Quartiers Marrakech (Palmeraie Hivernage Gueliz Médina Prestigia Ourika Targa Annakhil) → quartier_bien
- 'villa'/'maison' → types_bien=[Villa]/[Maison] ; 'riad'→[Riad] ; 'appartement'→[Appartement] ; 'terrain'→[Terrain]

Schéma JSON (null si non mentionné) :
{prenom,nom,societe_nom,profil_type,profil,types_bien,pays_bien,ville_bien,quartier_bien,usage_bien,adresse_rue,code_postal,ville_residence,pays_residence,nationalite,budget_min,budget_max,devise,tel,email,notes_libres}

Règles V18.6 nouveaux champs :
- "numéro X rue/avenue/boulevard Y", "habite au X rue Y" → adresse_rue ("18 rue Eugénie Cotton")
- "44800", "75015", "13001" — code postal FR 5 chiffres ou MA 5 chiffres → code_postal
- "vit à Saint-Herblain", "habite à Nantes" — distingue ville_residence (où il vit) de ville_bien (où il cherche le bien)
- "Airbnb"/"location courte durée"/"saisonnière" → usage_bien="airbnb"
- "investissement locatif"/"pour louer"/"rendement"/"investir" → usage_bien="investissement_locatif"
- "résidence principale"/"y habiter"/"pour habiter" → usage_bien="residence_principale"
- "résidence secondaire"/"vacances" → usage_bien="residence_secondaire"
- Si "société X conciergerie/immobilière/SARL/SAS"/"sa société X" → societe_nom="X" (avec son nom complet, ex "Sophie Conciergerie") + profil_type="Société". Le profil reste celui détecté (Investisseur si Airbnb).
- "investit dans"/"acquérir pour louer" → profil="Investisseur" + usage_bien="investissement_locatif"

IMPORTANT — TOLÉRANCE FAUTES RECONNAISSANCE VOCALE :
La dictée passe par une reco vocale Safari iPad imparfaite. Corrige intelligemment selon le contexte immobilier Maroc/France :
- 'Arade'/'arabe'/'arad'/'arrad'/'ariad'/'a rad' (contexte Maroc) → Riad
- 'Régali'/'régalie'/'regaly'/'régaly'/'régale' → Gueliz (quartier Marrakech)
- 'Agdal'/'Agdale' → Agdal (Rabat)
- 'Palmerai'/'palmerée'/'palmraie' → Palmeraie
- 'Hyvernage'/'ivernage' → Hivernage
- 'Medina'/'médine' → Médina
- 'Marakèche'/'Marrakèche'/'Maraqèche'/'Marakech' → Marrakech
- 'Essaouira'/'sauveur'/'sauira' (contexte MA) → Essaouira
- 'Yvie'/'y vit'/'hive'/'il live' → 'il vit'
- 'Casa blanca'/'casablanca'/'casa' (contexte MA seul) → Casablanca
- 'Beaune hacker'/'bonaker'/'bonne hacker' → Bon Aker / quartier MA
- 'Prestigia'/'prestigial'/'prestigias' → Prestigia
- 'madame X' / 'monsieur Y' / 'mister Z' / 'mrs W' → nom=X/Y/Z/W
- Si un mot semble incohérent mais qu'un terme phonétiquement proche fait sens dans l'immobilier Maroc/France, corrige.
- Utilise ton jugement contextuel : 'cherche une Arade à Marrakech à rénover' → types_bien=[Riad], pays_bien=MA, notes="à rénover"
- Normalise les numéros FR en format E.164 : '06 88 28 48 77' → '+33688284877', MA : '06 12 34 56 78' sans préfixe dans contexte MA → '+212612345678'.

EXEMPLE :
Input: 'Marc cherche une villa à Marrakech route d'Ourika budget 500000 euros vit en France'
Output: {"prenom":"Marc","nom":null,"profil":"Acheteur","types_bien":["Villa"],"pays_bien":"MA","ville_bien":"Marrakech","quartier_bien":"Ourika","budget_max":500000,"devise":"EUR","pays_residence":"FR"}

EXEMPLE 2 (fautes reco) :
Input: 'Ahmed cherche une arade dans le Régali à Marakèche 2 millions de dirhams numéro 06 12 34 56 78'
Output: {"prenom":"Ahmed","nom":null,"profil":"Acheteur","types_bien":["Riad"],"pays_bien":"MA","ville_bien":"Marrakech","quartier_bien":"Gueliz","budget_max":2000000,"devise":"MAD","tel":"+212612345678"}

EXEMPLE 3 (V18.6 — investisseur Société + Airbnb + adresse complète) :
Input: 'Sophie qui vit à Nantes au 18 rue Eugénie Cotton 44800 Saint-Herblain, qui a 500 000 euros de budget pour acheter un Riad ou une villa à Marrakech pour faire de l\'Airbnb, et ils ont une société de conciergerie Sophie conciergerie'
Output: {"prenom":"Sophie","societe_nom":"Sophie Conciergerie","profil_type":"Société","profil":"Investisseur","types_bien":["Riad","Villa"],"pays_bien":"MA","ville_bien":"Marrakech","usage_bien":"airbnb","adresse_rue":"18 rue Eugénie Cotton","code_postal":"44800","ville_residence":"Saint-Herblain","pays_residence":"FR","budget_max":500000,"devise":"EUR"}
PROMPT;

// V17.14 : listes embedded pour fallback heuristique.
const PRENOMS_FR_TOP = [
    'Marc','Jean','Pierre','Paul','Jacques','Michel','Philippe','André','Louis','Thomas',
    'François','Nicolas','Daniel','Alain','Christophe','Olivier','Julien','Laurent','Vincent','Antoine',
    'Mathieu','Guillaume','Sébastien','Stéphane','David','Alexandre','Bernard','Patrick','Éric','Hugo',
    'Maxime','Arthur','Lucas','Théo','Léo','Enzo','Clément','Nathan','Romain','Adrien',
    'Gabriel','Mathis','Ethan','Sacha','Raphaël','Noah','Jules','Timothée','Martin','Florian',
    'Marie','Sophie','Julie','Anne','Catherine','Isabelle','Laurence','Sylvie','Nathalie','Valérie',
    'Sandrine','Céline','Hélène','Véronique','Christine','Françoise','Émilie','Aurélie','Camille','Pauline',
    'Claire','Léa','Chloé','Manon','Sarah','Océane','Charlotte','Lucie','Louise','Emma',
    'Zoé','Jade','Lola','Inès','Juliette','Alice','Éléonore','Margaux','Clémence','Laura',
    'Mélanie','Caroline','Aurore','Delphine','Maud','Magali','Sabrina','Amandine','Élodie','Virginie',
    'Baptiste','Quentin','Benjamin','Simon','Léon','Samuel','Matthieu','Yann','Loïc','Damien',
];
const PRENOMS_MA_TOP = [
    'Ahmed','Mohamed','Mohammed','Youssef','Karim','Hicham','Mehdi','Omar','Rachid','Abdellatif',
    'Abdelaziz','Brahim','Khalid','Said','Mustapha','Hassan','Hamza','Anas','Othmane','Zakaria',
    'Amine','Ayoub','Bilal','Driss','Fahd','Fouad','Ilyas','Ismail','Jamal','Malik',
    'Nabil','Nizar','Reda','Saad','Tarik','Walid','Yahya','Yassine','Fatima','Zineb',
    'Aicha','Khadija','Salma','Sara','Meryem','Hanane','Ikram','Imane','Kenza','Latifa',
    'Laila','Najat','Nawal','Nadia','Rim','Soukaina','Siham','Sophia','Yasmina','Zahra',
    'Amira','Sofia','Wissal','Houda','Saida','Malika','Naima','Hind','Samira','Mouna',
    'Karima','Soumia','Dounia','Oumaima','Rania','Rabia','Amina','Mariam','Noor','Nour',
];
const VILLES_MA = [
    'Marrakech','Marrakesh','Casablanca','Rabat','Tanger','Agadir','Fès','Fez','Essaouira',
    'Meknès','Oujda','Tétouan','Kenitra','Safi','El Jadida','Nador','Larache','Ouarzazate',
    'Chefchaouen','Ifrane','Béni Mellal','Mohammedia','Dakhla','Laâyoune',
];
const VILLES_FR = [
    'Paris','Lyon','Marseille','Toulouse','Nice','Nantes','Bordeaux','Lille','Strasbourg','Montpellier',
    'Rennes','Reims','Le Havre','Saint-Étienne','Toulon','Grenoble','Dijon','Angers','Nîmes','Villeurbanne',
    'Clermont-Ferrand','Le Mans','Aix-en-Provence','Brest','Tours','Amiens','Limoges','Annecy','Perpignan','Metz',
    'Besançon','Orléans','Mulhouse','Rouen','Caen','Nancy','Saint-Denis','Argenteuil','Poitiers','Versailles',
    'Chamonix','Biarritz','Bayonne','Pau','Cannes','Antibes','Avignon','Cassis','Saint-Tropez','Deauville',
];
const QUARTIERS_MARRAKECH = [
    'Médina','Gueliz','Hivernage','Palmeraie','Prestigia','Bab Taghzout','Annakhil',
    'Targa','Semlalia','Amerchich','Route de Fès','Route de Casablanca','Route d\'Ourika','Ourika',
];
const TYPE_KEYWORDS = [
    'villa' => 'Villa',
    'appartement' => 'Appartement',
    'appart' => 'Appartement',
    'riad' => 'Riad',
    'maison' => 'Maison',
    'terrain' => 'Terrain',
    'commerce' => 'Commerce',
    'ferme' => 'Ferme',
    'bureau' => 'Bureau / plateau',
    'plateau' => 'Bureau / plateau',
];

function getAnthropicKey() {
    $k = getSetting('anthropic_api_key', '');
    if (!$k) $k = getenv('ANTHROPIC_API_KEY') ?: '';
    if (!$k) {
        $f = '/root/.secrets/anthropic_api_key';
        if (is_readable($f)) $k = trim((string)@file_get_contents($f));
    }
    return $k ?: null;
}

function callClaude($transcript) {
    $key = getAnthropicKey();
    if (!$key) return ['error' => 'no_key'];
    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 800,
        'system' => SYSTEM_PROMPT,
        'messages' => [
            ['role' => 'user', 'content' => $transcript],
        ],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'curl: ' . $err];
    if ($code >= 400) return ['error' => 'http ' . $code . ': ' . substr($resp, 0, 200)];
    $j = json_decode($resp, true);
    if (!$j || empty($j['content'])) return ['error' => 'no_content'];
    $text = '';
    foreach ($j['content'] as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $extracted = json_decode($m[0], true);
        if (is_array($extracted)) return ['extracted' => $extracted, 'raw' => $text];
    }
    return ['error' => 'parse_failed', 'raw' => $text];
}

// V17.14 : fallback heuristique PHP pur si pas de clé ou Claude KO.
function heuristicExtract($transcript) {
    $out = [
        'prenom' => null, 'nom' => null, 'societe_nom' => null, 'profil' => null,
        'types_bien' => null, 'pays_bien' => null, 'ville_bien' => null, 'quartier_bien' => null,
        'budget_min' => null, 'budget_max' => null, 'devise' => null,
        'pays_residence' => null, 'tel' => null, 'email' => null, 'notes_libres' => null,
    ];
    $t = $transcript;
    $lower = mb_strtolower($t);

    // Profil — élargi V17.15
    if (preg_match('/\bveut\s+louer\b|\bcherche\s+(à|a)\s+louer\b/iu', $t)) $out['profil'] = 'Locataire';
    elseif (preg_match('/\binvesti(sseur|t|r|ssement)\b|investissement\s+locatif/iu', $t)) $out['profil'] = 'Investisseur';
    elseif (preg_match('/\b(cherche|veut\s+acheter|recherche|acqu(é|e)rir|(à|a)\s+la\s+recherche|(à|a)\s+acheter)\b/iu', $t)) $out['profil'] = 'Acheteur';
    elseif (preg_match('/\b(vend|(à|a)\s+vendre|met(\s+en)?\s+vente|vendre|souhaite\s+vendre)\b/iu', $t)) $out['profil'] = 'Vendeur';
    elseif (preg_match('/\b(loue|propose\s+(à|a|en)\s+(la\s+)?location|met(\s+en)?\s+location)\b/iu', $t)) $out['profil'] = 'Bailleur';

    // Prénom : premier mot capitalisé qui matche une liste connue.
    $all_prenoms = array_merge(PRENOMS_FR_TOP, PRENOMS_MA_TOP);
    $prenom_set = array_flip(array_map('mb_strtolower', $all_prenoms));
    if (preg_match_all('/\b([A-ZÀ-Ý][a-zà-ÿ]+)\b/u', $t, $mm)) {
        $words = $mm[1];
        foreach ($words as $i => $w) {
            if (isset($prenom_set[mb_strtolower($w)])) {
                $out['prenom'] = $w;
                // Mot suivant capitalisé = nom potentiel (heuristique simple)
                if (isset($words[$i + 1]) && !isset($prenom_set[mb_strtolower($words[$i + 1])])) {
                    $out['nom'] = $words[$i + 1];
                }
                break;
            }
        }
    }
    // 'monsieur X' / 'madame Y' → nom de famille
    if (preg_match('/\bmonsieur\s+([A-ZÀ-Ý][a-zà-ÿ]+)/iu', $t, $mm)) { $out['nom'] = $mm[1]; }
    elseif (preg_match('/\bmadame\s+([A-ZÀ-Ý][a-zà-ÿ]+)/iu', $t, $mm)) { $out['nom'] = $mm[1]; }

    // Villes + pays_bien
    foreach (VILLES_MA as $v) {
        if (mb_stripos($t, $v) !== false) { $out['ville_bien'] = $v; $out['pays_bien'] = 'MA'; break; }
    }
    if (!$out['ville_bien']) {
        foreach (VILLES_FR as $v) {
            if (mb_stripos($t, $v) !== false) { $out['ville_bien'] = $v; $out['pays_bien'] = 'FR'; break; }
        }
    }
    // Quartiers Marrakech
    foreach (QUARTIERS_MARRAKECH as $q) {
        if (mb_stripos($t, $q) !== false) { $out['quartier_bien'] = $q; break; }
    }
    // Pays résidence : "vit en France" / "vit au Maroc"
    if (preg_match('/\bvit\s+(?:en|au|aux|à)\s+(France|Maroc|Espagne|Belgique|Suisse)/iu', $t, $mm)) {
        $map = ['France' => 'FR', 'Maroc' => 'MA', 'Espagne' => 'ES', 'Belgique' => 'BE', 'Suisse' => 'CH'];
        $out['pays_residence'] = $map[ucfirst(mb_strtolower($mm[1]))] ?? null;
    }
    // Types bien
    $types = [];
    foreach (TYPE_KEYWORDS as $kw => $t_label) {
        if (mb_stripos($lower, $kw) !== false && !in_array($t_label, $types, true)) $types[] = $t_label;
    }
    if ($types) $out['types_bien'] = $types;

    // Budget — V17.15 : gère "500k", "500 000", "500.000", "1,8M", "1 million et demi", "1 200 000 MAD"
    // "un million et demi" / "un million cinq cents mille"
    if (preg_match('/\bun\s+million\s+et\s+demi\b/iu', $t)) {
        $out['budget_max'] = 1500000;
    } elseif (preg_match('/\b(deux|trois|quatre|cinq|six|sept|huit|neuf|dix)\s+millions?\b/iu', $t, $mm)) {
        $map = ['deux'=>2,'trois'=>3,'quatre'=>4,'cinq'=>5,'six'=>6,'sept'=>7,'huit'=>8,'neuf'=>9,'dix'=>10];
        $out['budget_max'] = ($map[mb_strtolower($mm[1])] ?? 1) * 1000000;
    } elseif (preg_match('/(\d+(?:[.,]\d+)?)\s*(k|M|million|millions)\b/iu', $t, $mm)) {
        $n = (float)str_replace(',', '.', $mm[1]);
        $mult = strtolower(substr($mm[2], 0, 1));
        if ($mult === 'k') $n *= 1000;
        else if ($mult === 'm') $n *= 1000000;
        $out['budget_max'] = (int)$n;
    } elseif (preg_match('/(\d[\d\s\.]{3,}\d)\s*(euros?|€|dirhams?|MAD|DH|DHs)/iu', $t, $mm)) {
        $raw = preg_replace('/[^\d]/', '', $mm[1]);
        if ($raw) $out['budget_max'] = (int)$raw;
    } elseif (preg_match('/\bbudget\s+(\d[\d\s\.]{2,}\d)/iu', $t, $mm)) {
        $raw = preg_replace('/[^\d]/', '', $mm[1]);
        if ($raw) $out['budget_max'] = (int)$raw;
    }
    // Devise
    if (preg_match('/\b(euros?|€|EUR)\b/iu', $t)) $out['devise'] = 'EUR';
    elseif (preg_match('/\b(dirhams?|MAD|DH|DHs)\b/iu', $t)) $out['devise'] = 'MAD';

    // Tel — V17.15 : normalisation FR/MA en E.164.
    // FR : 06 88 28 48 77 → +33688284877
    if (preg_match('/(?:(?:\+|00)33[\s.-]?|0)\s*([1-9])(?:[\s.-]*(\d{2})){4}/u', $t, $mm)) {
        // Reconstruit les 10 chiffres
        preg_match_all('/\d/', $mm[0], $dg);
        $digits = implode('', $dg[0]);
        // Si commence par 33, enlève-le
        if (strpos($digits, '33') === 0 && strlen($digits) >= 11) $digits = substr($digits, 2);
        if (strlen($digits) >= 10 && $digits[0] === '0') $digits = substr($digits, 1);
        if (strlen($digits) === 9 && in_array($digits[0], ['1','2','3','4','5','6','7','8','9'])) {
            $out['tel'] = '+33' . $digits;
        }
    }
    // MA : +212 6XX XX XX XX ou 06XX XX XX XX avec pattern MA
    if (!$out['tel'] && preg_match('/(?:(?:\+|00)212[\s.-]?|0)\s*([5-7])(?:[\s.-]*\d){8}/u', $t, $mm)) {
        preg_match_all('/\d/', $mm[0], $dg);
        $digits = implode('', $dg[0]);
        if (strpos($digits, '212') === 0 && strlen($digits) >= 12) $digits = substr($digits, 3);
        if (strlen($digits) >= 10 && $digits[0] === '0') $digits = substr($digits, 1);
        if (strlen($digits) === 9) {
            $out['tel'] = '+212' . $digits;
        }
    }
    // Fallback international brut.
    if (!$out['tel'] && preg_match('/\+\d{1,3}[\s.\-]?\d[\d\s.\-]{6,}/', $t, $mm)) {
        $out['tel'] = preg_replace('/[^\d+]/', '', $mm[0]);
    }
    // Email
    if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $t, $mm)) $out['email'] = $mm[0];

    // Compter combien de champs ont été extraits — si <2, autant retourner null
    $filled = 0;
    foreach ($out as $v) if ($v !== null && $v !== [] && $v !== '') $filled++;
    if ($filled === 0) return null;
    return $out;
}

// V18.4 — variante extract avec classification intent.
function callClaudeIntent($transcript, $date_ref_iso) {
    $key = getAnthropicKey();
    if (!$key) return ['error' => 'no_key'];
    $userMsg = "Date de référence : $date_ref_iso\n\nTranscription :\n$transcript";
    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 800,
        'system' => SYSTEM_PROMPT_V2,
        'messages' => [['role' => 'user', 'content' => $userMsg]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'curl: ' . $err];
    if ($code >= 400) return ['error' => 'http ' . $code];
    $j = json_decode($resp, true);
    if (!$j || empty($j['content'])) return ['error' => 'no_content'];
    $text = '';
    foreach ($j['content'] as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed) && isset($parsed['intent'])) return ['parsed' => $parsed];
    }
    return ['error' => 'parse_failed', 'raw' => $text];
}

switch ($action) {
    case 'extract_v2': {
        $transcript = trim((string)($input['transcript'] ?? ''));
        if (!$transcript) jsonError('transcript requis');
        if (mb_strlen($transcript) > 4000) $transcript = mb_substr($transcript, 0, 4000);
        $date_ref = (string)($input['date_ref_iso'] ?? date('c'));
        $r = callClaudeIntent($transcript, $date_ref);
        if (isset($r['parsed'])) {
            jsonOk(['intent' => $r['parsed']['intent'], 'data' => $r['parsed']['data'] ?? [], 'transcript' => $transcript, 'mode' => 'ai']);
        }
        // Fallback heuristique : si phrase contient "rappelle", "rendez-vous", "appelé"… on devine intent.
        $low = mb_strtolower($transcript);
        $intent = 'creer_dossier';
        $data = [];
        if (preg_match('/\b(rappelle|tâche|à\s+faire|n\'oublie\s+pas)\b/u', $low)) {
            $intent = 'creer_todo';
            $data = ['title' => $transcript];
        } elseif (preg_match('/\b(rendez-vous|rdv|visite|appeler)\b/u', $low)) {
            $intent = 'creer_rdv';
            $data = ['titre' => $transcript, 'type' => preg_match('/visite/', $low) ? 'visite' : (preg_match('/appel/', $low) ? 'appel' : 'rdv')];
        } elseif (preg_match('/\b(appelé|envoyé|m\'a\s+dit)\b/u', $low)) {
            $intent = 'noter_interaction';
            $data = ['kind' => preg_match('/appel/', $low) ? 'appel_sortant' : 'note', 'content' => $transcript];
        } else {
            // Heuristique dossier classique
            $heur = heuristicExtract($transcript);
            if ($heur) { $data = $heur; }
        }
        jsonOk(['intent' => $intent, 'data' => $data, 'transcript' => $transcript, 'mode' => 'heuristic', 'ai_error' => $r['error'] ?? 'no_key']);
    }

    case 'extract': {
        $transcript = trim((string)($input['transcript'] ?? ''));
        if (!$transcript) jsonError('transcript requis');
        if (mb_strlen($transcript) > 4000) $transcript = mb_substr($transcript, 0, 4000);
        $r = callClaude($transcript);
        if (isset($r['extracted'])) {
            jsonOk(['extracted' => $r['extracted'], 'transcript' => $transcript, 'mode' => 'ai']);
        }
        // V17.14 : fallback heuristique PHP au lieu du raw_text brut.
        $heur = heuristicExtract($transcript);
        if ($heur) {
            jsonOk([
                'extracted' => $heur,
                'transcript' => $transcript,
                'mode' => 'heuristic',
                'ai_error' => $r['error'] ?? 'no_key',
            ]);
        }
        // Ultime fallback : transcript brut
        jsonOk([
            'extracted' => null,
            'raw_text' => $transcript,
            'transcript' => $transcript,
            'mode' => 'raw',
            'error' => $r['error'] ?? 'extract_failed',
        ]);
    }

    case 'has_key': {
        jsonOk(['has_key' => (bool)getAnthropicKey()]);
    }

    case 'test_key': {
        // Admin : teste la validité d'une clé donnée sans la sauvegarder.
        requireAdmin();
        $test_key = trim((string)($input['api_key'] ?? ''));
        if (!$test_key) jsonError('api_key requise');
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => CLAUDE_MODEL, 'max_tokens' => 1,
                'messages' => [['role' => 'user', 'content' => 'Hi']],
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $test_key,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $valid = $code >= 200 && $code < 300;
        jsonOk(['valid' => $valid, 'http_code' => $code, 'response_snippet' => substr((string)$resp, 0, 200)]);
    }

    default:
        jsonError('Action inconnue', 404);
}
