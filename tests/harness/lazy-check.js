// First-row card images load eagerly; the rest stay lazy (not fetched at load on a phone).
const { chromium } = require('D:/xampp/htdocs/Kangaru/node_modules/playwright-core');
(async () => {
  const ctx = await chromium.launchPersistentContext('D:/pdtest/profile', {
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true,
    viewport: { width: 390, height: 844 }, isMobile: true,
  });
  const page = await ctx.newPage();
  const cardImgs = new Set();
  page.on('request', (r) => { if (r.resourceType() === 'image' && /uploads/.test(r.url())) cardImgs.add(r.url()); });
  await page.goto('http://127.0.0.1:8099/give/', { waitUntil: 'networkidle' });
  const attrs = await page.$$eval('.pd-grid article img.pd-card__image', (els) => els.map((e) => e.getAttribute('loading')));
  console.log('loading attrs:', attrs.join(','));
  console.log('card images fetched at load (phone):', cardImgs.size, 'of', attrs.length);
  await ctx.close();
})();
