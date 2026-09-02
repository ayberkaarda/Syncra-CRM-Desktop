-- Migration 0002 — server-computed SLA fields on `tickets` (defter O2 / O35, KARAR A26).
--
-- `backend/app/Services/Sync/SyncPullService.php::attachTicketSla()` attaches four fields to
-- every `tickets` pull row, computed via the SAME `SlaService` methods `TicketResource`
-- already uses for the web client (`SyncPullTicketSlaTest` proves pull and `TicketResource`
-- never diverge). `db/upsert.rs`'s per-key loop silently drops any pulled JSON key that has no
-- matching column ("the server is free to send columns this build does not mirror") — before
-- this migration these four keys had no column, so they were dropped on every pull and
-- `mapTicket()` (desktop/src/platform/data/mappers.ts) never saw them no matter what it read.
--
-- Column types mirror the wire contract exactly — see `SlaService`
-- (backend/app/Services/Tickets/SlaService.php) and `SyncPullTicketSlaTest`:
--   sla_remaining_seconds : int|null -> INTEGER, nullable. NULL means the SLA clock has
--                                       stopped (resolved/closed ticket, or no sla_due_at at
--                                       all); this is NOT the same as 0 (clock still running,
--                                       deadline reached/passed) — KARAR A23.
--   sla_total_seconds     : int      -> INTEGER, never null on the wire (SlaService::
--                                       totalSeconds() always falls back to the priority
--                                       target).
--   sla_target_hours      : float    -> REAL, never null on the wire, may be fractional
--                                       (e.g. 4.25).
--   sla_breached          : bool     -> INTEGER (0/1), the same convention every other
--                                       boolean column in 0001_init.sql already uses (e.g.
--                                       `contacts.is_primary`) — not a project-specific
--                                       invention.
--
-- `ALTER TABLE ... ADD COLUMN` is additive and safe on a populated `tickets` table: every
-- existing row gets NULL in the four new columns until the next pull re-attaches real values,
-- which is the correct "no value yet" state for a mirror that has not talked to the server
-- since upgrading.
ALTER TABLE tickets ADD COLUMN sla_remaining_seconds INTEGER;
ALTER TABLE tickets ADD COLUMN sla_total_seconds      INTEGER;
ALTER TABLE tickets ADD COLUMN sla_target_hours       REAL;
ALTER TABLE tickets ADD COLUMN sla_breached           INTEGER;

-- ---------------------------------------------------------------------------------------------
-- pipeline_stages.name_key — the SAME silent-drop bug (defter O7 follow-up), a different
-- table.
-- ---------------------------------------------------------------------------------------------
--
-- `PipelineStage::$fillable` (backend/app/Models/PipelineStage.php) carries a real
-- `name_key` column, `PipelineStageResource` exposes it, and `SyncPullService`'s pull query
-- for `pipeline_stages` is `SELECT *` — so it has always been present on the wire. It only
-- became a visible gap when the desktop Kanban board (O7) moved from talking to the server
-- directly to reading the local mirror: `pipeline_stages` never had a `name_key` column, so
-- `db/upsert.rs`'s per-key loop dropped it on every pull, exactly like the four ticket SLA
-- fields above.
--
-- `frontend/src/features/deals/types.ts` renders `enums:pipelineStage.<name_key>` when
-- `name_key` is set (the built-in taxonomy stages) and falls back to the raw `name` only when
-- it is `null` (a user-created custom stage). Without this column every stage title on
-- desktop silently stopped translating the moment the board's data source changed, even
-- though the web client — which still reads `name_key` off the API response — kept working.
--
-- TEXT, nullable: `null` is a real, meaningful value here (a custom stage has no taxonomy
-- key), not "value missing" — same discipline as the ticket SLA columns above, applied to a
-- string instead of a number.
ALTER TABLE pipeline_stages ADD COLUMN name_key TEXT;
