<?php
/**
 * Template Name: Redirect oi-demande → oi-recherche (M_OCRE_RENAME_DEMANDE_RECHERCHE)
 * 301 permanent. Pour anciens liens partagés.
 *
 * Note : WP hooks peuvent injecter HTML avant ce template. Combinaison header server + meta refresh + JS pour garantir redirect.
 */
@ob_end_clean();
if (!headers_sent()) {
    header('Location: https://ocre.immo/oi-recherche/', true, 301);
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
}
?><!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="0; url=https://ocre.immo/oi-recherche/">
<link rel="canonical" href="https://ocre.immo/oi-recherche/">
<title>Redirection · Oi Recherche</title>
</head><body>
<p>Redirection vers <a href="https://ocre.immo/oi-recherche/">Oi Recherche</a>…</p>
<script>window.location.replace('https://ocre.immo/oi-recherche/');</script>
</body></html>
<?php exit; ?>
