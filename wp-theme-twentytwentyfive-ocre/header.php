<?php
/**
 * Header minimal pour vitrine M102. La nav est rendue dans front-page.php pour
 * etre fidele maquette et eviter conflit avec le header parent block-based.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
