// The checkout's currency choice in Chrome: picker, converted quick picks, the charge line, minimum, submit.
const { chromium } = require('D:/xampp/htdocs/Kangaru/node_modules/playwright-core');
const B = 'http://127.0.0.1:8099';
const CID = process.argv[2] || '11';

(async () => {
  const ctx = await chromium.launchPersistentContext('D:/pdtest/profile', {
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true,
  });
  const page = await ctx.newPage();
  const errs = [];
  const posts = [];
  page.on('pageerror', (e) => errs.push('pageerror ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errs.push('console ' + m.text()); });
  page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('admin-ajax')) posts.push(r.postData() || ''); });
  page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('admin-ajax')) errs.push(r.status() + ' ' + r.url()); });
  const ok = (c, m) => console.log((c ? 'PASS ' : 'FAIL ') + m);
  const state = () => page.evaluate(() => { const d = document.querySelector('.pd-checkout')._x_dataStack[0]; return { currency: d.currency, picks: d.quickPicks, conv: d.conversionText, q: d.quote, err: d.errors.amount || '' }; });

  for (const [w, label] of [[1280, 'desktop'], [390, 'phone']]) {
    await page.setViewportSize({ width: w, height: 900 });
    await page.goto(`${B}/donation-checkout/?pd_cid=${CID}`, { waitUntil: 'networkidle' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ok(overflow <= 0, `${label}: no horizontal scroll (${overflow}px)`);
  }

  const select = page.locator('select[id$="-currency"]');
  ok(await select.count() === 1, 'currency picker present');
  ok(await page.locator('label[for$="-currency"]').count() === 1, 'picker has a real label');
  let s = await state();
  ok(s.currency === 'UGX' && s.picks.length > 0, `starts in the campaign currency with quick picks ${JSON.stringify(s.picks)}`);
  ok(await page.locator('.pd-amount-convert:visible').count() === 0, 'no conversion line in the campaign currency');

  await select.selectOption('EUR');
  await page.waitForTimeout(150);
  s = await state();
  ok(s.picks.length > 0 && s.picks.every((v) => v < 1000), `quick picks converted to euros: ${JSON.stringify(s.picks)}`);
  ok(await page.locator('.pd-input-group__prefix').first().innerText() === 'EUR', 'amount prefix reads EUR');
  await page.locator('.pd-amount-btn').first().click();
  s = await state();
  const line = await page.locator('.pd-amount-convert').innerText();
  console.log('INFO conversion line:', line.replace(/\s+/g, ' '));
  ok(/charged [\d,]+ UGX/.test(line) && /1 EUR = [\d,.]+ UGX/.test(line), 'line states the UGX charge and the rate');
  ok(/Rates By Exchange Rate API/.test(line), 'line credits the rate source');

  // Below the minimum, in euros.
  await page.fill('input[id$="-amount"]', '0.5');
  await page.fill('input[id$="-first-name"]', 'Euro');
  await page.fill('input[id$="-last-name"]', 'Donor');
  const email = `browser-eur-${Date.now()}@example.com`;
  await page.fill('input[id$="-email"]', email);
  await page.fill('input[id$="-confirm-email"]', email);
  await page.check('input[x-model="formData.agree_terms"]');
  await page.locator('.pd-checkout__submit button').click();
  s = await state();
  console.log('INFO minimum message:', s.err);
  ok(/Minimum donation is [\d,]+ UGX \(about [\d.]+ EUR\)/.test(s.err), 'minimum shown in UGX and in euros');

  // A real amount: the request carries the shown charge, and the payment window opens.
  await page.fill('input[id$="-amount"]', '25');
  s = await state();
  await page.locator('.pd-checkout__submit button').click();
  await page.waitForFunction(() => { const d = document.querySelector('.pd-checkout')._x_dataStack[0]; return !d.loading; }, null, { timeout: 30000 });
  const sent = new URLSearchParams(posts[posts.length - 1].replace(/\r\n/g, '\n').split('\n').filter((l) => !l.startsWith('---') && !l.startsWith('Content-')).join('&'));
  const body = posts[posts.length - 1];
  ok(/name="currency"\s+EUR/.test(body) && new RegExp('name="quote"\\s+' + s.q.charge + '\\b').test(body), `request sent EUR and the shown charge ${s.q.charge}`);
  const iframe = await page.evaluate(() => document.querySelector('.pd-checkout')._x_dataStack[0].iframeOpen);
  ok(iframe, 'payment window opened');

  // Back to the campaign currency: no conversion.
  await page.goto(`${B}/donation-checkout/?pd_cid=${CID}`, { waitUntil: 'networkidle' });
  await page.locator('select[id$="-currency"]').selectOption('USD');
  await page.fill('input[id$="-amount"]', '20');
  ok(await page.locator('.pd-amount-convert:visible').count() === 0, 'USD: charged as dollars, no conversion line');

  console.log(errs.length ? 'ERRORS:\n  ' + errs.join('\n  ') : 'no page errors, no failed requests');
  await ctx.close();
  console.log('EMAIL ' + email);
})().catch((e) => { console.error('SCRIPT', e); process.exit(1); });
