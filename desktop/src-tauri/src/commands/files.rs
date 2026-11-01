//! `files::*` — quote PDF cache, drag-drop attachments and screenshot capture
//! (`SYNCDESKTOP.md` §6.2 command surface; §6.4 F5 items 5 and 8).
//!
//! This is the one command module with no [`syncra_sync::SyncEngine`] method behind it. The
//! engine's frozen API (`docs/DESKTOP-SYNC-PROTOCOL.md` §5) has no attachment entity and no
//! file cache writer: [`syncra_sync::Entity`] lists thirteen writable tables and `attachments`
//! is not one of them (`SYNCDESKTOP.md` §12/1 — `Attachment` is one of the six entities that
//! deliberately carry no audit trail and never sync). `docs/DESKTOP-ARCHITECTURE.md` §5.2
//! nevertheless writes "`mutate`" in the engine column for `attach_from_paths`; that mapping
//! cannot be honoured, and the divergence is recorded in the phase report rather than papered
//! over by inventing an entity. So the queue here is a **shell-owned directory**, not an
//! outbox row.
//!
//! Everything this module writes lives under `$APPDATA/syncra/cache`, which is the same root
//! `storage::clear_local` empties and the first entry of the `fs:scope` allowlist in
//! `capabilities/default.json`.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Runtime, State};
use tauri_plugin_shell::ShellExt;
use uuid::Uuid;

use syncra_sync::keystore::{KeyStore, SystemKeyStore, KEY_TOKEN};

use super::{CommandError, CommandResult};
use crate::drop_grants::{DropGrants, GRANT_WAIT};
use crate::session_gate::{SessionGate, SessionStamp};
use crate::state::AppState;

// ---------------------------------------------------------------------------------------------
// Limits — transcribed from the server, not invented here
// ---------------------------------------------------------------------------------------------

/// Extension allowlist, byte-for-byte the keys of `$mimeMap` in
/// `backend/config/chat.php` (lines 25-64), which `config('chat.attachments.allowed_extensions')`
/// derives with `array_keys` and `App\Services\Attachments\AttachmentTypeGuard::isAllowed`
/// enforces on every `POST /api/attachments`.
///
/// The client list must be **identical** to the server's, not a subset: a stricter client
/// silently refuses files the server would have taken (a drag-drop that does nothing, with no
/// way for the user to tell why), and a looser one queues bytes that can only ever come back
/// as a `422`. SVG's absence is deliberate on the server side (`config/chat.php` header
/// comment: XML that can carry `<script>`), so it is deliberately absent here too.
///
/// What this list can NOT reproduce is the second half of the server's rule: `AttachmentTypeGuard`
/// also requires the finfo-detected **content** type to match the extension. That check needs a
/// magic-number database this shell does not carry, and duplicating a partial version of it
/// would be worse than not having it — the server still runs the full check on upload, so a
/// content/extension mismatch is refused there with a `422` instead of here. The client owns the
/// cheap half (extension, size); the server keeps the authoritative half.
const ALLOWED_EXTENSIONS: &[&str] = &[
    // Documents
    "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "csv", "txt", //
    // Raster images
    "jpg", "jpeg", "png", "gif", "webp", //
    // Archive
    "zip", //
    // Media
    "mp4", "webm", "mp3", "wav",
];

/// Per-file ceiling in bytes (`SYNCDESKTOP.md` §6.4: "tek dosya ≤25 MB").
///
/// The server writes the same number as `chat.attachments.max_size_kb`, whose default is
/// `25 * 1024` **kilobytes** (`backend/config/chat.php` line 75), consumed by
/// `StoreAttachmentRequest`'s `max:25600` rule. Laravel's `max` on a file compares
/// `size_in_bytes / 1024 <= 25600` and the comparison is inclusive, so a file of exactly
/// 26_214_400 bytes is ACCEPTED by the server; the check here is `> MAX_FILE_BYTES` for the
/// same reason. One byte of asymmetry either way is a file the two sides disagree about.
const MAX_FILE_BYTES: u64 = 25 * 1024 * 1024;

/// Offline queue ceiling in bytes (`SYNCDESKTOP.md` §6.4: "offline kuyruk ≤100 MB").
///
/// Client-only — the server has no opinion about how much a disconnected desktop is holding.
/// It exists so an offline user dragging a folder of videos in cannot fill the disk with
/// bytes nothing will ever be able to send.
const MAX_QUEUE_BYTES: u64 = 100 * 1024 * 1024;

/// `$APPDATA/syncra/cache/quotes` — one file per `{quote_id}-{revision}` (§6.4).
const QUOTES_SUBDIR: &str = "quotes";

/// `cached_files.kind` for every quote PDF this module writes (B1) — the identity half of
/// [`syncra_sync::SyncEngine::record_cached_file`]'s `(kind, reference)` key, alongside
/// [`quote_pdf_reference`]. A single constant so the cache-hit and download branches of
/// [`cache_quote_pdf`] can never drift into two different strings, which would split one
/// logical file into two ledger rows the LRU eviction would never reconcile.
const QUOTE_PDF_CACHE_KIND: &str = "quote_pdf";

/// `$APPDATA/syncra/cache/attachments` — the staging queue for drag-drop and screenshots.
const ATTACHMENTS_SUBDIR: &str = "attachments";

/// Extension of the sidecar that records what a staged blob is and where it is going.
const SIDECAR_EXTENSION: &str = "json";

/// Multipart part `Content-Type` for an upload.
///
/// Deliberately not a guessed per-extension type: `AttachmentTypeGuard` documents in as many
/// words that the client's `Content-Type` header is never read ("İstemcinin gönderdiği
/// `Content-Type` BİLİNÇLİ OLARAK KULLANILMAZ") and the stored `mime_type` comes from finfo on
/// the server. Sending a specific type would suggest it carries weight; it does not.
const UPLOAD_CONTENT_TYPE: &str = "application/octet-stream";

// ---------------------------------------------------------------------------------------------
// Error codes
// ---------------------------------------------------------------------------------------------

/// The file's extension is not in [`ALLOWED_EXTENSIONS`].
///
/// A code of its own rather than `VALIDATION_ERROR`: this is the one rejection a user can act
/// on ("convert it, or zip it") and it is reported per file inside a batch, where a generic
/// sentence would leave five identical rows saying nothing. Needs a `desktop.errors.*` entry
/// and a `KNOWN_ERROR_CODES` line — see the phase report's integration list.
const CODE_FILE_TYPE_REJECTED: &str = "FILE_TYPE_REJECTED";

/// The file is larger than [`MAX_FILE_BYTES`].
const CODE_FILE_TOO_LARGE: &str = "FILE_TOO_LARGE";

/// Staging the file would push the offline queue past [`MAX_QUEUE_BYTES`].
const CODE_QUEUE_FULL: &str = "QUEUE_FULL";

// ---------------------------------------------------------------------------------------------
// Wire types
// ---------------------------------------------------------------------------------------------

/// What [`cache_quote_pdf`] hands back: an absolute path the webview can pass straight to
/// [`open_cached`].
#[derive(Debug, Clone, Serialize)]
pub struct CachedPdf {
    /// Absolute path of the cached file.
    pub path: String,
    /// Size on disk.
    pub bytes: u64,
    /// `true` when the file was already on disk and no request went out. The webview uses this
    /// to decide whether the "last fetched" stamp needs updating; §8 lists `quotes.pdf` as
    /// online-only *when uncached*, and this is how it tells the two cases apart.
    pub from_cache: bool,
}

/// Which record an attachment should end up on.
#[derive(Debug, Clone, Copy, Serialize, Deserialize)]
#[serde(tag = "kind", rename_all = "snake_case")]
pub enum AttachTarget {
    /// Upload only. `POST /api/attachments` creates the row with `attachable_id = NULL`; the
    /// chat composer links it later by sending `attachment_id` with the message. This is the
    /// drag-drop-into-the-composer case and it mirrors what the web build's
    /// `comms.uploadAttachment` already does.
    Unattached,
    /// Post the file into the record's embedded conversation.
    ///
    /// There is no endpoint that attaches a file directly to a ticket or a deal:
    /// `AttachmentPolicy` fails closed on any `attachable_type` other than `Message`
    /// (`backend/app/Policies/AttachmentPolicy.php` line 54), and the only writer of
    /// `attachable_id` is the chat message pipeline. So "ticket attach" resolves to the
    /// three-step server flow `POST /api/attachments` → `POST /api/conversations/for-record`
    /// → `POST /api/conversations/{id}/messages`, which is what [`attach_to_record`] runs.
    Record {
        /// `deal` or `ticket` — `App\Services\Chat\RecordChatRegistry::TYPES`, the only two
        /// values `ForRecordConversationRequest` accepts.
        record: RecordKind,
        /// Server-side id of the record.
        id: i64,
    },
}

/// The record types that can host a conversation (`RecordChatRegistry::TYPES`).
#[derive(Debug, Clone, Copy, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum RecordKind {
    /// `deals`.
    Deal,
    /// `tickets`.
    Ticket,
}

impl RecordKind {
    /// The `conversable_type` string the server validates with `Rule::in(RecordChatRegistry::TYPES)`.
    fn as_str(self) -> &'static str {
        match self {
            RecordKind::Deal => "deal",
            RecordKind::Ticket => "ticket",
        }
    }
}

/// What happened to one file in an [`attach_from_paths`] batch.
///
/// A batch never fails as a whole. Dropping six files onto a window and getting one error
/// message tells the user nothing about which five went through, so every path gets its own
/// verdict and the command itself only rejects when something is wrong with the request rather
/// than with a file.
#[derive(Debug, Clone, Serialize)]
#[serde(tag = "status", rename_all = "snake_case")]
pub enum AttachOutcome {
    /// The file reached the server. `attachment` is the `AttachmentResource` body.
    Uploaded {
        /// The name the user sees, i.e. the source file's own name.
        original_name: String,
        /// The server's row.
        attachment: UploadedAttachment,
        /// Id of the chat message that carries it, for [`AttachTarget::Record`]. `None` for
        /// [`AttachTarget::Unattached`], where nothing links the row yet.
        #[serde(skip_serializing_if = "Option::is_none")]
        message_id: Option<i64>,
    },
    /// The file is staged on disk and nothing was sent — offline, or the upload failed.
    Queued {
        /// The name the user sees.
        original_name: String,
        /// Stem of the staged blob and its sidecar, so a later drain can name them.
        queue_id: Uuid,
        /// Size on disk.
        bytes: u64,
        /// Why it is queued rather than uploaded — an error code from the same vocabulary
        /// `desktop.errors.*` renders (`OFFLINE`, `AUTH_REQUIRED`, `HTTP_422`, ...).
        reason: String,
    },
    /// The file was refused before anything was written or sent.
    Rejected {
        /// The name the user sees.
        original_name: String,
        /// [`CODE_FILE_TYPE_REJECTED`], [`CODE_FILE_TOO_LARGE`], [`CODE_QUEUE_FULL`] or
        /// `VALIDATION_ERROR`.
        code: String,
        /// Detail for the log; the UI renders `code`, not this.
        message: String,
    },
}

/// One row of `AttachmentResource` (`backend/app/Http/Resources/AttachmentResource.php`).
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct UploadedAttachment {
    /// `attachments.id`.
    pub id: i64,
    /// Name the file was uploaded under.
    pub original_name: String,
    /// Server-detected MIME type (finfo), not what this client sent.
    pub mime_type: String,
    /// Size in bytes, as the server recorded it.
    pub size: i64,
    /// Whether the server will serve it inline (raster images only).
    pub is_image: bool,
    /// Host-less path to `AttachmentController::show`, e.g. `/api/attachments/12`.
    pub url: String,
}

/// Screen region to capture, in virtual-desktop coordinates.
///
/// The **selection UI** is not this module's: `SYNCDESKTOP.md` §6.4 item 8 reads "hotkey →
/// bölge seç → PNG → ticket attach", and neither the hotkey (F5-3) nor the overlay window that
/// produces the rectangle belongs to a command. What arrives here is the finished rectangle;
/// `None` means "the whole primary screen", which is what the hotkey does before any overlay
/// exists.
#[derive(Debug, Clone, Copy, Deserialize)]
pub struct CaptureRegion {
    /// Left edge, virtual-desktop coordinates (may be negative on a multi-monitor setup).
    pub x: i32,
    /// Top edge, virtual-desktop coordinates.
    pub y: i32,
    /// Width in pixels; must be non-zero.
    pub width: u32,
    /// Height in pixels; must be non-zero.
    pub height: u32,
}

/// The sidecar written next to every staged blob.
///
/// Without it a queued file is an anonymous blob: the drain would not know what to call it on
/// the server or which record it was dropped on. `original_name` is stored rather than used as
/// the on-disk name for the same reason `AttachmentUploadService` randomises the server-side
/// name — a user-supplied file name must never become part of a path.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct QueuedAttachment {
    /// Stem shared by the blob and this sidecar.
    pub id: Uuid,
    /// File name to upload under.
    pub original_name: String,
    /// Lowercase extension, already checked against [`ALLOWED_EXTENSIONS`].
    pub extension: String,
    /// Size of the staged blob.
    pub bytes: u64,
    /// Where it is going.
    pub target: AttachTarget,
    /// RFC 3339, UTC.
    pub queued_at: String,
    /// The signed-in session the file was staged under, persisted so that whatever reads the
    /// queue back later can tell whose file it is. `None` when nobody was signed in.
    #[serde(default)]
    pub staged_for: Option<StagedSession>,
    /// The in-process session generation the file was staged under. Never written to the
    /// sidecar: a file read back from disk has no stamp and is therefore never sent with
    /// whatever token is current — it has to be staged again under the session that sends it.
    #[serde(skip)]
    pub stamp: Option<SessionStamp>,
}

/// Who a staged file belongs to (`QueuedAttachment::staged_for`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct StagedSession {
    /// The signed-in user.
    pub user_id: i64,
    /// The device token behind the session; a new login is a new token.
    pub token_id: i64,
}

impl StagedSession {
    fn of(session: Option<&syncra_sync::Session>) -> Option<Self> {
        session.map(|session| StagedSession {
            user_id: session.user_id,
            token_id: session.token_id,
        })
    }
}

// ---------------------------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------------------------

/// `GET /api/quotes/{id}/pdf` → `$APPDATA/syncra/cache/quotes/{id}-{rev}.pdf` (§6.4).
///
/// A hit returns without touching the network, which is the whole point: `SYNCDESKTOP.md` §8
/// files `quotes.pdf` as "**cache varsa çalışır**", so an offline user can still open a quote
/// they have looked at before. Every hit and every fresh download is recorded in the engine's
/// `cached_files` ledger (B1: [`syncra_sync::SyncEngine::record_cached_file`]/
/// [`syncra_sync::SyncEngine::touch_cached_file`]) so [`syncra_sync::SyncEngine::run_retention`]'s
/// LRU eviction and `storage_stats`'s size accounting both know this file exists — before this
/// wiring landed neither did, so the cache could fill the disk unbounded and Storage settings
/// under-reported it.
///
/// The revision is a caller-supplied part of the identity, not something this command reads
/// back from the mirror, because it is what makes the cached name stable: `quotes.revision`
/// (`backend/database/migrations/2026_08_24_300001_...php`) increments whenever a sent quote is
/// superseded, so `{id}-{rev}` names an immutable document.
///
/// # `refresh` (D3)
///
/// A **draft** quote can still change under a fixed `revision` — `revision` only increments
/// when a *sent* quote is superseded, not on every edit — so a cache hit under a fixed revision
/// can silently serve a stale draft PDF. `refresh: true` is the caller's escape hatch: it skips
/// the cache-hit check unconditionally, always re-downloads, overwrites `target` in place, and
/// re-records the ledger row with the fresh size, exactly like a first download. `refresh:
/// false` behaves exactly as before this parameter existed.
///
/// Asking the server for the current `revision` and comparing it before deciding was considered
/// and rejected (advisor decision): it needs a network round trip even for the common case that
/// would otherwise serve straight from cache, defeating the point of caching, and it does not
/// work offline. The intended caller rule — `refresh: true` whenever `quote.status === 'draft'`,
/// `refresh: false` otherwise — belongs in the TS wrapper around this command; that wrapper is
/// not written this turn (it lands with its consumer in a later phase), so this doc comment is
/// where the rule is recorded until then.
#[tauri::command]
pub async fn cache_quote_pdf(
    state: State<'_, AppState>,
    quote_id: i64,
    revision: u32,
    refresh: bool,
) -> CommandResult<CachedPdf> {
    // Taken before anything else, and in particular before the token is read: the response is
    // only written if this is still the session (and the data directory) it was requested
    // under. See `crate::session_gate`.
    let gate = state.session_gate.clone();
    let reference = quote_pdf_reference(quote_id, revision);

    // A cache hit is answered and recorded under the gate as well. When the session changed
    // between the stamp and the lookup, the lookup is repeated once under the current session
    // (whose cache and engine may differ — a move, a wipe); a second change is a refusal.
    let mut retried = false;
    let (stamp, engine, target) = loop {
        let stamp = gate.stamp();
        let engine = state.engine();
        let target = quote_pdf_path(&state.cache_dir(), quote_id, revision);
        match cache_hit(&gate, stamp, &engine, &target, &reference, refresh)? {
            CacheLookup::Hit(hit) => return Ok(hit),
            CacheLookup::Miss => break (stamp, engine, target),
            CacheLookup::Stale if !retried => retried = true,
            CacheLookup::Stale => return Err(session_changed("the quote PDF")),
        }
    };

    if !engine.status().online {
        return Err(CommandError::new(
            "ONLINE_ONLY",
            format!("quote {quote_id} revision {revision} is not cached and the app is offline"),
        ));
    }

    let url = state
        .api_base
        .join(&format!("quotes/{quote_id}/pdf"))
        .map_err(|e| CommandError::new("VALIDATION_ERROR", format!("bad api_base: {e}")))?;
    let token = bearer_token(&state.keychain_service)?;

    let response = state
        .http
        .get(url)
        .bearer_auth(token)
        .send()
        .await
        .map_err(http_error)?;
    let response = ensure_success(response).await?;
    let body = response.bytes().await.map_err(http_error)?;

    let bytes = body.len() as u64;
    let write_target = target.clone();
    blocking(move || {
        store_downloaded_pdf(
            &gate,
            stamp,
            &engine,
            &write_target,
            &reference,
            &body,
        )
    })
    .await??;

    Ok(CachedPdf {
        path: path_string(&target),
        bytes,
        from_cache: false,
    })
}

/// Non-network half of [`cache_quote_pdf`]: resolve a cache hit for `target`/`reference`, or
/// say there is not one, without ever reaching the network. Split out from the command so the
/// `refresh` semantics (D3) and the ledger wiring (B1) are unit-testable without an HTTP mock
/// or a full `AppState`.
///
/// `refresh: true` always answers `Miss` — the whole point of the flag is to bypass this
/// check unconditionally, so it is evaluated before anything on disk is even looked at.
///
/// The look at the disk and the ledger write both happen inside [`SessionGate::commit`]. A
/// login by another user, a logout, a wipe or a move can otherwise empty the cache between
/// reading the file's metadata and recording the hit, and the next session's database would get
/// a row for a PDF that no longer exists while the caller is handed its path. `Stale` means the
/// session changed since `stamp` and nothing was looked at or written.
fn cache_hit(
    gate: &SessionGate,
    stamp: SessionStamp,
    engine: &syncra_sync::SyncEngine,
    target: &Path,
    reference: &str,
    refresh: bool,
) -> CommandResult<CacheLookup> {
    if refresh {
        return Ok(CacheLookup::Miss);
    }

    gate.commit(stamp, || {
        let Ok(meta) = std::fs::metadata(target) else {
            return Ok(CacheLookup::Miss);
        };
        if !meta.is_file() {
            return Ok(CacheLookup::Miss);
        }

        record_cache_hit(engine, reference, target, meta.len())?;
        Ok(CacheLookup::Hit(CachedPdf {
            path: path_string(target),
            bytes: meta.len(),
            from_cache: true,
        }))
    })
    .unwrap_or(Ok(CacheLookup::Stale))
}

/// What [`cache_hit`] found.
#[derive(Debug)]
enum CacheLookup {
    /// Served from the cache, and the hit is in the ledger.
    Hit(CachedPdf),
    /// Not cached (or `refresh`): download it.
    Miss,
    /// The session changed since the stamp; nothing was looked at.
    Stale,
}

/// Account for a cache **hit** in the `cached_files` ledger (B1).
///
/// Tries [`syncra_sync::SyncEngine::touch_cached_file`] first — the common case, moving the
/// existing row to the head of the LRU queue. It answers `false` for exactly one situation
/// (per its own doc comment): the blob is on disk but no row names it, e.g. one written before
/// this ledger wiring existed. That case falls back to
/// [`syncra_sync::SyncEngine::record_cached_file`], which both creates the missing row and
/// counts the file in `storage_stats` for the first time.
fn record_cache_hit(
    engine: &syncra_sync::SyncEngine,
    reference: &str,
    path: &Path,
    bytes: u64,
) -> CommandResult<()> {
    if !engine.touch_cached_file(QUOTE_PDF_CACHE_KIND, reference)? {
        engine.record_cached_file(QUOTE_PDF_CACHE_KIND, reference, path, bytes)?;
    }
    Ok(())
}

/// Write a downloaded PDF to the cache and record it in the ledger, but only if the session
/// and the data directory are still the ones the download was started under.
///
/// Both halves run inside [`SessionGate::commit`]: a login by another user, a logout, a local
/// wipe or a data directory move that happened while the body was on the wire makes this a
/// refusal with nothing written. Without it the previous user's document landed in the cache
/// and in the next user's `cached_files`, or a moved directory was recreated at its old place
/// and recorded in the new database. `engine` and `target` are the ones captured with `stamp`;
/// a commit that succeeds proves neither has been replaced since, because replacing them
/// (`AppState::adopt`) only happens inside a transition.
fn store_downloaded_pdf(
    gate: &SessionGate,
    stamp: SessionStamp,
    engine: &syncra_sync::SyncEngine,
    target: &Path,
    reference: &str,
    body: &[u8],
) -> CommandResult<()> {
    gate.commit(stamp, || {
        write_atomically(target, body)?;
        engine
            .record_cached_file(QUOTE_PDF_CACHE_KIND, reference, target, body.len() as u64)
            .map_err(CommandError::from)?;
        Ok(())
    })
    .unwrap_or_else(|| Err(session_changed("the quote PDF")))
}

/// The refusal for shell work whose session ended while it ran.
///
/// `AUTH_REQUIRED` because that is what it is from the caller's side: the session the request
/// belonged to is gone, and a new request has to be made under the current one.
fn session_changed(what: &str) -> CommandError {
    CommandError::new(
        "AUTH_REQUIRED",
        format!("the session changed while {what} was in progress; nothing was stored"),
    )
}

/// Hand a cached file to the OS default application.
///
/// # Why the validation here is the only validation
///
/// `Shell::open` is the Rust-side entry point of `tauri-plugin-shell`, and it calls
/// `open::open(None, path, with)` — the `None` is the scope, and the plugin's own source says
/// what that means: *"when running directly from Rust code we don't need to validate the
/// path"*. The `shell:allow-open` entry in `capabilities/default.json` (`http://*`, `https://*`)
/// therefore constrains the **JavaScript** `shell|open` command and nothing else; it neither
/// permits nor forbids what this function does. Nothing but the check below stands between an
/// `invoke('open_cached', {path})` call and launching an arbitrary file on the user's disk, so:
///
/// * the path must be **absolute** — a relative path would resolve against the process working
///   directory, which is not a location this command has any business reaching into;
/// * it is **canonicalised**, which is what actually neutralises `..` segments and symlinks —
///   a textual `contains("..")` check does not, because `cache/sub/../../secret.pdf` has no
///   `..` left once the OS resolves it and a symlink has none to begin with;
/// * the result must live **under the canonicalised cache root**;
/// * and its extension must be one this app itself writes ([`ALLOWED_EXTENSIONS`]), so that a
///   file that somehow landed in the cache directory by another route still cannot be launched
///   as, say, a `.exe`.
///
/// `#[allow(deprecated)]`: `Shell::open` carries `#[deprecated(since = "2.1.0")]` pointing at
/// `tauri-plugin-opener`. Swapping the plugin is a change to the plugin set that
/// `docs/DESKTOP-ARCHITECTURE.md` §5.1 pins and `SYNCDESKTOP.md` §6.1 lists, which is not this
/// module's to make — flagged for the tech lead instead of quietly widening the dependency
/// list.
#[tauri::command]
pub fn open_cached<R: Runtime>(
    app: AppHandle<R>,
    state: State<'_, AppState>,
    path: String,
) -> CommandResult<()> {
    let resolved = resolve_cached_path(&state.cache_dir(), &path)?;
    #[allow(deprecated)]
    app.shell()
        .open(path_string(&resolved), None)
        .map_err(|e| CommandError::new("VALIDATION_ERROR", format!("cannot open file: {e}")))
}

/// Drag-drop entry point (§6.4): validate, stage under `$APPDATA/syncra/cache/attachments`,
/// and send if the app is online.
///
/// The `tauri://drag-drop` listener that calls this is webview/setup-side and is not part of
/// this module.
///
/// # Only paths the native layer produced
///
/// Every path must have been handed out by the native layer — the drop event the runtime
/// delivered to Rust, or a native dialog run from Rust — and is spent on use
/// (`crate::drop_grants`). Any other string is refused per file before the file is even looked
/// at, so a script running in the webview cannot make the shell read and upload an arbitrary
/// local file with the user's token.
///
/// Staging happens **before** the upload attempt, not instead of it: a file dropped while the
/// network is up but the server is unhappy would otherwise be lost the moment the drop
/// animation ends. A successful upload deletes its staged copy immediately, so the queue only
/// ever holds what has not been sent.
#[tauri::command]
pub async fn attach_from_paths(
    state: State<'_, AppState>,
    paths: Vec<String>,
    target: AttachTarget,
) -> CommandResult<Vec<AttachOutcome>> {
    let gate = state.session_gate.clone();
    let stamp = gate.stamp();
    let staged_for = StagedSession::of(state.engine().session().as_ref());
    let queue = attachments_dir(&state.cache_dir());

    let checked = granted_sources(&state.drop_grants, &paths, GRANT_WAIT).await;
    let sources: Vec<PathBuf> = checked
        .iter()
        .filter_map(|entry| entry.as_ref().ok().cloned())
        .collect();

    let staging_queue = queue.clone();
    let staged = blocking(move || {
        stage_batch(&staging_queue, &sources, target, &gate, stamp, staged_for)
    })
    .await?;

    let mut staged = staged.into_iter();
    let mut outcomes = Vec::with_capacity(checked.len());
    for entry in checked {
        outcomes.push(match entry {
            Err(refused) => refused,
            Ok(_) => match staged.next() {
                Some(Staged::Rejected(outcome)) => outcome,
                Some(Staged::Ready(queued)) => deliver(&state, &queue, queued).await,
                // `stage_batch` answers once per source, in order, so this arm is never taken;
                // it refuses rather than panicking in a command.
                None => AttachOutcome::Rejected {
                    original_name: String::new(),
                    code: "VALIDATION_ERROR".to_string(),
                    message: "no staging verdict for this path".to_string(),
                },
            },
        });
    }
    Ok(outcomes)
}

/// Split a batch into the paths the native layer granted and a refusal for every other one,
/// keeping the caller's order. A refused path is never opened, stat-ed or copied.
async fn granted_sources(
    grants: &DropGrants,
    paths: &[String],
    wait: std::time::Duration,
) -> Vec<Result<PathBuf, AttachOutcome>> {
    let mut out = Vec::with_capacity(paths.len());
    for raw in paths {
        let path = PathBuf::from(raw);
        if grants.take_waiting(&path, wait).await {
            out.push(Ok(path));
        } else {
            out.push(Err(AttachOutcome::Rejected {
                original_name: path
                    .file_name()
                    .map(|name| name.to_string_lossy().into_owned())
                    .unwrap_or_else(|| raw.clone()),
                code: "VALIDATION_ERROR".to_string(),
                message: "this path was not produced by a drop or a file dialog".to_string(),
            }));
        }
    }
    out
}

/// Capture the screen (or one region of it), write a PNG, and post it into the ticket's
/// conversation (§6.4 item 8).
///
/// The capture itself runs on a blocking thread: `xcap::Monitor::capture_*` is a
/// synchronous OS call that copies a full framebuffer, and doing that on an async runtime
/// thread stalls every other command for its duration.
///
/// The PNG is written into the same queue directory drag-drop uses and then goes through the
/// same delivery path, so a screenshot taken offline survives in the queue exactly like a
/// dropped file rather than being lost with an error toast.
#[tauri::command]
pub async fn screenshot_to_ticket(
    state: State<'_, AppState>,
    ticket_id: i64,
    region: Option<CaptureRegion>,
) -> CommandResult<AttachOutcome> {
    if let Some(region) = region {
        if region.width == 0 || region.height == 0 {
            return Err(CommandError::new(
                "VALIDATION_ERROR",
                "capture region must have a non-zero width and height",
            ));
        }
    }

    let gate = state.session_gate.clone();
    let stamp = gate.stamp();
    let staged_for = StagedSession::of(state.engine().session().as_ref());
    let queue = attachments_dir(&state.cache_dir());
    let target = AttachTarget::Record {
        record: RecordKind::Ticket,
        id: ticket_id,
    };

    let capture_queue = queue.clone();
    let queued = blocking(move || {
        let image = capture(region)?;
        gate.commit(stamp, || {
            queue_capture(&capture_queue, &image, target, stamp, staged_for)
        })
        .unwrap_or_else(|| Err(session_changed("the screenshot")))
    })
    .await??;

    Ok(deliver(&state, &queue, queued).await)
}

// ---------------------------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------------------------

/// `$APPDATA/syncra/cache/quotes/{id}-{rev}.pdf`, exactly as §6.4 spells it.
fn quote_pdf_path(cache_dir: &Path, quote_id: i64, revision: u32) -> PathBuf {
    cache_dir
        .join(QUOTES_SUBDIR)
        .join(format!("{quote_id}-{revision}.pdf"))
}

/// The `reference` half of the `cached_files` `(kind, reference)` identity for a quote PDF —
/// the same `{id}-{rev}` spelling [`quote_pdf_path`] embeds in the file name, kept as its own
/// function so the two ledger call sites in [`cache_quote_pdf`] (and their tests) cannot drift
/// from that spelling independently.
fn quote_pdf_reference(quote_id: i64, revision: u32) -> String {
    format!("{quote_id}-{revision}")
}

/// `$APPDATA/syncra/cache/attachments`.
fn attachments_dir(cache_dir: &Path) -> PathBuf {
    cache_dir.join(ATTACHMENTS_SUBDIR)
}

/// Empty the attachment staging queue. Called when the session changes (a logout, a login by
/// another user): staged files are the previous user's, the engine's wipe never knew about
/// them (they are not in its ledger), and nothing may send them with the next user's token.
///
/// Best-effort like the cache sweep in `storage::clear_local`: the session change has already
/// happened, and a file held open elsewhere must not turn it into a failure. Every failure is
/// logged.
pub fn purge_staging(cache_dir: &Path) {
    let queue = attachments_dir(cache_dir);
    match std::fs::remove_dir_all(&queue) {
        Ok(()) => {}
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => {}
        Err(error) => {
            tracing::warn!(%error, path = %queue.display(), "cannot empty the attachment queue")
        }
    }
}

/// Resolve a caller-supplied path to a real file inside `cache_dir`, or refuse.
///
/// See [`open_cached`] for why every step is load-bearing.
fn resolve_cached_path(cache_dir: &Path, requested: &str) -> CommandResult<PathBuf> {
    let root = cache_dir.canonicalize().map_err(|e| {
        CommandError::new(
            "VALIDATION_ERROR",
            format!("cache directory is not reachable: {e}"),
        )
    })?;

    let candidate = Path::new(requested);
    if !candidate.is_absolute() {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            "only an absolute path inside the cache directory can be opened",
        ));
    }

    // Canonicalisation is what resolves `..` and symlinks; it also requires the file to exist,
    // which is fine — a path that is not there is not openable either.
    let resolved = candidate.canonicalize().map_err(|e| {
        CommandError::new("VALIDATION_ERROR", format!("no such cached file: {e}"))
    })?;

    if !resolved.starts_with(&root) {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            "path is outside the cache directory",
        ));
    }
    if !resolved.is_file() {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            "cached path is not a regular file",
        ));
    }
    if !is_allowed_extension(extension_of(&resolved).as_deref()) {
        return Err(CommandError::new(
            CODE_FILE_TYPE_REJECTED,
            "cached file has an extension this app never writes",
        ));
    }

    Ok(resolved)
}

/// Lossless-enough rendering of a path for the wire. `to_string_lossy` rather than a `?`:
/// every path this module produces is built from ASCII it chose itself, joined onto an OS path
/// the OS handed us, so a non-UTF-8 component can only come from the OS side and refusing to
/// name it would be less useful than naming it approximately.
fn path_string(path: &Path) -> String {
    path.to_string_lossy().into_owned()
}

/// Lowercase final extension, the same normalisation `AttachmentTypeGuard::extension` does
/// with `strtolower(getClientOriginalExtension())`.
fn extension_of(path: &Path) -> Option<String> {
    path.extension()
        .map(|ext| ext.to_string_lossy().to_ascii_lowercase())
}

/// Whether an extension is on the server's allowlist. `None` (no extension at all) is refused:
/// PHP's `pathinfo` yields an empty string there, which is never a key of `$mimeMap`.
fn is_allowed_extension(extension: Option<&str>) -> bool {
    match extension {
        Some(ext) => ALLOWED_EXTENSIONS.contains(&ext),
        None => false,
    }
}

/// Whether `incoming` more bytes still fit under [`MAX_QUEUE_BYTES`].
fn queue_admits(queued: u64, incoming: u64) -> bool {
    queued.saturating_add(incoming) <= MAX_QUEUE_BYTES
}

/// Bytes currently staged: every blob in the queue directory, sidecars excluded.
///
/// A missing directory counts as empty rather than as an error — the queue is created lazily
/// on the first drop and `clear_local` deletes it wholesale.
fn queued_bytes(queue: &Path) -> u64 {
    let Ok(entries) = std::fs::read_dir(queue) else {
        return 0;
    };
    entries
        .flatten()
        .filter(|entry| {
            extension_of(&entry.path()).as_deref() != Some(SIDECAR_EXTENSION)
                && entry.file_type().map(|t| t.is_file()).unwrap_or(false)
        })
        .filter_map(|entry| entry.metadata().ok())
        .map(|meta| meta.len())
        .sum()
}

/// Write `bytes` to `target` through a temporary file in the same directory.
///
/// A half-written PDF that a later call finds and treats as a cache hit is worse than no cache
/// at all: it would open as a corrupt document with no way for the user to force a refetch.
/// Renaming a fully-written temp file over the target makes the visible file either absent or
/// complete.
fn write_atomically(target: &Path, bytes: &[u8]) -> CommandResult<()> {
    let parent = target.parent().ok_or_else(|| {
        CommandError::new("VALIDATION_ERROR", "cache target has no parent directory")
    })?;
    std::fs::create_dir_all(parent).map_err(|e| {
        CommandError::new(
            "VALIDATION_ERROR",
            format!("cannot create {}: {e}", parent.display()),
        )
    })?;

    let temp = parent.join(format!(".{}.part", Uuid::new_v4()));
    std::fs::write(&temp, bytes).map_err(|e| {
        CommandError::new("VALIDATION_ERROR", format!("cannot write cache file: {e}"))
    })?;
    // `rename` over an existing file is atomic on POSIX and, since Windows 10, on NTFS too.
    if let Err(error) = std::fs::rename(&temp, target) {
        let _ = std::fs::remove_file(&temp);
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            format!("cannot publish cache file: {error}"),
        ));
    }
    Ok(())
}

// ---------------------------------------------------------------------------------------------
// Staging
// ---------------------------------------------------------------------------------------------

/// Per-path result of the staging pass.
///
/// Not a `Result`: a refusal here is an ordinary per-file verdict that travels to the UI in the
/// same vector as every success, not an error the caller should propagate with `?`.
#[derive(Debug)]
enum Staged {
    /// Written to the queue and ready for delivery.
    Ready(QueuedAttachment),
    /// Refused; nothing was written.
    Rejected(AttachOutcome),
}

/// Validate and stage a whole batch, keeping a running total so the queue ceiling is applied
/// across the batch rather than per file.
///
/// Dropping twenty 10 MB files at once must not be able to leave 200 MB on disk just because
/// each one was individually admissible.
///
/// Each file is staged inside [`SessionGate::commit`] under the batch's `stamp`, so a file
/// cannot be written into the queue after the session it was dropped in has ended (and its
/// queue been emptied), and every staged file carries that stamp into delivery.
fn stage_batch(
    queue: &Path,
    sources: &[PathBuf],
    target: AttachTarget,
    gate: &SessionGate,
    stamp: SessionStamp,
    staged_for: Option<StagedSession>,
) -> Vec<Staged> {
    let mut total = queued_bytes(queue);
    let mut out = Vec::with_capacity(sources.len());

    for source in sources {
        let staged = gate
            .commit(stamp, || stage_one(queue, source, target, total, staged_for))
            .unwrap_or_else(|| {
                let refusal = session_changed("the attachment");
                Staged::Rejected(AttachOutcome::Rejected {
                    original_name: source
                        .file_name()
                        .map(|name| name.to_string_lossy().into_owned())
                        .unwrap_or_else(|| path_string(source)),
                    code: refusal.code,
                    message: refusal.message,
                })
            });
        let staged = match staged {
            Staged::Ready(mut queued) => {
                total = total.saturating_add(queued.bytes);
                queued.stamp = Some(stamp);
                Staged::Ready(queued)
            }
            rejected => rejected,
        };
        out.push(staged);
    }
    out
}

/// Validate one dropped path and copy it into the queue.
fn stage_one(
    queue: &Path,
    source: &Path,
    target: AttachTarget,
    already_queued: u64,
    staged_for: Option<StagedSession>,
) -> Staged {
    let display_name = source
        .file_name()
        .map(|name| name.to_string_lossy().into_owned())
        .unwrap_or_else(|| path_string(source));

    let reject = |code: &str, message: String| {
        Staged::Rejected(AttachOutcome::Rejected {
            original_name: display_name.clone(),
            code: code.to_string(),
            message,
        })
    };

    let meta = match std::fs::metadata(source) {
        Ok(meta) => meta,
        Err(error) => {
            return reject(
                "VALIDATION_ERROR",
                format!("cannot read dropped path: {error}"),
            )
        }
    };
    if !meta.is_file() {
        // Dropping a directory is a normal thing for a user to do by accident; it is refused
        // rather than walked, because walking one turns a single gesture into an unbounded
        // number of uploads.
        return reject(
            "VALIDATION_ERROR",
            "only files can be attached, not directories".to_string(),
        );
    }

    let extension = extension_of(source);
    if !is_allowed_extension(extension.as_deref()) {
        return reject(
            CODE_FILE_TYPE_REJECTED,
            format!("extension {extension:?} is not on the server allowlist"),
        );
    }
    let extension = extension.unwrap_or_default();

    let bytes = meta.len();
    if bytes > MAX_FILE_BYTES {
        return reject(
            CODE_FILE_TOO_LARGE,
            format!("{bytes} bytes exceeds the {MAX_FILE_BYTES} byte per-file ceiling"),
        );
    }
    if !queue_admits(already_queued, bytes) {
        return reject(
            CODE_QUEUE_FULL,
            format!(
                "{already_queued} bytes already queued; {bytes} more would exceed {MAX_QUEUE_BYTES}"
            ),
        );
    }

    let id = Uuid::new_v4();
    let blob = queue.join(format!("{id}.{extension}"));
    if let Err(error) = std::fs::create_dir_all(queue) {
        return reject("VALIDATION_ERROR", format!("cannot create queue: {error}"));
    }
    if let Err(error) = std::fs::copy(source, &blob) {
        return reject("VALIDATION_ERROR", format!("cannot stage file: {error}"));
    }

    let queued = QueuedAttachment {
        id,
        original_name: display_name.clone(),
        extension,
        bytes,
        target,
        queued_at: now_rfc3339(),
        staged_for,
        stamp: None,
    };
    if let Err(error) = write_sidecar(queue, &queued) {
        let _ = std::fs::remove_file(&blob);
        return reject("VALIDATION_ERROR", error.message);
    }
    Staged::Ready(queued)
}

/// Capture a screenshot straight into the queue directory, outside any session. Only the
/// manual display test uses it; the command captures first and queues inside the gate.
#[cfg(test)]
fn capture_to_queue(
    queue: &Path,
    region: Option<CaptureRegion>,
    target: AttachTarget,
) -> CommandResult<QueuedAttachment> {
    let image = capture(region)?;
    queue_capture(queue, &image, target, SessionGate::new().stamp(), None)
}

/// Encode a captured image as PNG and stage it in the queue directory.
fn queue_capture(
    queue: &Path,
    image: &xcap::image::RgbaImage,
    target: AttachTarget,
    stamp: SessionStamp,
    staged_for: Option<StagedSession>,
) -> CommandResult<QueuedAttachment> {
    let mut png = Vec::new();
    image
        .write_to(&mut std::io::Cursor::new(&mut png), xcap::image::ImageFormat::Png)
        .map_err(|e| CommandError::new("VALIDATION_ERROR", format!("cannot encode PNG: {e}")))?;

    if png.len() as u64 > MAX_FILE_BYTES {
        return Err(CommandError::new(
            CODE_FILE_TOO_LARGE,
            format!("screenshot is {} bytes", png.len()),
        ));
    }
    let queued_already = queued_bytes(queue);
    if !queue_admits(queued_already, png.len() as u64) {
        return Err(CommandError::new(
            CODE_QUEUE_FULL,
            format!("{queued_already} bytes already queued"),
        ));
    }

    let id = Uuid::new_v4();
    let blob = queue.join(format!("{id}.png"));
    write_atomically(&blob, &png)?;

    let queued = QueuedAttachment {
        id,
        // A stable, non-user-supplied name. The timestamp is what distinguishes two
        // screenshots of the same ticket in the conversation list.
        original_name: format!("screenshot-{}.png", now_rfc3339().replace(':', "-")),
        extension: "png".to_string(),
        bytes: png.len() as u64,
        target,
        queued_at: now_rfc3339(),
        staged_for,
        stamp: Some(stamp),
    };
    if let Err(error) = write_sidecar(queue, &queued) {
        let _ = std::fs::remove_file(&blob);
        return Err(error);
    }
    Ok(queued)
}

/// Grab pixels, either from one region or from the whole primary screen.
///
/// `xcap` rather than the `screenshots` crate `SYNCDESKTOP.md` §6.4 item 8 names: that crate is
/// abandoned (0.8.10 is its last release and its own README points here) and pinned a second
/// copy of `image` beside Tauri's. See `Cargo.toml` for the full reasoning; the API shape is the
/// same, so this function is the only place the change is visible.
fn capture(region: Option<CaptureRegion>) -> CommandResult<xcap::image::RgbaImage> {
    let capture_error =
        |e: xcap::XCapError| CommandError::new("VALIDATION_ERROR", format!("screen capture: {e}"));

    match region {
        Some(region) => {
            let monitor = xcap::Monitor::from_point(region.x, region.y).map_err(capture_error)?;
            // `capture_region` takes MONITOR-relative coordinates and adds the display origin
            // back on itself, so the virtual-desktop rectangle has to be rebased first — passing
            // virtual coordinates straight through double-counts the origin on every monitor
            // that is not at (0, 0). (Same contract the `screenshots` crate's `capture_area`
            // had; only the parameter type changed from `i32` to `u32`.)
            let origin_x = monitor.x().map_err(capture_error)?;
            let origin_y = monitor.y().map_err(capture_error)?;
            // `from_point` returned the monitor CONTAINING this point, so the rebased offset is
            // non-negative — but that is the OS's word, not a guarantee, and `as u32` on a
            // negative number would wrap into a colossal offset that `check_capture_region`
            // rejects with a message about a region nobody asked for. A refusal that names the
            // real problem is worth the four lines.
            let (Ok(local_x), Ok(local_y)) = (
                u32::try_from(region.x - origin_x),
                u32::try_from(region.y - origin_y),
            ) else {
                return Err(CommandError::new(
                    "VALIDATION_ERROR",
                    format!(
                        "capture region ({}, {}) is outside the monitor at ({origin_x},                          {origin_y}) that contains it",
                        region.x, region.y
                    ),
                ));
            };
            monitor
                .capture_region(local_x, local_y, region.width, region.height)
                .map_err(capture_error)
        }
        None => {
            let monitors = xcap::Monitor::all().map_err(capture_error)?;
            let monitor = monitors
                // `is_primary` is fallible per monitor here (it was a plain field on
                // `screenshots::DisplayInfo`). A monitor that cannot answer is treated as "not
                // primary" rather than failing the capture: the `or_else` below still produces
                // a screen, and refusing a screenshot because a secondary display misreported
                // itself would be a worse outcome than capturing the first one.
                .iter()
                .find(|monitor| monitor.is_primary().unwrap_or(false))
                .or_else(|| monitors.first())
                .ok_or_else(|| {
                    CommandError::new("VALIDATION_ERROR", "no screen available to capture")
                })?;
            monitor.capture_image().map_err(capture_error)
        }
    }
}

/// Write `{id}.json` next to the staged blob.
fn write_sidecar(queue: &Path, queued: &QueuedAttachment) -> CommandResult<()> {
    let path = queue.join(format!("{}.{SIDECAR_EXTENSION}", queued.id));
    let body = serde_json::to_vec_pretty(queued).map_err(|e| {
        CommandError::new("VALIDATION_ERROR", format!("cannot encode sidecar: {e}"))
    })?;
    write_atomically(&path, &body)
}

/// Delete a staged blob and its sidecar once the server has the bytes.
fn discard_staged(queue: &Path, queued: &QueuedAttachment) {
    let _ = std::fs::remove_file(queue.join(format!("{}.{}", queued.id, queued.extension)));
    let _ = std::fs::remove_file(queue.join(format!("{}.{SIDECAR_EXTENSION}", queued.id)));
}

/// RFC 3339, UTC, second precision.
fn now_rfc3339() -> String {
    time::OffsetDateTime::now_utc()
        .replace_nanosecond(0)
        .unwrap_or_else(|_| time::OffsetDateTime::now_utc())
        .format(&time::format_description::well_known::Rfc3339)
        .unwrap_or_default()
}

// ---------------------------------------------------------------------------------------------
// Delivery
// ---------------------------------------------------------------------------------------------

/// Try to send one staged file; fall back to leaving it queued.
///
/// Every failure path here ends in [`AttachOutcome::Queued`] rather than an `Err`, on purpose:
/// the bytes are already on disk, so the honest answer to "the server refused right now" is
/// "it is waiting", not "it is gone".
async fn deliver(state: &AppState, queue: &Path, queued: QueuedAttachment) -> AttachOutcome {
    if !state.engine().status().online {
        return queued_outcome(queued, "OFFLINE");
    }
    match send(state, queue, &queued).await {
        Ok(outcome) => outcome,
        Err(error) => queued_outcome(queued, &error.code),
    }
}

/// Upload, then link if the target is a record.
async fn send(
    state: &AppState,
    queue: &Path,
    queued: &QueuedAttachment,
) -> CommandResult<AttachOutcome> {
    let token = token_for_staged(&state.session_gate, queued, || {
        bearer_token(&state.keychain_service)
    })?;
    let blob = queue.join(format!("{}.{}", queued.id, queued.extension));
    let bytes = blocking(move || {
        std::fs::read(&blob).map_err(|e| {
            CommandError::new("VALIDATION_ERROR", format!("cannot read staged file: {e}"))
        })
    })
    .await??;

    let attachment = upload_attachment(state, &token, queued, bytes).await?;

    let message_id = match queued.target {
        AttachTarget::Unattached => None,
        AttachTarget::Record { record, id } => {
            Some(attach_to_record(state, &token, record, id, attachment.id).await?)
        }
    };

    discard_staged(queue, queued);
    Ok(AttachOutcome::Uploaded {
        original_name: queued.original_name.clone(),
        attachment,
        message_id,
    })
}

/// The bearer token to send a staged file with — but only the token of the session the file
/// was staged under.
///
/// The token is read inside [`SessionGate::commit`] with the file's own stamp. A login by
/// another user between the drop and this point used to make the upload go out with the NEW
/// user's token, putting the previous user's file into the next user's account; now it is
/// refused, and the file stays where it was (the session change empties the queue). A file
/// without a stamp — one read back from disk — is refused for the same reason: nothing proves
/// whose it is.
fn token_for_staged(
    gate: &SessionGate,
    queued: &QueuedAttachment,
    read_token: impl FnOnce() -> CommandResult<String>,
) -> CommandResult<String> {
    let Some(stamp) = queued.stamp else {
        return Err(session_changed("the attachment"));
    };
    gate.commit(stamp, read_token)
        .unwrap_or_else(|| Err(session_changed("the attachment")))
}

/// `POST /api/attachments` — multipart, field name `file`, exactly what
/// `StoreAttachmentRequest` validates.
async fn upload_attachment(
    state: &AppState,
    token: &str,
    queued: &QueuedAttachment,
    bytes: Vec<u8>,
) -> CommandResult<UploadedAttachment> {
    let url = state
        .api_base
        .join("attachments")
        .map_err(|e| CommandError::new("VALIDATION_ERROR", format!("bad api_base: {e}")))?;

    let part = reqwest::multipart::Part::bytes(bytes)
        .file_name(queued.original_name.clone())
        .mime_str(UPLOAD_CONTENT_TYPE)
        .map_err(http_error)?;
    let form = reqwest::multipart::Form::new().part("file", part);

    let response = state
        .http
        .post(url)
        .bearer_auth(token)
        .multipart(form)
        .send()
        .await
        .map_err(http_error)?;
    let response = ensure_success(response).await?;

    Ok(response
        .json::<Envelope<UploadedAttachment>>()
        .await
        .map_err(http_error)?
        .data)
}

/// Put an already-uploaded attachment onto a record, the only way the server allows.
///
/// `POST /api/conversations/for-record` is a server-side get-or-create and has no local
/// equivalent (`desktop/src/platform/data/comms.ts` says the same thing about
/// `recordConversation`: doing it locally would fork the thread), so this whole path is
/// online-only by construction.
///
/// If the message post fails after the upload succeeded, the server is left holding an
/// unattached `attachments` row. That is a known, bounded leak rather than a silent one:
/// `Attachment::scopeUnattached` and the `attachments:prune-orphans` command exist for exactly
/// this shape, and retrying the upload would produce a *second* copy of the file instead.
async fn attach_to_record(
    state: &AppState,
    token: &str,
    record: RecordKind,
    record_id: i64,
    attachment_id: i64,
) -> CommandResult<i64> {
    let for_record = state
        .api_base
        .join("conversations/for-record")
        .map_err(|e| CommandError::new("VALIDATION_ERROR", format!("bad api_base: {e}")))?;

    let response = state
        .http
        .post(for_record)
        .bearer_auth(token)
        .json(&serde_json::json!({
            "conversable_type": record.as_str(),
            "conversable_id": record_id,
        }))
        .send()
        .await
        .map_err(http_error)?;
    let conversation = ensure_success(response)
        .await?
        .json::<Envelope<IdOnly>>()
        .await
        .map_err(http_error)?
        .data
        .id;

    let messages = state
        .api_base
        .join(&format!("conversations/{conversation}/messages"))
        .map_err(|e| CommandError::new("VALIDATION_ERROR", format!("bad api_base: {e}")))?;

    // Body omitted on purpose: `StoreMessageRequest` accepts a message with an attachment and
    // no text, and inventing a sentence here would be UI copy written in Rust — the kind of
    // hard-coded string `SYNCDESKTOP.md` §0.6 forbids.
    let response = state
        .http
        .post(messages)
        .bearer_auth(token)
        .json(&serde_json::json!({ "attachment_id": attachment_id }))
        .send()
        .await
        .map_err(http_error)?;

    Ok(ensure_success(response)
        .await?
        .json::<Envelope<IdOnly>>()
        .await
        .map_err(http_error)?
        .data
        .id)
}

/// `{"data": ...}` — the envelope every `JsonResource` response arrives in. Not deserialising
/// through it is the AUTH-1 U4 bug (`auth::list_devices` read the rows one level too high and
/// the screen never opened).
#[derive(Debug, Clone, Deserialize)]
struct Envelope<T> {
    data: T,
}

/// The one field this module needs out of a conversation or a message resource.
#[derive(Debug, Clone, Copy, Deserialize)]
struct IdOnly {
    id: i64,
}

fn queued_outcome(queued: QueuedAttachment, reason: &str) -> AttachOutcome {
    AttachOutcome::Queued {
        original_name: queued.original_name,
        queue_id: queued.id,
        bytes: queued.bytes,
        reason: reason.to_string(),
    }
}

// ---------------------------------------------------------------------------------------------
// Shared plumbing
// ---------------------------------------------------------------------------------------------

/// Run blocking filesystem/OS work off the async runtime.
///
/// The `JoinError` is folded into a `CommandError` so callers get one `?`-able layer instead of
/// two; a join failure means the blocking pool panicked, which is a bug, not a user-facing
/// condition — hence the generic code.
async fn blocking<T, F>(work: F) -> CommandResult<T>
where
    F: FnOnce() -> T + Send + 'static,
    T: Send + 'static,
{
    tauri::async_runtime::spawn_blocking(work)
        .await
        .map_err(|e| CommandError::new("VALIDATION_ERROR", format!("file task failed: {e}")))
}

/// The device bearer token, from the OS keychain (K9 — it lives nowhere else).
fn bearer_token(keychain_service: &str) -> CommandResult<String> {
    SystemKeyStore
        .get(keychain_service, KEY_TOKEN)
        .map_err(CommandError::from)?
        .ok_or_else(|| CommandError::new("AUTH_REQUIRED", "no device token in the keychain"))
}

/// Same `HTTP_<status>` shape `commands::auth` produces, so `desktop.errors.httpStatus`
/// renders both without a second code vocabulary.
async fn ensure_success(response: reqwest::Response) -> CommandResult<reqwest::Response> {
    if response.status().is_success() {
        return Ok(response);
    }
    let status = response.status();
    let body = response.text().await.unwrap_or_default();
    Err(CommandError::new(
        format!("HTTP_{}", status.as_u16()),
        if body.is_empty() {
            status.to_string()
        } else {
            body
        },
    ))
}

fn http_error(err: reqwest::Error) -> CommandError {
    CommandError::new("HTTP_ERROR", err.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A throwaway directory under the OS temp dir, removed on drop.
    struct TempDir(PathBuf);

    impl TempDir {
        fn new() -> Self {
            let path = std::env::temp_dir().join(format!("syncra-files-test-{}", Uuid::new_v4()));
            std::fs::create_dir_all(&path).expect("temp dir");
            TempDir(path)
        }
        fn path(&self) -> &Path {
            &self.0
        }
    }

    impl Drop for TempDir {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    fn touch(path: &Path, bytes: u64) {
        if let Some(parent) = path.parent() {
            std::fs::create_dir_all(parent).expect("parent");
        }
        std::fs::write(path, vec![0u8; bytes as usize]).expect("write");
    }

    // -----------------------------------------------------------------------------------------
    // Extension allowlist — the server's list, both directions
    // -----------------------------------------------------------------------------------------

    /// Every key of `$mimeMap` in `backend/config/chat.php`. Spelled out again here rather than
    /// looped over `ALLOWED_EXTENSIONS`, so the test fails if the constant is edited — a test
    /// that reads its expectation from the thing it is testing proves nothing.
    #[test]
    fn the_allowlist_is_the_servers_allowlist() {
        for extension in [
            "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "csv", "txt", "jpg", "jpeg", "png",
            "gif", "webp", "zip", "mp4", "webm", "mp3", "wav",
        ] {
            assert!(
                is_allowed_extension(Some(extension)),
                "{extension} is allowed by config/chat.php but refused here"
            );
        }
        assert_eq!(ALLOWED_EXTENSIONS.len(), 19, "the server's map has 19 keys");
    }

    /// The rejections that matter. `svg` is refused by the server on purpose (XML that can
    /// carry `<script>`); `exe`/`ps1`/`dll` were never on the list at all.
    #[test]
    fn types_outside_the_allowlist_are_refused() {
        for extension in ["svg", "exe", "ps1", "dll", "bat", "sh", "html", "rar", "7z"] {
            assert!(
                !is_allowed_extension(Some(extension)),
                "{extension} must not be attachable"
            );
        }
    }

    /// `fatura.pdf.exe` — PHP's `pathinfo` returns the LAST extension and so does
    /// `Path::extension`, which is what makes the double-extension case need no special code
    /// path on either side (`AttachmentTypeGuard` class comment).
    #[test]
    fn a_double_extension_is_judged_by_its_last_segment() {
        assert!(!is_allowed_extension(
            extension_of(Path::new("invoice.pdf.exe")).as_deref()
        ));
        assert!(is_allowed_extension(
            extension_of(Path::new("invoice.exe.pdf")).as_deref()
        ));
    }

    /// The allowlist keys are lowercase, so the extension has to be normalised before lookup —
    /// the same `strtolower` `AttachmentTypeGuard::extension` applies.
    #[test]
    fn extensions_are_compared_case_insensitively() {
        assert!(is_allowed_extension(
            extension_of(Path::new("SCAN.PDF")).as_deref()
        ));
        assert!(is_allowed_extension(
            extension_of(Path::new("photo.JPEG")).as_deref()
        ));
    }

    /// A file with no extension has nothing to match against `$mimeMap`.
    #[test]
    fn a_file_without_an_extension_is_refused() {
        assert!(!is_allowed_extension(
            extension_of(Path::new("Makefile")).as_deref()
        ));
        assert!(!is_allowed_extension(None));
    }

    // -----------------------------------------------------------------------------------------
    // Size ceilings
    // -----------------------------------------------------------------------------------------

    /// 25 MB is `max:25600` KB on the server, and Laravel's comparison is inclusive, so the
    /// boundary itself must pass here too.
    #[test]
    fn the_per_file_ceiling_matches_the_servers_max_size_kb() {
        assert_eq!(MAX_FILE_BYTES, 25 * 1024 * 1024);
        assert_eq!(MAX_FILE_BYTES / 1024, 25_600, "config('chat.attachments.max_size_kb')");
    }

    #[test]
    fn a_file_at_the_ceiling_is_staged_and_one_byte_over_is_not() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");

        let exact = temp.path().join("exact.pdf");
        touch(&exact, MAX_FILE_BYTES);
        assert!(
            matches!(
                stage_one(&queue, &exact, AttachTarget::Unattached, 0, None),
                Staged::Ready(_)
            ),
            "a file of exactly 25 MB is accepted by the server and must be accepted here"
        );

        let over = temp.path().join("over.pdf");
        touch(&over, MAX_FILE_BYTES + 1);
        match stage_one(&queue, &over, AttachTarget::Unattached, 0, None) {
            Staged::Rejected(AttachOutcome::Rejected { code, .. }) => {
                assert_eq!(code, CODE_FILE_TOO_LARGE)
            }
            other => panic!("expected FILE_TOO_LARGE, got {other:?}"),
        }
    }

    #[test]
    fn a_disallowed_extension_is_rejected_before_anything_is_written() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");
        let source = temp.path().join("payload.exe");
        touch(&source, 10);

        match stage_one(&queue, &source, AttachTarget::Unattached, 0, None) {
            Staged::Rejected(AttachOutcome::Rejected { code, .. }) => {
                assert_eq!(code, CODE_FILE_TYPE_REJECTED)
            }
            other => panic!("expected FILE_TYPE_REJECTED, got {other:?}"),
        }
        assert!(!queue.exists(), "a refused file must not create the queue");
    }

    /// Dropping a folder is a normal misclick; it must not be walked.
    #[test]
    fn a_directory_is_not_attachable() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");
        let dir = temp.path().join("folder.pdf");
        std::fs::create_dir_all(&dir).expect("dir");

        match stage_one(&queue, &dir, AttachTarget::Unattached, 0, None) {
            Staged::Rejected(AttachOutcome::Rejected { code, .. }) => {
                assert_eq!(code, "VALIDATION_ERROR")
            }
            other => panic!("expected VALIDATION_ERROR, got {other:?}"),
        }
    }

    // -----------------------------------------------------------------------------------------
    // Queue ceiling
    // -----------------------------------------------------------------------------------------

    #[test]
    fn the_queue_ceiling_is_one_hundred_megabytes() {
        assert_eq!(MAX_QUEUE_BYTES, 100 * 1024 * 1024);
        assert!(queue_admits(MAX_QUEUE_BYTES - 1, 1), "the boundary itself fits");
        assert!(!queue_admits(MAX_QUEUE_BYTES, 1));
        assert!(!queue_admits(MAX_QUEUE_BYTES - 1, 2));
        // No wrap-around on a nonsense input.
        assert!(!queue_admits(u64::MAX, u64::MAX));
    }

    /// `queued_bytes` counts staged blobs and ignores the sidecars, which are bookkeeping the
    /// user never asked to store.
    #[test]
    fn queued_bytes_counts_blobs_and_ignores_sidecars() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");
        touch(&queue.join("a.pdf"), 100);
        touch(&queue.join("b.png"), 50);
        touch(&queue.join("a.json"), 4096);

        assert_eq!(queued_bytes(&queue), 150);
        // A queue that does not exist yet is empty, not an error.
        assert_eq!(queued_bytes(&temp.path().join("nope")), 0);
    }

    /// The ceiling applies across a batch: each file here is admissible on its own, the second
    /// one is not once the first has been counted.
    #[test]
    fn the_batch_ceiling_is_cumulative_not_per_file() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");
        // Pre-fill the queue to one byte under the ceiling so the test moves kilobytes, not
        // hundreds of megabytes.
        touch(&queue.join("existing.zip"), MAX_QUEUE_BYTES - 3);

        let first = temp.path().join("first.pdf");
        touch(&first, 2);
        let second = temp.path().join("second.pdf");
        touch(&second, 2);

        let gate = SessionGate::new();
        let staged = stage_batch(
            &queue,
            &[first, second],
            AttachTarget::Unattached,
            &gate,
            gate.stamp(),
            None,
        );
        assert!(matches!(staged[0], Staged::Ready(_)), "the first file fits");
        match &staged[1] {
            Staged::Rejected(AttachOutcome::Rejected { code, .. }) => {
                assert_eq!(code, CODE_QUEUE_FULL)
            }
            other => panic!("expected QUEUE_FULL for the second file, got {other:?}"),
        }
    }

    /// Staging writes the blob under a random name plus a sidecar; the user's file name is
    /// carried as data, never as a path component (same rule `AttachmentUploadService` states
    /// for the server side).
    #[test]
    fn staging_writes_a_blob_and_a_sidecar_under_a_random_name() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");
        let source = temp.path().join("Q3 report.pdf");
        touch(&source, 12);

        let Staged::Ready(queued) = stage_one(&queue, &source, AttachTarget::Unattached, 0, None) else {
            panic!("a 12 byte pdf must stage");
        };
        assert_eq!(queued.original_name, "Q3 report.pdf");
        assert_eq!(queued.extension, "pdf");
        assert_eq!(queued.bytes, 12);

        let blob = queue.join(format!("{}.pdf", queued.id));
        let sidecar = queue.join(format!("{}.json", queued.id));
        assert!(blob.is_file());
        assert!(sidecar.is_file());
        assert!(
            !queue.join("Q3 report.pdf").exists(),
            "the user's file name must never become a path component"
        );

        let read_back: QueuedAttachment =
            serde_json::from_slice(&std::fs::read(&sidecar).expect("sidecar")).expect("json");
        assert_eq!(read_back.id, queued.id);
        assert_eq!(read_back.original_name, "Q3 report.pdf");

        discard_staged(&queue, &queued);
        assert!(!blob.exists() && !sidecar.exists());
    }

    // -----------------------------------------------------------------------------------------
    // Cache path generation
    // -----------------------------------------------------------------------------------------

    /// `SYNCDESKTOP.md` §6.4: `$APPDATA/syncra/cache/quotes/{id}-{rev}.pdf`.
    #[test]
    fn the_quote_cache_path_is_id_dash_revision_dot_pdf() {
        let cache = Path::new("C:/appdata/syncra/cache");
        let path = quote_pdf_path(cache, 42, 3);

        assert_eq!(path.file_name().unwrap(), "42-3.pdf");
        assert_eq!(path.parent().unwrap(), cache.join("quotes"));
        // A different revision of the same quote is a different file, which is the whole point
        // of putting the revision in the name.
        assert_ne!(path, quote_pdf_path(cache, 42, 4));
        assert_ne!(path, quote_pdf_path(cache, 43, 3));
    }

    #[test]
    fn a_cached_pdf_is_written_atomically_and_leaves_no_part_file() {
        let temp = TempDir::new();
        let target = quote_pdf_path(temp.path(), 7, 2);
        write_atomically(&target, b"%PDF-1.7").expect("write");

        assert_eq!(std::fs::read(&target).expect("read"), b"%PDF-1.7");
        let leftovers: Vec<_> = std::fs::read_dir(target.parent().unwrap())
            .expect("read_dir")
            .flatten()
            .filter(|e| e.file_name().to_string_lossy().ends_with(".part"))
            .collect();
        assert!(leftovers.is_empty(), "temp files must not survive");

        // Overwriting an existing cache entry works (a revision refetched after a manual wipe).
        write_atomically(&target, b"%PDF-1.7 v2").expect("overwrite");
        assert_eq!(std::fs::read(&target).expect("read"), b"%PDF-1.7 v2");
    }

    // -----------------------------------------------------------------------------------------
    // cached_files ledger wiring (B1) + refresh (D3)
    // -----------------------------------------------------------------------------------------

    /// A `SyncEngine` over a throwaway SQLCipher file with an in-memory keystore
    /// ([`syncra_sync::SyncEngine::open_ephemeral`], the same constructor the crate's own tests
    /// use), so the ledger tests exercise the real `record_cached_file`/`touch_cached_file`
    /// implementation rather than a mock. The `TempDir` must outlive the engine — its `Drop`
    /// deletes the directory the SQLite file lives in — so it is handed back alongside it.
    async fn ephemeral_engine() -> (syncra_sync::SyncEngine, TempDir) {
        let temp = TempDir::new();
        let cfg = syncra_sync::SyncConfig::new(
            url::Url::parse("http://localhost/api/").expect("static url"),
            temp.path().join("test.db"),
        );
        let engine = syncra_sync::SyncEngine::open_ephemeral(cfg)
            .await
            .expect("ephemeral engine opens");
        (engine, temp)
    }

    /// [`cache_hit`] under a session that does not change: a hit, or `None` for a miss.
    fn lookup(
        engine: &syncra_sync::SyncEngine,
        target: &Path,
        reference: &str,
        refresh: bool,
    ) -> CommandResult<Option<CachedPdf>> {
        let gate = SessionGate::new();
        match cache_hit(&gate, gate.stamp(), engine, target, reference, refresh)? {
            CacheLookup::Hit(hit) => Ok(Some(hit)),
            CacheLookup::Miss => Ok(None),
            CacheLookup::Stale => panic!("no transition ran, so the stamp cannot be stale"),
        }
    }

    /// A cache hit looked up after a login by another user wiped the cache must neither
    /// record a ledger row in the new database nor hand back the path of the deleted PDF.
    #[tokio::test]
    async fn a_cache_hit_after_a_session_change_records_nothing() {
        let (engine, _db_dir) = ephemeral_engine().await;
        let cache = TempDir::new();
        let target = quote_pdf_path(cache.path(), 42, 3);
        touch(&target, 8);
        let gate = SessionGate::new();

        // User A's request takes its stamp and sees the PDF ...
        let stamp = gate.stamp();
        // ... then user B signs in and the cache is wiped.
        let login = gate.begin();
        std::fs::remove_file(&target).expect("the wipe removes the PDF");
        assert!(matches!(
            cache_hit(&gate, stamp, &engine, &target, "42-3", false).expect("no error"),
            CacheLookup::Stale
        ));
        drop(login);
        assert!(matches!(
            cache_hit(&gate, stamp, &engine, &target, "42-3", false).expect("no error"),
            CacheLookup::Stale
        ));
        assert_eq!(
            engine.storage_stats().cached_file_bytes,
            0,
            "nothing may be recorded in the new session's database"
        );

        // Under the current session the lookup is an honest miss, so the caller downloads.
        assert!(matches!(
            cache_hit(&gate, gate.stamp(), &engine, &target, "42-3", false).expect("no error"),
            CacheLookup::Miss
        ));
    }

    /// The command's call site: a stale lookup is retried under a fresh stamp, never served.
    #[test]
    fn the_cache_hit_runs_under_the_session_gate() {
        let source = include_str!("files.rs");
        let command = source
            .split_once("pub async fn cache_quote_pdf(")
            .expect("cache_quote_pdf")
            .1
            .split_once("\n}")
            .expect("end of cache_quote_pdf")
            .0;
        assert!(command.contains("cache_hit(&gate, stamp,"), "{command}");
        assert!(command.contains("CacheLookup::Stale"), "{command}");
    }

    /// D3: `refresh: true` bypasses the cache-hit check even though the file is on disk and
    /// perfectly readable — the whole reason the parameter exists.
    #[tokio::test]
    async fn refresh_true_skips_the_cache_even_though_a_file_is_on_disk() {
        let (engine, _db_dir) = ephemeral_engine().await;
        let cache = TempDir::new();
        let target = quote_pdf_path(cache.path(), 42, 3);
        touch(&target, 8);

        let hit = lookup(&engine, &target, "42-3", true).expect("refresh must not error");
        assert!(hit.is_none(), "refresh=true must bypass the cache entirely");
        assert_eq!(
            engine.storage_stats().cached_file_bytes,
            0,
            "a bypassed cache must not be recorded either"
        );
    }

    /// B1: a `refresh: false` hit against an unrecorded blob (the pre-B1 shape — on disk, no
    /// ledger row) falls back to `record_cached_file`, and a second hit on the same reference
    /// touches that row instead of adding a duplicate.
    #[tokio::test]
    async fn refresh_false_serves_the_hit_and_wires_the_ledger() {
        let (engine, _db_dir) = ephemeral_engine().await;
        let cache = TempDir::new();
        let target = quote_pdf_path(cache.path(), 42, 3);
        touch(&target, 8);

        let hit = lookup(&engine, &target, "42-3", false)
            .expect("cache_hit must not error")
            .expect("the file is on disk, so this must be a hit");
        assert!(hit.from_cache);
        assert_eq!(hit.bytes, 8);
        assert_eq!(
            engine.storage_stats().cached_file_bytes,
            8,
            "the first hit on an unrecorded blob must record it"
        );

        lookup(&engine, &target, "42-3", false)
            .expect("cache_hit must not error")
            .expect("still a hit");
        assert_eq!(
            engine.storage_stats().cached_file_bytes,
            8,
            "a repeated hit must touch the existing row, not add a second one"
        );
    }

    /// No file on disk is not a hit, and nothing about it reaches the ledger.
    #[tokio::test]
    async fn a_missing_file_is_not_a_hit_and_touches_nothing() {
        let (engine, _db_dir) = ephemeral_engine().await;
        let cache = TempDir::new();
        let target = quote_pdf_path(cache.path(), 1, 1);

        let hit = lookup(&engine, &target, "1-1", false).expect("a miss is not an error");
        assert!(hit.is_none());
        assert_eq!(engine.storage_stats().cached_file_bytes, 0);
    }

    /// The `(kind, reference)` identity is the `{id}-{rev}` string, independent of the file
    /// name spelling `quote_pdf_path` produces — this is what [`record_cache_hit`] and
    /// [`SyncEngine::record_cached_file`]'s deterministic id both key off.
    #[test]
    fn the_cache_reference_is_id_dash_revision() {
        assert_eq!(quote_pdf_reference(42, 3), "42-3");
        assert_ne!(quote_pdf_reference(42, 3), quote_pdf_reference(42, 4));
        assert_ne!(quote_pdf_reference(42, 3), quote_pdf_reference(43, 3));
    }

    // -----------------------------------------------------------------------------------------
    // open_cached — path containment (mandatory negative check)
    // -----------------------------------------------------------------------------------------

    #[test]
    fn a_file_inside_the_cache_directory_resolves() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        let file = quote_pdf_path(&cache, 9, 1);
        touch(&file, 8);

        let resolved = resolve_cached_path(&cache, &path_string(&file)).expect("inside the cache");
        assert_eq!(resolved, file.canonicalize().expect("canonical"));
    }

    /// The traversal case. `<cache>/quotes/../../secret.pdf` is an ABSOLUTE path that starts
    /// with the cache root textually and resolves outside it — which is exactly why the check
    /// canonicalises instead of comparing strings.
    #[test]
    fn a_path_that_escapes_the_cache_directory_is_refused() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        std::fs::create_dir_all(cache.join(QUOTES_SUBDIR)).expect("cache");

        let secret = temp.path().join("secret.pdf");
        touch(&secret, 4);

        let traversal = cache
            .join(QUOTES_SUBDIR)
            .join("..")
            .join("..")
            .join("secret.pdf");
        assert!(
            traversal.canonicalize().expect("resolves") == secret.canonicalize().expect("secret"),
            "the traversal really does point at the file outside the cache"
        );

        let error = resolve_cached_path(&cache, &path_string(&traversal))
            .expect_err("traversal must be refused");
        assert_eq!(error.code, "VALIDATION_ERROR");
        assert!(
            error.message.contains("outside the cache directory"),
            "{}",
            error.message
        );
    }

    /// A path with no relationship to the cache at all — the blunt version of the same attack.
    #[test]
    fn an_arbitrary_absolute_path_is_refused() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        std::fs::create_dir_all(&cache).expect("cache");

        let elsewhere = temp.path().join("elsewhere.pdf");
        touch(&elsewhere, 4);

        let error = resolve_cached_path(&cache, &path_string(&elsewhere))
            .expect_err("outside paths must be refused");
        assert_eq!(error.code, "VALIDATION_ERROR");
    }

    /// A relative path is refused outright: it would resolve against the process working
    /// directory, which is not the cache.
    #[test]
    fn a_relative_path_is_refused() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        std::fs::create_dir_all(&cache).expect("cache");

        let error =
            resolve_cached_path(&cache, "quotes/1-1.pdf").expect_err("relative must be refused");
        assert_eq!(error.code, "VALIDATION_ERROR");
        assert!(error.message.contains("absolute"), "{}", error.message);
    }

    /// A directory inside the cache is not something to hand the OS.
    #[test]
    fn a_directory_inside_the_cache_is_refused() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        let inner = cache.join(QUOTES_SUBDIR);
        std::fs::create_dir_all(&inner).expect("cache");

        let error = resolve_cached_path(&cache, &path_string(&inner))
            .expect_err("a directory must be refused");
        assert_eq!(error.code, "VALIDATION_ERROR");
    }

    /// Defence in depth: even a file that is inside the cache directory cannot be launched if
    /// it is a type this app never writes there.
    #[test]
    fn an_executable_inside_the_cache_is_still_refused() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        let planted = cache.join("payload.exe");
        touch(&planted, 4);

        let error = resolve_cached_path(&cache, &path_string(&planted))
            .expect_err("an .exe in the cache is still not openable");
        assert_eq!(error.code, CODE_FILE_TYPE_REJECTED);
    }

    /// A path that does not exist cannot be opened, and says so rather than reaching the shell.
    #[test]
    fn a_missing_file_is_refused() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        std::fs::create_dir_all(&cache).expect("cache");

        let error = resolve_cached_path(&cache, &path_string(&cache.join("ghost.pdf")))
            .expect_err("missing file must be refused");
        assert_eq!(error.code, "VALIDATION_ERROR");
    }

    // -----------------------------------------------------------------------------------------
    // Wire shapes
    // -----------------------------------------------------------------------------------------

    /// The webview sends `target` as a tagged object; a typo in the tag must fail to
    /// deserialise rather than silently default to "unattached".
    #[test]
    fn attach_target_round_trips_through_its_tagged_wire_shape() {
        let unattached: AttachTarget =
            serde_json::from_str(r#"{"kind":"unattached"}"#).expect("unattached");
        assert!(matches!(unattached, AttachTarget::Unattached));

        let record: AttachTarget =
            serde_json::from_str(r#"{"kind":"record","record":"ticket","id":12}"#).expect("record");
        match record {
            AttachTarget::Record { record, id } => {
                assert_eq!(record.as_str(), "ticket");
                assert_eq!(id, 12);
            }
            other => panic!("expected a record target, got {other:?}"),
        }

        assert_eq!(RecordKind::Deal.as_str(), "deal");
        assert!(serde_json::from_str::<AttachTarget>(r#"{"kind":"tickets","id":1}"#).is_err());
    }

    /// `conversable_type` is validated server-side against `RecordChatRegistry::TYPES`, which
    /// is exactly `['deal', 'ticket']`.
    #[test]
    fn record_kinds_are_the_two_the_server_accepts() {
        for kind in [RecordKind::Deal, RecordKind::Ticket] {
            assert!(["deal", "ticket"].contains(&kind.as_str()));
        }
    }

    /// The rejected/queued/uploaded discriminator is what the UI branches on.
    #[test]
    fn attach_outcomes_serialise_with_a_status_tag() {
        let rejected = AttachOutcome::Rejected {
            original_name: "x.exe".into(),
            code: CODE_FILE_TYPE_REJECTED.into(),
            message: "no".into(),
        };
        let json = serde_json::to_value(&rejected).expect("json");
        assert_eq!(json["status"], "rejected");
        assert_eq!(json["code"], CODE_FILE_TYPE_REJECTED);

        let queued = AttachOutcome::Queued {
            original_name: "x.pdf".into(),
            queue_id: Uuid::nil(),
            bytes: 3,
            reason: "OFFLINE".into(),
        };
        assert_eq!(
            serde_json::to_value(&queued).expect("json")["status"],
            "queued"
        );
    }

    // -----------------------------------------------------------------------------------------
    // Session boundaries: the PDF cache and the attachment queue
    // -----------------------------------------------------------------------------------------

    /// A download that started under one session and finished after a login by another user
    /// (or a logout, or a local wipe) must not reach the cache or the current ledger.
    #[tokio::test]
    async fn a_pdf_finishing_after_a_session_change_is_neither_cached_nor_recorded() {
        let (engine, _db_dir) = ephemeral_engine().await;
        let cache = TempDir::new();
        let target = quote_pdf_path(cache.path(), 42, 3);
        let gate = SessionGate::new();

        let stamp = gate.stamp();
        // The login happens while the body is on the wire.
        drop(gate.begin());

        let error = store_downloaded_pdf(&gate, stamp, &engine, &target, "42-3", b"%PDF-A")
            .expect_err("a response from the previous session must be dropped");
        assert_eq!(error.code, "AUTH_REQUIRED");
        assert!(!target.exists(), "nothing may be written to the cache");
        assert_eq!(
            engine.storage_stats().cached_file_bytes,
            0,
            "nothing may be recorded in the current database"
        );

        // The same call under the current session still works.
        store_downloaded_pdf(&gate, gate.stamp(), &engine, &target, "42-3", b"%PDF-B")
            .expect("a current download is stored");
        assert_eq!(std::fs::read(&target).expect("cached"), b"%PDF-B");
        assert_eq!(engine.storage_stats().cached_file_bytes, 6);
    }

    /// The download started while the login was still running and finishes after it.
    #[tokio::test]
    async fn a_pdf_requested_during_a_login_is_not_stored_after_it() {
        let (engine, _db_dir) = ephemeral_engine().await;
        let cache = TempDir::new();
        let target = quote_pdf_path(cache.path(), 1, 1);
        let gate = SessionGate::new();

        let login = gate.begin();
        let stamp = gate.stamp();
        drop(login);

        assert!(store_downloaded_pdf(&gate, stamp, &engine, &target, "1-1", b"%PDF").is_err());
        assert!(!target.exists());
    }

    /// Item 4: the data directory moves while a PDF is on the wire. The old root is gone, and
    /// the late response must not recreate it or be recorded with the old path.
    #[tokio::test]
    async fn a_pdf_racing_a_data_directory_move_does_not_recreate_the_old_root() {
        let (engine, _db_dir) = ephemeral_engine().await;
        let old_root = TempDir::new();
        let old_cache = old_root.path().join("cache");
        let target = quote_pdf_path(&old_cache, 7, 2);
        let gate = SessionGate::new();

        let stamp = gate.stamp();
        let moving = gate.begin();
        std::fs::remove_dir_all(old_root.path()).expect("the move removes the old root");

        // While the move runs, and after it has finished.
        assert!(store_downloaded_pdf(&gate, stamp, &engine, &target, "7-2", b"%PDF").is_err());
        drop(moving);
        assert!(store_downloaded_pdf(&gate, stamp, &engine, &target, "7-2", b"%PDF").is_err());

        assert!(!old_root.path().exists(), "the old root must stay gone");
        assert_eq!(engine.storage_stats().cached_file_bytes, 0);
    }

    /// A file dropped by one user cannot be written into the queue after the session changed.
    #[test]
    fn staging_after_a_session_change_writes_nothing() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");
        let source = temp.path().join("a.pdf");
        touch(&source, 4);
        let gate = SessionGate::new();
        let stamp = gate.stamp();
        drop(gate.begin());

        let staged = stage_batch(
            &queue,
            std::slice::from_ref(&source),
            AttachTarget::Unattached,
            &gate,
            stamp,
            None,
        );
        match &staged[0] {
            Staged::Rejected(AttachOutcome::Rejected { code, .. }) => {
                assert_eq!(code, "AUTH_REQUIRED")
            }
            other => panic!("expected a refusal, got {other:?}"),
        }
        assert!(!queue.exists(), "nothing may reach the queue");
    }

    /// A staged file carries its session, both in memory and in the sidecar.
    #[test]
    fn a_staged_file_carries_the_session_it_was_staged_under() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");
        let source = temp.path().join("a.pdf");
        touch(&source, 4);
        let gate = SessionGate::new();
        let stamp = gate.stamp();
        let owner = StagedSession {
            user_id: 5,
            token_id: 77,
        };

        let staged = stage_batch(
            &queue,
            std::slice::from_ref(&source),
            AttachTarget::Unattached,
            &gate,
            stamp,
            Some(owner),
        );
        let Staged::Ready(queued) = &staged[0] else {
            panic!("expected a staged file, got {:?}", staged[0]);
        };
        assert_eq!(queued.stamp, Some(stamp));
        assert_eq!(queued.staged_for, Some(owner));

        let sidecar = queue.join(format!("{}.json", queued.id));
        let read_back: QueuedAttachment =
            serde_json::from_slice(&std::fs::read(sidecar).expect("sidecar")).expect("json");
        assert_eq!(read_back.staged_for, Some(owner));
        assert_eq!(read_back.stamp, None, "a stamp never survives a round trip to disk");
    }

    /// The attachment race: A drops a file, B logs in before the upload starts. The upload
    /// must not go out with B's token.
    #[test]
    fn a_staged_file_is_not_sent_with_the_next_sessions_token() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");
        let source = temp.path().join("a.pdf");
        touch(&source, 4);
        let gate = SessionGate::new();
        let staged = stage_batch(
            &queue,
            std::slice::from_ref(&source),
            AttachTarget::Unattached,
            &gate,
            gate.stamp(),
            None,
        );
        let Staged::Ready(queued) = staged.into_iter().next().expect("one verdict") else {
            panic!("expected a staged file");
        };

        // Same session: the token is read.
        assert_eq!(
            token_for_staged(&gate, &queued, || Ok("token-a".to_string())).expect("same session"),
            "token-a"
        );

        drop(gate.begin());
        let mut read = false;
        let error = token_for_staged(&gate, &queued, || {
            read = true;
            Ok("token-b".to_string())
        })
        .expect_err("another session's token must not be used");
        assert_eq!(error.code, "AUTH_REQUIRED");
        assert!(!read, "the token must not even be read");

        // A file read back from disk has no stamp and is never sent with whatever is current.
        let mut from_disk = queued.clone();
        from_disk.stamp = None;
        let fresh = SessionGate::new();
        assert!(token_for_staged(&fresh, &from_disk, || Ok("t".to_string())).is_err());
    }

    /// The session change empties the staging queue.
    #[test]
    fn purging_staging_removes_every_staged_file() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        touch(&attachments_dir(&cache).join("x.pdf"), 3);
        touch(&attachments_dir(&cache).join("x.json"), 3);
        touch(&quote_pdf_path(&cache, 1, 1), 3);

        purge_staging(&cache);

        assert!(!attachments_dir(&cache).exists());
        assert!(
            quote_pdf_path(&cache, 1, 1).exists(),
            "the PDF cache is the engine's to wipe"
        );
        // Nothing there is not an error.
        purge_staging(&cache);
    }

    // -----------------------------------------------------------------------------------------
    // O10: only paths the native layer produced
    // -----------------------------------------------------------------------------------------

    /// A path the webview made up is refused before the file is opened; a dropped path is
    /// accepted once.
    #[tokio::test]
    async fn rejects_ungranted_attachment_path() {
        let temp = TempDir::new();
        let dropped = temp.path().join("dropped.pdf");
        let secret = temp.path().join("secret.pdf");
        touch(&dropped, 4);
        touch(&secret, 4);
        let grants = DropGrants::new();
        grants.grant([&dropped]);

        let wait = std::time::Duration::from_millis(20);
        let checked = granted_sources(
            &grants,
            &[path_string(&dropped), path_string(&secret)],
            wait,
        )
        .await;
        assert_eq!(checked[0].as_ref().ok(), Some(&dropped));
        match &checked[1] {
            Err(AttachOutcome::Rejected {
                code,
                original_name,
                ..
            }) => {
                assert_eq!(code, "VALIDATION_ERROR");
                assert_eq!(original_name, "secret.pdf");
            }
            other => panic!("an ungranted path must be refused, got {other:?}"),
        }

        // A replay of the granted call is refused: the grant was spent.
        let replay = granted_sources(&grants, &[path_string(&dropped)], wait).await;
        assert!(replay[0].is_err());

        // Another spelling of the dropped file is not the dropped path.
        grants.grant([&dropped]);
        let spelled = path_string(&temp.path().join("sub").join("..").join("dropped.pdf"));
        let respelled = granted_sources(&grants, &[spelled], wait).await;
        assert!(respelled[0].is_err());
    }

    // --- screen capture (manual) ----------------------------------------------------------

    /// Proves the `xcap` -> PNG -> queue path end to end, including the file the queue ends up
    /// holding.
    ///
    /// `#[ignore]` because it needs a real display: `Monitor::all()` has nothing to enumerate on
    /// a headless CI runner, and a suite that goes red without a logged-in desktop session is a
    /// suite people learn to ignore. Run it by hand on the platform being verified:
    /// `cargo test --lib -- --ignored capture_writes_a_real_png_into_the_queue`.
    #[test]
    #[ignore = "needs a real display; run manually per SYNCDESKTOP F5 acceptance"]
    fn capture_writes_a_real_png_into_the_queue() {
        let temp = TempDir::new();
        let queue = temp.path().join("queue");
        let queued = capture_to_queue(
            &queue,
            None,
            AttachTarget::Record {
                record: RecordKind::Ticket,
                id: 1,
            },
        )
        .expect("primary screen capture");

        let blob = queue.join(format!("{}.png", queued.id));
        let bytes = std::fs::read(&blob).expect("staged png");
        assert_eq!(
            &bytes[..8],
            &[0x89, b'P', b'N', b'G', 0x0d, 0x0a, 0x1a, 0x0a],
            "the staged blob must be a real PNG"
        );
        assert_eq!(queued.bytes, bytes.len() as u64);
        assert_eq!(queued.extension, "png");
        assert!(queued.original_name.starts_with("screenshot-"));
        assert!(queue.join(format!("{}.json", queued.id)).is_file());
    }

    // --- integration scaffolding --------------------------------------------------------------

    /// The `#![allow(dead_code)]` at the top of this file is legitimate for exactly one window:
    /// the commands are written but `lib.rs` has not registered them yet, so nothing here is
    /// reachable from the crate root. The moment registration lands, that attribute stops
    /// covering a temporary state and starts hiding real dead code — so removing it is made
    /// part of the integration instead of a note somebody has to remember.
    ///
    /// The needle is split across `concat!` on purpose: this test's own source is part of the
    /// file it reads, and an intact literal would match itself forever.
    #[test]
    fn the_dead_code_scaffold_is_removed_once_the_commands_are_registered() {
        let registered = include_str!("../lib.rs").contains("commands::files::cache_quote_pdf");
        // Whole-line comparison, not `contains`: the needle also appears in this test's own
        // doc comment and panic message, so a substring search matches itself forever and the
        // assertion can never go green. Only the real attribute occupies a line of its own.
        let needle = concat!("#![allow(dead_", "code)]");
        let scaffolded = include_str!("files.rs")
            .lines()
            .any(|line| line.trim() == needle);

        assert!(
            !(registered && scaffolded),
            "files::* is registered in generate_handler! now — delete the #![allow(dead_code)] \
             at the top of commands/files.rs; its items are reachable and real dead code in this \
             module would go unreported"
        );
    }

    #[test]
    fn the_timestamp_is_rfc_3339() {
        let stamp = now_rfc3339();
        assert!(stamp.ends_with('Z'), "{stamp}");
        assert_eq!(stamp.len(), 20, "YYYY-MM-DDTHH:MM:SSZ — {stamp}");
    }
}
