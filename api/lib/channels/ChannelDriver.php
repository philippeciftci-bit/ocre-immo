<?php
// M104 — Interface unifiee Channel manager v4. Tous les drivers portails l'implementent.
// Methode publish/update/delete/getStatus/validateListing + getName + getRequiredFields/getMaxLengths.

interface ChannelDriver {
    public function getName(): string;
    public function publish(array $listing, array $credentials): array;
    public function update(string $external_id, array $changes, array $credentials): array;
    public function delete(string $external_id, array $credentials): array;
    public function getStatus(string $external_id, array $credentials): array;
    public function validateListing(array $listing): array;
    public function getRequiredFields(): array;
    public function getMaxLengths(): array;
}

// Helper commun : POST/PUT/DELETE/GET vers une URL avec timeout + JSON body.
class ChannelHttpClient {
    public static function request(string $method, string $url, ?array $jsonBody = null, ?array $headers = null, int $timeout = 15): array {
        $start = microtime(true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $hdrs = ['Accept: application/json'];
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $hdrs[] = 'Content-Type: application/json';
        }
        if ($headers) {
            foreach ($headers as $k => $v) $hdrs[] = "$k: $v";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $duration_ms = (int) round((microtime(true) - $start) * 1000);
        $parsed = $body ? json_decode($body, true) : null;
        return [
            'status_code' => $code,
            'body' => $parsed,
            'raw_body' => $body,
            'error' => $err ?: null,
            'duration_ms' => $duration_ms,
        ];
    }
}
