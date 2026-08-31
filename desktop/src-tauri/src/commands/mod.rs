//! Command layer (`SYNCDESKTOP.md` §6.2, `docs/DESKTOP-ARCHITECTURE.md` §5.2).
//!
//! Every command here is a thin delegation to [`syncra_sync::SyncEngine`] — the crate's
//! public API is frozen (`docs/DESKTOP-SYNC-PROTOCOL.md` §5) and none of it is reproduced or
//! reinterpreted here. The two exceptions are documented where they live:
//! [`auth::list_devices`]/[`auth::revoke_device`] (not engine methods — they call
//! `GET`/`DELETE /api/me/devices*` directly, per `docs/DESKTOP-ARCHITECTURE.md` §5.2) and
//! [`storage::clear_local`] (uses the crate's public `db::open`/`db::wipe` through a second,
//! short-lived connection, exactly as `db::wipe`'s own doc comment licenses).
//!
//! `files::*` and `os::*` (`SYNCDESKTOP.md` §6.2) are **out of scope this turn** — F5.

pub mod auth;
pub mod data;
pub mod storage;
pub mod sync;

use serde::Serialize;
use syncra_sync::SyncError;

/// Wire shape of every failed command (`SYNCDESKTOP.md` §6.2): `{code, message}`. The UI
/// maps `code` through the `desktop.errors.<code>` i18n namespace
/// (`docs/DESKTOP-ARCHITECTURE.md` §5.2 KARAR A10); an unrecognised code falls back to
/// `desktop.errors.unknown` there — nothing on the Rust side needs to know about that.
#[derive(Debug, Clone, Serialize)]
pub struct CommandError {
    /// Stable machine-readable code.
    pub code: String,
    /// Human-readable detail; not shown as-is in the UI (i18n owns the copy), useful for logs.
    pub message: String,
}

impl From<SyncError> for CommandError {
    fn from(err: SyncError) -> Self {
        CommandError {
            code: err.code().to_string(),
            message: err.to_string(),
        }
    }
}

/// Result alias every command in this module tree returns.
pub type CommandResult<T> = Result<T, CommandError>;
