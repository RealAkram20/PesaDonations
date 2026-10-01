// Browser check of the public components on the throwaway site (127.0.0.1:8099).
const { chromium } = require('D:/xampp/htdocs/Kangaru/node_modules/playwright-core');
const B = 'http://127.0.0.1:8099';
const CID = process.argv[2];

(async () => {
  const ctx = await chromium.launchPersistentContext('D:/pdtest/profile', {
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true,
  });
  const page = await ctx.newPage();
  const errs = [];
  const ajax = [];
  page.on('pageerror', e => errs.push('pageerror ' + e.message));
  page.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') errs.push('console ' + m.text()); });
  page.on('response', r => {
    if (r.url().includes('admin-ajax.php')) ajax.push(r.request().method() + ' ' + r.status() + ' ' + (r.url().split('action=')[1] || 'post'));
    else if (r.status() >= 400) errs.push(r.status() + ' ' + r.url());
  });
  const ok = (cond, msg) => console.log((cond ? 'PASS ' : 'FAIL ') + msg);
  const data = sel => page.evaluate(s => { const el = document.querySelector(s); return el && el._x_dataStack ? JSON.parse(JSON.stringify(el._x_dataStack[0], (k, v) => (v instanceof Node ? undefined : v))) : null; }, sel);

  // ---- Browse (projects) -------------------------------------------------
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(B + '/give/', { waitUntil: 'networkidle' });
  const cards = await page.locator('.pd-grid article:visible').count();
  ok(cards > 0 && cards <= 12, `/give/ grid renders ${cards} cards (per page 12)`);
  ok(await page.locator('.pd-select-wrap--sort .pd-select-label').innerText() === 'Default', 'sort label comes from the PHP labels');

  await page.locator('.pd-grid article:visible button:has-text("View Details")').first().click();
  await page.waitForSelector('.pd-modal-overlay', { state: 'visible' });
  const title = await page.locator('.pd-modal__title').innerText();
  ok(title.trim().length > 0, `details modal shows title at once: "${title.trim()}"`);
  await page.waitForFunction(() => { const el = document.querySelector('.pd-browse'); return el && !el._x_dataStack[0].detailsLoading; }, null, { timeout: 5000 });
  const content = await page.locator('.pd-modal__content').innerText();
  ok(content.trim().length > 0, `story loaded after open (${content.trim().length} chars)`);
  ok(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('pd-modal__close')), 'focus moved to the close button');
  const hasBar = await page.evaluate(() => document.querySelector('.pd-browse')._x_dataStack[0].active.has_progress);
  const barShown = await page.locator('.pd-modal .pd-progress:visible').count();
  ok(hasBar ? barShown === 1 : barShown === 0, `modal progress bar ${hasBar ? 'shown' : 'hidden'} as the campaign says (${barShown})`);
  await page.keyboard.press('Escape');
  await page.waitForSelector('.pd-modal-overlay', { state: 'hidden' });
  ok(true, 'Escape closes the modal');
  const detailCalls = ajax.filter(a => a.includes('pd_campaign_details')).length;
  await page.locator('.pd-grid article:visible button:has-text("View Details")').first().click();
  await page.waitForSelector('.pd-modal-overlay', { state: 'visible' });
  await page.waitForTimeout(300);
  ok(ajax.filter(a => a.includes('pd_campaign_details')).length === detailCalls, 'second open of the same campaign uses the cached story');
  await page.keyboard.press('Escape');

  // Pagination: page 3, then a search narrowing to fewer than 3 pages must still show results.
  const pages = await page.locator('.pd-browse__pagination .pd-page-btn').count();
  if (pages >= 5) {
    await page.locator('.pd-browse__pagination .pd-page-btn', { hasText: '3' }).first().click();
    const first = await data('.pd-browse');
    const name = first.campaigns[0].title;
    await page.fill('.pd-browse__search', name);
    await page.waitForTimeout(500);
    const after = await page.locator('.pd-grid article:visible').count();
    const empty = await page.locator('.pd-empty:visible').count();
    ok(after > 0 && empty === 0, `on page 3, a search for "${name}" shows ${after} results, not "No results"`);
    await page.fill('.pd-browse__search', '');
  } else {
    console.log('SKIP pagination (only ' + pages + ' buttons)');
  }
  await page.waitForTimeout(400);
  await page.locator('.pd-view-btn').nth(1).click();
  await page.waitForTimeout(300);
  const rows = await page.locator('.pd-list article:visible').count();
  ok(rows > 0, `list view renders ${rows} rows`);
  if (!rows) console.log('DEBUG', JSON.stringify(await page.evaluate(() => { const d = document.querySelector('.pd-browse')._x_dataStack[0]; return { view: d.view, n: d.paginated.length, page: d.page, search: d.filters.search, listDisplay: getComputedStyle(document.querySelector('.pd-list')).display }; })));
  const progressShown = await page.evaluate(() => { const b = document.querySelector('.pd-browse'); return b._x_dataStack[0].campaigns.filter(c => c.has_progress).length; });
  console.log('INFO campaigns with a progress bar:', progressShown);

  // ---- Sliders -------------------------------------------------------------
  await page.goto(B + '/home-sliders/', { waitUntil: 'networkidle' });
  const sliders = await page.locator('.pd-slider[x-data]').count();
  ok(sliders > 0, `${sliders} sliders on /home-sliders/`);
  const perView = await page.evaluate(() => getComputedStyle(document.querySelector('.pd-slider[x-data]')).getPropertyValue('--pd-slider-per-view').trim());
  console.log('INFO slider --pd-slider-per-view computed:', perView);
  await page.locator('.pd-slider[x-data] button:has-text("View Details")').first().click();
  await page.waitForSelector('.pd-slider .pd-modal-overlay >> visible=true');
  const st = (await page.locator('.pd-slider .pd-modal__title >> visible=true').first().innerText()).trim();
  ok(st.length > 0, `slider details modal title: "${st}"`);
  await page.keyboard.press('Escape');

  // ---- Sponsor browse -------------------------------------------------------
  await page.goto(B + '/sponsor/', { waitUntil: 'networkidle' });
  ok(await page.locator('.pd-grid article:visible').count() > 0, '/sponsor/ grid renders');

  // ---- Checkout: comma amount, expired nonce retried -----------------------
  await page.goto(B + '/donation-checkout/?pd_cid=' + CID, { waitUntil: 'networkidle' });
  ok(await page.locator('label[for$="-first-name"]').count() === 1, 'first name has a real label');
  await page.fill('input[id$="-amount"]', '50,000');
  await page.fill('input[id$="-first-name"]', 'Test');
  await page.fill('input[id$="-last-name"]', 'Donor');
  await page.fill('input[id$="-email"]', 'test.donor@example.com');
  await page.fill('input[id$="-confirm-email"]', 'Test.Donor@example.com');
  await page.check('input[x-model="formData.agree_terms"]');
  await page.evaluate(() => { document.querySelector('.pd-checkout')._x_dataStack[0].nonce = 'expired'; });
  const before = ajax.length;
  await page.locator('.pd-checkout__submit button').click();
  await page.waitForFunction(() => !document.querySelector('.pd-checkout')._x_dataStack[0].loading, null, { timeout: 40000 });
  const seq = ajax.slice(before);
  console.log('INFO checkout requests:', seq.join(' , '));
  ok(seq.length === 3 && seq[0].startsWith('POST 403') && seq[1].includes('pd_nonce') && !seq[2].startsWith('POST 403'), 'expired nonce: refused, fresh nonce fetched, sent again');
  const ge = await page.locator('.pd-error-msg--global').innerText().catch(() => '');
  console.log('INFO message after submit:', JSON.stringify(ge));
  const errsInForm = (await data('.pd-checkout')).errors;
  ok(!errsInForm.amount, '"50,000" accepted as an amount');

  console.log(errs.length ? 'ERRORS:\n  ' + errs.join('\n  ') : 'no page errors, no console errors, no 4xx/5xx assets');
  await ctx.close();
})().catch(e => { console.error('SCRIPT', e); process.exit(1); });
