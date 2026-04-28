<?php
// M/2026/04/29/7 — Quota alerts : 80% (warning) et 100% (block).
require_once __DIR__ . '/billing.php';

if (!function_exists('quota_check')) {

function quota_count_user_dossiers(int $uid): int {
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM clients WHERE user_id = ? AND deleted_at IS NULL AND is_draft = 0");
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function quota_count_dossier_photos(int $clientId): int {
    $dir = '/opt/ocre-app/uploads/' . $clientId;
    if (!is_dir($dir)) return 0;
    $count = 0;
    foreach (glob($dir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
        if (strpos($f, '_thumb.') === false) $count++;
    }
    return $count;
}

function quota_check(int $uid, string $key, int $current = -1): array {
    $limits = billing_get_user_plan_limits($uid);
    $maxKey = 'max_' . $key;
    if (!isset($limits[$maxKey])) return ['ok' => true, 'unlimited' => true];
    $max = (int) $limits[$maxKey];
    if ($current < 0) {
        if ($key === 'dossiers') $current = quota_count_user_dossiers($uid);
        else return ['ok' => true, 'unknown' => true];
    }
    $pct = $max > 0 ? round(100 * $current / $max) : 0;
    $level = 'ok';
    if ($current >= $max) $level = 'blocked';
    elseif ($pct >= 80) $level = 'warning';
    return [
        'ok' => $level !== 'blocked',
        'level' => $level,
        'current' => $current,
        'max' => $max,
        'pct' => $pct,
        'plan' => $limits['plan'] ?? 'decouverte',
    ];
}

function quota_alert_message(string $key, array $check): string {
    $labels = [
        'dossiers' => 'dossiers',
        'photos_per_dossier' => 'photos sur ce dossier',
        'docs_per_dossier' => 'documents sur ce dossier',
    ];
    $l = $labels[$key] ?? $key;
    if ($check['level'] === 'blocked') {
        return "Limite atteinte : {$check['current']} / {$check['max']} {$l}. Passez en plan supérieur pour augmenter votre quota.";
    }
    if ($check['level'] === 'warning') {
        return "Vous approchez de la limite : {$check['current']} / {$check['max']} {$l} ({$check['pct']}%).";
    }
    return '';
}

}
