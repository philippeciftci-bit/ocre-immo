<?php
// V20 phase 7 — mailer + 6 templates emails officiels Ocre Immo.
// Envoi via /usr/sbin/sendmail si dispo, sinon log fallback /var/log/ocre/mail.log.

const OCRE_MAIL_FROM = 'Ocre Immo <noreply@ocre.immo>';
const OCRE_MAIL_FOOTER = "\n\n— L'équipe Ocre Immo · ocre.immo";
const OCRE_BTN_BG = '#8B5E3C';
const OCRE_BTN_HOVER = '#6B4429';

function ocre_send_mail(string $to, string $subject, string $html_body, ?string $pdf_attachment = null): bool {
    // Priorité Resend via _email.php si dispo (pas de pièces jointes côté Resend pour l'instant).
    if ($pdf_attachment === null) {
        $emailLib = __DIR__ . '/../_email.php';
        if (file_exists($emailLib)) {
            require_once $emailLib;
            if (function_exists('sendEmail') && function_exists('emailEnabled') && emailEnabled()) {
                $r = sendEmail($to, $subject, $html_body, 'v20', null, [], true);
                if (!empty($r['ok'])) return true;
                @mkdir('/var/log/ocre', 0775, true);
                file_put_contents('/var/log/ocre/mail.log',
                    "[" . gmdate('c') . "] RESEND-FAIL TO={$to} ERR=" . ($r['error'] ?? '') . "\n",
                    FILE_APPEND);
            }
        }
    }
    $boundary = bin2hex(random_bytes(16));
    $headers = [
        'From: ' . OCRE_MAIL_FROM,
        'To: ' . $to,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
    ];

    if ($pdf_attachment && file_exists($pdf_attachment)) {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body = "--{$boundary}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . $html_body . "\r\n\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: application/pdf; name=\"" . basename($pdf_attachment) . "\"\r\n"
              . "Content-Transfer-Encoding: base64\r\n"
              . "Content-Disposition: attachment; filename=\"" . basename($pdf_attachment) . "\"\r\n\r\n"
              . chunk_split(base64_encode(file_get_contents($pdf_attachment))) . "\r\n"
              . "--{$boundary}--";
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $body = $html_body;
    }

    $sendmail = '/usr/sbin/sendmail';
    if (is_executable($sendmail)) {
        $process = proc_open("$sendmail -t -i", [0 => ['pipe', 'r']], $pipes);
        if (is_resource($process)) {
            fwrite($pipes[0], implode("\r\n", $headers) . "\r\n\r\n" . $body);
            fclose($pipes[0]);
            $rc = proc_close($process);
            if ($rc === 0) return true;
        }
    }

    @mkdir('/var/log/ocre', 0775, true);
    file_put_contents('/var/log/ocre/mail.log',
        "[" . gmdate('c') . "] TO={$to} SUBJECT={$subject}\n" . $html_body . "\n---\n",
        FILE_APPEND);
    return false;
}

function ocre_btn_html(string $url, string $label): string {
    $u = htmlspecialchars($url);
    $l = htmlspecialchars($label);
    $bg = OCRE_BTN_BG;
    return "<p style=\"text-align:center;margin:24px 0\"><a href=\"{$u}\" style=\"display:inline-block;padding:12px 28px;background:{$bg};color:#fff;text-decoration:none;border-radius:4px;font-family:Georgia,serif\">{$l}</a></p>";
}

function ocre_wrap_html(string $inner): string {
    return "<!DOCTYPE html><html><body style=\"font-family:Georgia,serif;line-height:1.6;color:#2A1810;max-width:600px;margin:0 auto;padding:20px\">"
         . $inner
         . "<p style=\"font-size:13px;color:#6B6B6B;margin-top:30px;border-top:1px solid #E5DDC8;padding-top:15px\">— L'équipe Ocre Immo · ocre.immo</p>"
         . "</body></html>";
}

// === 6 emails officiels ===

function email_welcome_agent(string $to, string $prenom, string $slug, string $email, string $pwd_temp): bool {
    $subject = "Ton compte Ocre Immo est prêt";
    $url = "https://{$slug}.ocre.immo";
    $body = "<p>Bonjour " . htmlspecialchars($prenom) . ",</p>"
          . "<p>Ton compte Ocre Immo a été créé. Tu peux dès à présent te connecter à ton espace personnel pour gérer tes dossiers, tes biens et tes mandats.</p>"
          . "<p><strong>Adresse de connexion :</strong> <a href=\"{$url}\">{$url}</a><br>"
          . "<strong>Identifiant :</strong> " . htmlspecialchars($email) . "<br>"
          . "<strong>Mot de passe initial :</strong> <code>" . htmlspecialchars($pwd_temp) . "</code></p>"
          . "<p>Pour des raisons de sécurité, tu seras invité à choisir un mot de passe personnel à ta première connexion.</p>"
          . ocre_btn_html($url, "Accéder à mon espace")
          . "<p>En cas de difficulté, réponds simplement à ce message.</p>";
    return ocre_send_mail($to, $subject, ocre_wrap_html($body));
}

function email_pact_invite(string $to, string $prenom, string $prenom_init, string $email_init, string $nom_wsc, string $sign_url): bool {
    $subject = "{$prenom_init} t'invite au partenariat {$nom_wsc}";
    $body = "<p>Bonjour " . htmlspecialchars($prenom) . ",</p>"
          . "<p>" . htmlspecialchars($prenom_init) . " (" . htmlspecialchars($email_init) . ") souhaite formaliser un partenariat avec toi sur Ocre Immo, sous le nom <strong>" . htmlspecialchars($nom_wsc) . "</strong>.</p>"
          . "<p>Pour activer ce workspace commun, chaque partenaire doit lire et signer le pacte de partenariat. Le pacte couvre la gestion des dossiers partagés, le RGPD, le préavis de rupture de 48h, et le droit applicable.</p>"
          . ocre_btn_html($sign_url, "Lire et signer le pacte")
          . "<p>Le workspace ne sera activé qu'une fois tous les partenaires signataires. Tu peux refuser sans aucune conséquence.</p>";
    return ocre_send_mail($to, $subject, ocre_wrap_html($body));
}

function email_pact_active(string $to, string $prenom, string $nom_wsc, string $wsc_url, ?string $pdf_path = null): bool {
    $subject = "Le partenariat {$nom_wsc} est actif";
    $body = "<p>Bonjour " . htmlspecialchars($prenom) . ",</p>"
          . "<p>Tous les partenaires ont signé le pacte. Le workspace <strong>" . htmlspecialchars($nom_wsc) . "</strong> est désormais actif. Tu peux y partager des dossiers, collaborer en temps réel, et le retrouver à tout moment depuis le sélecteur d'espaces de ton application.</p>"
          . "<p>Le pacte signé est joint à ce message en PDF, à conserver.</p>"
          . ocre_btn_html($wsc_url, "Ouvrir le workspace")
          . "<p>Bonne collaboration.</p>";
    return ocre_send_mail($to, $subject, ocre_wrap_html($body), $pdf_path);
}

function email_dossier_shared(string $to, string $prenom, string $prenom_app, string $nom_wsc, string $client, string $type_bien, string $prix, string $url): bool {
    $subject = "{$prenom_app} t'a partagé un dossier dans {$nom_wsc}";
    $body = "<p>Bonjour " . htmlspecialchars($prenom) . ",</p>"
          . "<p>" . htmlspecialchars($prenom_app) . " vient de partager un nouveau dossier dans le workspace <strong>" . htmlspecialchars($nom_wsc) . "</strong> :</p>"
          . "<ul><li><strong>Client :</strong> " . htmlspecialchars($client) . "</li>"
          . "<li><strong>Type de bien :</strong> " . htmlspecialchars($type_bien) . "</li>"
          . "<li><strong>Prix :</strong> " . htmlspecialchars($prix) . "</li></ul>"
          . "<p>Tu peux le consulter et y travailler dès maintenant. Toutes les modifications sont synchronisées en temps réel entre les partenaires.</p>"
          . ocre_btn_html($url, "Ouvrir le dossier");
    return ocre_send_mail($to, $subject, ocre_wrap_html($body));
}

function email_rupture_request(string $to, string $prenom, string $prenom_partant, string $nom_wsc, string $date_echeance, string $heure_echeance, string $status_url): bool {
    $subject = "{$prenom_partant} a demandé à quitter le partenariat {$nom_wsc}";
    $body = "<p>Bonjour " . htmlspecialchars($prenom) . ",</p>"
          . "<p>" . htmlspecialchars($prenom_partant) . " vient de déclencher une demande de rupture du partenariat <strong>" . htmlspecialchars($nom_wsc) . "</strong>. Conformément au pacte signé, cette rupture sera effective dans 48h, soit le <strong>" . htmlspecialchars($date_echeance) . " à " . htmlspecialchars($heure_echeance) . "</strong>.</p>"
          . "<p>Pendant ce délai :</p>"
          . "<ul><li>Les dossiers de " . htmlspecialchars($prenom_partant) . " sont en lecture seule pour vous tous</li>"
          . "<li>Vos propres dossiers sont en lecture seule pour " . htmlspecialchars($prenom_partant) . "</li>"
          . "<li>Toute tentative de modification est tracée et notifiée</li>"
          . "<li>" . htmlspecialchars($prenom_partant) . " peut annuler sa demande à tout moment</li></ul>"
          . "<p>À l'échéance, " . htmlspecialchars($prenom_partant) . " récupérera ses dossiers (ceux dont il est l'apporteur). Une copie figée en lecture seule sera conservée dans le workspace pour vous.</p>"
          . ocre_btn_html($status_url, "Voir le statut du partenariat");
    return ocre_send_mail($to, $subject, ocre_wrap_html($body));
}

function email_rupture_done(string $to, string $prenom, string $nom_wsc, string $prenom_partant, int $n_dossiers, bool $is_solo, string $url): bool {
    $subject = "Le partenariat {$nom_wsc} est rompu";
    $body = "<p>Bonjour " . htmlspecialchars($prenom) . ",</p>"
          . "<p>Le partenariat <strong>" . htmlspecialchars($nom_wsc) . "</strong> est désormais rompu. " . htmlspecialchars($prenom_partant) . " a récupéré ses dossiers (" . $n_dossiers . " au total). Une copie figée en lecture seule de chacun est conservée dans le workspace.</p>";
    if ($is_solo) {
        $body .= "<p>Tu es désormais le seul membre du workspace. Tu peux choisir de l'archiver (lecture seule définitive) ou de continuer à l'utiliser en solo. Si un nouveau partenariat s'engage, il faudra créer un nouveau workspace.</p>";
    } else {
        $body .= "<p>Le workspace continue avec les membres restants.</p>";
    }
    $body .= ocre_btn_html($url, "Ouvrir le workspace");
    return ocre_send_mail($to, $subject, ocre_wrap_html($body));
}
