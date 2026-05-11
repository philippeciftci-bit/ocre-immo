// M/2026/05/11/27 — Walkthrough complet superadmin.ocre.immo (audit chaque section + screenshot).
// Génère un activation_token pour Philippe directement en DB, navigate via ?activate=, click chaque section, screenshot, check console.
// Output : /opt/atelier-tools/maquettes/superadmin-walkthrough-<TS>/{screenshot-<section>.png, index.html, report.json}

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ADMIN_EMAIL = 'philippe.ciftci@gmail.com';
const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/superadmin-walkthrough-${TS}`;

const SECTIONS = [
  { id: 'overview', label: "Vue d'ensemble" },
  { id: 'users', label: 'Utilisateurs' },
  { id: 'sessions', label: 'Sessions actives' },
  { id: 'magic', label: 'Magic links' },
  { id: 'modules', label: 'Modules Oi' },
  { id: 'audit', label: 'Audit log' },
  { id: 'atelier', label: 'Atelier' },
  { id: 'danger', label: 'Zone danger' },
];

function genActivateToken() {
  const tok = crypto.randomBytes(32).toString('hex');
  const cmd = `mariadb ocre_meta -e "UPDATE users SET activation_token='${tok}', activation_token_expires_at=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email='${ADMIN_EMAIL}' AND role='super_admin'"`;
  execSync(cmd);
  return tok;
}

test('Walkthrough complet 8 sections + screenshots', async ({ page, context }, testInfo) => {
  test.setTimeout(120000);
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));

  // Step 1 : activate token
  const token = genActivateToken();
  await page.goto(`https://superadmin.ocre.immo/?activate=${token}`, { waitUntil: 'networkidle' });

  // Step 2 : attend dashboard (sidebar visible)
  await page.waitForSelector('#app:not([hidden])', { timeout: 20000 });
  await page.waitForSelector('.nav-item.active', { timeout: 10000 });

  const report = { ts: TS, sections: [] };

  // Step 3 : pour chaque section
  for (const sec of SECTIONS) {
    const start = Date.now();
    const sectionErrors = [];
    const errStart = errors.length;
    try {
      await page.click(`.nav-item[data-section="${sec.id}"]`);
      await page.waitForFunction(s => document.querySelector('#section-title').textContent.trim() === s, sec.label, { timeout: 8000 });
      // Attente du contenu : pas de spinner restant
      await page.waitForFunction(() => !document.querySelector('#content-body .loader'), { timeout: 8000 });
      await page.waitForTimeout(400); // laisse les fetchs finir
      const titleOk = (await page.textContent('#section-title')).trim() === sec.label;
      const screenshot = path.join(OUT_DIR, `screenshot-${sec.id}.png`);
      await page.screenshot({ path: screenshot, fullPage: true });
      const newErrors = errors.slice(errStart);
      report.sections.push({ id: sec.id, label: sec.label, title_ok: titleOk, errors: newErrors, ms: Date.now() - start, screenshot: `screenshot-${sec.id}.png` });
    } catch (e) {
      const screenshot = path.join(OUT_DIR, `screenshot-${sec.id}-failed.png`);
      try { await page.screenshot({ path: screenshot, fullPage: true }); } catch (_) {}
      report.sections.push({ id: sec.id, label: sec.label, title_ok: false, errors: [e.message], ms: Date.now() - start, screenshot: `screenshot-${sec.id}-failed.png` });
    }
  }

  // Step 4 : audit liens Atelier (sans clic, juste check href)
  await page.click('.nav-item[data-section="atelier"]');
  await page.waitForFunction(() => document.querySelectorAll('.atelier-card').length >= 4, { timeout: 5000 });
  const ateliers = await page.$$eval('.atelier-card', cards => cards.map(c => ({ title: c.querySelector('h3')?.textContent?.trim(), href: c.querySelector('a')?.getAttribute('href') })));
  report.atelier_links = ateliers;
  const liveOk = ateliers.some(a => a.href && a.href.includes('46-225-215-148.sslip.io/live'));
  report.atelier_live_view_correct = liveOk;

  // Step 5 : Test Zone danger — Reset Total modal step 1 + step 2 (sans click final destructif)
  await page.click('.nav-item[data-section="danger"]');
  await page.waitForFunction(() => document.querySelector('.danger-zone'));
  await page.click('button:has-text("Reset Total")');
  const modalVisible = await page.isVisible('#modal-input');
  const expectedText = await page.locator('.modal-warn b').textContent();
  const btnDisabled = await page.locator('#modal-confirm').isDisabled();
  await page.fill('#modal-input', 'WRONG');
  const stillDisabled = await page.locator('#modal-confirm').isDisabled();
  await page.fill('#modal-input', 'DELETE');
  const enabledNow = !(await page.locator('#modal-confirm').isDisabled());
  await page.click('#modal-cancel'); // PAS de submit destructif
  report.danger_double_confirm = { modal_visible: modalVisible, expected_word: expectedText, btn_disabled_initially: btnDisabled, btn_disabled_on_wrong_word: stillDisabled, btn_enabled_on_DELETE: enabledNow };

  // Step 6 : sauve report.json + index.html
  fs.writeFileSync(path.join(OUT_DIR, 'report.json'), JSON.stringify(report, null, 2));
  const ok = report.sections.every(s => s.title_ok && s.errors.length === 0);
  const indexHtml = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Superadmin walkthrough ${TS}</title>
<style>body{font-family:system-ui;background:#fafaf7;color:#2a1810;padding:24px;max-width:1100px;margin:auto}
h1{font-size:24px;color:#001D3D}h2{font-size:16px;margin-top:18px;color:#001D3D}
.sec{background:#fff;border:1px solid #e5dac6;border-radius:10px;padding:14px;margin:10px 0}
.sec.ok{border-left:4px solid #2e7d32}.sec.ko{border-left:4px solid #c73e3e}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px;margin-top:8px}
.bd{font-size:12px;color:#6b5e4a}.err{color:#c73e3e;font-family:monospace;font-size:11px;background:#fef2f2;padding:6px;border-radius:4px}
table{border-collapse:collapse;width:100%;font-size:13px}td,th{padding:6px 8px;border-bottom:1px solid #f0e6d5;text-align:left}
.badge-ok{background:#e8f5e9;color:#2e7d32;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600}
.badge-ko{background:#fdecea;color:#c73e3e;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600}
</style></head><body>
<h1>Superadmin walkthrough — ${TS}</h1>
<p class="bd">Statut global : <span class="badge-${ok ? 'ok' : 'ko'}">${ok ? 'PASS' : 'PARTIAL'}</span> · Sections testées : ${report.sections.length}/8</p>
<h2>Liens Atelier</h2>
<table><tr><th>Card</th><th>Lien</th><th>OK ?</th></tr>
${ateliers.map(a => `<tr><td>${a.title || '?'}</td><td><code>${a.href || '—'}</code></td><td>${a.href ? '<span class="badge-ok">href OK</span>' : '<span class="badge-ko">manquant</span>'}</td></tr>`).join('')}
</table>
<p class="bd">Live View correct (46-225-215-148.sslip.io/live) : ${liveOk ? '<span class="badge-ok">OK</span>' : '<span class="badge-ko">KO</span>'}</p>
<h2>Zone danger — double confirmation</h2>
<table>
<tr><th>Modal visible</th><td>${report.danger_double_confirm.modal_visible ? '<span class="badge-ok">oui</span>' : '<span class="badge-ko">non</span>'}</td></tr>
<tr><th>Mot attendu</th><td><code>${report.danger_double_confirm.expected_word}</code></td></tr>
<tr><th>Bouton désactivé initialement</th><td>${report.danger_double_confirm.btn_disabled_initially ? '<span class="badge-ok">oui</span>' : '<span class="badge-ko">non</span>'}</td></tr>
<tr><th>Bouton désactivé sur mauvais mot</th><td>${report.danger_double_confirm.btn_disabled_on_wrong_word ? '<span class="badge-ok">oui</span>' : '<span class="badge-ko">non</span>'}</td></tr>
<tr><th>Bouton activé sur DELETE</th><td>${report.danger_double_confirm.btn_enabled_on_DELETE ? '<span class="badge-ok">oui</span>' : '<span class="badge-ko">non</span>'}</td></tr>
</table>
<h2>Sections (8)</h2>
${report.sections.map(s => `
<div class="sec ${s.title_ok && s.errors.length === 0 ? 'ok' : 'ko'}">
  <b>${s.label}</b> <span class="bd">· ${s.ms} ms · ${s.errors.length} erreur(s)</span><br>
  ${s.errors.length ? s.errors.map(e => `<div class="err">${e.replace(/</g, '&lt;')}</div>`).join('') : ''}
  <img src="${s.screenshot}" alt="${s.label}">
</div>`).join('')}
</body></html>`;
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), indexHtml);

  // Cleanup activation token
  try { execSync(`mariadb ocre_meta -e "UPDATE users SET activation_token=NULL WHERE email='${ADMIN_EMAIL}'"`); } catch (e) {}

  console.log('Walkthrough output : ' + OUT_DIR);
  console.log('URL : https://46-225-215-148.sslip.io/maquettes/superadmin-walkthrough-' + TS + '/');
  expect(ok).toBe(true);
});
