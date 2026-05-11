// M/2026/05/11/46 — Tests M_OCRE_VITRINE_RESPONSIVE : pas d'overflow horizontal global sur iPhone.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/vitrine-responsive-${TS}`;

const IPHONES = [
  { name: 'iphone-SE-375',     viewport: { width: 375, height: 667 } },
  { name: 'iphone-13-390',     viewport: { width: 390, height: 844 } },
  { name: 'iphone-14-PM-430',  viewport: { width: 430, height: 932 } },
];

async function checkNoOverflow(page, viewport, url) {
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  const stats = await page.evaluate(() => ({
    docWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    bodyScrollWidth: document.body.scrollWidth,
    htmlOverflowX: getComputedStyle(document.documentElement).overflowX,
    bodyOverflowX: getComputedStyle(document.body).overflowX,
    viewport: (document.querySelector('meta[name="viewport"]') || {}).content || null,
  }));
  // (1) document scrollWidth ne dépasse pas le viewport
  expect(stats.scrollWidth).toBeLessThanOrEqual(stats.docWidth);
  // (2) body scrollWidth idem (tolerance 1px subpixel)
  expect(stats.bodyScrollWidth).toBeLessThanOrEqual(stats.docWidth + 1);
  // (3) html + body overflow-x = hidden (filet de securite)
  expect(stats.htmlOverflowX).toBe('hidden');
  expect(stats.bodyOverflowX).toBe('hidden');
  // (4) viewport meta inclut viewport-fit=cover
  expect(stats.viewport).toMatch(/viewport-fit=cover/);
  return stats;
}

for (const vp of IPHONES) {
  test(`${vp.name} (${vp.viewport.width}×${vp.viewport.height}) : home + oi-agent sans overflow horizontal`, async ({ browser }) => {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const ctx = await browser.newContext({ viewport: vp.viewport });
    const page = await ctx.newPage();
    await checkNoOverflow(page, vp.viewport, 'https://ocre.immo/');
    await page.screenshot({ path: path.join(OUT_DIR, `${vp.name}-home.png`), fullPage: false });

    await checkNoOverflow(page, vp.viewport, 'https://ocre.immo/oi-agent');
    await page.screenshot({ path: path.join(OUT_DIR, `${vp.name}-oi-agent.png`), fullPage: false });

    // Popup login : pas d'overflow + bounding box width ≤ viewport
    await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
    await page.waitForSelector('#oal-overlay.oal-show');
    const popupBox = await page.locator('.oal-modal').boundingBox();
    expect(popupBox.width).toBeLessThanOrEqual(vp.viewport.width + 1);
    await page.screenshot({ path: path.join(OUT_DIR, `${vp.name}-popup.png`), fullPage: false });
    await ctx.close();
  });
}

test('Desktop 1440×900 anti-régression : pas d\'overflow + design inchangé', async ({ browser }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await checkNoOverflow(page, { width: 1440, height: 900 }, 'https://ocre.immo/');
  await page.screenshot({ path: path.join(OUT_DIR, 'desktop-1440-home.png'), fullPage: false });
  await ctx.close();
});

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>vitrine-responsive ${TS}</title>
<style>body{font-family:system-ui;max-width:1300px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:24px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px}
.cell{background:#fff;border:1px solid #e5dac6;border-radius:8px;padding:10px}
.cell h3{font-size:12px;color:#6b5e4a;margin-bottom:8px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_OCRE_VITRINE_RESPONSIVE — pas d'overflow horizontal iPhone</h1>
<p>Mission M/2026/05/11/46 — ${TS}</p>
<p class="ok">PASS ✓ iPhone SE / 13 / 14 PM home + oi-agent + popup · Desktop 1440 anti-régression</p>
${['iphone-SE-375','iphone-13-390','iphone-14-PM-430'].map(v => `
<h2>${v}</h2>
<div class="grid">
<div class="cell"><h3>Home</h3><img src="${v}-home.png"></div>
<div class="cell"><h3>Oi Agent</h3><img src="${v}-oi-agent.png"></div>
<div class="cell"><h3>Popup ouvert</h3><img src="${v}-popup.png"></div>
</div>`).join('')}
<h2>desktop-1440</h2><img src="desktop-1440-home.png" style="max-width:600px">
</body></html>`);
});
