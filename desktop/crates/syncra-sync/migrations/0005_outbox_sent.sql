-- Migration 0005 — `outbox.sent`: has this mutation ever left the device?
--
-- THE BUG
-- -------
-- A mutation that went out and came back to `queued` without a verdict (a 5xx, a timeout, a
-- crash while it was in flight) may already be applied on the server under its
-- `idempotency_key`. The coalescing rules of SYNCDESKTOP.md §5.4 still treated it as a purely
-- local row:
--
--   * a later edit was folded into it, under the same key; the resend then got the server's
--     stored result for the *old* payload back, the outbox row was settled, and the new value
--     never reached the server;
--   * a later delete of a created row cancelled both locally, while the create stayed on the
--     server for good.
--
-- THE FIX
-- -------
-- `outbox::mark_inflight` sets `sent = 1` and nothing ever clears it. `outbox::enqueue` only
-- coalesces into rows with `sent = 0`; an edit or a delete after a sent row is queued as its
-- own mutation with its own key, and the sent row is resent unchanged.
--
-- EXISTING ROWS
-- -------------
-- An `inflight` row (the process died with it on the wire) and any row with `attempts > 0`
-- (it has gone out and failed at least once) count as sent. Everything else never left.

ALTER TABLE outbox ADD COLUMN sent INTEGER NOT NULL DEFAULT 0;

UPDATE outbox SET sent = 1 WHERE state = 'inflight' OR attempts > 0;
