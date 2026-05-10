<?php
// M_OAUTH_DIAGNOSTIC_FIX — Page consent mock simulee style Google/Apple/Facebook
// Affiche page HTML simulee + 2 boutons Autoriser/Annuler
// Tap Autoriser → redirect vers callback du provider concerne avec code+state
$provider = preg_replace('/[^a-z]/', '', strtolower((string)($_GET['provider'] ?? 'google')));
$state = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['state'] ?? ''));
if (!in_array($provider, ['google','apple','facebook'], true)) { http_response_code(400); echo "Provider invalide"; exit; }
if (!$state) { http_response_code(400); echo "State requis"; exit; }

$brandColors = [
    'google' => ['#fff', '#1F1F1F', '#DADCE0', 'Google'],
    'apple' => ['#000', '#fff', '#000', 'Apple'],
    'facebook' => ['#1877F2', '#fff', '#1877F2', 'Facebook'],
];
[$bg, $fg, $border, $name] = $brandColors[$provider];

$callbackUrl = '/api/oauth/' . $provider . '/callback.php?code=mock_' . bin2hex(random_bytes(8)) . '&state=' . urlencode($state);
$cancelUrl = 'https://ocre.immo/?error=cancelled';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion <?php echo htmlspecialchars($name); ?> · Ocre</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;}
html,body{margin:0;padding:0;height:100%;}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;line-height:1.55;}
.card{width:100%;max-width:420px;background:<?php echo $bg; ?>;border-radius:14px;padding:30px;<?php if($provider==='google'){echo 'border:1px solid #DADCE0;box-shadow:0 4px 24px rgba(0,0,0,0.08);';}else{echo 'box-shadow:0 4px 24px rgba(0,0,0,0.18);';} ?>}
.logo{text-align:center;margin-bottom:18px;}
.logo svg{width:42px;height:42px;}
h1{font-size:20px;font-weight:500;text-align:center;margin:0 0 6px;color:<?php echo $fg; ?>;}
.sub{text-align:center;font-size:13px;opacity:0.65;margin-bottom:24px;}
.app-card{padding:14px 16px;border-radius:10px;<?php echo $provider==='google' ? 'background:#F8F9FA;' : 'background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);'; ?>margin-bottom:20px;display:flex;align-items:center;gap:12px;}
.app-card .ic{width:38px;height:38px;border-radius:8px;background:linear-gradient(135deg,#C9A961 0%,#8B5E3C 100%);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;font-family:Georgia,serif;font-style:italic;}
.app-card .meta{flex:1;}
.app-card .name{font-size:14px;font-weight:600;color:<?php echo $fg; ?>;}
.app-card .domain{font-size:11.5px;opacity:0.6;}
.user{padding:14px 16px;border-radius:10px;border:1px solid <?php echo $border; ?>;<?php echo $provider==='google' ? '' : 'border-color:rgba(255,255,255,0.2);'; ?>display:flex;align-items:center;gap:12px;margin-bottom:18px;}
.user .av{width:38px;height:38px;border-radius:50%;background:#E8B95E;color:#3D2818;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;}
.user .info{flex:1;font-size:13.5px;}
.user .info strong{display:block;font-weight:600;color:<?php echo $fg; ?>;}
.user .info span{opacity:0.65;font-size:12px;}
.scopes{font-size:12.5px;opacity:0.75;margin-bottom:24px;line-height:1.7;}
.scopes ul{list-style:none;padding:0;margin:8px 0 0;}
.scopes li{padding:4px 0 4px 22px;position:relative;}
.scopes li::before{content:'✓';position:absolute;left:0;color:#34A853;font-weight:700;}
.actions{display:flex;gap:10px;flex-direction:column;}
.btn{padding:13px 22px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;border:none;font-family:inherit;text-decoration:none;text-align:center;transition:opacity .15s,transform .15s;}
.btn:hover{transform:translateY(-1px);}
.btn-primary{background:<?php echo $provider==='google' ? '#1A73E8' : ($provider==='apple' ? '#fff' : 'rgba(255,255,255,0.95)'); ?>;color:<?php echo $provider==='google' ? '#fff' : '#000'; ?>;}
.btn-secondary{background:transparent;color:<?php echo $fg; ?>;border:1px solid <?php echo $border; ?>;<?php echo $provider!=='google' ? 'border-color:rgba(255,255,255,0.3);' : ''; ?>}
.foot{text-align:center;font-size:11px;opacity:0.5;margin-top:18px;letter-spacing:0.04em;}
.mock-badge{position:fixed;top:14px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.7);color:#fff;padding:6px 14px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:0.08em;}
</style>
</head>
<body>

<div class="mock-badge">⚠ MOCK DEV · pas un vrai consent <?php echo htmlspecialchars($name); ?></div>

<div class="card">
  <div class="logo">
    <?php if ($provider === 'google'): ?>
      <svg viewBox="0 0 48 48"><path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/><path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/><path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/><path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/></svg>
    <?php elseif ($provider === 'apple'): ?>
      <svg viewBox="0 0 384 512" fill="#fff"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
    <?php else: ?>
      <svg viewBox="0 0 24 24" fill="#fff"><path d="M22.675 0H1.325C.593 0 0 .593 0 1.325v21.351C0 23.407.593 24 1.325 24H12.82v-9.294H9.692V11.25h3.128V8.564c0-3.1 1.893-4.788 4.659-4.788 1.325 0 2.464.099 2.795.143v3.24h-1.918c-1.504 0-1.795.715-1.795 1.763v2.313h3.587l-.467 3.456h-3.12V24h6.116c.73 0 1.323-.593 1.323-1.325V1.325C24 .593 23.407 0 22.675 0z"/></svg>
    <?php endif; ?>
  </div>
  <h1>Se connecter à Ocre</h1>
  <div class="sub">avec votre compte <?php echo htmlspecialchars($name); ?></div>

  <div class="user">
    <div class="av">P</div>
    <div class="info"><strong>Philippe Ciftci</strong><span>philippe.ciftci@gmail.com</span></div>
  </div>

  <div class="app-card">
    <div class="ic">O</div>
    <div class="meta"><div class="name">Ocre — Outils immo gratuits</div><div class="domain">ocre.immo</div></div>
  </div>

  <div class="scopes">
    Cette demande accédera à :
    <ul>
      <li>Ton adresse email</li>
      <li>Ton nom et prénom</li>
      <li>Ta photo de profil publique</li>
    </ul>
  </div>

  <div class="actions">
    <a href="<?php echo htmlspecialchars($callbackUrl); ?>" class="btn btn-primary">Autoriser</a>
    <a href="<?php echo htmlspecialchars($cancelUrl); ?>" class="btn btn-secondary">Annuler</a>
  </div>

  <div class="foot">Mode développement · les vraies credentials <?php echo htmlspecialchars($name); ?> seront branchées en production.</div>
</div>
</body>
</html>
