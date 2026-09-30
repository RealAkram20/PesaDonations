# ADR-0001: A donation's status changes only through one state machine

**Status:** Accepted
**Date:** 2026-09-30

## Context

A donation's status was written from five places: the PesaPal callback, the IPN,
the admin donation editor, the admin bulk actions and the checkout. Each wrote
the column directly. The callback and the IPN arrive together for the same
payment, so receipts could go twice; the admin editor could move a completed
gift back to pending; bulk actions skipped receipts and left campaign totals
stale. Money records need one rule, applied the same way everywhere.

## Decision

`Donation::transition( $to, $fields, $context )` is the only writer of `status`
and `completed_at` (`Donation::update()` refuses both). It applies the move in
one conditional UPDATE, `WHERE id = ? AND status IN (states that may move to $to)`,
so of two racing callers exactly one wins. Only the winner recalculates the
donor, flushes campaign totals and fires `pd_donation_{status}` with a context
(`source`, `notify`, `previous`). The allowed moves are `Donation::MOVES`:

| From | To |
|---|---|
| pending | completed, failed, cancelled |
| failed | completed |
| cancelled | completed |
| completed | reversed |
| reversed | (final) |

## Consequences

- Receipts and alerts go once per real change; listeners read `source` and
  `notify` (an admin edit emails the donor only when asked; bulk changes never do).
- Nothing moves back to pending, and a completed gift can only be reversed. An
  admin who needs to undo a wrong completion has no button for it; the escape
  hatch is a new move added to `MOVES` by a superseding ADR, never a direct
  UPDATE.
- The admin screens offer only the allowed moves, and bulk actions report the
  rows the rules refused.

## Alternatives considered

- **Row locks (`SELECT … FOR UPDATE`) in a transaction.** Correct, but every
  caller would need the transaction; the conditional UPDATE gives the same
  single winner in one statement.
- **Let the admin set any status.** It is how 1.1.0 behaved, and it is how
  receipts went missing and totals went stale. Money records follow rules.
