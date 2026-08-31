// Local writes — `SYNCDESKTOP.md` §5.2 `mutate()`.
//
// Every write here is applied to the local mirror **and** queued in the outbox in one
// transaction, so the UI sees its own edit immediately and the engine never loses an edit it
// already showed (`syncra_sync::sync::local`). Nothing in this module talks to the network;
// the push happens on the next sync round.
//
// ## What is deliberately NOT here
//
// * **Read-only entities.** `products`, `price_lists`, `price_list_items`, `saved_views`,
//   `users`, `exchange_rates`, `pipeline_stages`, `custom_fields` and `settings` are RO in the
//   sync scope (`SYNCDESKTOP.md` §4.1), and `SyncEngine::mutate` refuses them outright
//   ("`{entity}` is read-only"). Their writes go to `platform.http`.
// * **Actions outside the whitelist.** `syncra_sync::protocol::ACTION_WHITELIST` is the list
//   of `op = action` values the server accepts offline; anything else is answered `rejected`
//   with `ONLINE_ONLY`, which is exactly `SYNCDESKTOP.md` §8 expressed on the wire. Those go
//   to `platform.http` too, rather than into an outbox that would tell the user the work was
//   done.
import {
  clientIdFor,
  mutate,
  newClientId,
  rowsByIds,
  runQuery,
  MissingRowError,
  type EntityName,
  type LocalRow,
} from './engine'

/** A create/update body: the REST payload, plus any mirror column it implies. */
export type WritePayload = Record<string, unknown>

/**
 * Create a row locally and queue it.
 *
 * The returned local identity is the only handle the caller has until the push assigns a
 * server id, which is why every create in this layer reads the row back by `client_id`.
 */
export async function createRow(entity: EntityName, payload: WritePayload): Promise<string> {
  const clientId = newClientId()
  await mutate({ entity, op: 'create', client_id: clientId, payload })
  return clientId
}

/**
 * Update a row addressed by the id its DTO carries.
 *
 * `changed_fields` is the payload's own key set: protocol §4.4 writes exactly those fields and
 * nothing else, on the server and in the mirror alike, and `SyncEngine::mutate` rejects an
 * update whose `changed_fields` is empty or names a key the payload lacks.
 */
export async function updateRow(
  entity: EntityName,
  id: number,
  payload: WritePayload,
): Promise<void> {
  const clientId = await clientIdFor(entity, id)
  const changed = Object.keys(payload)
  if (changed.length === 0) return
  await mutate({ entity, op: 'update', client_id: clientId, changed_fields: changed, payload })
}

/** Update a row addressed by its local identity (the notifications case, protocol §6.1 P12). */
export async function updateRowByClientId(
  entity: EntityName,
  clientId: string,
  payload: WritePayload,
): Promise<void> {
  const changed = Object.keys(payload)
  if (changed.length === 0) return
  await mutate({ entity, op: 'update', client_id: clientId, changed_fields: changed, payload })
}

/** Soft-delete a row locally (it becomes a tombstone) and queue the deletion. */
export async function deleteRow(entity: EntityName, id: number): Promise<void> {
  const clientId = await clientIdFor(entity, id)
  await mutate({ entity, op: 'delete', client_id: clientId })
}

/** Soft-delete a row addressed by its local identity. */
export async function deleteRowByClientId(entity: EntityName, clientId: string): Promise<void> {
  await mutate({ entity, op: 'delete', client_id: clientId })
}

/**
 * Run a whitelisted domain action against a row.
 *
 * The whitelist lives in the crate (`ACTION_WHITELIST`); an action outside it fails validation
 * before anything is written, which is the loud failure this layer wants — the alternative is
 * an outbox entry the server will never accept.
 */
export async function runAction(
  entity: EntityName,
  id: number,
  action: string,
  payload: WritePayload = {},
): Promise<void> {
  const clientId = await clientIdFor(entity, id)
  await mutate({ entity, op: 'action', action, client_id: clientId, payload })
}

/** Run a whitelisted action against a row addressed by its local identity. */
export async function runActionByClientId(
  entity: EntityName,
  clientId: string,
  action: string,
  payload: WritePayload = {},
): Promise<void> {
  await mutate({ entity, op: 'action', action, client_id: clientId, payload })
}

/** Run the one action that has no row identity at all (protocol §4.3 P10). */
export async function runUserScopedAction(entity: EntityName, action: string): Promise<void> {
  await mutate({ entity, op: 'action', action })
}

/**
 * Read a row back after writing it, so the caller can return the DTO the REST endpoint would
 * have returned.
 *
 * Accepts either identity: a number is the id a DTO carries (server id, or `-local_rowid`),
 * a string is the local `client_id` a fresh create just produced.
 */
export async function readBack(entity: EntityName, ref: number | string): Promise<LocalRow> {
  if (typeof ref === 'number') {
    const rows = await rowsByIds(entity, [ref])
    if (!rows[0]) throw new MissingRowError(entity, ref)
    return rows[0]
  }
  const rows = await runQuery({ query: 'rows_by_client_ids', entity, client_ids: [ref] }, { limit: 1 })
  if (!rows[0]) throw new MissingRowError(entity, 0)
  return rows[0]
}
