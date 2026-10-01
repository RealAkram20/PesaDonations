# Verification harness (not shipped)

Kept out of release archives by `.gitattributes`. Written for the 1.2.0 bug scan (2026-09-30);
see `docs/worklog.md` for what each part proved.

It runs against a throwaway WordPress, never a client site:

- `D:\pdwp\site` — WordPress core, database `pdwp_test` on a private MariaDB (port 3399),
  served by `php -S 127.0.0.1:8099 -t D:\pdwp\site router.php` (the `-t` matters: without it
  every static file 404s).
- `pesapal-mock.php` and `pd-perf.php` go in that site's `wp-content/mu-plugins/`. The mock answers
  PesaPal's API through `pre_http_request`; options `pd_mock_status`, `pd_mock_amount_delta` and
  `pd_mock_fail` steer it, `pd_mock_calls` counts the calls.
- Copy the scripts to `D:\pdtest\`; `sync.sh` copies the plugin working tree into the site.

| Script | Checks |
|---|---|
| `verify.sh` | checkout over HTTP, IPN and reconcile against the mock, throttle, sandbox exclusion, admin saves and bulk actions, settings, campaign-editor saves (`verify-meta.php`) |
| `schedule-test.php` | the period calendar, standalone (`php schedule-test.php`) |
| `public-check.js`, `admin-check.js`, `lazy-check.js` | the pages in Chrome (Playwright): JS errors, failed requests, modal, pagination, checkout token retry |
| `uninstall-check.sh` | uninstall keeping and deleting data, reactivation; backs up and restores the database |
| `measure.sh`, `vitals.js` | queries and server time per page; web vitals on a throttled phone profile (refuses to report if any request failed) |
