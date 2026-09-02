//! Error type for the sync engine.

use crate::types::WriteBlockReason;

/// Every fallible entry point of [`crate::SyncEngine`] returns this error.
///
/// The variants mirror `SYNCDESKTOP.md` §5.2 exactly. Tauri commands map them to a
/// `{code, message}` JSON pair, which the UI renders through `desktop.errors.*`.
#[derive(Debug, thiserror::Error)]
pub enum SyncError {
    /// No usable session: no token in the keychain, or the server answered 401.
    #[error("authentication required")]
    Auth,

    /// The engine is offline, either because the OS said so or because the request failed
    /// at the transport layer.
    #[error("offline")]
    Offline,

    /// The server speaks a protocol version this build does not implement, or answered
    /// with a body that does not match the frozen wire contract.
    #[error("protocol error: {0}")]
    Protocol(String),

    /// Local database failure.
    #[error("database error: {0}")]
    Db(#[from] rusqlite::Error),

    /// Transport failure that is not a clean offline signal.
    #[error("http error: {0}")]
    Http(#[from] reqwest::Error),

    /// Local writes are refused because a retention ceiling was reached
    /// (`SYNCDESKTOP.md` §5.6). Reads keep working.
    #[error("writes blocked: {0:?}")]
    WriteBlocked(WriteBlockReason),

    /// The caller handed the engine something the contract does not allow.
    #[error("validation error: {0}")]
    Validation(String),

    /// The server refused the request and said why, in a machine-readable way.
    ///
    /// Introduced by the AUTH-1 wire audit (U11/U12/U16). Before it, `transport.rs` folded
    /// the server's own code into the *message* of a [`SyncError::Validation`], which meant
    /// the UI had to recover `INVALID_CREDENTIALS` / `USER_INACTIVE` / `LOCKED_OUT` with a
    /// substring search, `retry_after` was lost entirely (so `LoginPage`'s lockout countdown
    /// could not work on the desktop), and `403 USER_DEACTIVATED` — the one signal KARAR A25
    /// hangs the local wipe on — was indistinguishable from any other 403.
    #[error("server error {}: {}", .0.status, .0.code)]
    Server(ServerError),
}

/// A refusal the server described with a `code` (`SYNCDESKTOP.md` §4.3, §4.4).
///
/// `status` is kept alongside `code` because the two together are the contract: KARAR A25
/// distinguishes `403 USER_DEACTIVATED` (wipe) from a bare 401 (keep the outbox), and a code
/// alone cannot express that.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ServerError {
    /// HTTP status the server answered with.
    pub status: u16,
    /// Machine-readable code, e.g. `INVALID_CREDENTIALS` or `USER_DEACTIVATED`. Synthesised
    /// from the status (`VALIDATION_ERROR` for 422, `HTTP_<status>` otherwise) when the body
    /// carried none.
    pub code: String,
    /// Human-readable server message, when the body carried one.
    pub message: Option<String>,
    /// Seconds until a lockout expires (`423 LOCKED_OUT`).
    pub retry_after: Option<u64>,
}

impl SyncError {
    /// Stable machine-readable code, used by the Tauri command layer.
    ///
    /// `&str` rather than `&'static str`: [`SyncError::Server`] carries the server's own code,
    /// which is only known at runtime. Every other arm still yields a `'static` literal.
    pub fn code(&self) -> &str {
        match self {
            SyncError::Auth => "AUTH_REQUIRED",
            SyncError::Offline => "OFFLINE",
            SyncError::Protocol(_) => "PROTOCOL_ERROR",
            SyncError::Db(_) => "DB_ERROR",
            SyncError::Http(_) => "HTTP_ERROR",
            SyncError::WriteBlocked(_) => "WRITE_BLOCKED",
            SyncError::Validation(_) => "VALIDATION_ERROR",
            SyncError::Server(err) => &err.code,
        }
    }

    /// The server's refusal detail, when this is one.
    pub fn server(&self) -> Option<&ServerError> {
        match self {
            SyncError::Server(err) => Some(err),
            _ => None,
        }
    }

    /// Seconds until a lockout expires, when the server sent one (`423 LOCKED_OUT`).
    ///
    /// This is what `LoginPage`'s countdown reads; it used to be dropped on the floor.
    pub fn retry_after(&self) -> Option<u64> {
        self.server().and_then(|err| err.retry_after)
    }
}


impl From<serde_json::Error> for SyncError {
    fn from(e: serde_json::Error) -> Self {
        SyncError::Protocol(format!("json: {e}"))
    }
}

/// Convenience alias.
pub type Result<T> = std::result::Result<T, SyncError>;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_server_error_reports_the_servers_own_code_and_retry_after() {
        let err = SyncError::Server(ServerError {
            status: 423,
            code: "LOCKED_OUT".into(),
            message: Some("too many attempts".into()),
            retry_after: Some(120),
        });
        assert_eq!(err.code(), "LOCKED_OUT");
        assert_eq!(err.retry_after(), Some(120));
    }

    #[test]
    fn other_variants_carry_no_retry_after() {
        assert_eq!(SyncError::Offline.code(), "OFFLINE");
        assert_eq!(SyncError::Offline.retry_after(), None);
    }
}
