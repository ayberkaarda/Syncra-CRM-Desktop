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
}

impl SyncError {
    /// Stable machine-readable code, used by the Tauri command layer.
    pub fn code(&self) -> &'static str {
        match self {
            SyncError::Auth => "AUTH_REQUIRED",
            SyncError::Offline => "OFFLINE",
            SyncError::Protocol(_) => "PROTOCOL_ERROR",
            SyncError::Db(_) => "DB_ERROR",
            SyncError::Http(_) => "HTTP_ERROR",
            SyncError::WriteBlocked(_) => "WRITE_BLOCKED",
            SyncError::Validation(_) => "VALIDATION_ERROR",
        }
    }
}

impl From<serde_json::Error> for SyncError {
    fn from(e: serde_json::Error) -> Self {
        SyncError::Protocol(format!("json: {e}"))
    }
}

/// Convenience alias.
pub type Result<T> = std::result::Result<T, SyncError>;
