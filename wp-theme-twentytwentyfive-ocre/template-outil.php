<?php
/**
 * Template Name: Outil Ocre (M_OCRE_HOME_VISUELLE + M_OCRE_PATCH_OUTILS_RICHES)
 *
 * 6 sections riches par page outil + popup signup overlay shared (pas de navigation).
 * Utilisation : créer page WordPress avec slug oi-agent/oi-scan/oi-book/oi-demande/oi-capture/oi-estimer
 * et assigner ce template. Le slug détermine automatiquement le contenu.
 */
get_header();

$slug = get_post_field('post_name', get_the_ID()) ?: 'oi-agent';
$tools = [
  'oi-agent'   => ['name'=>'Oi Agent',   'tagline'=>'CRM matching immo',         'photo'=>'https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=2400&q=80', 'cta_app'=>'agent', 'feats'=>[
      ['icon'=>'https://images.unsplash.com/photo-1551836022-deb4988cc6c0?auto=format&fit=crop&w=600&q=80','title'=>'Dossiers anonymisés'],
      ['icon'=>'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=600&q=80','title'=>'Pacts confrères bilatéraux'],
      ['icon'=>'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80','title'=>'Matching auto multi-pays'],
      ['icon'=>'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=600&q=80','title'=>'Diffusion 7 portails immo'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=600&q=80','title'=>'Notifs push iOS/Android'],
      ['icon'=>'https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=600&q=80','title'=>'Équipe agents/managers'],
  ], 'testimonial'=>['photo'=>'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=2400&q=80','text'=>"« Ocre m'a fait gagner 2h par jour. »",'cite'=>'— Karim, Marrakech']],
  'oi-scan'    => ['name'=>'Oi Scan',    'tagline'=>'Diagnostic bâtiment 2 min', 'photo'=>'https://images.unsplash.com/photo-1542621334-a254cf47733d?auto=format&fit=crop&w=2400&q=80', 'cta_app'=>'scan', 'feats'=>[
      ['icon'=>'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=600&q=80','title'=>'IA scan photo'],
      ['icon'=>'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=600&q=80','title'=>'Rapport PDF 5 min'],
      ['icon'=>'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80','title'=>'Norme NF DPE'],
      ['icon'=>'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=600&q=80','title'=>'Estimation travaux'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-deb4988cc6c0?auto=format&fit=crop&w=600&q=80','title'=>'Géolocalisation auto'],
      ['icon'=>'https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=600&q=80','title'=>'Partage 1-clic confrère'],
  ], 'testimonial'=>['photo'=>'https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=2400&q=80','text'=>"« Diagnostic complet en 2 minutes, c'est fou. »",'cite'=>'— Inès, architecte Casablanca']],
  'oi-book'    => ['name'=>'Oi Book',    'tagline'=>'Mon voyage, mon planning',  'photo'=>'https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&w=2400&q=80', 'cta_app'=>'book', 'feats'=>[
      ['icon'=>'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=600&q=80','title'=>'Planning visites groupées'],
      ['icon'=>'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80','title'=>'Carte multi-pays'],
      ['icon'=>'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=600&q=80','title'=>'Sync Google Calendar'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-deb4988cc6c0?auto=format&fit=crop&w=600&q=80','title'=>'Réservations 1-clic'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=600&q=80','title'=>'Notifs RDV iPhone'],
      ['icon'=>'https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=600&q=80','title'=>'Partage planning agent'],
  ], 'testimonial'=>['photo'=>'https://images.unsplash.com/photo-1542361345-89e58247f2d5?auto=format&fit=crop&w=2400&q=80','text'=>"« 8 visites en 2 jours, organisées par Oi Book. »",'cite'=>'— Sophie, investisseuse Paris']],
  'oi-demande' => ['name'=>'Oi Demande', 'tagline'=>'Trouve ton bien rêvé',      'photo'=>'https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=2400&q=80', 'cta_app'=>'demande', 'feats'=>[
      ['icon'=>'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80','title'=>'Brief en 2 minutes'],
      ['icon'=>'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=600&q=80','title'=>'Match agents pertinents'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-deb4988cc6c0?auto=format&fit=crop&w=600&q=80','title'=>'Anonymat préservé'],
      ['icon'=>'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=600&q=80','title'=>'Multi-pays Maroc/France/Espagne'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=600&q=80','title'=>'Propositions filtrées'],
      ['icon'=>'https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=600&q=80','title'=>'Chat agent direct'],
  ], 'testimonial'=>['photo'=>'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?auto=format&fit=crop&w=2400&q=80','text'=>"« 3 propositions pertinentes en 48h. »",'cite'=>'— Marc, acquéreur Riad Marrakech']],
  'oi-capture' => ['name'=>'Oi Capture', 'tagline'=>'Scan tous mes documents',   'photo'=>'https://images.unsplash.com/photo-1554224155-6726b3ff858f?auto=format&fit=crop&w=2400&q=80', 'cta_app'=>'capture', 'feats'=>[
      ['icon'=>'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80','title'=>'Photo → PDF auto'],
      ['icon'=>'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=600&q=80','title'=>'OCR full-text recherche'],
      ['icon'=>'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=600&q=80','title'=>'Catégories auto IA'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-deb4988cc6c0?auto=format&fit=crop&w=600&q=80','title'=>'Cloud chiffré'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=600&q=80','title'=>'Partage sécurisé'],
      ['icon'=>'https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=600&q=80','title'=>'Export comptable'],
  ], 'testimonial'=>['photo'=>'https://images.unsplash.com/photo-1450101499163-c8848c66ca85?auto=format&fit=crop&w=2400&q=80','text'=>"« Mes factures classées sans rien faire. »",'cite'=>'— Yasmine, autoentrepreneuse']],
  'oi-estimer' => ['name'=>'Oi Estimer', 'tagline'=>'Estime ton bien gratuit',   'photo'=>'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=2400&q=80', 'cta_app'=>'estimer', 'feats'=>[
      ['icon'=>'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80','title'=>'IA marché local'],
      ['icon'=>'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=600&q=80','title'=>'Comparables réels'],
      ['icon'=>'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=600&q=80','title'=>'Fourchette précise'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-deb4988cc6c0?auto=format&fit=crop&w=600&q=80','title'=>'PDF rapport gratuit'],
      ['icon'=>'https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=600&q=80','title'=>'Multi-devises EUR/MAD/USD'],
      ['icon'=>'https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=600&q=80','title'=>'Suivi marché 30j'],
  ], 'testimonial'=>['photo'=>'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=2400&q=80','text'=>"« Estimation cohérente avec 3 agences. »",'cite'=>'— Hassan, vendeur villa Casablanca']],
];
$t = $tools[$slug] ?? $tools['oi-agent'];
$other_tools = array_filter($tools, function($k) use ($slug) { return $k !== $slug; }, ARRAY_FILTER_USE_KEY);
?>

<style>
:root { --cream:#FAF6F1; --cream-2:#F4ECDF; --brown:#3D2818; --brown-soft:#6B5642; --brown-mute:#998877; --ocre:#C9A961; --ocre-dark:#8B5E3C; --gold:#D4A256; --gold-bright:#E8B95E; --line:#E5DAC6; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', system-ui, sans-serif; color: var(--brown); background: var(--cream); overflow-x: hidden; }

.op-back { position: absolute; top: 24px; left: 24px; z-index: 10; color: #fff; text-decoration: none; font-size: 13.5px; padding: 8px 14px; border-radius: 999px; background: rgba(0,0,0,0.32); backdrop-filter: blur(10px); transition: background .15s; }
.op-back:hover { background: rgba(0,0,0,0.55); }

/* SECTION 1 — Hero plein écran 100vh */
.op-hero {
  height: 100vh; min-height: 600px; position: relative;
  background-image: linear-gradient(180deg, rgba(0,0,0,0.18) 0%, rgba(0,0,0,0.05) 30%, rgba(0,0,0,0.62) 100%), url('<?php echo esc_url($t['photo']); ?>');
  background-size: cover; background-position: center;
  display: flex; align-items: center; justify-content: center; text-align: center; color: #fff;
  overflow: hidden;
}
.op-hero-inner { padding: 0 20px; max-width: 880px; animation: hv-fade-up 1.4s cubic-bezier(.2,.7,.2,1) both; z-index: 2; }
.op-hero h1 { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: clamp(64px, 11vw, 140px); line-height: 1; letter-spacing: -0.02em; color: var(--gold-bright); text-shadow: 0 4px 30px rgba(0,0,0,0.45); margin-bottom: 14px; }
.op-hero .op-tag { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 500; font-size: clamp(20px, 3vw, 32px); margin-bottom: 36px; color: rgba(255,255,255,0.92); text-shadow: 0 2px 14px rgba(0,0,0,0.4); }
.op-cta { display: inline-flex; align-items: center; gap: 10px; padding: 17px 36px; border-radius: 999px; background: var(--gold-bright); color: var(--brown); font-weight: 600; font-size: 15px; text-decoration: none; box-shadow: 0 8px 24px rgba(212,162,86,0.4); transition: all .25s; cursor: pointer; border: none; font-family: inherit; }
.op-cta:hover { transform: translateY(-2px); background: #fff; color: var(--brown); box-shadow: 0 12px 32px rgba(212,162,86,0.55); }
.op-scroll-indicator { position: absolute; bottom: 36px; left: 50%; transform: translateX(-50%); color: rgba(255,255,255,0.85); animation: hv-bounce 2.2s ease-in-out infinite; z-index: 2; }
.op-scroll-indicator svg { width: 24px; height: 24px; }
@keyframes hv-fade-up { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }
@keyframes hv-bounce { 0%, 100% { transform: translateX(-50%) translateY(0); } 50% { transform: translateX(-50%) translateY(10px); } }

/* SECTION 2 — Démo iPhone scrollable horizontal style Apple */
.op-demo { padding: 110px 0 90px; background: linear-gradient(180deg, var(--cream) 0%, var(--cream-2) 100%); overflow: hidden; }
.op-section-head { text-align: center; margin-bottom: 50px; padding: 0 24px; }
.op-section-head h2 { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: clamp(36px, 5vw, 60px); color: var(--brown); margin-bottom: 8px; }
.op-section-head p { font-size: 15px; color: var(--brown-soft); }
.op-demo-strip { display: flex; gap: 24px; padding: 10px 24px 30px; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; }
.op-demo-strip::-webkit-scrollbar { display: none; }
.op-demo-card { flex: 0 0 280px; height: 540px; border-radius: 32px; background: #1A1A1A; position: relative; overflow: hidden; scroll-snap-align: center; box-shadow: 0 30px 60px -20px rgba(60,40,20,0.35); border: 8px solid #2C2820; transition: transform .3s; }
.op-demo-card:hover { transform: translateY(-6px); }
.op-demo-card-img { position: absolute; inset: 0; background-size: cover; background-position: center; }
.op-demo-card-cap { position: absolute; bottom: 18px; left: 16px; right: 16px; background: rgba(255,255,255,0.94); backdrop-filter: blur(10px); padding: 10px 14px; border-radius: 14px; text-align: center; font-size: 12.5px; color: var(--brown); font-weight: 600; }
.op-demo-step-num { position: absolute; top: 16px; left: 16px; background: var(--gold-bright); color: var(--brown); width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; box-shadow: 0 4px 12px rgba(0,0,0,0.25); }

/* SECTION 3 — Features grid 6 cards */
.op-features { padding: 110px 24px; background: var(--cream); }
.op-features-grid { max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; }
@media (max-width: 980px) { .op-features-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 580px) { .op-features-grid { grid-template-columns: 1fr; } }
.op-feat { position: relative; aspect-ratio: 4/3; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 22px -8px rgba(60,40,20,0.18); cursor: default; transition: transform .3s; }
.op-feat:hover { transform: translateY(-4px); }
.op-feat-img { position: absolute; inset: 0; background-size: cover; background-position: center; }
.op-feat-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(61,40,24,0) 40%, rgba(61,40,24,0.85) 100%); }
.op-feat-label { position: absolute; left: 18px; bottom: 16px; right: 18px; color: #fff; font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: 22px; line-height: 1.15; text-shadow: 0 2px 10px rgba(0,0,0,0.4); }

/* SECTION 4 — Social proof */
.op-social { position: relative; height: 540px; background-image: linear-gradient(180deg, rgba(0,0,0,0.15) 0%, rgba(0,0,0,0.65) 100%), url('<?php echo esc_url($t['testimonial']['photo']); ?>'); background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; text-align: center; color: #fff; }
.op-social blockquote { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 500; font-size: clamp(28px, 4.5vw, 56px); line-height: 1.15; max-width: 900px; padding: 0 24px; text-shadow: 0 4px 30px rgba(0,0,0,0.5); }
.op-social cite { display: block; font-size: 14px; font-style: normal; font-weight: 500; letter-spacing: 0.15em; text-transform: uppercase; margin-top: 26px; color: rgba(255,255,255,0.85); }

/* SECTION 5 — CTA final bandeau gold */
.op-cta-final { background: linear-gradient(135deg, var(--gold-bright) 0%, var(--ocre) 100%); padding: 110px 24px; text-align: center; color: var(--brown); }
.op-cta-final h2 { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: clamp(48px, 7vw, 96px); line-height: 1; letter-spacing: -0.02em; margin-bottom: 16px; }
.op-cta-final p { font-size: clamp(15px, 2vw, 19px); font-style: italic; margin-bottom: 36px; color: rgba(61,40,24,0.78); }
.op-cta-final .op-cta { background: var(--brown); color: #fff; box-shadow: 0 12px 30px rgba(61,40,24,0.3); }
.op-cta-final .op-cta:hover { background: #1F140C; color: var(--gold-bright); }

/* SECTION 6 — Footer cross-sell mini cards 5 outils */
.op-cross { background: var(--cream-2); padding: 80px 24px 50px; }
.op-cross-head { text-align: center; margin-bottom: 38px; }
.op-cross-head h3 { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: clamp(28px, 4vw, 40px); color: var(--brown); }
.op-cross-grid { max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; }
@media (max-width: 880px) { .op-cross-grid { grid-template-columns: repeat(2, 1fr); } }
.op-cross-tile { position: relative; aspect-ratio: 1; border-radius: 14px; overflow: hidden; text-decoration: none; color: #fff; cursor: pointer; transition: transform .25s; }
.op-cross-tile:hover { transform: translateY(-4px); }
.op-cross-img { position: absolute; inset: 0; background-size: cover; background-position: center; transition: transform .6s; }
.op-cross-tile:hover .op-cross-img { transform: scale(1.06); }
.op-cross-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0) 30%, rgba(0,0,0,0.7) 100%); }
.op-cross-name { position: absolute; left: 12px; bottom: 12px; font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: 18px; text-shadow: 0 2px 10px rgba(0,0,0,0.4); }
.op-footer { background: var(--brown); color: rgba(255,255,255,0.55); padding: 22px 24px; text-align: center; font-size: 12px; letter-spacing: 0.04em; }
.op-footer a { color: rgba(255,255,255,0.78); text-decoration: none; margin: 0 8px; }
.op-footer a:hover { color: var(--gold-bright); }

.op-reveal { opacity: 0; transform: translateY(30px); transition: opacity .9s cubic-bezier(.2,.7,.2,1), transform .9s cubic-bezier(.2,.7,.2,1); }
.op-reveal.op-on { opacity: 1; transform: translateY(0); }
</style>

<a href="/" class="op-back">← Tous les outils</a>

<!-- SECTION 1 — Hero -->
<section class="op-hero">
  <div class="op-hero-inner">
    <h1><?php echo esc_html($t['name']); ?></h1>
    <div class="op-tag"><?php echo esc_html($t['tagline']); ?></div>
    <button type="button" class="op-cta" data-signup-trigger="<?php echo esc_attr($t['cta_app']); ?>" onclick="if(window.ocreSignupOpen){ocreSignupOpen();}return false;">Commencer (gratuit)
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </button>
  </div>
  <div class="op-scroll-indicator" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13l5 5 5-5M7 6l5 5 5-5"/></svg>
  </div>
</section>

<!-- SECTION 2 — Démo iPhone scrollable -->
<section class="op-demo">
  <div class="op-section-head op-reveal">
    <h2>Vois comme c'est simple</h2>
    <p>Quelques étapes, c'est tout.</p>
  </div>
  <div class="op-demo-strip">
    <?php foreach ($t['feats'] as $i => $f): if ($i >= 4) break; ?>
    <div class="op-demo-card"><div class="op-demo-step-num"><?php echo $i+1; ?></div><div class="op-demo-card-img" style="background-image:url('<?php echo esc_url($f['icon']); ?>')"></div><div class="op-demo-card-cap"><?php echo esc_html($f['title']); ?></div></div>
    <?php endforeach; ?>
  </div>
</section>

<!-- SECTION 3 — Features grid 6 cards -->
<section class="op-features">
  <div class="op-section-head op-reveal">
    <h2>Tout ce qu'il te faut</h2>
    <p>Six fonctionnalités clés, zéro config.</p>
  </div>
  <div class="op-features-grid">
    <?php foreach ($t['feats'] as $f): ?>
    <div class="op-feat op-reveal">
      <div class="op-feat-img" style="background-image:url('<?php echo esc_url($f['icon']); ?>')"></div>
      <div class="op-feat-overlay"></div>
      <div class="op-feat-label"><?php echo esc_html($f['title']); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- SECTION 4 — Social proof -->
<section class="op-social">
  <blockquote><?php echo esc_html($t['testimonial']['text']); ?><cite><?php echo esc_html($t['testimonial']['cite']); ?></cite></blockquote>
</section>

<!-- SECTION 5 — CTA final -->
<section class="op-cta-final">
  <h2>Prêt à utiliser <?php echo esc_html($t['name']); ?> ?</h2>
  <p>Inscription en 30 secondes, sans carte bancaire.</p>
  <button type="button" class="op-cta" onclick="ocreSignupOpen()">Commencer gratuit · 1 minute
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
  </button>
</section>

<!-- SECTION 6 — Cross-sell 5 autres outils -->
<section class="op-cross">
  <div class="op-cross-head op-reveal">
    <h3>Découvre les 5 autres outils Ocre</h3>
  </div>
  <div class="op-cross-grid">
    <?php foreach ($other_tools as $oslug => $ot): ?>
    <a href="/<?php echo esc_attr($oslug); ?>" class="op-cross-tile">
      <div class="op-cross-img" style="background-image:url('<?php echo esc_url($ot['photo']); ?>')"></div>
      <div class="op-cross-overlay"></div>
      <div class="op-cross-name"><?php echo esc_html($ot['name']); ?></div>
    </a>
    <?php endforeach; ?>
  </div>
</section>

<footer class="op-footer">
  © 2026 Ocre · <a href="mailto:contact@ocre.immo">contact@ocre.immo</a> · <a href="/mentions-legales/">Mentions légales</a> · <a href="/confidentialite/">Confidentialité</a>
</footer>

<?php
// M_OCRE_PATCH_OUTILS_RICHES — popup signup overlay shared (utilisable depuis CTA Hero + CTA final)
include get_stylesheet_directory() . '/parts/signup-popup.php';

// Source app pour redirect post-magic-link
?>
<script>
window.OCRE_SIGNUP_APP = '<?php echo esc_js($t['cta_app']); ?>';
(function(){
  if (!('IntersectionObserver' in window)) return;
  var obs = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if (e.isIntersecting) { e.target.classList.add('op-on'); obs.unobserve(e.target); }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -10% 0px' });
  document.querySelectorAll('.op-reveal').forEach(function(el){ obs.observe(el); });
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
