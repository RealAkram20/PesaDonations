// Settings → General currency controls and the campaign editor's currency box, in Chrome.
const { chromium } = require('D:/xampp/htdocs/Kangaru/node_modules/playwright-core');
const fs = require('fs');
const { execSync } = require('child_process');
const B = 'http://127.0.0.1:8099';
const wp = (cmd) => execSync(`bash D:/pdtest/wpx.sh ${cmd}`, { encoding: 'utf8', env: { ...process.env, MSYS_NO_PATHCONV: '1' } }).trim();

(async () => {
  const ctx = await chromium.launchPersistentContext('D:/pdtest/profile', { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push('pageerror ' + e.message));
  page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('favicon')) errs.push(r.status() + ' ' + r.url()); });
  const ok = (c, m) => console.log((c ? 'PASS ' : 'FAIL ') + m);

  await page.goto(B + '/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', fs.readFileSync('D:/pdwp/.adminpass', 'utf8').trim());
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

  await page.goto(B + '/wp-admin/admin.php?page=pd-settings&tab=general', { waitUntil: 'networkidle' });
  ok(await page.locator('input[name="pd_enabled_currencies[]"]').count() === 23, '23 currency boxes');
  ok(await page.locator('fieldset.pd-currency-group legend').allInnerTexts().then((l) => l.join('|')) === 'East Africa|International', 'grouped East Africa, International');
  const rateRow = await page.locator('tr', { hasText: 'Exchange rates' }).innerText();
  ok(/Updated \d/.test(rateRow), 'rate status shows the update time: ' + JSON.stringify(rateRow.replace(/\s+/g, ' ').trim()));
  await page.uncheck('input[name="pd_enabled_currencies[]"][value="EUR"]');
  await Promise.all([page.waitForNavigation(), page.click('#submit')]);
  ok(!JSON.parse(wp('option get pd_enabled_currencies --format=json')).includes('EUR'), 'unticking EUR saves');
  await page.check('input[name="pd_enabled_currencies[]"][value="EUR"]');
  await Promise.all([page.waitForNavigation(), page.click('button[name="pd_refresh_rates"]')]);
  ok(await page.locator('.notice-success', { hasText: 'Exchange rates updated' }).count() === 1, '"Save and refresh rates" saves and refreshes');
  ok(JSON.parse(wp('option get pd_enabled_currencies --format=json')).includes('EUR'), 'EUR ticked again');

  await page.goto(B + '/wp-admin/post.php?post=11&action=edit', { waitUntil: 'networkidle' });
  const opts = await page.locator('#_pd_base_currency option').count();
  ok(opts === 23, `campaign base currency offers ${opts} currencies`);
  ok(await page.locator('input[name="_pd_single_currency"]').count() === 1 && await page.locator('input[name="_pd_allow_currency_switch"]').count() === 0, 'opt-out box replaces the old switch box');

  console.log(errs.length ? 'ERRORS:\n  ' + errs.join('\n  ') : 'no page errors, no failed requests');
  await ctx.close();
})().catch((e) => { console.error('SCRIPT', e); process.exit(1); });
