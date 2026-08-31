//! `data::*` — the read/write surface over the local mirror (`SYNCDESKTOP.md` §6.2).
//!
//! Every read goes through [`NamedQuery`]; there is no raw-SQL command, on purpose
//! (`SYNCDESKTOP.md` §5.2: "Ham SQL UI'dan kabul edilmez — YASAK").

use tauri::State;
use uuid::Uuid;

use syncra_sync::{Entity, LocalMutation, NamedQuery, QueryParams, Row, SearchHit};

use super::{CommandError, CommandResult};
use crate::state::AppState;

/// Run a whitelisted query (`NamedQuery`) against the local mirror.
#[tauri::command]
pub fn query(
    state: State<'_, AppState>,
    query: NamedQuery,
    params: QueryParams,
) -> CommandResult<Vec<Row>> {
    state.engine.query(query, params).map_err(CommandError::from)
}

/// Apply a mutation locally and queue it for the next `sync::sync_now`.
#[tauri::command]
pub fn mutate(state: State<'_, AppState>, mutation: LocalMutation) -> CommandResult<Uuid> {
    state.engine.mutate(mutation).map_err(CommandError::from)
}

/// Local full-text search.
#[tauri::command]
pub fn search(
    state: State<'_, AppState>,
    fts: String,
    entities: Vec<Entity>,
    limit: u16,
) -> CommandResult<Vec<SearchHit>> {
    state
        .engine
        .search(&fts, &entities, limit)
        .map_err(CommandError::from)
}
