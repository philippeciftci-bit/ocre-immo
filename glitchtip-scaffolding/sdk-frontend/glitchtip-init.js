// M_GLITCHTIP_INSTALL — SDK Sentry init pour 4 surfaces frontend Ocre
// Compatible GlitchTip (protocol Sentry). Inclure dans <head> via <script src="https://browser.sentry-cdn.com/...">
// puis ce script avec DSN de l'environnement (template, à remplacer par DSN réel).
//
// Usage par surface :
// - Vitrine ocre.immo : ocre-vitrine DSN
// - auth.ocre.immo   : ocre-auth DSN
// - app.ocre.immo    : ocre-app DSN
// - launcher         : ocre-launcher DSN
//
// DSN à fournir via window.OCRE_GLITCHTIP_DSN AVANT inclusion (ex via PHP echo dans header.php)

(function(){
  if (typeof Sentry === 'undefined') { console.warn('[ocre] Sentry SDK absent, GlitchTip skip'); return; }
  var dsn = window.OCRE_GLITCHTIP_DSN || '';
  if (!dsn) { console.warn('[ocre] OCRE_GLITCHTIP_DSN missing, GlitchTip skip'); return; }
  Sentry.init({
    dsn: dsn,
    environment: window.OCRE_GLITCHTIP_ENV || 'production',
    release: window.OCRE_GLITCHTIP_RELEASE || undefined,
    tracesSampleRate: 0.1,
    sendDefaultPii: false,
    beforeSend: function(event) {
      // Exclure erreurs externes connues (bruit)
      var msg = event.exception && event.exception.values && event.exception.values[0] && event.exception.values[0].value;
      if (msg && /Non-Error promise rejection|ResizeObserver loop|Script error/i.test(msg)) return null;
      return event;
    },
    ignoreErrors: [
      'Network request failed',
      'fetch aborted',
      'Load failed',
    ],
  });
})();
