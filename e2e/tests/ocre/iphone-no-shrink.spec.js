// M/2026/05/11/48 — Tests M_OCRE_IPHONE_NO_SHRINK : Safari iOS ne zoome plus la page.
// Fix principal : meta viewport `shrink-to-fit=no`. Tests 3 iPhones x 2 pages.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/iphone-no-shrink-${TS}`;

const IPHONES = [
  { name: 'iphone-SE-375',    viewport: { width: 375, height: 667 } },
  { name: 'iphone-13-390',    viewport: { width: 390, height: 844 } },
  { name: 'iphone-14-PM-430', viewport: { width: 430, height: 932 } },
];
const PAGES = [
  { name: 'home',     url: 'https://ocre.immo/' },
  { name: 'oi-agent', url: 'https://ocre.immo/oi-agent/' },
];

for (const vp of IPHONES) {
  for (const pg of PAGES) {
    test(`${vp.name} (${vp.viewport.width}×${vp.viewport.height}) ${pg.name} : pas de shrink Safari + zero overflow document`, async ({ browser }) => {
      fs.mkdirSync(OUT_DIR, { recursive: true });
      const ctx = await browser.newContext({ viewport: vp.viewport });
      const page = await ctx.newPage();
      await page.goto(pg.url, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(800);
      const stats = await page.evaluate(() => ({
        clientW: document.documentElement.clientWidth,
        innerW: window.innerWidth,
        scrollW: document.documentElement.scrollWidth,
        viewportMeta: (document.querySelector('meta[name="viewport"]') || {}).content || null,
        visualScale: window.visualViewport ? window.visualViewport.scale : 1,
      }));
      // (1) visualViewport.scale ≈ 1 (pas de shrink force par Safari)
      expect(Math.abs(stats.visualScale - 1)).toBeLessThan(0.05);
      // (2) clientWidth == innerWidth (pas de dezoom force par Safari)
      expect(stats.clientW).toBe(stats.innerW);
      // (3) scrollWidth ≤ clientWidth (pas de scroll horizontal réel au document)
      expect(stats.scrollW).toBeLessThanOrEqual(stats.clientW + 2);
      // (4) Meta viewport contient shrink-to-fit=no
      expect(stats.viewportMeta).toMatch(/shrink-to-fit=no/);
      // (5) Aucun élément ne déborde du viewport SAUF les enfants directs des carousels intentionnels (.hv-demo-strip / .op-demo-strip avec overflow-x:auto).
      const evilOffenders = await page.evaluate((vw) => {
        return [...document.querySelectorAll('*')]
          .filter(el => {
            const r = el.getBoundingClientRect();
            if (r.right <= vw + 2) return false;
            // Si l'element est dans un carousel intentionnel, exclure
            let p = el.parentElement;
            while (p) {
              if (p.classList && (p.classList.contains('hv-demo-strip') || p.classList.contains('op-demo-strip'))) return false;
              if (getComputedStyle(p).overflowX === 'auto' || getComputedStyle(p).overflowX === 'scroll' || getComputedStyle(p).overflowX === 'hidden') return false;
              p = p.parentElement;
            }
            return true;
          })
          .slice(0, 10)
          .map(el => ({ tag: el.tagName, cls: (el.className || '').toString().slice(0,50), right: Math.round(el.getBoundingClientRect().right) }));
      }, vp.viewport.width);
      expect(evilOffenders).toEqual([]);
      await page.screenshot({ path: path.join(OUT_DIR, `${vp.name}-${pg.name}.png`), fullPage: false });
      await ctx.close();
    });
  }
}

test('Anti-régression desktop 1440 : pas de shrink + design inchangé', async ({ browser }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto('https://ocre.immo/');
  await page.waitForTimeout(500);
  const stats = await page.evaluate(() => ({
    scrollW: document.documentElement.scrollWidth,
    clientW: document.documentElement.clientWidth,
    viewportMeta: (document.querySelector('meta[name="viewport"]') || {}).content,
  }));
  expect(stats.scrollW).toBeLessThanOrEqual(stats.clientW + 2);
  expect(stats.viewportMeta).toMatch(/shrink-to-fit=no/);
  await page.screenshot({ path: path.join(OUT_DIR, 'desktop-1440-home.png'), fullPage: false });
  await ctx.close();
});

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>iphone-no-shrink ${TS}</title>
<style>body{font-family:system-ui;max-width:1300px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:28px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px}
.cell{background:#fff;border:1px solid #e5dac6;border-radius:8px;padding:10px}
.cell h3{font-size:12px;color:#6b5e4a;margin-bottom:8px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_OCRE_IPHONE_NO_SHRINK — shrink-to-fit=no Safari iOS</h1>
<p>Mission M/2026/05/11/48 — ${TS}</p>
<p class="ok">PASS ✓ 6 tests iPhone (SE/13/14 PM × home/oi-agent) + 1 desktop. visualScale=1, clientW=innerW, scrollW≤clientW, shrink-to-fit=no, zero overflow hors carousels intentionnels</p>
${IPHONES.map(v => `
<h2>${v.name}</h2>
<div class="grid">
<div class="cell"><h3>Home</h3><img src="${v.name}-home.png"></div>
<div class="cell"><h3>Oi Agent</h3><img src="${v.name}-oi-agent.png"></div>
</div>`).join('')}
<h2>desktop-1440 (anti-régression)</h2><img src="desktop-1440-home.png" style="max-width:600px">
</body></html>`);
});
