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
    /// Seconds until a lockout expires, when the server sent one (`423 LOCKED_OUT`).
    ///
    /// Additive to the `{code, message}` shape §6.2 fixes, and omitted whenever it is absent,
    /// so nothing that reads the old two-field shape changes. It exists because `LoginPage`'s
    /// lockout countdown needs the number and there was no way for it to arrive: the engine
    /// used to drop `retry_after` on the floor while flattening the refusal into a message
    /// string (AUTH-1 U12).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub retry_after: Option<u64>,
}

impl CommandError {
    /// A shell-side failure that has no [`SyncError`] behind it.
    pub fn new(code: impl Into<String>, message: impl Into<String>) -> Self {
        CommandError {
            code: code.into(),
            message: message.into(),
            retry_after: None,
        }
    }
}

impl From<SyncError> for CommandError {
    fn from(err: SyncError) -> Self {
        CommandError {
            code: command_error_code(&err),
            message: err.to_string(),
            retry_after: err.retry_after(),
        }
    }
}

/// The `code` a [`SyncError`] surfaces to the UI.
///
/// Every arm but one just forwards [`SyncError::code`]. The exception is a `5xx`
/// (`SyncError::Server`, O25): the transport already turned it into a structured error
/// instead of a bare `Offline`, but `syncra_sync::transport::server_error` still falls back
/// to a synthesised `HTTP_<status>` code when the server's own body carried none — which a
/// `500` almost never does. `desktop.errors.*` has no entry for `HTTP_500` and was never
/// meant to grow one per status; `SERVER_ERROR` is the one key the dictionary defines for
/// "the server broke and said nothing more specific". A `5xx` that *does* carry the server's
/// own code (rare, but the wire contract allows it) keeps that code unchanged.
fn command_error_code(err: &SyncError) -> String {
    if let Some(server) = err.server() {
        if server.status >= 500 && server.code.starts_with("HTTP_") {
            return "SERVER_ERROR".to_string();
        }
    }
    err.code().to_string()
}

/// Result alias every command in this module tree returns.
pub type CommandResult<T> = Result<T, CommandError>;

#[cfg(test)]
mod tests {
    use super::*;
    use syncra_sync::ServerError;

    /// A `5xx` with no code of its own — the common shape for a `500` — reaches the UI as
    /// `SERVER_ERROR`, not the synthesised `HTTP_500` the engine uses internally.
    #[test]
    fn a_bare_500_maps_to_server_error() {
        let err = SyncError::Server(ServerError {
            status: 500,
            code: "HTTP_500".into(),
            message: Some("SQLSTATE[42S22]".into()),
            retry_after: None,
        });
        let command_err: CommandError = err.into();
        assert_eq!(command_err.code, "SERVER_ERROR");
    }

    /// A `5xx` that *does* carry the server's own code keeps it — `SERVER_ERROR` is only the
    /// fallback for "the server said nothing more specific", not a blanket rewrite of every
    /// `5xx`.
    #[test]
    fn a_5xx_with_its_own_code_keeps_that_code() {
        let err = SyncError::Server(ServerError {
            status: 503,
            code: "MAINTENANCE_MODE".into(),
            message: None,
            retry_after: None,
        });
        let command_err: CommandError = err.into();
        assert_eq!(command_err.code, "MAINTENANCE_MODE");
    }

    /// A `4xx` is untouched by the O25 fallback — `HTTP_422` still reaches the UI as itself,
    /// which `desktop.errors.httpStatus` already renders.
    #[test]
    fn a_4xx_is_not_rewritten_to_server_error() {
        let err = SyncError::Server(ServerError {
            status: 422,
            code: "HTTP_422".into(),
            message: None,
            retry_after: None,
        });
        let command_err: CommandError = err.into();
        assert_eq!(command_err.code, "HTTP_422");
    }

    /// A transport-level offline error is untouched by the O25 mapping.
    #[test]
    fn offline_still_maps_to_offline() {
        let command_err: CommandError = SyncError::Offline.into();
        assert_eq!(command_err.code, "OFFLINE");
    }
}
