<?php
// M105 — Helper i18n + conversion devise pour drivers internationaux Idealista + Apartments.com.
// Pas de vraie traduction IA : mapping de termes-cles courants pour titres/types.
// Conversion devise via taux statiques (mise a jour manuelle ou via DualCurrencyPair existant M73-M75).

class ChannelI18n {
    // Mapping termes-cles immo trilingue (fr/en/es).
    // Utilise pour traduire titres + features. Description longue : retourne original + flag warning.
    private const KEYWORDS = [
        // chambres
        'chambre' => ['en' => 'bedroom', 'es' => 'dormitorio'],
        'chambres' => ['en' => 'bedrooms', 'es' => 'dormitorios'],
        'salle de bain' => ['en' => 'bathroom', 'es' => 'baño'],
        'salle de bains' => ['en' => 'bathrooms', 'es' => 'baños'],
        'sdb' => ['en' => 'bath', 'es' => 'baño'],
        // pieces
        'pièces' => ['en' => 'rooms', 'es' => 'habitaciones'],
        'pieces' => ['en' => 'rooms', 'es' => 'habitaciones'],
        'studio' => ['en' => 'studio', 'es' => 'estudio'],
        // exterieur
        'jardin' => ['en' => 'garden', 'es' => 'jardín'],
        'terrasse' => ['en' => 'terrace', 'es' => 'terraza'],
        'balcon' => ['en' => 'balcony', 'es' => 'balcón'],
        'piscine' => ['en' => 'pool', 'es' => 'piscina'],
        'parking' => ['en' => 'parking', 'es' => 'parking'],
        'garage' => ['en' => 'garage', 'es' => 'garaje'],
        'cave' => ['en' => 'cellar', 'es' => 'bodega'],
        'ascenseur' => ['en' => 'elevator', 'es' => 'ascensor'],
        'climatisation' => ['en' => 'air conditioning', 'es' => 'aire acondicionado'],
        'chauffage' => ['en' => 'heating', 'es' => 'calefacción'],
        // type bien
        'appartement' => ['en' => 'apartment', 'es' => 'piso'],
        'maison' => ['en' => 'house', 'es' => 'casa'],
        'villa' => ['en' => 'villa', 'es' => 'chalet'],
        'riad' => ['en' => 'riad (traditional moroccan house)', 'es' => 'riad (casa tradicional marroquí)'],
        'duplex' => ['en' => 'duplex', 'es' => 'dúplex'],
        'loft' => ['en' => 'loft', 'es' => 'loft'],
        // location
        'centre-ville' => ['en' => 'city center', 'es' => 'centro ciudad'],
        'medina' => ['en' => 'medina (old town)', 'es' => 'medina (casco antiguo)'],
        // surface
        'm²' => ['en' => 'sqm', 'es' => 'm²'],
        'm2' => ['en' => 'sqm', 'es' => 'm²'],
        // transactions
        'à vendre' => ['en' => 'for sale', 'es' => 'en venta'],
        'à louer' => ['en' => 'for rent', 'es' => 'en alquiler'],
        'vendeur' => ['en' => 'sale', 'es' => 'venta'],
        'bailleur' => ['en' => 'rent', 'es' => 'alquiler'],
    ];

    // Type bien (ocre) → enum portail Idealista.
    private const PROPERTY_TYPE_IDEALISTA = [
        'apartment' => 'piso',
        'house' => 'casa',
        'villa' => 'chalet',
        'riad' => 'casa',
        'duplex' => 'duplex',
        'loft' => 'piso',
        'studio' => 'estudio',
        'land' => 'terreno',
        'parking' => 'garaje',
        'cellar' => 'trastero',
        'office' => 'oficina',
        'commercial' => 'local',
        'warehouse' => 'nave',
    ];

    // Type bien (ocre) → enum apartments.com.
    private const PROPERTY_TYPE_APARTMENTS = [
        'apartment' => 'apartment',
        'house' => 'single-family',
        'villa' => 'single-family',
        'riad' => 'single-family',
        'duplex' => 'condo',
        'loft' => 'loft',
        'studio' => 'studio',
        'townhouse' => 'townhouse',
    ];

    // Taux de change statique (a rafraichir quotidiennement en V2 via cron + API ECB/Fixer).
    // Source : valeurs proches du marche reel mai 2026.
    private const RATES_TO_EUR = [
        'EUR' => 1.0,
        'USD' => 0.92,
        'GBP' => 1.17,
        'CHF' => 1.05,
        'CAD' => 0.68,
        'MAD' => 0.094,    // Dirham Marocain
        'TND' => 0.30,     // Dinar Tunisien
        'DZD' => 0.0070,   // Dinar Algerien
        'AED' => 0.25,     // Dirham UAE
        'XOF' => 0.0015,
        'SAR' => 0.245,
        'JPY' => 0.0061,
    ];

    // Traduit un texte court (titre, feature) en remplacant les termes-cles.
    // Pour textes longs : retourne ['translated' => texte_modifie, 'partial' => true|false].
    public static function translateField(string $text, string $from, string $to): array {
        if ($from === $to || empty($text)) {
            return ['translated' => $text, 'partial' => false];
        }
        // Si on traduit DEPUIS le francais (ce qu'on fait quasi-tjs vu qu'Ocre cible francophone)
        // on fait un str_ireplace des keywords ; sinon retourne tel quel + flag partial.
        if ($from !== 'fr') {
            return ['translated' => $text, 'partial' => true];
        }
        if (!in_array($to, ['en', 'es'])) {
            return ['translated' => $text, 'partial' => true];
        }
        $out = $text;
        $found = 0;
        foreach (self::KEYWORDS as $fr => $tr) {
            if (!isset($tr[$to])) continue;
            // Word-boundary case-insensitive replace
            $count = 0;
            $out = preg_replace('/\b' . preg_quote($fr, '/') . '\b/iu', $tr[$to], $out, -1, $count);
            $found += $count;
        }
        // Heuristique : si tres peu de termes traduits sur un long texte, partial=true
        $words = max(1, str_word_count($text));
        $coverage = $found / $words;
        $partial = $coverage < 0.10;
        return ['translated' => $out, 'partial' => $partial, 'keywords_replaced' => $found];
    }

    public static function mapPropertyType(string $ocreType, string $channel): string {
        $key = strtolower($ocreType);
        if ($channel === 'idealista') {
            return self::PROPERTY_TYPE_IDEALISTA[$key] ?? 'piso';
        }
        if ($channel === 'apartments_com') {
            return self::PROPERTY_TYPE_APARTMENTS[$key] ?? 'apartment';
        }
        return $ocreType;
    }

    public static function convertCurrency(float $amount, string $from, string $to): array {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return ['amount' => round($amount, 2), 'rate' => 1.0, 'converted' => false];
        }
        if (!isset(self::RATES_TO_EUR[$from]) || !isset(self::RATES_TO_EUR[$to])) {
            return ['amount' => round($amount, 2), 'rate' => 1.0, 'converted' => false, 'warning' => 'rate_unavailable'];
        }
        // EUR pivot
        $inEur = $amount * self::RATES_TO_EUR[$from];
        $converted = $inEur / self::RATES_TO_EUR[$to];
        $rate = self::RATES_TO_EUR[$from] / self::RATES_TO_EUR[$to];
        return [
            'amount' => round($converted, 2),
            'rate' => round($rate, 6),
            'converted' => true,
            'from' => $from,
            'to' => $to,
            'original' => $amount,
        ];
    }
}
