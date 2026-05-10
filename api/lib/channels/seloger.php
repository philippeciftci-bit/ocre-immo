<?php
// M104 — Driver SeLoger (mode STUB).
// Vrai SeLoger : push XML feed via FTP/SFTP. En mode stub, on ecrit le XML
// dans /tmp/mock-seloger-feed/ + on simule le retour external_id.
// Migration prod : remplacer le file_put_contents par sftp_upload($host, $user, $pass).

require_once __DIR__ . '/ChannelDriver.php';

class SeLogerDriver implements ChannelDriver {
    private const MOCK_FEED_DIR = '/tmp/mock-seloger-feed';

    public function getName(): string { return 'seloger'; }

    public function getRequiredFields(): array {
        return ['title', 'description', 'price', 'surface_m2', 'photos', 'transaction_type', 'location'];
    }

    public function getMaxLengths(): array {
        return ['title' => 80, 'description' => 5000];
    }

    public function validateListing(array $listing): array {
        $missing = [];
        $warnings = [];
        if (empty($listing['title'])) $missing[] = 'title';
        $desc = trim($listing['description'] ?? '');
        if (mb_strlen($desc) < 100) $missing[] = 'description (100 chars min, ' . mb_strlen($desc) . ' actuels)';
        if (empty($listing['surface_m2'])) $missing[] = 'surface_m2';
        if ((float) ($listing['price'] ?? 0) <= 0) $missing[] = 'price > 0';
        $photos = $listing['photos'] ?? [];
        if (!is_array($photos) || count($photos) < 1) $missing[] = 'photos (1 minimum)';
        if (empty($listing['transaction_type'])) $missing[] = 'transaction_type (sale/rent)';
        if (empty($listing['location'])) $missing[] = 'location';
        return ['ok' => empty($missing), 'missing_fields' => $missing, 'warnings' => $warnings];
    }

    private function mapToXml(array $listing, ?string $externalId = null): string {
        $eid = $externalId ?: ('SL_' . bin2hex(random_bytes(6)));
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_XML1, 'UTF-8');
        $photos = '';
        foreach (($listing['photos'] ?? []) as $p) {
            $url = is_array($p) ? $p['url'] : $p;
            $photos .= "    <photo url=\"" . $esc($url) . "\"/>\n";
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<listing id="' . $esc($eid) . '" feed="seloger">' . "\n"
            . '  <title>' . $esc(mb_substr($listing['title'] ?? '', 0, 80)) . '</title>' . "\n"
            . '  <description>' . $esc(mb_substr($listing['description'] ?? '', 0, 5000)) . '</description>' . "\n"
            . '  <price currency="' . $esc($listing['currency'] ?? 'EUR') . '">' . $esc($listing['price'] ?? 0) . '</price>' . "\n"
            . '  <surface unit="m2">' . $esc($listing['surface_m2'] ?? '') . '</surface>' . "\n"
            . '  <transaction>' . $esc($listing['transaction_type'] ?? 'sale') . '</transaction>' . "\n"
            . '  <type>' . $esc($listing['real_estate_type'] ?? 'apartment') . '</type>' . "\n"
            . '  <rooms>' . $esc($listing['rooms'] ?? '') . '</rooms>' . "\n"
            . '  <location>' . "\n"
            . '    <city>' . $esc($listing['location']['city'] ?? '') . '</city>' . "\n"
            . '    <zipcode>' . $esc($listing['location']['zipcode'] ?? '') . '</zipcode>' . "\n"
            . '  </location>' . "\n"
            . '  <photos>' . "\n"
            . $photos
            . '  </photos>' . "\n"
            . '</listing>' . "\n";
    }

    private function ensureDir(): void {
        if (!is_dir(self::MOCK_FEED_DIR)) {
            @mkdir(self::MOCK_FEED_DIR, 0775, true);
        }
    }

    public function publish(array $listing, array $credentials): array {
        $start = microtime(true);
        // Simulate refus si titre contient REFUSE_TEST
        if (stripos($listing['title'] ?? '', 'REFUSE_TEST') !== false) {
            return ['ok' => false, 'error' => 'TEST refus simule (REFUSE_TEST dans titre)', 'status_code' => 422, 'duration_ms' => 1];
        }
        $eid = 'SL_' . bin2hex(random_bytes(6));
        $xml = $this->mapToXml($listing, $eid);
        $this->ensureDir();
        $path = self::MOCK_FEED_DIR . '/' . $eid . '.xml';
        $bytes = file_put_contents($path, $xml);
        $duration_ms = (int) round((microtime(true) - $start) * 1000);
        if ($bytes === false) {
            return ['ok' => false, 'error' => 'feed write failed', 'duration_ms' => $duration_ms];
        }
        return ['ok' => true, 'external_id' => $eid, 'duration_ms' => $duration_ms, 'response' => ['feed_path' => $path, 'bytes' => $bytes]];
    }

    public function update(string $external_id, array $changes, array $credentials): array {
        $this->ensureDir();
        $xml = $this->mapToXml($changes, $external_id);
        $path = self::MOCK_FEED_DIR . '/' . $external_id . '.xml';
        $bytes = file_put_contents($path, $xml);
        return ['ok' => $bytes !== false, 'error' => $bytes === false ? 'feed write failed' : null, 'duration_ms' => 1, 'response' => ['feed_path' => $path]];
    }

    public function delete(string $external_id, array $credentials): array {
        $path = self::MOCK_FEED_DIR . '/' . $external_id . '.xml';
        if (file_exists($path)) @unlink($path);
        return ['ok' => true, 'duration_ms' => 1];
    }

    public function getStatus(string $external_id, array $credentials): array {
        $path = self::MOCK_FEED_DIR . '/' . $external_id . '.xml';
        if (!file_exists($path)) {
            return ['ok' => false, 'status' => 'expired', 'error' => 'feed not found'];
        }
        return ['ok' => true, 'status' => 'published', 'last_modif' => date('c', filemtime($path)), 'views' => rand(20, 500)];
    }
}
