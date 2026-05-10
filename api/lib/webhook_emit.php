<?php
// M116c — Helper emit_event safe wrapper autour de dispatchEvent (ne bloque jamais flow metier).
require_once __DIR__ . '/webhook_dispatcher.php';

function emit_event(string $tenant_slug, string $event_type, array $data): void {
    try {
        $payload = ['event' => $event_type, 'timestamp' => time(), 'data' => $data];
        @dispatchEvent($tenant_slug, $event_type, $payload);
    } catch (Throwable $e) {
        @file_put_contents('/var/log/ocre-webhooks-dispatch.log',
            '[' . date('c') . '] [' . $tenant_slug . '] [' . $event_type . '] ERROR: ' . $e->getMessage() . "\n",
            FILE_APPEND);
    }
}
