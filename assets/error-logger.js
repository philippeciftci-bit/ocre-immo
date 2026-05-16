/*!
 * Ocre error-logger — M/2026/05/16/5
 * SDK maison capture erreurs client (WebKit/Safari iOS prioritaire) -> /api/error-log.php -> Telegram.
 * Doit charger en TOUT PREMIER dans <head>. Ne doit JAMAIS throw (sinon il masque les bugs qu'il traque).
 * Vanilla ES5 defensif : pas d'arrow, pas d'optional chaining, pas de template literal.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/error-log.php';
  var MAX_PER_MIN = 5;          // throttle anti-flood
  var NAV_LOOP_COUNT = 3;       // 3+ chargements
  var NAV_LOOP_WINDOW = 10000;  // en moins de 10 s
  var FLUSH_DELAY = 800;        // debounce buffer (ms)

  var sentTimes = [];           // timestamps des envois (fenetre glissante 60 s)
  var droppedSinceFlush = 0;    // erreurs jetees par throttle depuis le dernier flush
  var buffer = [];              // payloads en attente
  var flushTimer = null;
  var sending = false;          // garde anti-recursion pendant l'envoi
  var lastSig = '';             // dedup local (meme erreur repetee en boucle)
  var lastSigTs = 0;

  function now() { return Date.now ? Date.now() : new Date().getTime(); }

  function safeStr(v, max) {
    try {
      if (v === null || v === undefined) return '';
      var s = typeof v === 'string' ? v : (v.message ? v.message : String(v));
      return s.length > max ? s.slice(0, max) : s;
    } catch (e) { return ''; }
  }

  function sessionToken() {
    try {
      return localStorage.getItem('ocre_impersonation_token') ||
             localStorage.getItem('ocre_token') || '';
    } catch (e) { return ''; }
  }

  function throttled() {
    var t = now();
    var cutoff = t - 60000;
    var kept = [];
    for (var i = 0; i < sentTimes.length; i++) {
      if (sentTimes[i] > cutoff) kept.push(sentTimes[i]);
    }
    sentTimes = kept;
    return sentTimes.length >= MAX_PER_MIN;
  }

  function enqueue(type, message, stack, extraMeta) {
    try {
      // Anti-recursion : ne JAMAIS logger une erreur issue de l'endpoint lui-meme.
      if (sending) return;
      var msg = safeStr(message, 2000);
      var stk = safeStr(stack, 4000);
      var u = '';
      try { u = String(window.location.href); } catch (e) {}
      if (msg.indexOf(ENDPOINT) !== -1 || u.indexOf(ENDPOINT) !== -1) return;

      // Dedup local : meme type+message dans la seconde -> ignore (boucle serree).
      var sig = type + '|' + msg;
      var t = now();
      if (sig === lastSig && (t - lastSigTs) < 1000) return;
      lastSig = sig; lastSigTs = t;

      if (throttled()) { droppedSinceFlush++; return; }
      sentTimes.push(t);

      var meta = { ts: t };
      if (droppedSinceFlush > 0) { meta.dropped = droppedSinceFlush; droppedSinceFlush = 0; }
      if (extraMeta) {
        for (var k in extraMeta) {
          if (Object.prototype.hasOwnProperty.call(extraMeta, k)) meta[k] = extraMeta[k];
        }
      }
      var tok = sessionToken();

      buffer.push({
        type: type,
        message: msg,
        stack: stk,
        url: u,
        user_agent: (navigator && navigator.userAgent) ? navigator.userAgent : '',
        token: tok,
        meta: meta
      });
      scheduleFlush();
    } catch (e) { /* le logger ne casse jamais la page */ }
  }

  function scheduleFlush() {
    if (flushTimer) return;
    flushTimer = setTimeout(function () { flushTimer = null; flush(false); }, FLUSH_DELAY);
  }

  function flush(useBeacon) {
    if (!buffer.length) return;
    var batch = buffer.slice();
    buffer = [];
    sending = true;
    try {
      for (var i = 0; i < batch.length; i++) {
        var payload = JSON.stringify(batch[i]);
        var ok = false;
        if (useBeacon && navigator && typeof navigator.sendBeacon === 'function') {
          try {
            ok = navigator.sendBeacon(ENDPOINT, new Blob([payload], { type: 'application/json' }));
          } catch (e) { ok = false; }
        }
        if (!ok) {
          try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ENDPOINT, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            var tk = batch[i].token;
            if (tk) { try { xhr.setRequestHeader('X-Session-Token', tk); } catch (e) {} }
            xhr.send(payload);
          } catch (e) { /* perdu, tant pis : jamais throw */ }
        }
      }
    } catch (e) { /* noop */ }
    sending = false;
  }

  /* ---- 1. window.onerror (erreurs JS sync) ---- */
  window.addEventListener('error', function (ev) {
    try {
      if (ev && ev.target && ev.target !== window &&
          (ev.target.tagName === 'SCRIPT' || ev.target.tagName === 'LINK' || ev.target.tagName === 'IMG')) {
        // erreur de chargement de ressource
        enqueue('JS_ERROR', 'Resource load failed: ' + (ev.target.src || ev.target.href || ev.target.tagName), '', {
          subtype: 'resource'
        });
        return;
      }
      var err = ev && ev.error;
      enqueue('JS_ERROR',
        (ev && ev.message) ? ev.message : 'Unknown error',
        err && err.stack ? err.stack : '',
        { line: ev && ev.lineno, col: ev && ev.colno, file: ev && ev.filename });
    } catch (e) {}
  }, true);

  /* ---- 2. unhandledrejection (Promises rejetees) ---- */
  window.addEventListener('unhandledrejection', function (ev) {
    try {
      var r = ev ? ev.reason : null;
      enqueue('PROMISE_REJECTION',
        r && r.message ? r.message : safeStr(r, 2000),
        r && r.stack ? r.stack : '', null);
    } catch (e) {}
  });

  /* ---- 3. console.error (wrap) ---- */
  try {
    var origErr = window.console && window.console.error;
    if (origErr) {
      window.console.error = function () {
        try {
          var parts = [];
          for (var i = 0; i < arguments.length; i++) parts.push(safeStr(arguments[i], 500));
          enqueue('CONSOLE_ERROR', parts.join(' '), '', null);
        } catch (e) {}
        return origErr.apply(window.console, arguments);
      };
    }
  } catch (e) {}

  /* ---- 4. Detection boucle navigation (NAV_LOOP) ---- */
  try {
    var navKey = 'ocre_navloop';
    var here = '';
    try { here = window.location.pathname + window.location.search; } catch (e) {}
    var hist = [];
    try { hist = JSON.parse(sessionStorage.getItem(navKey) || '[]'); } catch (e) { hist = []; }
    if (Object.prototype.toString.call(hist) !== '[object Array]') hist = [];
    var tnav = now();
    var recent = [];
    for (var i = 0; i < hist.length; i++) {
      if (hist[i] && hist[i].u === here && (tnav - hist[i].t) < NAV_LOOP_WINDOW) recent.push(hist[i]);
    }
    recent.push({ u: here, t: tnav });
    // garder seulement les entrees recentes (toutes URL) pour borner la taille
    var pruned = [];
    for (var j = 0; j < hist.length; j++) {
      if (hist[j] && (tnav - hist[j].t) < NAV_LOOP_WINDOW) pruned.push(hist[j]);
    }
    pruned.push({ u: here, t: tnav });
    try { sessionStorage.setItem(navKey, JSON.stringify(pruned.slice(-20))); } catch (e) {}
    if (recent.length >= NAV_LOOP_COUNT) {
      enqueue('NAV_LOOP', 'Navigation loop detected: ' + here + ' (' + recent.length + 'x en <' + (NAV_LOOP_WINDOW / 1000) + 's)', '', {
        count: recent.length, path: here
      });
    }
  } catch (e) {}

  /* ---- 5. fetch / XHR echoues (HTTP_FAIL) ---- */
  try {
    var origFetch = window.fetch;
    if (origFetch) {
      window.fetch = function (input, init) {
        var reqUrl = '';
        try { reqUrl = (typeof input === 'string') ? input : (input && input.url ? input.url : ''); } catch (e) {}
        var p = origFetch.apply(window, arguments);
        try {
          if (reqUrl.indexOf(ENDPOINT) === -1) {
            p.then(function (resp) {
              try {
                if (resp && resp.status >= 400) {
                  enqueue('HTTP_FAIL', 'HTTP ' + resp.status + ' ' + (resp.statusText || '') + ' ' + reqUrl, '', {
                    status: resp.status, req_url: reqUrl, transport: 'fetch'
                  });
                }
              } catch (e) {}
            }, function (netErr) {
              try {
                enqueue('HTTP_FAIL', 'Network error (fetch): ' + (netErr && netErr.message ? netErr.message : reqUrl), '', {
                  req_url: reqUrl, transport: 'fetch', network: true
                });
              } catch (e) {}
              throw netErr;
            });
          }
        } catch (e) {}
        return p;
      };
    }
  } catch (e) {}

  try {
    var XP = window.XMLHttpRequest && window.XMLHttpRequest.prototype;
    if (XP && XP.open && XP.send) {
      var origOpen = XP.open;
      var origSend = XP.send;
      XP.open = function (method, url) {
        try { this.__ocre_url = String(url || ''); } catch (e) {}
        return origOpen.apply(this, arguments);
      };
      XP.send = function () {
        try {
          var self = this;
          var u = self.__ocre_url || '';
          if (u.indexOf(ENDPOINT) === -1) {
            self.addEventListener('load', function () {
              try {
                if (self.status >= 400) {
                  enqueue('HTTP_FAIL', 'HTTP ' + self.status + ' ' + (self.statusText || '') + ' ' + u, '', {
                    status: self.status, req_url: u, transport: 'xhr'
                  });
                }
              } catch (e) {}
            });
            self.addEventListener('error', function () {
              try {
                enqueue('HTTP_FAIL', 'Network error (xhr): ' + u, '', { req_url: u, transport: 'xhr', network: true });
              } catch (e) {}
            });
          }
        } catch (e) {}
        return origSend.apply(this, arguments);
      };
    }
  } catch (e) {}

  /* ---- 6. Flush survie unload ---- */
  try {
    window.addEventListener('visibilitychange', function () {
      try { if (document.visibilityState === 'hidden') { if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; } flush(true); } } catch (e) {}
    });
    window.addEventListener('pagehide', function () {
      try { if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; } flush(true); } catch (e) {}
    });
  } catch (e) {}

  // API manuelle minimale (debug / tests).
  try { window.__ocreLog = function (msg, meta) { enqueue('JS_ERROR', msg || 'manual', '', meta || null); flush(false); }; } catch (e) {}
})();
