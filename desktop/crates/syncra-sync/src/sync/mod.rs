//! [`SyncEngine`] — the public face of the crate.

pub mod backoff;
pub mod local;
pub mod pull;
pub mod push;

use crate::config::{DesktopSettings, ServerPolicy, SyncConfig, MANIFEST_CACHE_SECS, PROTOCOL_VERSION};
use crate::db::{self, query::NamedQuery, query::QueryParams, schema, upsert};
use crate::error::{Result, SyncError};
use crate::events::{EngineEvent, EVENT_CHANNEL_CAPACITY};
use crate::keystore::{self, KeyStoreHandle, MemoryKeyStore, SystemKeyStore};
use crate::outbox;
use crate::protocol::{Manifest, PushRequest};
use crate::transport::Transport;
use crate::types::*;
use crate::{conflicts, retention};
use chrono::{DateTime, Duration as ChronoDuration, Utc};
use rusqlite::Connection;
use serde_json::Value as Json;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, RwLock};
use std::time::Instant;
use tokio::sync::broadcast;
use uuid::Uuid;

/// `desktop_settings` key holding the id of the user the local database belongs to.
const SETTING_USER_ID: &str = "session.user_id";
/// `desktop_settings` key holding the cached session document.
const SETTING_SESSION: &str = "session.payload";
/// `desktop_settings` key holding the serialised [`DesktopSettings`].
const SETTING_PREFERENCES: &str = "preferences";
/// `desktop_settings` key holding the last successful retention sweep.
const SETTING_LAST_RETENTION: &str = "retention.last_run_at";

/// Timer trigger of the background loop while online (`SYNCDESKTOP.md` §5.5).
const SYNC_INTERVAL: std::time::Duration = std::time::Duration::from_secs(60);
/// How often the background loop re-checks connectivity while offline.
const OFFLINE_POLL_INTERVAL: std::time::Duration = std::time::Duration::from_secs(1);

/// Handle to the background loop started by [`SyncEngine::start_background_sync`].
///
/// Dropping it does **not** stop the loop; call [`SyncScheduler::stop`] for that, or keep
/// the handle for the lifetime of the app.
#[derive(Debug)]
pub struct SyncScheduler {
    handle: tokio::task::JoinHandle<()>,
}

impl SyncScheduler {
    /// Stop the background loop. In-flight requests are cancelled at the next await point;
    /// the outbox is durable, so nothing is lost.
    pub fn stop(self) {
        self.handle.abort();
    }
}

struct Inner {
    cfg: RwLock<SyncConfig>,
    db: Mutex<Connection>,
    keystore: KeyStoreHandle,
    transport: Transport,
    events: broadcast::Sender<EngineEvent>,
    status: Mutex<SyncStatus>,
    session: RwLock<Option<Session>>,
    token: RwLock<Option<String>>,
    manifest: Mutex<Option<(Instant, Manifest)>>,
    /// Serialises sync rounds; a trigger arriving mid-round coalesces into the next one.
    round: tokio::sync::Mutex<()>,
    /// Set once the server reports a protocol version this build cannot speak.
    halted: AtomicBool,
    /// Signalled by the triggers of §5.5 so the background loop wakes early.
    wake: tokio::sync::Notify,
}

/// The offline-first sync engine.
///
/// Cheap to clone: every clone shares one database handle, one event stream and one
/// sync-round lock.
#[derive(Clone)]
pub struct SyncEngine {
    inner: Arc<Inner>,
}

impl std::fmt::Debug for SyncEngine {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("SyncEngine")
            .field("db_path", &self.inner.cfg.read().unwrap().db_path)
            .finish_non_exhaustive()
    }
}

impl SyncEngine {
    /// Open the local database (creating and migrating it if needed) using the OS keychain
    /// for the SQLCipher key and the device token (`SYNCDESKTOP.md` K9).
    pub async fn open(cfg: SyncConfig) -> Result<Self> {
        Self::open_with_keystore(cfg, Arc::new(SystemKeyStore)).await
    }

    /// Same as [`SyncEngine::open`] with a caller-supplied secret store.
    ///
    /// Tests use [`MemoryKeyStore`] here so a test run never writes to the developer's real
    /// credential store.
    pub async fn open_with_keystore(cfg: SyncConfig, keystore: KeyStoreHandle) -> Result<Self> {
        let key = keystore::ensure_db_key(keystore.as_ref(), &cfg.keychain_service)?;
        let conn = db::open(&cfg.db_path, &key)?;
        outbox::recover_inflight(&conn)?;

        let transport = Transport::new(cfg.api_base.clone())?;
        let (events, _) = broadcast::channel(EVENT_CHANNEL_CAPACITY);

        // Preferences persisted by a previous run win over the constructor defaults.
        let mut cfg = cfg;
        if let Some(raw) = db::get_setting(&conn, SETTING_PREFERENCES)? {
            if let Ok(prefs) = serde_json::from_str::<DesktopSettings>(&raw) {
                let prefs = prefs.clamped();
                cfg.retention_days = prefs.retention_days;
                cfg.max_db_size_mb = prefs.max_db_size_mb;
                cfg.max_outbox = prefs.max_outbox;
            }
        }
        let session = db::get_setting(&conn, SETTING_SESSION)?
            .and_then(|raw| serde_json::from_str::<Session>(&raw).ok());

        let token = keystore.get(&cfg.keychain_service, keystore::KEY_TOKEN)?;

        let inner = Arc::new(Inner {
            cfg: RwLock::new(cfg),
            db: Mutex::new(conn),
            keystore,
            transport,
            events,
            status: Mutex::new(SyncStatus::default()),
            session: RwLock::new(session),
            token: RwLock::new(token),
            manifest: Mutex::new(None),
            round: tokio::sync::Mutex::new(()),
            halted: AtomicBool::new(false),
            wake: tokio::sync::Notify::new(),
        });

        let engine = SyncEngine { inner };
        engine.refresh_status()?;
        Ok(engine)
    }

    /// Convenience constructor for tests and tools: an in-memory keystore.
    pub async fn open_ephemeral(cfg: SyncConfig) -> Result<Self> {
        Self::open_with_keystore(cfg, Arc::new(MemoryKeyStore::new())).await
    }

    // -----------------------------------------------------------------------
    // Session
    // -----------------------------------------------------------------------

    /// Exchange credentials for a device token (`SYNCDESKTOP.md` §4.3).
    ///
    /// If the token belongs to a **different** user than the one the local database was
    /// built for, the database is wiped first (§5.5). Logging back in as the *same* user
    /// keeps everything, including an outbox that has been waiting since the last 401.
    pub async fn login(&self, email: &str, password: &str, device: DeviceInfo) -> Result<Session> {
        let response = self
            .inner
            .transport
            .device_login(email, password, &device)
            .await?;

        let user_id = response
            .user
            .get("id")
            .and_then(|v| v.as_i64())
            .ok_or_else(|| SyncError::Protocol("auth/device response has no user.id".into()))?;

        let session = Session {
            token_id: response.token_id,
            user_id,
            user: response.user.clone(),
            must_change_password: response.must_change_password,
            abilities: response.abilities.clone(),
        };

        {
            let conn = self.db()?;
            let previous = db::get_setting(&conn, SETTING_USER_ID)?
                .and_then(|raw| raw.parse::<i64>().ok());
            if previous.is_some_and(|prev| prev != user_id) {
                db::wipe(&conn)?;
            }
            db::put_setting(&conn, SETTING_USER_ID, &user_id.to_string())?;
            db::put_setting(&conn, SETTING_SESSION, &serde_json::to_string(&session)?)?;
        }

        let cfg_service = self.inner.cfg.read().unwrap().keychain_service.clone();
        self.inner
            .keystore
            .set(&cfg_service, keystore::KEY_TOKEN, &response.token)?;
        *self.inner.token.write().unwrap() = Some(response.token);
        *self.inner.session.write().unwrap() = Some(session.clone());
        self.inner.halted.store(false, Ordering::SeqCst);
        self.set_online_flag(true);
        self.refresh_status()?;

        Ok(session)
    }

    /// Resume a stored session: keychain token plus a manifest round-trip to prove it still
    /// works and that the protocol version still matches.
    pub async fn restore_session(&self) -> Result<Option<Session>> {
        let Some(_token) = self.token() else {
            return Ok(None);
        };
        let manifest = self.load_manifest(true).await?;
        let mut session = self.inner.session.read().unwrap().clone();
        if let Some(ref mut s) = session {
            if !manifest.user.is_null() {
                s.user = manifest.user.clone();
            }
        }
        if let Some(ref s) = session {
            let conn = self.db()?;
            db::put_setting(&conn, SETTING_SESSION, &serde_json::to_string(s)?)?;
        }
        *self.inner.session.write().unwrap() = session.clone();
        self.set_online_flag(true);
        self.refresh_status()?;
        Ok(session)
    }

    /// The session, if one is loaded.
    pub fn session(&self) -> Option<Session> {
        self.inner.session.read().unwrap().clone()
    }

    /// Drop the session and wipe local state.
    ///
    /// Refuses while unpushed mutations exist unless `force` is set — logging out is how a
    /// user loses offline work, so it asks first.
    pub async fn logout(&self, force: bool) -> Result<LogoutOutcome> {
        let pending = {
            let conn = self.db()?;
            outbox::pending_count(&conn)?
        };
        if pending > 0 && !force {
            return Ok(LogoutOutcome::PendingMutations(pending));
        }

        let service = self.inner.cfg.read().unwrap().keychain_service.clone();
        self.inner.keystore.delete(&service, keystore::KEY_TOKEN)?;
        {
            let conn = self.db()?;
            db::wipe(&conn)?;
            db::put_setting(&conn, SETTING_USER_ID, "")?;
            db::put_setting(&conn, SETTING_SESSION, "")?;
        }
        *self.inner.token.write().unwrap() = None;
        *self.inner.session.write().unwrap() = None;
        *self.inner.manifest.lock().unwrap() = None;
        self.refresh_status()?;
        Ok(LogoutOutcome::Wiped)
    }

    // -----------------------------------------------------------------------
    // Sync
    // -----------------------------------------------------------------------

    /// First full download: every granted table, from cursor 0, inside the retention window.
    ///
    /// K12: rows outside the window are not sent at all; [`SyncEngine::download_archive`]
    /// is how the user widens it later.
    pub async fn bootstrap(
        &self,
        progress: impl Fn(BootstrapProgress) + Send + Sync,
    ) -> Result<()> {
        let manifest = self.load_manifest(true).await?;
        let entities = pull::granted_entities(&manifest);
        let window_days = self.inner.cfg.read().unwrap().retention_days;
        self.pull_until_drained(&manifest, &entities, window_days, Some(&progress))
            .await?;
        self.refresh_status()?;
        self.emit(EngineEvent::TablesChanged { entities });
        Ok(())
    }

    /// One full round: push, then pull, then (at most once a day) retention.
    ///
    /// Concurrent callers do not queue up behind each other — the second caller coalesces
    /// into the round already running, which is what `SYNCDESKTOP.md` §5.5 asks for.
    pub async fn sync_now(&self) -> Result<SyncReport> {
        if self.inner.halted.load(Ordering::SeqCst) {
            return Err(SyncError::Protocol(
                "engine halted by protocol version mismatch".into(),
            ));
        }
        if !self.status().online {
            return Err(SyncError::Offline);
        }
        let Ok(_guard) = self.inner.round.try_lock() else {
            // A round is already in flight; its results cover this trigger too.
            return Ok(SyncReport::default());
        };

        self.set_syncing(true);
        let result = self.run_round().await;
        self.set_syncing(false);

        match result {
            Ok(report) => {
                self.mark_synced_now();
                if !report.tables_changed.is_empty() {
                    self.emit(EngineEvent::TablesChanged { entities: report.tables_changed.clone() });
                }
                self.refresh_status()?;
                Ok(report)
            }
            Err(err) => {
                if matches!(err, SyncError::Auth) {
                    self.handle_auth_lost()?;
                }
                if matches!(err, SyncError::Offline) {
                    self.set_online_flag(false);
                }
                self.refresh_status()?;
                Err(err)
            }
        }
    }

    async fn run_round(&self) -> Result<SyncReport> {
        let manifest = self.load_manifest(false).await?;
        let policy = manifest.policy;
        let mut report = SyncReport::default();

        self.push_round(&policy, &mut report).await?;

        let entities = pull::granted_entities(&manifest);
        let pulled = self
            .pull_until_drained(&manifest, &entities, 0, None)
            .await?;
        report.pulled_rows = pulled.rows;
        report.deletions = pulled.deletions;
        for entity in pulled.tables_changed {
            if !report.tables_changed.contains(&entity) {
                report.tables_changed.push(entity);
            }
        }
        report.tables_changed.sort();

        self.maybe_run_retention()?;
        Ok(report)
    }

    async fn push_round(&self, policy: &ServerPolicy, report: &mut SyncReport) -> Result<()> {
        let token = self.token().ok_or(SyncError::Auth)?;
        let batches = {
            let conn = self.db()?;
            push::prepare(&conn, policy.push_batch_max, policy.push_bytes_max)?
        };

        for batch in batches {
            if batch.is_empty() {
                continue;
            }
            {
                let conn = self.db()?;
                push::mark_inflight(&conn, &batch)?;
            }

            let request = PushRequest {
                batch_id: Uuid::now_v7().to_string(),
                mutations: batch.iter().map(|row| row.to_wire()).collect(),
            };

            match self.inner.transport.push(&token, &request).await {
                Ok(response) => {
                    let (outcome, entities, new_conflicts) = {
                        let conn = self.db()?;
                        push::apply_results(&conn, &batch, &response)?
                    };
                    report.pushed += batch.len() as u32;
                    report.applied += outcome.applied;
                    report.duplicates += outcome.duplicates;
                    report.conflicts += outcome.conflicts;
                    report.rejected += outcome.rejected;
                    report.deferred += outcome.deferred;
                    for entity in entities {
                        if !report.tables_changed.contains(&entity) {
                            report.tables_changed.push(entity);
                        }
                    }
                    for id in new_conflicts {
                        self.emit(EngineEvent::ConflictAdded { id });
                    }
                }
                Err(err) => {
                    let conn = self.db()?;
                    push::requeue_after_failure(&conn, &batch, &err.to_string())?;
                    drop(conn);
                    return Err(err);
                }
            }
        }
        Ok(())
    }

    async fn pull_until_drained(
        &self,
        manifest: &Manifest,
        entities: &[Entity],
        window_days: u32,
        progress: Option<&(dyn Fn(BootstrapProgress) + Send + Sync)>,
    ) -> Result<pull::PullOutcome> {
        let token = self.token().ok_or(SyncError::Auth)?;
        let limit = manifest.policy.pull_limit_max.clamp(1, 500);
        let mut total = pull::PullOutcome::default();
        let mut pending: Vec<Entity> = entities.to_vec();
        let mut guard = 0u32;

        while !pending.is_empty() {
            guard += 1;
            if guard > 10_000 {
                return Err(SyncError::Protocol(
                    "pull did not converge: server keeps reporting has_more".into(),
                ));
            }

            let request = {
                let conn = self.db()?;
                pull::build_request(&conn, &pending, limit, window_days)?
            };
            let response = self.inner.transport.pull(&token, &request).await?;
            let outcome = {
                let conn = self.db()?;
                pull::apply(&conn, &response)?
            };

            total.rows += outcome.rows;
            total.shadowed += outcome.shadowed;
            total.deletions += outcome.deletions;
            for entity in &outcome.tables_changed {
                if !total.tables_changed.contains(entity) {
                    total.tables_changed.push(*entity);
                }
            }

            if let Some(progress) = progress {
                let done = entities.len().saturating_sub(outcome.incomplete.len());
                progress(BootstrapProgress {
                    entity: *pending.first().unwrap_or(&Entity::Company),
                    rows_loaded: total.rows,
                    tables_done: done as u32,
                    tables_total: entities.len() as u32,
                });
            }

            pending = outcome.incomplete;
        }

        total.tables_changed.sort();
        Ok(total)
    }

    /// Widen the retention window and re-download the extra history (K12).
    ///
    /// `window_days` only applies while a cursor is 0 (§4.4), so the cursors are reset for
    /// this pass; the upsert is idempotent, so re-seeing known rows costs nothing but time.
    pub async fn download_archive(&self, extra_days: u32) -> Result<()> {
        let manifest = self.load_manifest(true).await?;
        let entities = pull::granted_entities(&manifest);
        let window = {
            let cfg = self.inner.cfg.read().unwrap();
            cfg.retention_days.saturating_add(extra_days)
        }
        .min(manifest.policy.retention_days_max);

        {
            let conn = self.db()?;
            for entity in &entities {
                db::set_cursor(&conn, *entity, 0)?;
            }
        }

        self.pull_until_drained(&manifest, &entities, window, None)
            .await?;
        self.refresh_status()?;
        self.emit(EngineEvent::TablesChanged { entities });
        Ok(())
    }

    /// Reverb told us something changed; pull just those tables.
    pub async fn handle_realtime(&self, event: RealtimeEvent) {
        self.inner.wake.notify_one();
        if event.entities.is_empty() || !self.status().online {
            return;
        }
        let Ok(manifest) = self.load_manifest(false).await else {
            return;
        };
        let granted = pull::granted_entities(&manifest);
        let wanted: Vec<Entity> = event
            .entities
            .into_iter()
            .filter(|e| granted.contains(e))
            .collect();
        if wanted.is_empty() {
            return;
        }
        if let Ok(outcome) = self.pull_until_drained(&manifest, &wanted, 0, None).await {
            let _ = self.refresh_status();
            if !outcome.tables_changed.is_empty() {
                self.emit(EngineEvent::TablesChanged { entities: outcome.tables_changed });
            }
        }
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /// Run a whitelisted query. Raw SQL is not accepted (`SYNCDESKTOP.md` §5.2).
    pub fn query(&self, q: NamedQuery, params: QueryParams) -> Result<Vec<Row>> {
        let entity = q.entity();
        let (sql, binds) = q.build(&params)?;
        let conn = self.db()?;
        let mut stmt = conn.prepare(&sql)?;
        let params: Vec<&dyn rusqlite::ToSql> =
            binds.iter().map(|v| v as &dyn rusqlite::ToSql).collect();
        let mut rows = stmt.query(params.as_slice())?;
        let mut out = Vec::new();
        while let Some(row) = rows.next()? {
            out.push(db::row_to_json(row, Some(entity))?);
        }
        Ok(out)
    }

    /// Fetch one row by its local identity.
    pub fn get(&self, entity: Entity, client_id: Uuid) -> Result<Option<Row>> {
        let conn = self.db()?;
        let sql = format!("SELECT * FROM {} WHERE client_id = ?1", entity.table());
        let mut stmt = conn.prepare(&sql)?;
        let mut rows = stmt.query([client_id.to_string()])?;
        match rows.next()? {
            Some(row) => Ok(Some(db::row_to_json(row, Some(entity))?)),
            None => Ok(None),
        }
    }

    /// Local full-text search (§5.3).
    ///
    /// The query goes through the same `to_lowercase` fold as the indexed text, so Turkish
    /// `İ`/`ı` behave the same on both sides.
    pub fn search(&self, fts: &str, entities: &[Entity], limit: u16) -> Result<Vec<SearchHit>> {
        let expression = fts_expression(fts);
        if expression.is_empty() {
            return Ok(Vec::new());
        }
        let conn = self.db()?;
        let (filter, mut binds): (String, Vec<rusqlite::types::Value>) = if entities.is_empty() {
            (String::new(), vec![rusqlite::types::Value::Text(expression)])
        } else {
            let mut binds = vec![rusqlite::types::Value::Text(expression)];
            let mut slots = Vec::new();
            for entity in entities {
                binds.push(rusqlite::types::Value::Text(entity.wire_name().to_string()));
                slots.push(format!("?{}", binds.len()));
            }
            (format!(" AND entity IN ({})", slots.join(", ")), binds)
        };
        binds.push(rusqlite::types::Value::Integer(i64::from(limit.max(1))));
        let sql = format!(
            "SELECT entity, client_id, title, snippet(fts_records, 3, '', '', '…', 12)
               FROM fts_records
              WHERE fts_records MATCH ?1{filter}
              ORDER BY rank LIMIT ?{}",
            binds.len()
        );

        let mut stmt = conn.prepare(&sql)?;
        let params: Vec<&dyn rusqlite::ToSql> =
            binds.iter().map(|v| v as &dyn rusqlite::ToSql).collect();
        let mut rows = stmt.query(params.as_slice())?;
        let mut hits = Vec::new();
        while let Some(row) = rows.next()? {
            let entity: String = row.get(0)?;
            let Some(entity) = Entity::from_wire_name(&entity) else {
                continue;
            };
            hits.push(SearchHit {
                entity,
                client_id: row.get(1)?,
                title: row.get::<_, Option<String>>(2)?.unwrap_or_default(),
                snippet: row.get::<_, Option<String>>(3)?.unwrap_or_default(),
            });
        }
        Ok(hits)
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /// Apply a mutation locally and queue it for the server.
    ///
    /// Returns the row's `client_id` — generated here when the caller left it out on a
    /// create — or, for `notification.read_all`, the outbox entry id.
    ///
    /// Fails with [`SyncError::WriteBlocked`] once a retention ceiling is hit (§5.6);
    /// reads keep working in that state.
    pub fn mutate(&self, mutation: LocalMutation) -> Result<Uuid> {
        let mut mutation = mutation;
        if mutation.op == Op::Create && mutation.client_id.is_none() {
            mutation.client_id = Some(Uuid::now_v7());
        }
        local::validate(&mutation)?;

        let conn = self.db()?;
        let cfg = self.inner.cfg.read().unwrap().clone();
        let stats = retention::storage_stats(&conn, &cfg)?;
        if let Some(reason) = retention::write_block_reason(&stats) {
            return Err(SyncError::WriteBlocked(reason));
        }

        let tx = conn.unchecked_transaction()?;
        local::apply(&tx, &mutation)?;

        let anchor = match mutation.client_id {
            Some(client_id) => local::anchor(&tx, mutation.entity, &client_id.to_string())?,
            None => local::RowAnchor::default(),
        };
        let outcome = outbox::enqueue(&tx, &mutation, anchor.server_id, anchor.base_sync_version)?;
        tx.commit()?;
        drop(conn);

        self.refresh_status()?;
        self.emit(EngineEvent::TablesChanged { entities: vec![mutation.entity] });

        Ok(mutation
            .client_id
            .or_else(|| outcome.outbox_id())
            .unwrap_or_else(Uuid::nil))
    }

    // -----------------------------------------------------------------------
    // Conflicts
    // -----------------------------------------------------------------------

    /// Everything waiting in the Conflict Inbox.
    pub fn conflicts(&self) -> Result<Vec<Conflict>> {
        let conn = self.db()?;
        conflicts::list(&conn)
    }

    /// Settle one conflict.
    ///
    /// [`Resolution::KeepMine`] does not merely re-flag the row: it enqueues a **fresh**
    /// mutation whose `base_sync_version` is the version the server reported in the
    /// conflict. Re-sending against the stale base would only produce the same conflict
    /// again.
    pub fn resolve_conflict(&self, id: Uuid, choice: Resolution) -> Result<()> {
        let conn = self.db()?;
        let Some(conflict) = conflicts::find(&conn, id)? else {
            return Err(SyncError::Validation(format!("no such conflict {id}")));
        };
        let entity = conflict.entity;
        let client_id = conflict
            .client_id
            .ok_or_else(|| SyncError::Validation("conflict has no row to resolve".into()))?;
        let client_id_text = client_id.to_string();

        let original = conflict
            .outbox_id
            .map(|oid| outbox::find(&conn, oid))
            .transpose()?
            .flatten();

        let cols = db::columns(&conn, entity.table())?;
        let tx = conn.unchecked_transaction()?;

        // The server row, from the conflict record or from a shadow a later pull parked.
        let shadow = conflicts::take_shadow(&tx, entity, &client_id_text)?;
        let theirs = match (&conflict.theirs, &shadow) {
            (Json::Null, Some((row, _))) => row.clone(),
            (row, _) => row.clone(),
        };
        let their_version = theirs
            .get("sync_version")
            .and_then(|v| v.as_i64())
            .or(shadow.as_ref().map(|(_, v)| *v));

        match &choice {
            Resolution::TakeServer => {
                apply_server_row(&tx, entity, &client_id_text, &theirs, &cols)?;
                local::set_state(&tx, entity, &client_id_text, SyncState::Synced)?;
                if let Some(row) = original.as_ref() {
                    outbox::remove(&tx, row.id)?;
                }
                conflicts::remove(&tx, conflict.id)?;
                tx.commit()?;
            }
            Resolution::KeepMine | Resolution::Merge(_) => {
                let keep: Vec<String> = match &choice {
                    Resolution::Merge(fields) => fields.clone(),
                    _ => original
                        .as_ref()
                        .and_then(|r| r.changed_fields.clone())
                        .or_else(|| {
                            conflict
                                .mine
                                .as_object()
                                .map(|o| o.keys().cloned().collect())
                        })
                        .unwrap_or_default(),
                };

                if matches!(choice, Resolution::Merge(_)) {
                    apply_server_row(&tx, entity, &client_id_text, &theirs, &cols)?;
                }

                // Adopt the server's version as the new base before re-queueing.
                if let Some(version) = their_version {
                    local::set_server_identity(
                        &tx,
                        entity,
                        &client_id_text,
                        theirs.get("id").and_then(|v| v.as_i64()),
                        Some(version),
                    )?;
                }

                if let Some(row) = original.as_ref() {
                    outbox::remove(&tx, row.id)?;
                }
                conflicts::remove(&tx, conflict.id)?;

                let payload = restrict(&conflict.mine, &keep);
                let op = original.as_ref().map(|r| r.op).unwrap_or(Op::Update);
                let replacement = LocalMutation {
                    entity,
                    op,
                    action: original.as_ref().and_then(|r| r.action.clone()),
                    client_id: Some(client_id),
                    changed_fields: if op == Op::Update {
                        Some(keep.clone())
                    } else {
                        None
                    },
                    payload,
                };
                local::apply(&tx, &replacement)?;
                let anchor = local::anchor(&tx, entity, &client_id_text)?;
                outbox::enqueue(
                    &tx,
                    &replacement,
                    anchor.server_id,
                    anchor.base_sync_version,
                )?;
                tx.commit()?;
            }
        }

        drop(conn);
        self.refresh_status()?;
        self.emit(EngineEvent::TablesChanged { entities: vec![entity] });
        Ok(())
    }

    // -----------------------------------------------------------------------
    // Storage and settings
    // -----------------------------------------------------------------------

    /// Current local storage accounting.
    pub fn storage_stats(&self) -> StorageStats {
        let cfg = self.inner.cfg.read().unwrap().clone();
        match self.inner.db.lock() {
            Ok(conn) => retention::storage_stats(&conn, &cfg).unwrap_or(StorageStats {
                db_bytes: 0,
                max_db_bytes: cfg.max_db_bytes(),
                cached_file_bytes: 0,
                outbox_count: 0,
                max_outbox: cfg.max_outbox,
                db_usage_percent: 0,
            }),
            Err(_) => StorageStats {
                db_bytes: 0,
                max_db_bytes: cfg.max_db_bytes(),
                cached_file_bytes: 0,
                outbox_count: 0,
                max_outbox: cfg.max_outbox,
                db_usage_percent: 0,
            },
        }
    }

    /// Change the user-tunable ceilings; values below the K8 minimums are clamped.
    pub fn update_settings(&self, settings: DesktopSettings) -> Result<()> {
        let settings = settings.clamped();
        {
            let mut cfg = self.inner.cfg.write().unwrap();
            cfg.retention_days = settings.retention_days;
            cfg.max_db_size_mb = settings.max_db_size_mb;
            cfg.max_outbox = settings.max_outbox;
        }
        let conn = self.db()?;
        db::put_setting(&conn, SETTING_PREFERENCES, &serde_json::to_string(&settings)?)?;
        drop(conn);
        self.refresh_status()?;
        Ok(())
    }

    /// Read back the persisted settings.
    pub fn settings(&self) -> DesktopSettings {
        let cfg = self.inner.cfg.read().unwrap();
        DesktopSettings {
            retention_days: cfg.retention_days,
            max_db_size_mb: cfg.max_db_size_mb,
            max_outbox: cfg.max_outbox,
            clipboard_capture: false,
        }
    }

    /// Run retention now, regardless of when it last ran.
    pub fn run_retention(&self) -> Result<retention::RetentionReport> {
        let cfg = self.inner.cfg.read().unwrap().clone();
        let conn = self.db()?;
        let report = retention::run(&conn, &cfg)?;
        db::put_setting(&conn, SETTING_LAST_RETENTION, &Utc::now().to_rfc3339())?;
        drop(conn);
        self.refresh_status()?;
        Ok(report)
    }

    // -----------------------------------------------------------------------
    // Status and events
    // -----------------------------------------------------------------------

    /// A snapshot of engine state.
    pub fn status(&self) -> SyncStatus {
        self.inner.status.lock().unwrap().clone()
    }

    /// Subscribe to the event stream.
    pub fn subscribe(&self) -> broadcast::Receiver<EngineEvent> {
        self.inner.events.subscribe()
    }

    /// Tell the engine what the OS thinks about connectivity.
    ///
    /// Coming back online is one of the §5.5 triggers: it wakes the background loop
    /// immediately instead of waiting out the 60 second timer.
    pub fn set_online(&self, online: bool) {
        self.set_online_flag(online);
        let _ = self.refresh_status();
        if online {
            self.inner.wake.notify_one();
        }
    }

    /// Start the background sync loop of `SYNCDESKTOP.md` §5.5.
    ///
    /// Triggers: startup, [`SyncEngine::set_online(true)`](SyncEngine::set_online),
    /// [`SyncEngine::handle_realtime`], a 60 second timer while online, and any manual
    /// [`SyncEngine::sync_now`]. A failed round backs off `1s, 2s, 4s, ... 300s` with ±20%
    /// jitter ([`backoff`]); a successful one resets the ramp.
    ///
    /// The engine never starts this on its own — the shell decides when background work is
    /// appropriate — so an embedder that wants only manual syncs simply does not call it.
    pub fn start_background_sync(&self) -> SyncScheduler {
        let engine = self.clone();
        let handle = tokio::spawn(async move {
            let mut failures: u32 = 0;
            loop {
                let delay = if engine.inner.halted.load(Ordering::SeqCst) {
                    SYNC_INTERVAL
                } else if !engine.status().online {
                    OFFLINE_POLL_INTERVAL
                } else {
                    match engine.sync_now().await {
                        Ok(_) => {
                            failures = 0;
                            SYNC_INTERVAL
                        }
                        Err(SyncError::Offline) | Err(SyncError::Http(_)) => {
                            let delay = backoff::with_jitter(failures);
                            failures = failures.saturating_add(1);
                            delay
                        }
                        // Auth and protocol failures are not going to fix themselves on a
                        // fast retry; wait for a login or an app update.
                        Err(_) => SYNC_INTERVAL,
                    }
                };

                tokio::select! {
                    _ = tokio::time::sleep(delay) => {}
                    _ = engine.inner.wake.notified() => { failures = 0; }
                }
            }
        });
        SyncScheduler { handle }
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    fn db(&self) -> Result<std::sync::MutexGuard<'_, Connection>> {
        self.inner
            .db
            .lock()
            .map_err(|_| SyncError::Validation("database mutex poisoned".into()))
    }

    fn token(&self) -> Option<String> {
        self.inner.token.read().unwrap().clone()
    }

    fn emit(&self, event: EngineEvent) {
        let _ = self.inner.events.send(event);
    }

    fn set_online_flag(&self, online: bool) {
        let mut status = self.inner.status.lock().unwrap();
        status.online = online;
    }

    fn set_syncing(&self, syncing: bool) {
        {
            let mut status = self.inner.status.lock().unwrap();
            status.syncing = syncing;
        }
        let snapshot = self.status();
        self.emit(EngineEvent::StatusChanged { status: snapshot });
    }

    fn mark_synced_now(&self) {
        let mut status = self.inner.status.lock().unwrap();
        status.last_sync_at = Some(Utc::now());
    }

    /// Recompute the pending/conflict counters and the write-block reason, then publish.
    fn refresh_status(&self) -> Result<()> {
        let cfg = self.inner.cfg.read().unwrap().clone();
        let (pending, conflict_count, stats) = {
            let conn = self.db()?;
            (
                outbox::pending_count(&conn)?,
                conflicts::count(&conn)?,
                retention::storage_stats(&conn, &cfg)?,
            )
        };

        let blocked = retention::write_block_reason(&stats);
        let snapshot = {
            let mut status = self.inner.status.lock().unwrap();
            status.pending = pending;
            status.conflicts = conflict_count;
            status.write_blocked = blocked;
            status.clone()
        };

        if stats.db_usage_percent >= retention::WARN_PERCENT {
            self.emit(EngineEvent::StorageWarning { stats });
        }
        self.emit(EngineEvent::StatusChanged { status: snapshot });
        Ok(())
    }

    /// The manifest, cached for ten minutes (§5.5), with the protocol check applied.
    async fn load_manifest(&self, force: bool) -> Result<Manifest> {
        if !force {
            if let Some((fetched_at, manifest)) = self.inner.manifest.lock().unwrap().clone() {
                if fetched_at.elapsed().as_secs() < MANIFEST_CACHE_SECS {
                    return Ok(manifest);
                }
            }
        }
        let token = self.token().ok_or(SyncError::Auth)?;
        let manifest = match self.inner.transport.manifest(&token).await {
            Ok(manifest) => manifest,
            Err(SyncError::Auth) => {
                self.handle_auth_lost()?;
                return Err(SyncError::Auth);
            }
            Err(err) => return Err(err),
        };

        if manifest.protocol_version != PROTOCOL_VERSION {
            self.inner.halted.store(true, Ordering::SeqCst);
            self.emit(EngineEvent::ProtocolMismatch {
                server: manifest.protocol_version,
            });
            return Err(SyncError::Protocol(format!(
                "server protocol {} != client protocol {PROTOCOL_VERSION}",
                manifest.protocol_version
            )));
        }

        *self.inner.manifest.lock().unwrap() = Some((Instant::now(), manifest.clone()));
        Ok(manifest)
    }

    /// A 401 drops the session but **keeps the outbox** (§5.5): the same user logging back
    /// in resumes exactly where they left off.
    fn handle_auth_lost(&self) -> Result<()> {
        let service = self.inner.cfg.read().unwrap().keychain_service.clone();
        self.inner.keystore.delete(&service, keystore::KEY_TOKEN)?;
        *self.inner.token.write().unwrap() = None;
        *self.inner.session.write().unwrap() = None;
        *self.inner.manifest.lock().unwrap() = None;
        self.emit(EngineEvent::AuthLost);
        Ok(())
    }

    /// Retention runs at most once a day (§5.5).
    fn maybe_run_retention(&self) -> Result<()> {
        let last = {
            let conn = self.db()?;
            db::get_setting(&conn, SETTING_LAST_RETENTION)?
        };
        let due = match last.as_deref().map(|s| s.parse::<DateTime<Utc>>()) {
            Some(Ok(when)) => Utc::now() - when >= ChronoDuration::days(1),
            _ => true,
        };
        if due {
            self.run_retention()?;
        }
        Ok(())
    }
}

/// Overwrite a mirror row with the server's version of it.
fn apply_server_row(
    tx: &rusqlite::Transaction<'_>,
    entity: Entity,
    client_id: &str,
    theirs: &Json,
    cols: &[String],
) -> Result<()> {
    let Some(obj) = theirs.as_object() else {
        return Ok(());
    };
    let spec = schema::spec_for(entity);
    let mut names = Vec::new();
    let mut values: Vec<rusqlite::types::Value> = Vec::new();

    for (key, value) in obj {
        if key == "id" || key == "client_id" || key == "sync_version" {
            continue;
        }
        if !cols.iter().any(|c| c == key) {
            continue;
        }
        names.push(key.clone());
        values.push(db::json_to_sql(value));
    }
    for fk in spec.fks {
        if let Some(server_id) = obj.get(fk.server_col).and_then(|v| v.as_i64()) {
            names.push(fk.client_col.to_string());
            values.push(rusqlite::types::Value::Text(
                upsert::client_id_for_fk(tx, fk.target, server_id)?,
            ));
        }
    }
    if names.is_empty() {
        return Ok(());
    }

    let assignments: Vec<String> = names
        .iter()
        .enumerate()
        .map(|(i, n)| format!("{n} = ?{}", i + 1))
        .collect();
    values.push(rusqlite::types::Value::Text(client_id.to_string()));
    let sql = format!(
        "UPDATE {} SET {} WHERE client_id = ?{}",
        entity.table(),
        assignments.join(", "),
        values.len()
    );
    let params: Vec<&dyn rusqlite::ToSql> =
        values.iter().map(|v| v as &dyn rusqlite::ToSql).collect();
    tx.execute(&sql, params.as_slice())?;
    Ok(())
}

/// Keep only the listed keys of a payload.
fn restrict(payload: &Json, keep: &[String]) -> Json {
    match payload.as_object() {
        Some(obj) if !keep.is_empty() => {
            let mut out = serde_json::Map::new();
            for key in keep {
                if let Some(value) = obj.get(key) {
                    out.insert(key.clone(), value.clone());
                }
            }
            Json::Object(out)
        }
        _ => payload.clone(),
    }
}

/// Turn a user query into an FTS5 MATCH expression.
///
/// Every token is quoted (so punctuation cannot become an operator) and the last one gets a
/// prefix `*`, which is what makes as-you-type search feel right.
///
/// Splitting is on **whitespace only**. Splitting on "not alphanumeric" looks tidier but
/// breaks Turkish: `to_lowercase('İ')` is `i` followed by a combining dot above (U+0307),
/// which is a mark rather than an alphanumeric, so `İSTANBUL` would be cut into `i` and
/// `stanbul` and match nothing. Inside the quotes FTS5 hands the whole token to the
/// tokenizer, which strips the mark exactly as it did when indexing.
fn fts_expression(input: &str) -> String {
    let folded = db::fold(input);
    let tokens: Vec<String> = folded
        .split_whitespace()
        .map(|t| t.replace('"', "\"\""))
        .filter(|t| t.chars().any(|c| c.is_alphanumeric()))
        .collect();
    if tokens.is_empty() {
        return String::new();
    }
    let last = tokens.len() - 1;
    tokens
        .iter()
        .enumerate()
        .map(|(i, t)| {
            if i == last {
                format!("\"{t}\"*")
            } else {
                format!("\"{t}\"")
            }
        })
        .collect::<Vec<_>>()
        .join(" ")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn fts_expression_quotes_tokens_and_prefixes_the_last() {
        assert_eq!(fts_expression("acme ltd"), "\"acme\" \"ltd\"*");
        assert_eq!(fts_expression("  "), "");
        // Operators cannot escape the quotes.
        assert_eq!(fts_expression("a OR b"), "\"a\" \"or\" \"b\"*");
    }

    #[test]
    fn restrict_keeps_only_named_fields() {
        let payload = serde_json::json!({"title": "t", "amount": 1, "notes": "n"});
        let out = restrict(&payload, &["title".into(), "amount".into()]);
        assert_eq!(out, serde_json::json!({"title": "t", "amount": 1}));
    }
}
