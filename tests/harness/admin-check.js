// Admin screens in a real browser: no JS errors, no failed assets, the secret eye works.
const { chromium } = require('D:/xampp/htdocs/Kangaru/node_modules/playwright-core');
const fs = require('fs');
const B = 'http://127.0.0.1:8099';

(async () => {
  const ctx = await chromium.launchPersistentContext('D:/pdtest/profile', {
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true,
  });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', e => errs.push(page.url() + ' pageerror ' + e.message));
  page.on('response', r => { if (r.status() >= 400 && !r.url().includes('favicon')) errs.push(r.status() + ' ' + r.url()); });
  const ok = (c, m) => console.log((c ? 'PASS ' : 'FAIL ') + m);

  await page.goto(B + '/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', fs.readFileSync('D:/pdwp/.adminpass', 'utf8').trim());
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

  const screens = [
    'admin.php?page=pd-donations', 'admin.php?page=pd-donation-new', 'admin.php?page=pd-donors',
    'admin.php?page=pd-donor-new', 'admin.php?page=pd-settings&tab=pesapal', 'admin.php?page=pd-settings&tab=advanced',
    'admin.php?page=pd-system-status', 'edit.php?post_type=pd_campaign', 'post-new.php?post_type=pd_campaign', '../dashboard/',
  ];
  for (const s of screens) {
    const res = await page.goto(B + '/wp-admin/' + s, { waitUntil: 'networkidle' });
    const body = await page.content();
    const phpErr = /Fatal error|Warning:|Notice:|Deprecated:/.test(body);
    ok(res.status() === 200 && !phpErr, `${s} loads (${res.status()})${phpErr ? ' with PHP output' : ''}`);
  }
  // pd-admin.css loads on the plugin's own screens now.
  await page.goto(B + '/wp-admin/admin.php?page=pd-donations', { waitUntil: 'networkidle' });
  ok(await page.locator('link[href*="pd-admin.css"]').count() === 1, 'pd-admin.css enqueued on All Donations');

  await page.goto(B + '/wp-admin/admin.php?page=pd-settings&tab=pesapal', { waitUntil: 'networkidle' });
  const input = page.locator('#pd_pesapal_consumer_secret');
  ok(await input.inputValue() === '', 'secret box is empty on screen');
  ok((await input.getAttribute('placeholder') || '').length > 0, 'placeholder says a secret is saved');
  await input.fill('typed-secret');
  await page.click('.pd-secret__eye[aria-controls="pd_pesapal_consumer_secret"]');
  ok(await input.getAttribute('type') === 'text', 'eye reveals what is typed');
  ok(await page.getAttribute('.pd-secret__eye[aria-controls="pd_pesapal_consumer_secret"]', 'aria-pressed') === 'true', 'eye reports pressed');
  await page.click('.pd-secret__eye[aria-controls="pd_pesapal_consumer_secret"]');
  ok(await input.getAttribute('type') === 'password', 'eye hides it again');

  await page.goto(B + '/wp-admin/admin.php?page=pd-system-status', { waitUntil: 'networkidle' });
  const status = await page.locator('table').innerText();
  ok(!/FX/.test(status), 'System Status no longer checks the removed FX job');
  ok(/Payment check/.test(status), 'System Status shows the hourly payment check');

  await page.goto(B + '/dashboard/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  const alpineErrs = errs.filter(e => /Alpine|TypeError/.test(e));
  ok(alpineErrs.length === 0, 'dashboard renders without Alpine errors');

  console.log(errs.length ? 'ERRORS:\n  ' + errs.join('\n  ') : 'no page errors, no failed requests');
  await ctx.close();
})().catch(e => { console.error('SCRIPT', e); process.exit(1); });
