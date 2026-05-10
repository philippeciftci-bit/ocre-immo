<?php
// M_OAUTH_DIAGNOSTIC_FIX + M_OAUTH_MOCK_ACCOUNT_PICKER — Page consent mock simulee
// Sélecteur compte multi-emails (historique localStorage + form ajout compte)
// Transmission infos au callback via query string email/first_name/last_name
$provider = preg_replace('/[^a-z]/', '', strtolower((string)($_GET['provider'] ?? 'google')));
$state = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['state'] ?? ''));
if (!in_array($provider, ['google','apple','facebook'], true)) { http_response_code(400); echo "Provider invalide"; exit; }
if (!$state) { http_response_code(400); echo "State requis"; exit; }

$brandColors = [
    'google' => ['#fff', '#1F1F1F', '#DADCE0', 'Google', '#1A73E8'],
    'apple' => ['#000', '#fff', '#000', 'Apple', '#fff'],
    'facebook' => ['#1877F2', '#fff', '#1877F2', 'Facebook', 'rgba(255,255,255,0.95)'],
];
[$bg, $fg, $border, $name, $primaryBtn] = $brandColors[$provider];

// Code mock unique pour ce flow
$mockCode = 'mock_' . bin2hex(random_bytes(8));
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
.card{width:100%;max-width:440px;background:<?php echo $bg; ?>;border-radius:14px;padding:30px;<?php if($provider==='google'){echo 'border:1px solid #DADCE0;box-shadow:0 4px 24px rgba(0,0,0,0.08);';}else{echo 'box-shadow:0 4px 24px rgba(0,0,0,0.18);';} ?>}
.logo{text-align:center;margin-bottom:18px;}
.logo svg{width:42px;height:42px;}
h1{font-size:20px;font-weight:500;text-align:center;margin:0 0 6px;color:<?php echo $fg; ?>;}
.sub{text-align:center;font-size:13px;opacity:0.65;margin-bottom:22px;}

.app-card{padding:14px 16px;border-radius:10px;<?php echo $provider==='google' ? 'background:#F8F9FA;' : 'background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);'; ?>margin-bottom:20px;display:flex;align-items:center;gap:12px;}
.app-card .ic{width:38px;height:38px;border-radius:8px;background:linear-gradient(135deg,#C9A961 0%,#8B5E3C 100%);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;font-family:Georgia,serif;font-style:italic;}
.app-card .meta{flex:1;}
.app-card .name{font-size:14px;font-weight:600;color:<?php echo $fg; ?>;}
.app-card .domain{font-size:11.5px;opacity:0.6;}

.section-label{font-size:11px;font-weight:600;letter-spacing:0.06em;opacity:0.7;text-transform:uppercase;margin-bottom:10px;}

.acct-list{display:flex;flex-direction:column;gap:8px;margin-bottom:14px;}
.acct{padding:12px 14px;border-radius:10px;border:1px solid <?php echo $border; ?>;<?php echo $provider!=='google' ? 'border-color:rgba(255,255,255,0.2);' : ''; ?>display:flex;align-items:center;gap:12px;cursor:pointer;transition:background .15s,transform .15s;text-decoration:none;color:inherit;}
.acct:hover{<?php echo $provider==='google' ? 'background:#F0F4F9;' : 'background:rgba(255,255,255,0.08);'; ?>transform:translateY(-1px);}
.acct .av{width:36px;height:36px;border-radius:50%;background:#E8B95E;color:#3D2818;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;text-transform:uppercase;}
.acct .info{flex:1;min-width:0;}
.acct .info strong{display:block;font-weight:600;font-size:13.5px;color:<?php echo $fg; ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.acct .info span{font-size:11.5px;opacity:0.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;}
.acct .arrow{opacity:0.4;}

.acct-add{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;background:transparent;border:1px dashed <?php echo $border; ?>;<?php echo $provider!=='google' ? 'border-color:rgba(255,255,255,0.3);' : ''; ?>cursor:pointer;font-family:inherit;font-size:13.5px;font-weight:500;color:<?php echo $fg; ?>;width:100%;text-align:left;transition:opacity .15s;}
.acct-add:hover{opacity:0.8;}
.acct-add .plus{width:36px;height:36px;border-radius:50%;background:transparent;border:1.5px dashed <?php echo $border; ?>;<?php echo $provider!=='google' ? 'border-color:rgba(255,255,255,0.4);' : ''; ?>display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}

.add-form{display:none;margin-top:12px;padding:14px;border-radius:10px;<?php echo $provider==='google' ? 'background:#F8F9FA;' : 'background:rgba(255,255,255,0.06);'; ?>}
.add-form.show{display:block;}
.add-form .field{margin-bottom:10px;}
.add-form label{display:block;font-size:11px;opacity:0.65;font-weight:500;letter-spacing:0.04em;margin-bottom:4px;}
.add-form input{width:100%;padding:10px 12px;border-radius:8px;font-size:13.5px;font-family:inherit;background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;border:1px solid <?php echo $border; ?>;<?php echo $provider!=='google' ? 'border-color:rgba(255,255,255,0.2);' : ''; ?>}
.add-form input:focus{outline:none;border-color:<?php echo $provider==='google' ? '#1A73E8' : '#E8B95E'; ?>;}
.add-form .row-2{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.status{font-size:11.5px;margin-top:8px;display:none;padding:6px 10px;border-radius:6px;}
.status.show{display:block;}
.status.exists{background:#FEF7E0;color:#7C5C00;}
.status.new{background:#E6F4EA;color:#137333;}

.scopes{font-size:12.5px;opacity:0.75;margin:18px 0;line-height:1.7;}
.scopes ul{list-style:none;padding:0;margin:8px 0 0;}
.scopes li{padding:4px 0 4px 22px;position:relative;}
.scopes li::before{content:'✓';position:absolute;left:0;color:#34A853;font-weight:700;}

.actions{display:flex;gap:10px;flex-direction:column;margin-top:14px;}
.btn{padding:13px 22px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;border:none;font-family:inherit;text-decoration:none;text-align:center;transition:opacity .15s,transform .15s;display:block;}
.btn:hover{transform:translateY(-1px);}
.btn:disabled{opacity:0.5;cursor:not-allowed;transform:none;}
.btn-primary{background:<?php echo $primaryBtn; ?>;color:<?php echo $provider==='google' ? '#fff' : '#000'; ?>;}
.btn-secondary{background:transparent;color:<?php echo $fg; ?>;border:1px solid <?php echo $border; ?>;<?php echo $provider!=='google' ? 'border-color:rgba(255,255,255,0.3);' : ''; ?>}

.foot{text-align:center;font-size:11px;opacity:0.5;margin-top:18px;letter-spacing:0.04em;}
.mock-badge{position:fixed;top:14px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.7);color:#fff;padding:6px 14px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:0.08em;z-index:10;}
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

  <div class="app-card">
    <div class="ic">O</div>
    <div class="meta"><div class="name">Ocre — Outils immo gratuits</div><div class="domain">ocre.immo</div></div>
  </div>

  <div class="section-label">Choisir un compte</div>
  <div class="acct-list" id="acct-list">
    <!-- Rempli en JS depuis localStorage. Fallback : compte philippe.ciftci@gmail.com par défaut visible toujours pour première utilisation -->
  </div>

  <button type="button" class="acct-add" id="btn-add-toggle">
    <span class="plus">+</span>
    <span>Utiliser un autre compte</span>
  </button>

  <div class="add-form" id="add-form">
    <div class="row-2">
      <div class="field"><label for="af-prenom">Prénom</label><input type="text" id="af-prenom" placeholder="Jean" autocomplete="given-name"></div>
      <div class="field"><label for="af-nom">Nom</label><input type="text" id="af-nom" placeholder="Dupont" autocomplete="family-name"></div>
    </div>
    <div class="field"><label for="af-email">Email</label><input type="email" id="af-email" placeholder="votre@email.com" autocomplete="email"></div>
    <div class="status" id="af-status"></div>
    <button type="button" class="btn btn-primary" id="btn-continue" style="margin-top:8px">Continuer avec ce compte</button>
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
    <a href="<?php echo htmlspecialchars($cancelUrl); ?>" class="btn btn-secondary">Annuler</a>
  </div>

  <div class="foot">Mode développement · les vraies credentials <?php echo htmlspecialchars($name); ?> seront branchées en production.</div>
</div>

<script>
(function(){
  var STORAGE_KEY = 'ocre_mock_test_accounts';
  var PROVIDER = <?php echo json_encode($provider); ?>;
  var STATE = <?php echo json_encode($state); ?>;
  var MOCK_CODE = <?php echo json_encode($mockCode); ?>;
  var DEFAULT_ACCT = { email: 'philippe.ciftci@gmail.com', first_name: 'Philippe', last_name: 'Ciftci', last_used_at: 0 };

  function loadAccts() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return [DEFAULT_ACCT];
      var arr = JSON.parse(raw);
      if (!Array.isArray(arr) || arr.length === 0) return [DEFAULT_ACCT];
      // Toujours inclure default en bas si pas présent
      if (!arr.some(function(a){ return a.email === DEFAULT_ACCT.email; })) arr.push(DEFAULT_ACCT);
      return arr;
    } catch (e) { return [DEFAULT_ACCT]; }
  }

  function saveAcct(a) {
    var arr = loadAccts().filter(function(x){ return x.email !== a.email; });
    arr.unshift({ email: a.email, first_name: a.first_name || '', last_name: a.last_name || '', last_used_at: Date.now() });
    arr = arr.slice(0, 5); // max 5 historiques
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(arr)); } catch (e) {}
  }

  function callbackUrl(acct) {
    var qs = new URLSearchParams({
      code: MOCK_CODE,
      state: STATE,
      email: acct.email,
      first_name: acct.first_name || '',
      last_name: acct.last_name || '',
    });
    return '/api/oauth/' + PROVIDER + '/callback.php?' + qs.toString();
  }

  function initial(s) { return (s || '?').trim().charAt(0).toUpperCase(); }

  function render() {
    var list = document.getElementById('acct-list');
    var accts = loadAccts().sort(function(a,b){ return (b.last_used_at||0) - (a.last_used_at||0); }).slice(0, 4);
    list.innerHTML = '';
    accts.forEach(function(a){
      var card = document.createElement('a');
      card.className = 'acct';
      card.href = callbackUrl(a);
      card.innerHTML = '<div class="av">' + initial(a.first_name || a.email) + '</div>'
        + '<div class="info"><strong>' + ((a.first_name + ' ' + (a.last_name || '')).trim() || 'Compte') + '</strong><span>' + a.email + '</span></div>'
        + '<svg class="arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg>';
      card.addEventListener('click', function(){ saveAcct(a); });
      list.appendChild(card);
    });
  }

  document.getElementById('btn-add-toggle').addEventListener('click', function(){
    var f = document.getElementById('add-form');
    f.classList.toggle('show');
    if (f.classList.contains('show')) document.getElementById('af-email').focus();
  });

  document.getElementById('af-email').addEventListener('blur', function(){
    var email = this.value.trim().toLowerCase();
    var status = document.getElementById('af-status');
    if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { status.classList.remove('show','exists','new'); return; }
    var existing = loadAccts().find(function(a){ return a.email === email; });
    status.classList.remove('exists','new');
    if (existing) {
      status.classList.add('show', 'exists');
      status.textContent = '⚠ Compte existant — connexion en cours';
      if (existing.first_name) document.getElementById('af-prenom').value = existing.first_name;
      if (existing.last_name) document.getElementById('af-nom').value = existing.last_name;
    } else {
      status.classList.add('show', 'new');
      status.textContent = '✨ Nouveau compte — création en cours';
    }
  });

  document.getElementById('btn-continue').addEventListener('click', function(){
    var email = document.getElementById('af-email').value.trim().toLowerCase();
    var first_name = document.getElementById('af-prenom').value.trim();
    var last_name = document.getElementById('af-nom').value.trim();
    if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      var status = document.getElementById('af-status');
      status.classList.add('show','exists'); status.textContent = '⚠ Email invalide';
      return;
    }
    var acct = { email: email, first_name: first_name, last_name: last_name };
    saveAcct(acct);
    location.href = callbackUrl(acct);
  });

  render();
})();
</script>
</body>
</html>
