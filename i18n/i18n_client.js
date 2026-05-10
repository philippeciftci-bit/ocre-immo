// M113b — Helper i18n client side. Charge JSON traduction au load + expose window.t(key, params).
// Usage : <script src="/i18n/i18n_client.js"></script> au top puis t('btn.save')
// Cookie ocre_lang detecte automatiquement.

(function() {
  const SUPPORTED = ['fr', 'en', 'es', 'ar'];
  const FALLBACK = 'fr';
  const LANG_FROM_COOKIE = (document.cookie.match(/ocre_lang=([a-z]{2})/) || [null, null])[1];
  const LANG_FROM_NAVIGATOR = (navigator.language || 'fr').slice(0, 2).toLowerCase();
  const CURRENT_LANG = SUPPORTED.includes(LANG_FROM_COOKIE) ? LANG_FROM_COOKIE
    : (SUPPORTED.includes(LANG_FROM_NAVIGATOR) ? LANG_FROM_NAVIGATOR : FALLBACK);

  let strings = {};
  let fallbackStrings = {};
  let loaded = false;
  let pending = [];

  // Apply RTL au document si AR
  function applyDirection() {
    if (CURRENT_LANG === 'ar') document.documentElement.setAttribute('dir', 'rtl');
    else document.documentElement.removeAttribute('dir');
    document.documentElement.setAttribute('lang', CURRENT_LANG);
  }

  function fetchLang(lang) {
    return fetch('/api/i18n/get_strings.php?lang=' + lang, { credentials: 'omit' })
      .then(r => r.ok ? r.json() : {})
      .catch(() => ({}));
  }

  // Load current + fallback FR pour les keys manquantes
  Promise.all([fetchLang(CURRENT_LANG), CURRENT_LANG === FALLBACK ? Promise.resolve({}) : fetchLang(FALLBACK)])
    .then(([cur, fb]) => {
      strings = cur || {};
      fallbackStrings = fb || {};
      loaded = true;
      // Auto-translate elements with data-i18n attributes (declarative usage)
      document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        const val = window.t(key);
        if (val !== key) el.textContent = val;
      });
      document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        el.setAttribute('placeholder', window.t(el.getAttribute('data-i18n-placeholder')));
      });
      document.querySelectorAll('[data-i18n-title]').forEach(el => {
        el.setAttribute('title', window.t(el.getAttribute('data-i18n-title')));
      });
      document.querySelectorAll('[data-i18n-aria-label]').forEach(el => {
        el.setAttribute('aria-label', window.t(el.getAttribute('data-i18n-aria-label')));
      });
      // Resolve pending callers
      pending.forEach(cb => cb());
      pending = [];
      // Notify rest of the app
      window.dispatchEvent(new CustomEvent('ocre:i18n:ready', { detail: { lang: CURRENT_LANG } }));
    });

  function interpolate(template, params) {
    if (!params) return template;
    return template.replace(/\{(\w+)\}/g, (_, k) => (params[k] != null ? params[k] : '{' + k + '}'));
  }

  // Public API
  window.t = function(key, params) {
    if (!key) return '';
    const v = strings[key] !== undefined ? strings[key] : (fallbackStrings[key] !== undefined ? fallbackStrings[key] : key);
    return interpolate(v, params);
  };
  window.i18n = {
    lang: CURRENT_LANG,
    isReady: function() { return loaded; },
    onReady: function(cb) { if (loaded) cb(); else pending.push(cb); },
    setLang: function(newLang) {
      if (!SUPPORTED.includes(newLang)) return Promise.reject(new Error('Lang non supportee'));
      document.cookie = 'ocre_lang=' + newLang + '; path=/; max-age=' + (365 * 86400) + '; secure; samesite=Lax';
      // Persist côté serveur si user authenticated
      fetch('/api/i18n/set_lang.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ lang: newLang }) }).catch(() => {});
      // Reload pour appliquer (plus simple que reactive translate de tout le DOM)
      location.reload();
    },
  };

  applyDirection();
})();
