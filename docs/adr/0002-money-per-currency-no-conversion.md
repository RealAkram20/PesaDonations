# ADR-0002: Money is shown per currency and never converted

**Status:** Accepted
**Date:** 2026-09-30

## Context

Donations arrive in UGX, KES, TZS and USD. The table has an `amount_base`
column meant for a converted amount, but the exchange-rate job was removed in
1.2.0 (its result was never read and the rate service now needs a key), so
`amount_base` is stored equal to `amount`. Screens that summed it added
shillings to dollars: a donor's "Total Given 150,040", a USD campaign reaching
its goal with 50,000 UGX.

## Decision

- Totals are grouped by currency and shown side by side ("150,000 UGX + 40.00
  USD"), through `Utils\Money::format_totals()`. Never summed across currencies.
- A campaign's raised amount counts only its own base currency; the dashboard
  totals the site's default currency and lists the others beside it.
- Amounts print in whole units for UGX, TZS, RWF and BIF, two decimals otherwise
  (`Money::format()`).
- `amount_base` stays as a column (and `total_donated_base` for sorting donors),
  but no screen presents it as money.

## Consequences

- No figure on any screen mixes currencies, so none is invented.
- A campaign's progress ignores gifts in another currency; they are listed in
  the donations, not lost.
- There is no single "total raised" across currencies. Adding one needs a rate
  source and a stored rate per donation, and a superseding ADR.

## Alternatives considered

- **Convert at a live rate on display.** Figures would change daily for gifts
  already received, and every page view would depend on an outside service.
- **Convert at the time of the gift.** The right design if conversion is ever
  needed; it needs a rate provider and a per-donation stored rate, which the
  plugin does not have. Rio's decision, not a bug fix.
