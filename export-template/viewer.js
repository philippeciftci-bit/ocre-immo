/* Viewer standalone Ocre Immo — vanilla JS.
   Lit data.json côté fichier local (file:// marche sauf sur Chrome desktop restrictif → conseiller
   iOS Files / Safari ou ouvrir via petit serveur local). */
(function(){
  'use strict';
  var PROFIL_COLORS = {Acheteur:'#8B5E3C', Vendeur:'#1E3A5F', Investisseur:'#6B4E8B', Bailleur:'#2D6B3F', Locataire:'#8B6B1E', Curieux:'#757575'};
  var BADGES = {Acheteur:'ACH', Vendeur:'VDR', Investisseur:'INV', Bailleur:'BAI', Locataire:'LOC', Curieux:'CUR'};
  var PROFILS = ['Tous','Acheteur','Vendeur','Investisseur','Bailleur','Locataire','Curieux'];

  var state = {data: null, query: '', filter: 'Tous', detail: null};

  function $(id){ return document.getElementById(id); }
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function normalize(s){ return String(s == null ? '' : s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
  function fmtTel(v){ if(!v) return ''; return String(v).replace(/(\+\d{2})(\d)(\d{2})(\d{2})(\d{2})(\d{2})/, '$1 $2 $3 $4 $5 $6'); }
  function fmtDate(iso){ if(!iso) return ''; return String(iso).replace('T',' ').slice(0, 16); }
  function fmtMoney(n, cur){
    var x = Number(n); if (!x) return '';
    cur = cur || 'EUR';
    var label = cur === 'EUR' ? '€' : (cur === 'MAD' ? 'MAD' : cur);
    if (label === '€') {
      if (x >= 1000000) { var v = x/1000000; return (v === Math.floor(v) ? v.toFixed(0) : v.toFixed(1).replace('.', ',')) + ' M€'; }
      if (x >= 1000) return Math.round(x/1000) + 'k€';
      return x.toLocaleString('fr-FR') + ' €';
    }
    return x.toLocaleString('fr-FR') + ' ' + label;
  }

  function photoUrl(p){
    if (!p) return '';
    if (typeof p === 'string') return p;
    if (p.url_internal) return p.url_internal;
    if (p.url) return p.url;
    return '';
  }

  function match(c, q){
    if (!q) return true;
    var nq = normalize(q);
    var parts = [c.prenom, c.nom, c.societe_nom, c.ville];
    (c.tels || []).forEach(function(t){ parts.push(t && t.valeur); });
    (c.emails || []).forEach(function(e){ parts.push(e && e.valeur); });
    if (c.bien){ parts.push(c.bien.ville); parts.push(c.bien.quartier); parts.push((c.bien.types || []).join(' ')); }
    parts.push(c.notes);
    return parts.filter(Boolean).some(function(x){ return normalize(x).indexOf(nq) >= 0; });
  }

  function renderCountChip(){
    var d = state.data;
    if (!d) return;
    var n = (d.counts && d.counts.dossiers) || (d.dossiers || []).length;
    $('count-chip').textContent = n + ' dossier' + (n > 1 ? 's' : '');
    var meta = [];
    if (d.source && d.source.user_name) meta.push('Source : ' + d.source.user_name);
    if (d.exported_at) meta.push('Exporté le ' + fmtDate(d.exported_at));
    $('export-meta').textContent = meta.join(' · ');
  }

  function renderFilters(){
    var html = PROFILS.map(function(p){
      var n = p === 'Tous'
        ? (state.data.dossiers || []).length
        : (state.data.dossiers || []).filter(function(c){ return (c.projet || c.profil_detecte) === p; }).length;
      var active = state.filter === p;
      return '<button class="filter-chip' + (active ? ' active' : '') + '" data-f="' + esc(p) + '">' + esc(p) + (n > 0 ? ' (' + n + ')' : '') + '</button>';
    }).join('');
    $('filters').innerHTML = html;
    Array.prototype.forEach.call(document.querySelectorAll('.filter-chip'), function(btn){
      btn.addEventListener('click', function(){ state.filter = btn.dataset.f; renderList(); });
    });
  }

  function cardHTML(c){
    var profil = c.projet || c.profil_detecte || 'Acheteur';
    var col = PROFIL_COLORS[profil] || PROFIL_COLORS.Acheteur;
    var badge = BADGES[profil] || 'ACH';
    var bien = c.bien || {};
    var types = Array.isArray(bien.types) && bien.types.length ? bien.types.join(' · ') : (bien.type || '');
    var ville = [bien.ville, bien.quartier].filter(Boolean).join(' · ');
    var tels = Array.isArray(c.tels) && c.tels.length ? c.tels : (c.tel ? [{valeur:c.tel}] : []);
    var name = ((c.prenom || '') + ' ' + (c.nom || '').toUpperCase()).trim() || c.societe_nom || 'Sans nom';
    var photos = Array.isArray(bien.photos) ? bien.photos : [];
    var firstPhoto = photoUrl(photos[0]);
    var cur = bien.pays === 'MA' ? 'MAD' : 'EUR';
    var fin = (c.financement && typeof c.financement === 'object') ? c.financement : {};
    var bv = fin.budget_total || fin.budget_max || c.budget_max || c.prix_affiche || c.loyer_demande || c.loyer_max;

    return '<div class="card" data-id="' + c.id + '">' +
      '<div class="card-head">' +
        '<div class="badge" style="background:' + col + '">' + esc(badge) + '</div>' +
        '<div class="name">' + esc(name) + '</div>' +
        (tels[0] && tels[0].valeur ? '<div class="tel">📞 ' + esc(fmtTel(tels[0].valeur)) + '</div>' : '') +
      '</div>' +
      '<div class="grid3">' +
        '<div class="sector">' +
          '<div class="sector-label">🏠 Bien</div>' +
          (firstPhoto ? (
            '<div class="thumb-wrap">' +
              '<div class="thumb"><img src="' + esc(firstPhoto) + '" alt="" onerror="this.parentNode.style.display=\'none\'" loading="lazy">' +
                (photos.length > 1 ? '<span class="thumb-plus">+' + (photos.length - 1) + '</span>' : '') +
              '</div>' +
              '<div style="flex:1;min-width:0">' +
                (types ? '<div class="sector-l1">' + esc(types.slice(0, 16)) + '</div>' : '') +
                (ville ? '<div class="sector-l2">' + esc(ville.slice(0, 22)) + '</div>' : '') +
              '</div>' +
            '</div>'
          ) : (types || ville
            ? (types ? '<div class="sector-l1">' + esc(types.slice(0, 22)) + '</div>' : '') +
              (ville ? '<div class="sector-l2">' + esc(ville.slice(0, 28)) + '</div>' : '')
            : '<div class="sector-empty">—</div>')) +
        '</div>' +
        '<div class="sector">' +
          '<div class="sector-label">💰 Budget</div>' +
          (bv ? '<div class="sector-l1">' + esc(fmtMoney(bv, cur)) + '</div>' : '<div class="sector-empty">—</div>') +
        '</div>' +
        '<div class="sector">' +
          '<div class="sector-label">📅 Suivi</div>' +
          (c.updated_at ? '<div class="sector-l2">Mis à jour ' + esc(fmtDate(c.updated_at)) + '</div>' : '<div class="sector-empty">—</div>') +
        '</div>' +
      '</div>' +
    '</div>';
  }

  function renderList(){
    if (!state.data) return;
    var all = state.data.dossiers || [];
    var filtered = all.filter(function(c){
      if (state.filter !== 'Tous') {
        var p = c.projet || c.profil_detecte;
        if (p !== state.filter) return false;
      }
      return match(c, state.query);
    });
    if (filtered.length === 0) {
      $('list').innerHTML = '<div style="text-align:center;padding:40px 12px;color:#8B7F6E;background:#fff;border:1px dashed #D4C3A8;border-radius:10px">' +
        (state.query ? 'Aucun dossier ne correspond à « ' + esc(state.query) + ' ».' : 'Aucun dossier à afficher.') + '</div>';
      return;
    }
    $('list').innerHTML = filtered.map(cardHTML).join('');
    Array.prototype.forEach.call(document.querySelectorAll('.card'), function(el){
      el.addEventListener('click', function(){
        var id = parseInt(el.dataset.id, 10);
        openDetail(id);
      });
    });
  }

  function kv(label, value){
    if (value == null || value === '') return '';
    return '<div class="kv"><div class="kv-k">' + esc(label) + '</div><div class="kv-v">' + esc(value) + '</div></div>';
  }

  function openDetail(id){
    var c = (state.data.dossiers || []).find(function(x){ return x.id === id; });
    if (!c) return;
    state.detail = c;
    var profil = c.projet || c.profil_detecte || 'Acheteur';
    var col = PROFIL_COLORS[profil] || PROFIL_COLORS.Acheteur;
    var bien = c.bien || {};
    var types = Array.isArray(bien.types) && bien.types.length ? bien.types.join(' · ') : (bien.type || '');
    var photos = (Array.isArray(bien.photos) ? bien.photos : []).map(photoUrl).filter(Boolean);
    var fin = (c.financement && typeof c.financement === 'object') ? c.financement : {};
    var cur = bien.pays === 'MA' ? 'MAD' : 'EUR';
    var tels = Array.isArray(c.tels) && c.tels.length ? c.tels : (c.tel ? [{label:'Principal', valeur:c.tel}] : []);
    var emails = Array.isArray(c.emails) && c.emails.length ? c.emails : (c.email ? [{label:'Principal', valeur:c.email}] : []);
    var suivi = c.suivi || {};

    var name = ((c.prenom || '') + ' ' + (c.nom || '').toUpperCase()).trim() || c.societe_nom || 'Sans nom';

    var telsHtml = tels.map(function(t){ return kv('📞 ' + (t.label || 'Tel'), fmtTel(t.valeur)); }).join('');
    var emailsHtml = emails.map(function(e){ return kv('✉ ' + (e.label || 'Email'), e.valeur); }).join('');

    var html = '<h2 style="color:' + col + '">' + esc(name) + ' · ' + esc(profil) + '</h2>';
    if (c.societe_nom && (c.prenom || c.nom)) html += '<div class="sub">' + esc(c.societe_nom) + '</div>';

    html += '<h3>I · Identité & contact</h3><div class="section-group">' +
      kv('Type', c.profil_type || 'Particulier') +
      kv('Prénom', c.prenom) + kv('Nom', c.nom) + kv('Société', c.societe_nom) +
      kv('SIRET / ICE', c.siret) + kv('Représentant', c.representant) +
      telsHtml + emailsHtml +
      kv('Adresse', c.adresse) + kv('Ville', c.ville) + kv('Pays résidence', c.pays_residence) +
    '</div>';

    html += '<h3>II · Le bien</h3><div class="section-group">' +
      kv('Types', types) + kv('Titre', bien.titre) +
      kv('Ville', bien.ville) + kv('Quartier', bien.quartier) + kv('Pays', bien.pays) + kv('Code postal', c.code_postal) +
      kv('Surface habitable', bien.surface ? bien.surface + ' m²' : (bien.surface_habitable ? bien.surface_habitable + ' m²' : '')) +
      kv('Surface terrain', bien.surface_terrain ? bien.surface_terrain + ' m²' : '') +
      kv('Pièces', bien.pieces) + kv('Chambres', bien.chambres) + kv('SDB', bien.sdb) +
      kv('Étage', bien.etage != null ? bien.etage : '') +
      kv('DPE / GES', (bien.dpe || bien.ges) ? (bien.dpe || '?') + ' / ' + (bien.ges || '?') : '') +
      kv('Année construction', bien.annee_construction) +
      kv('Caractéristiques', Array.isArray(bien.caracteristiques) ? bien.caracteristiques.join(' · ') : bien.caracteristiques) +
      (bien.description ? '<div class="kv"><div class="kv-k">Description</div><div class="kv-v" style="white-space:pre-wrap">' + esc(bien.description) + '</div></div>' : '') +
    '</div>';

    if (photos.length) {
      html += '<h3>Photos (' + photos.length + ')</h3>' +
        '<div class="photos">' + photos.map(function(u){ return '<img src="' + esc(u) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'; }).join('') + '</div>';
    }

    html += '<h3>III · Budget</h3><div class="section-group">' +
      kv('Budget max', bv(c, cur, 'budget_max')) +
      kv('Prix affiché', bv(c, cur, 'prix_affiche')) +
      kv('Loyer demandé', bv(c, cur, 'loyer_demande')) +
      kv('Loyer max', bv(c, cur, 'loyer_max')) +
      kv('Mode financement', fin.mode) +
      kv('Mensualité', fin.mensualite ? fmtMoney(fin.mensualite, cur) + '/mois' : '') +
      kv('Travaux', fin.travaux ? fmtMoney(fin.travaux, cur) : '') +
      kv('Rendement cible', c.rendement_cible ? c.rendement_cible + '%' : '') +
    '</div>';

    html += '<h3>IV · Suivi & notes</h3><div class="section-group">' +
      (suivi.next_event && suivi.next_event.when_at ? kv('🗓 Prochain RDV', fmtDate(suivi.next_event.when_at) + (suivi.next_event.title ? ' · ' + suivi.next_event.title : '')) : '') +
      (suivi.next_todo && suivi.next_todo.title ? kv('✅ Tâche', suivi.next_todo.title) : '') +
      (suivi.last_interaction && suivi.last_interaction.ts ? kv('✍ Dernière interaction', fmtDate(suivi.last_interaction.ts)) : '') +
      kv('Langue', c.langue) + kv('Canal', c.canal) + kv('Origine', c.origine) +
      (Array.isArray(c.tags) && c.tags.length ? kv('🏷 Tags', c.tags.join(' · ')) : '') +
      (c.notes ? '<div class="kv"><div class="kv-k">Notes</div><div class="kv-v" style="white-space:pre-wrap">' + esc(c.notes) + '</div></div>' : '') +
    '</div>';

    html += '<h3>Métadonnées</h3><div class="section-group">' +
      kv('ID', c.id) +
      kv('Créé le', fmtDate(c.created_at)) +
      kv('Modifié le', fmtDate(c.updated_at)) +
      (c.is_staged ? kv('État', 'Téléchargement en attente de validation') : '') +
      (c.archived ? kv('État', 'Archivé') : '') +
    '</div>';

    $('detail-body').innerHTML = html;
    $('list').classList.add('hidden');
    $('detail').classList.remove('hidden');
    window.scrollTo(0, 0);
  }
  function bv(c, cur, key){
    var v = c[key] || (c.financement && c.financement[key]);
    return v ? fmtMoney(v, cur) : '';
  }

  $('detail-close').addEventListener('click', function(){
    state.detail = null;
    $('detail').classList.add('hidden');
    $('list').classList.remove('hidden');
    window.scrollTo(0, 0);
  });

  $('search').addEventListener('input', function(e){
    state.query = e.target.value;
    renderList();
  });

  function load(){
    fetch('data.json').then(function(r){ return r.json(); }).then(function(d){
      state.data = d;
      renderCountChip();
      renderFilters();
      renderList();
    }).catch(function(e){
      $('list').innerHTML = '<div style="padding:40px 14px;background:#FEE2E2;border:1px solid #DC2626;border-radius:10px;color:#991B1B;font-size:13px">' +
        '<b>Impossible de charger data.json.</b><br>' +
        'Certains navigateurs (Chrome desktop) bloquent l\'ouverture locale. Ouvre ce dossier sur iOS Safari via <b>Fichiers</b>, ou lance un petit serveur local (<code>python3 -m http.server</code> dans le dossier).<br><br>' +
        'Erreur : ' + esc(e && e.message || e) +
      '</div>';
    });
  }
  load();
})();
