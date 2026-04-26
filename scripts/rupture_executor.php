<?php
// V20 phase 9 — cron T+48h : exécute rupture effective.
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/lib/router.php';
require_once __DIR__ . '/../api/lib/mailer.php';

$meta = pdo_meta();
$pending = $meta->query(
    "SELECT * FROM rupture_requests
     WHERE cancelled_at IS NULL AND executed_at IS NULL AND scheduled_for <= NOW()"
)->fetchAll();

foreach ($pending as $r) {
    $wsc_id = (int)$r['wsc_id'];
    $partant_id = (int)$r['requester_user_id'];

    $w = $meta->prepare("SELECT * FROM workspaces WHERE id = ?");
    $w->execute([$wsc_id]);
    $wsc = $w->fetch();
    if (!$wsc) continue;

    $u = $meta->prepare("SELECT * FROM users WHERE id = ?");
    $u->execute([$partant_id]);
    $partant = $u->fetch();
    if (!$partant) continue;

    // Récupérer slug WSp du partant (premier WSp dont il est owner)
    $wsp_q = $meta->prepare(
        "SELECT w.slug FROM workspaces w
         JOIN workspace_members m ON m.workspace_id = w.id
         WHERE w.type = 'wsp' AND m.user_id = ? AND m.left_at IS NULL AND m.role = 'owner'
         LIMIT 1"
    );
    $wsp_q->execute([$partant_id]);
    $partant_wsp_slug = $wsp_q->fetchColumn();

    $pdo_wsc = pdo_workspace('ocre_wsc_' . $wsc['slug']);

    $apport = $pdo_wsc->prepare("SELECT * FROM dossiers WHERE _apporteur_user_id = ?");
    $apport->execute([$partant_id]);
    $dossiers = $apport->fetchAll();

    $pdo_wsp = null;
    if ($partant_wsp_slug) {
        try { $pdo_wsp = pdo_workspace('ocre_wsp_' . $partant_wsp_slug); } catch (Throwable $e) {}
    }

    $n = 0;
    foreach ($dossiers as $d) {
        if ($pdo_wsp) {
            $cols = array_keys($d);
            $colnames = implode(',', $cols);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO dossiers ({$colnames}) VALUES ({$placeholders})";
            try { $pdo_wsp->prepare($sql)->execute(array_values($d)); $n++; } catch (Throwable $e) {}
        }
        try {
            $pdo_wsc->prepare("UPDATE dossiers SET _frozen_readonly = 1, _frozen_label = ? WHERE id = ?")
                ->execute(["Récupéré par " . ($partant['display_name'] ?: $partant['email']) . " le " . gmdate('Y-m-d'), $d['id']]);
        } catch (Throwable $e) {}
    }

    $meta->prepare("UPDATE workspace_members SET left_at = NOW() WHERE workspace_id = ? AND user_id = ?")
        ->execute([$wsc_id, $partant_id]);

    $meta->prepare("UPDATE rupture_requests SET executed_at = NOW() WHERE id = ?")
        ->execute([$r['id']]);

    $meta->prepare("INSERT INTO audit_log (workspace_id, actor_user_id, action, payload_json, created_at) VALUES (?, ?, 'rupture_executed', ?, NOW())")
        ->execute([$wsc_id, $partant_id, json_encode(['dossiers_recovered' => $n])]);

    $rest = $meta->prepare("SELECT u.id, u.email, u.display_name FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND m.left_at IS NULL");
    $rest->execute([$wsc_id]);
    $remaining = $rest->fetchAll();
    $is_solo = (count($remaining) === 1);

    $prenom_partant = explode(' ', $partant['display_name'] ?? $partant['email'])[0];
    $url_wsc = "https://{$wsc['slug']}.ocre.immo";

    foreach ($remaining as $member) {
        $prenom = explode(' ', $member['display_name'] ?? $member['email'])[0];
        $meta->prepare("INSERT INTO notifications (user_id, type, title, body, payload_json, created_at) VALUES (?, 'rupture_done', ?, ?, ?, NOW())")
            ->execute([$member['id'], "Le partenariat {$wsc['display_name']} est rompu", "Résumé : {$n} dossiers récupérés par {$prenom_partant}.",
                       json_encode(['wsc_slug' => $wsc['slug'], 'dossiers_recovered' => $n])]);
        email_rupture_done($member['email'], $prenom, $wsc['display_name'], $prenom_partant, $n, $is_solo, $url_wsc);
    }
    $meta->prepare("INSERT INTO notifications (user_id, type, title, body, payload_json, created_at) VALUES (?, 'rupture_done', ?, ?, ?, NOW())")
        ->execute([$partant_id, "Le partenariat {$wsc['display_name']} est rompu", "Tu as récupéré {$n} dossiers dans ton WSp.",
                   json_encode(['wsc_slug' => $wsc['slug'], 'dossiers_recovered' => $n])]);

    echo "[" . gmdate('c') . "] Rupture exécutée WSc={$wsc['slug']} user={$partant['email']} dossiers_recovered={$n}\n";
}
