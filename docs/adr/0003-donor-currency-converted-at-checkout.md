# ADR-0003: Donors give in their own currency; it is converted once, at checkout

**Status:** Accepted (supersedes ADR-0002)
**Date:** 2026-10-02

## Context

Rio, 2 Oct 2026: *"we want for the people to be able to select any curency they
want we want the most popular international currencies and east africcan
currencies"*.

PesaPal's documents confirm two currencies per merchant account: the local one
(UGX, KES, TZS, RWF…) and USD (cards). EUR, GBP and neighbouring shillings are
not confirmed, and a developer reported BIF refused. ADR-0002 kept every total
in its own currency because nothing could convert one into another.

Asked, Rio chose (1) a currency PesaPal cannot charge is converted to the local
currency at the day's rate and charged in it, with USD still charged as USD;
(2) a converted gift counts toward its campaign's goal.

## Decision

- **Offered:** 23 currencies (`Utils\Currencies`): UGX, KES, TZS, RWF, BIF, SSP,
  CDF, SOS, ETB; USD, EUR, GBP, CAD, AUD, CHF, SEK, NOK, DKK, AED, ZAR, CNY, JPY,
  INR. The admin picks which (Settings → General); a campaign can opt out.
- **Charged:** the donor's currency if PesaPal charges it (local, and USD unless
  switched off), else the local currency. The checkout states the charge and the
  rate before the donor pays; the server refuses to charge a figure the donor was
  not shown (the request carries the quote; a mismatch answers 409 with the new
  figure).
- **Rates:** ExchangeRate-API's open feed, fetched once a day, stored in
  `pd_exchange_rates`, credited at checkout. A rate older than seven days is not
  used: only the campaign's own currency is offered until fresh rates arrive.
- **Stored per gift** (`Payments\Charge_Quote`): `amount`/`currency` = charged;
  `original_amount`/`original_currency` = given, when converted;
  `amount_base`/`base_currency` = counted, in the campaign's currency;
  `fx_rate` = charged → counted. Fixed at checkout, never recomputed; an admin
  edit that keeps the amount's basis keeps its rate.
- **Totals** add `amount_base WHERE base_currency = <campaign currency>`. Gifts
  from before 1.3 were back-filled `base_currency = currency`, so every existing
  total is unchanged (checked on 40 campaigns).

## Consequences

- A €50 gift moves a UGX campaign's bar by what was charged; a $20 gift by
  $20 at that day's rate.
- The donor's card bank converts the local-currency charge at its own rate; the
  donor's statement may differ slightly from our "≈" figure. Our figure is what
  PesaPal is asked for.
- Mobile money cannot pay a USD order; a donor wanting to pay by phone chooses
  the local currency. Untick "Charge dollars as dollars" to convert USD too.
- Without fresh rates the checkout still works in each campaign's own currency
  (if PesaPal charges it).
- Escape hatch: Settings → General → "Donors can choose" off restores 1.2
  behaviour at the checkout; stored conversions stay as recorded.

## Alternatives considered

- **Only what PesaPal charges** (local + USD): nothing can fail, but it does
  not give donors their own currency.
- **Send EUR/GBP/KES straight to PesaPal:** unconfirmed; a refusal would land at
  the payment step, after the donor filled the form.
- **Convert at display time with today's rate:** totals would drift daily for
  money already received. Rejected in ADR-0002 and still rejected.
- **Admin-entered fixed rates:** wrong within weeks for UGX, TZS, CDF, SSP.
