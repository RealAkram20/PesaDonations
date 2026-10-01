// Web vitals on a throttled phone profile (4x CPU, ~4G), on the throwaway site.
// Usage: node vitals.js <label>
// Median of RUNS loads per page. Any failed request (status >= 400) is reported
// and the run is refused: the first baseline was taken with every asset 404ing
// and nothing said so.
const { chromium } = require('D:/xampp/htdocs/Kangaru/node_modules/playwright-core');
const fs = require('fs');
const label = process.argv[2] || 'run';
const RUNS = +(process.env.RUNS || 3);
const pages = process.env.PAGES ? process.env.PAGES.split(',') : ['/give/', '/sponsor/', '/home-sliders/', '/donate/', '/donation-checkout/?pd_cid=27'];
const median = (xs) => { const s = [...xs].sort((a, b) => a - b); return s[Math.floor(s.length / 2)]; };

async function measure(ctx, path) {
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (9 * 1024 * 1024) / 8, uploadThroughput: (1.5 * 1024 * 1024) / 8 });
  let bytes = 0, requests = 0, docBytes = 0; const failed = [];
  const docId = { id: null };
  cdp.on('Network.loadingFinished', (e) => { bytes += e.encodedDataLength; if (e.requestId === docId.id) docBytes = e.encodedDataLength; });
  cdp.on('Network.responseReceived', (e) => {
    requests++;
    if (e.type === 'Document' && !docId.id) docId.id = e.requestId;
    if (e.response.status >= 400) failed.push(e.response.status + ' ' + e.response.url);
  });
  await page.addInitScript(() => {
    window.__v = { lcp: 0, cls: 0 };
    new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__v.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__v.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  });
  const t0 = Date.now();
  await page.goto('http://127.0.0.1:8099' + path, { waitUntil: 'load' });
  const load = Date.now() - t0;
  await page.waitForTimeout(2500);
  const v = await page.evaluate(() => ({ ...window.__v, fcp: (performance.getEntriesByName('first-contentful-paint')[0] || {}).startTime || 0, ttfb: performance.getEntriesByType('navigation')[0].responseStart }));
  await page.close();
  return { ttfb: v.ttfb, fcp: v.fcp, lcp: v.lcp, cls: v.cls, kb: bytes / 1024, doc_kb: docBytes / 1024, requests, load, failed };
}

(async () => {
  fs.rmSync('D:/pdtest/profile-vitals', { recursive: true, force: true });
  const ctx = await chromium.launchPersistentContext('D:/pdtest/profile-vitals', {
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true,
    viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
  });
  const rows = [];
  const allFailed = [];
  for (const path of pages) {
    const runs = [];
    for (let i = 0; i < RUNS; i++) runs.push(await measure(ctx, path));
    runs.forEach((r) => allFailed.push(...r.failed.map((f) => path + ' -> ' + f)));
    const m = (k) => median(runs.map((r) => r[k]));
    rows.push({ path, ttfb: Math.round(m('ttfb')), fcp: Math.round(m('fcp')), lcp: Math.round(m('lcp')), cls: +m('cls').toFixed(3), kb: Math.round(m('kb')), doc_kb: Math.round(m('doc_kb')), requests: m('requests'), load_ms: m('load') });
  }
  console.table(rows);
  await ctx.close();
  if (allFailed.length) {
    console.error('FAILED REQUESTS — results not written:\n  ' + [...new Set(allFailed)].join('\n  '));
    process.exit(1);
  }
  fs.writeFileSync(`D:/pdtest/perf/vitals-${label}.json`, JSON.stringify(rows, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
