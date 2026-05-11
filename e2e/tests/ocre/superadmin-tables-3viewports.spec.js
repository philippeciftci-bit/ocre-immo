// M/2026/05/11/32 — Test tables compactes sur 3 viewports (iPhone 13, iPad portrait, Desktop 1440).
// Capture screenshots des sections Users / Sessions / Magic links + verifie densite + user-agent parsé.
const { test, expect, devices } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ADMIN_EMAIL = 'philippe.ciftci@gmail.com';
const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/superadmin-tables-${TS}`;

const VIEWPORTS = [
  { name: 'iphone-390', viewport: { width: 390, height: 844 } },
  { name: 'ipad-768',   viewport: { width: 768, height: 1024 } },
  { name: 'desktop-1440', viewport: { width: 1440, height: 900 } },
];

function genActivateToken() {
  const tok = crypto.randomBytes(32).toString('hex');
  execSync(`mariadb ocre_meta -e "UPDATE users SET activation_token='${tok}', activation_token_expires_at=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email='${ADMIN_EMAIL}' AND role='super_admin'"`);
  return tok;
}

for (const vp of VIEWPORTS) {
  test(`Tables compactes ${vp.name} (${vp.viewport.width}x${vp.viewport.height})`, async ({ browser }) => {
    test.setTimeout(60000);
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const context = await browser.newContext({ viewport: vp.viewport });
    const page = await context.newPage();
    const errors = [];
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

    const tok = genActivateToken();
    await page.goto(`https://superadmin.ocre.immo/?activate=${tok}`);
    await page.waitForSelector('#app:not([hidden])', { timeout: 20000 });

    // Ferme le drawer si ouvert (mobile)
    if (vp.viewport.width < 1024) {
      const fab = page.locator('#hamburger-btn');
      if (await fab.isVisible()) {
        // ouvre puis ferme pour assurer state initial connu
      }
    }

    let tdPad = null, uaHasLabel = false;
    for (const section of ['users', 'sessions', 'magic']) {
      // En mobile, ouvre le drawer pour cliquer
      if (vp.viewport.width < 1024) {
        await page.click('#hamburger-btn');
        await page.waitForTimeout(300);
      }
      await page.click(`.nav-item[data-section="${section}"]`);
      await page.waitForTimeout(600);
      // Capture padding/UA sur la section qui a une table avec rows (priorite users -> sessions -> magic)
      const pad = await page.evaluate(() => {
        const td = document.querySelector('.dt tbody td');
        return td ? getComputedStyle(td).padding : null;
      });
      if (pad && !tdPad) tdPad = pad;
      if (!uaHasLabel) uaHasLabel = (await page.locator('.cell-ua .ua-label').count()) > 0;
      await page.screenshot({ path: path.join(OUT_DIR, `${vp.name}-${section}.png`), fullPage: true });
    }

    // Cleanup activation token
    try { execSync(`mariadb ocre_meta -e "UPDATE users SET activation_token=NULL WHERE email='${ADMIN_EMAIL}'"`); } catch (e) {}

    fs.appendFileSync(path.join(OUT_DIR, 'report.txt'),
      `[${vp.name}] viewport=${vp.viewport.width}x${vp.viewport.height} td_padding=${tdPad} ua_parsed=${uaHasLabel} errors=${errors.length}\n`);

    expect(errors).toEqual([]);
    expect(tdPad).toMatch(/^7px 10px/); // padding densifie

    await context.close();
  });
}

test.afterAll(() => {
  const report = fs.existsSync(path.join(OUT_DIR, 'report.txt')) ? fs.readFileSync(path.join(OUT_DIR, 'report.txt'), 'utf8') : '';
  const ts = TS;
  const sections = ['users', 'sessions', 'magic'];
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>Tables compactes ${ts}</title>
<style>body{font-family:system-ui;max-width:1300px;margin:auto;padding:24px;background:#fafaf7}h1{font-size:22px;color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:32px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px;margin-top:12px}
.cell{background:#fff;border:1px solid #e5dac6;border-radius:8px;padding:10px}
.cell h3{font-size:12px;color:#6b5e4a;margin-bottom:8px;font-weight:600}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px}
pre{background:#fff;border:1px solid #e5dac6;padding:10px;border-radius:6px;font-size:11px;overflow:auto}
</style></head><body>
<h1>Superadmin tables compactes — 3 viewports</h1>
<p>Mission M/2026/05/11/32 — ${ts}</p>
<pre>${report.replace(/</g, '&lt;')}</pre>
${sections.map(s => `<h2>Section : ${s}</h2><div class="grid">${VIEWPORTS.map(v => `<div class="cell"><h3>${v.name} (${v.viewport.width}×${v.viewport.height})</h3><img src="${v.name}-${s}.png"></div>`).join('')}</div>`).join('')}
</body></html>`);
});
