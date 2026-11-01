-- Migration 0006 — classify every outbox row that predates `outbox.sent` as sent.
--
-- THE BUG
-- -------
-- Migration 0005 counted a row as sent only when it was `inflight` or had `attempts > 0`. A
-- build older than 0005 recovered a crash by putting `inflight` rows back to `queued` and left
-- `attempts` alone, so a row that reached the server, was applied there, and lost its answer to
-- the crash looks exactly like a row that never left: `queued`, `attempts = 0`. 0005 left it at
-- `sent = 0`, and the coalescing rules then folded the next edit into it under its old
-- idempotency key (the server answers that key with the stored result, so the edit is lost), or
-- cancelled it against a delete (so the server keeps the row).
--
-- Nothing in an older database tells the two apart. `last_error` is only written by a failed
-- request, and the crash recovery wrote nothing at all.
--
-- THE FIX
-- -------
-- Every row present at this upgrade counts as sent. For a row that really never left, the cost
-- is that it is not coalesced: a later edit is pushed as its own mutation, and a create followed
-- by a delete is sent as both. Both are correct on the server. The opposite mistake loses an edit
-- or keeps a deleted record alive.
--
-- A database already at 5 gets the same treatment: its rows from before 0005 were classified by
-- 0005 and may be wrong, and a row the 0005 build queued itself and never sent only loses its
-- coalescing.

UPDATE outbox SET sent = 1;

-- ---------------------------------------------------------------------------------------------
-- Change revision
-- ---------------------------------------------------------------------------------------------
--
-- A forced logout refuses to wipe an edit made while its revocation was on the wire. It used to
-- find one by a higher `seq` or a newer `occurred_at`. Neither holds: `seq` is `MAX(seq) + 1`, so
-- it is handed out again once the newest row has settled and left, and `occurred_at` is the wall
-- clock, so an edit folded into an existing row in the same millisecond, or after the clock went
-- back, does not read as newer.
--
-- `outbox_revision` holds one counter that only goes up. Every enqueue — a new row or an edit
-- folded into one — takes the next value and stamps it on the row it wrote, in `outbox.rev`.
-- The counter is not part of the wipe, so it never goes back either. Rows from before this
-- migration carry 0, older than any mark taken after it.

ALTER TABLE outbox ADD COLUMN rev INTEGER NOT NULL DEFAULT 0;

CREATE TABLE outbox_revision (
  id    INTEGER PRIMARY KEY CHECK (id = 1),
  value INTEGER NOT NULL
);
INSERT INTO outbox_revision(id, value) VALUES (1, 0);
