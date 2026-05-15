/* M/2026/05/15/11 — Composant logo centralise charte OCRE.
 * Source de verite : /root/workspace/atelier-philippe/docs/CHARTE_OCRE.md (gravee 2026-05-15).
 * Reference visuelle : docs/charte-ocre-finale.html.
 *
 * Requiert ocre-brand.css charge + police Cormorant Garamond 600.
 *
 * Expose sur window (vanilla + React si present) :
 *   OcreLogo({ size=110, className })          -> monogramme Oi
 *   OcreMarque({ size=88, className })          -> marque OCRE.immo
 *   OcreLogoOutil({ outil, logoSize=110,
 *                   outilSize=88, className })  -> logo + nom outil baseline commune
 *
 * Chaque export a aussi une methode .html(props) -> string HTML (contextes PHP / non-React).
 *
 * La geometrie interne (140px O, 82px i, 15px point, 110px box) reste TOUJOURS celle de
 * la charte validee ; le redimensionnement passe par transform:scale pour ne jamais
 * faire deriver les proportions gravees.
 */
(function (root) {
  'use strict';

  var BOX = 110;   // box monogramme charte
  var MARQUE = 88; // taille hero marque charte
  var R = (typeof root.React !== 'undefined') ? root.React : null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- HTML string builders (source unique de la structure) ---------- */

  function monoHTML() {
    return '<span class="o-letter">O</span>' +
           '<span class="i-letter">ı</span>' +
           '<span class="dot-red"></span>';
  }

  function logoHTML(props) {
    props = props || {};
    var size = props.size || BOX;
    var cls = 'ocre-logo-mono' + (props.className ? ' ' + esc(props.className) : '');
    var scale = size / BOX;
    var wrapStyle = scale !== 1
      ? ' style="display:inline-block;width:' + (BOX * scale) + 'px;height:' + (BOX * scale) +
        'px;line-height:0"'
      : '';
    var innerStyle = scale !== 1
      ? ' style="transform:scale(' + scale + ');transform-origin:top left"'
      : '';
    if (scale !== 1) {
      return '<span' + wrapStyle + '><span class="' + cls + '"' + innerStyle + '>' +
             monoHTML() + '</span></span>';
    }
    return '<span class="' + cls + '">' + monoHTML() + '</span>';
  }

  function marqueHTML(props) {
    props = props || {};
    var size = props.size || MARQUE;
    var cls = 'ocre-marque' + (props.className ? ' ' + esc(props.className) : '');
    var style = size !== MARQUE ? ' style="font-size:' + size + 'px"' : '';
    return '<span class="' + cls + '"' + style + '>' +
           '<span class="ocre-c">OCRE</span>' +
           '<span class="dot-c">.</span>' +
           '<span class="immo-c">immo</span>' +
           '</span>';
  }

  function logoOutilHTML(props) {
    props = props || {};
    var outil = esc(props.outil || '');
    var logoSize = props.logoSize || BOX;
    var outilSize = props.outilSize || MARQUE;
    var cls = 'ocre-logo-outil' + (props.className ? ' ' + esc(props.className) : '');
    var scale = logoSize / BOX;
    var ghostFs = 140 * scale;
    var monoInner = scale !== 1
      ? '<span class="ocre-logo-mono" style="transform:scale(' + scale +
        ');transform-origin:bottom center">' + monoHTML() + '</span>'
      : '<span class="ocre-logo-mono">' + monoHTML() + '</span>';
    var outilStyle = outilSize !== MARQUE ? ' style="font-size:' + outilSize + 'px"' : '';
    return '<span class="' + cls + '">' +
             '<span class="logo-anchor">' +
               '<span class="baseline-ghost" style="font-size:' + ghostFs + 'px">O</span>' +
               monoInner +
             '</span>' +
             '<span class="outil"' + outilStyle + '>' + outil + '</span>' +
           '</span>';
  }

  /* ---------- React wrappers (dangerouslySetInnerHTML sur la meme source) ---------- */

  function makeReact(builder, tag) {
    return function (props) {
      props = props || {};
      return R.createElement(tag || 'span', {
        dangerouslySetInnerHTML: { __html: builder(props) }
      });
    };
  }

  var OcreLogo, OcreMarque, OcreLogoOutil;

  if (R) {
    OcreLogo = makeReact(logoHTML);
    OcreMarque = makeReact(marqueHTML);
    OcreLogoOutil = makeReact(logoOutilHTML);
  } else {
    // Vanilla : retourne un HTMLElement.
    OcreLogo = function (props) { var d = document.createElement('span'); d.innerHTML = logoHTML(props); return d.firstChild; };
    OcreMarque = function (props) { var d = document.createElement('span'); d.innerHTML = marqueHTML(props); return d.firstChild; };
    OcreLogoOutil = function (props) { var d = document.createElement('span'); d.innerHTML = logoOutilHTML(props); return d.firstChild; };
  }

  OcreLogo.html = logoHTML;
  OcreMarque.html = marqueHTML;
  OcreLogoOutil.html = logoOutilHTML;

  root.OcreLogo = OcreLogo;
  root.OcreMarque = OcreMarque;
  root.OcreLogoOutil = OcreLogoOutil;
  root.OcreBrand = { OcreLogo: OcreLogo, OcreMarque: OcreMarque, OcreLogoOutil: OcreLogoOutil };
})(typeof window !== 'undefined' ? window : this);
