<?php
/**
 * Template Name: Outil Ocre (M_OCRE_HOME_VISUELLE)
 *
 * Template page dédiée par outil. Utilisation : créer une page WordPress avec slug
 * /oi-agent /oi-scan /oi-book /oi-demande /oi-capture /oi-estimer et assigner ce template.
 * Le slug détermine automatiquement le contenu (nom + photo + tagline + CTA).
 *
 * Si la page n'existe pas en WordPress, ajouter dans wp-admin → Pages → Ajouter avec
 * Modèle = "Outil Ocre" pour chacun des 6 slugs.
 */
get_header();

$slug = get_post_field('post_name', get_the_ID()) ?: '';
// Catalogue 6 outils (synchronisé avec front-page.php tuiles)
$tools = [
  'oi-agent'   => ['name'=>'Oi Agent',   'tagline'=>'CRM matching immo',         'desc'=>"Crée des dossiers, croise avec tes confrères, ne perds plus jamais un client.", 'photo'=>'https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=2400&q=80', 'cta'=>'https://auth.ocre.immo/signup?app=agent'],
  'oi-scan'    => ['name'=>'Oi Scan',    'tagline'=>'Diagnostic bâtiment 2 min', 'desc'=>"Photo bâtiment → diagnostic complet IA en 2 minutes.",                            'photo'=>'https://images.unsplash.com/photo-1542621334-a254cf47733d?auto=format&fit=crop&w=2400&q=80', 'cta'=>'https://auth.ocre.immo/signup?app=scan'],
  'oi-book'    => ['name'=>'Oi Book',    'tagline'=>'Mon voyage, mon planning',  'desc'=>"Planifie ton voyage immo · agendas + RDV agents + visites groupées.",            'photo'=>'https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&w=2400&q=80', 'cta'=>'https://auth.ocre.immo/signup?app=book'],
  'oi-demande' => ['name'=>'Oi Demande', 'tagline'=>'Trouve ton bien rêvé',      'desc'=>"Décris ton projet · les agents Ocre te proposent des biens qui matchent.",       'photo'=>'https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=2400&q=80', 'cta'=>'https://auth.ocre.immo/signup?app=demande'],
  'oi-capture' => ['name'=>'Oi Capture', 'tagline'=>'Scan tous mes documents',   'desc'=>"Photo facture/contrat → archivage cloud + recherche full-text.",                 'photo'=>'https://images.unsplash.com/photo-1554224155-6726b3ff858f?auto=format&fit=crop&w=2400&q=80', 'cta'=>'https://auth.ocre.immo/signup?app=capture'],
  'oi-estimer' => ['name'=>'Oi Estimer', 'tagline'=>'Estime ton bien gratuit',   'desc'=>"Adresse + 3 photos → estimation IA cohérente avec le marché local.",             'photo'=>'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=2400&q=80', 'cta'=>'https://auth.ocre.immo/signup?app=estimer'],
];
$t = $tools[$slug] ?? $tools['oi-agent'];
?>

<style>
:root { --cream:#FAF6F1; --cream-2:#F4ECDF; --brown:#3D2818; --brown-soft:#6B5642; --ocre:#C9A961; --ocre-dark:#8B5E3C; --gold:#D4A256; --gold-bright:#E8B95E; --line:#E5DAC6; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', system-ui, sans-serif; color: var(--brown); background: var(--cream); }

.op-back { position: absolute; top: 24px; left: 24px; z-index: 10; color: #fff; text-decoration: none; font-size: 14px; padding: 8px 14px; border-radius: 999px; background: rgba(0,0,0,0.3); backdrop-filter: blur(10px); }
.op-back:hover { background: rgba(0,0,0,0.5); }

.op-hero {
  height: 92vh; min-height: 540px; position: relative;
  background-image: linear-gradient(180deg, rgba(0,0,0,0.2) 0%, rgba(0,0,0,0.65) 100%), url('<?php echo esc_url($t['photo']); ?>');
  background-size: cover; background-position: center;
  display: flex; align-items: center; justify-content: center; text-align: center; color: #fff;
}
.op-hero-inner { padding: 0 20px; max-width: 880px; }
.op-hero h1 {
  font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600;
  font-size: clamp(56px, 9vw, 130px); line-height: 1; letter-spacing: -0.02em;
  color: var(--gold-bright); text-shadow: 0 4px 30px rgba(0,0,0,0.45); margin-bottom: 14px;
}
.op-hero .op-tag { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-size: clamp(20px, 3vw, 32px); margin-bottom: 36px; color: rgba(255,255,255,0.92); }
.op-cta {
  display: inline-flex; align-items: center; gap: 10px; padding: 17px 36px; border-radius: 999px;
  background: var(--gold-bright); color: var(--brown); font-weight: 600; font-size: 15px;
  text-decoration: none; box-shadow: 0 8px 24px rgba(212,162,86,0.4); transition: all .2s;
}
.op-cta:hover { transform: translateY(-2px); background: #fff; color: var(--brown); }

.op-how { padding: 100px 24px; background: var(--cream); }
.op-how h2 { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: clamp(36px, 5vw, 60px); text-align: center; color: var(--brown); margin-bottom: 60px; }
.op-steps { max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; }
@media (max-width: 760px) { .op-steps { grid-template-columns: 1fr; } }
.op-step { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 28px 24px; text-align: center; }
.op-step-num { display: inline-flex; width: 40px; height: 40px; align-items: center; justify-content: center; background: var(--gold-bright); color: var(--brown); border-radius: 50%; font-weight: 700; margin-bottom: 16px; }
.op-step h3 { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: 22px; margin-bottom: 8px; color: var(--brown); }
.op-step p { font-size: 14px; color: var(--brown-soft); line-height: 1.5; }

.op-final { padding: 110px 24px; text-align: center; background: linear-gradient(135deg, var(--gold-bright) 0%, var(--ocre) 100%); color: var(--brown); }
.op-final h2 { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: clamp(40px, 6vw, 80px); margin-bottom: 16px; }
.op-final p { font-style: italic; font-size: 18px; margin-bottom: 32px; }
.op-final .op-cta { background: var(--brown); color: #fff; }
.op-final .op-cta:hover { background: #1F140C; color: var(--gold-bright); }

.op-footer { background: var(--brown); color: rgba(255,255,255,0.6); padding: 24px; text-align: center; font-size: 12.5px; }
.op-footer a { color: rgba(255,255,255,0.78); text-decoration: none; margin: 0 8px; }
</style>

<a href="/" class="op-back">← Tous les outils</a>

<section class="op-hero">
  <div class="op-hero-inner">
    <h1><?php echo esc_html($t['name']); ?></h1>
    <div class="op-tag"><?php echo esc_html($t['tagline']); ?></div>
    <a href="<?php echo esc_url($t['cta']); ?>" class="op-cta">Commencer (gratuit)
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
  </div>
</section>

<section class="op-how">
  <h2>Comment ça marche</h2>
  <div class="op-steps">
    <div class="op-step"><div class="op-step-num">1</div><h3>Inscris-toi</h3><p>Email + magic link · 30 secondes · pas de carte bancaire.</p></div>
    <div class="op-step"><div class="op-step-num">2</div><h3>Utilise <?php echo esc_html($t['name']); ?></h3><p><?php echo esc_html($t['desc']); ?></p></div>
    <div class="op-step"><div class="op-step-num">3</div><h3>Profite</h3><p>100% gratuit. Tant qu'on le décide encore.</p></div>
  </div>
</section>

<section class="op-final">
  <h2>Prêt ?</h2>
  <p>Inscription en 30 secondes, sans carte bancaire.</p>
  <a href="<?php echo esc_url($t['cta']); ?>" class="op-cta">Créer mon compte gratuit
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
  </a>
</section>

<footer class="op-footer">
  © 2026 Ocre · <a href="mailto:contact@ocre.immo">contact@ocre.immo</a> · <a href="/mentions-legales/">Mentions légales</a> · <a href="/confidentialite/">Confidentialité</a>
</footer>

<?php wp_footer(); ?>
</body>
</html>
