//! Engine configuration and user-tunable desktop settings.

use serde::{Deserialize, Serialize};
use std::path::PathBuf;
use url::Url;

/// Lower bounds from `SYNCDESKTOP.md` K8. Settings below these are clamped, not rejected.
pub const MIN_RETENTION_DAYS: u32 = 7;
/// Minimum local database ceiling, in megabytes (K8).
pub const MIN_MAX_DB_SIZE_MB: u32 = 100;
/// Minimum outbox ceiling (K8).
pub const MIN_MAX_OUTBOX: u32 = 500;

/// Default retention window in days (K8).
pub const DEFAULT_RETENTION_DAYS: u32 = 30;
/// Default local database ceiling in megabytes (K8).
pub const DEFAULT_MAX_DB_SIZE_MB: u32 = 500;
/// Default outbox ceiling (K8).
pub const DEFAULT_MAX_OUTBOX: u32 = 5000;

/// Protocol version this build implements. A server reporting anything else stops the
/// engine and raises [`crate::EngineEvent::ProtocolMismatch`] (`SYNCDESKTOP.md` §4.4).
pub const PROTOCOL_VERSION: u32 = 1;

/// Cache lifetime of `GET /api/sync/manifest` (`SYNCDESKTOP.md` §5.5).
pub const MANIFEST_CACHE_SECS: u64 = 600;

/// Everything the engine needs to open a local database and reach the server.
#[derive(Debug, Clone)]
pub struct SyncConfig {
    /// Base URL of the Laravel API, e.g. `https://crm.example.com/api/`.
    pub api_base: Url,
    /// Path of the SQLCipher database file.
    pub db_path: PathBuf,
    /// Retention window in days (K8).
    pub retention_days: u32,
    /// Local database ceiling in megabytes (K8).
    pub max_db_size_mb: u32,
    /// Outbox ceiling (K8).
    pub max_outbox: u32,
    /// OS keychain service name under which the token and the database key are stored (K9).
    pub keychain_service: String,
}

impl SyncConfig {
    /// Config with the specification defaults for everything except path and URL.
    pub fn new(api_base: Url, db_path: impl Into<PathBuf>) -> Self {
        SyncConfig {
            api_base,
            db_path: db_path.into(),
            retention_days: DEFAULT_RETENTION_DAYS,
            max_db_size_mb: DEFAULT_MAX_DB_SIZE_MB,
            max_outbox: DEFAULT_MAX_OUTBOX,
            keychain_service: "syncra-desktop".to_string(),
        }
    }

    /// Ceiling in bytes.
    pub fn max_db_bytes(&self) -> u64 {
        u64::from(self.max_db_size_mb) * 1024 * 1024
    }
}

/// The subset of the configuration the user may change at runtime.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct DesktopSettings {
    /// Retention window in days.
    pub retention_days: u32,
    /// Local database ceiling in megabytes.
    pub max_db_size_mb: u32,
    /// Configured outbox ceiling.
    pub max_outbox: u32,
    /// Clipboard capture is opt-in and off by default (K10).
    #[serde(default)]
    pub clipboard_capture: bool,
    /// Whether closing the window sends the app to the tray instead of quitting it (§6.4).
    ///
    /// Defaults to `true` because that is today's fixed, non-configurable behavior: a
    /// settings row persisted before this field existed has no opinion on it, and
    /// deserializing that row must not silently switch an existing user over to "close
    /// quits" the first time this build reads their settings back.
    #[serde(default = "default_close_to_tray")]
    pub close_to_tray: bool,
}

fn default_close_to_tray() -> bool {
    true
}

impl Default for DesktopSettings {
    fn default() -> Self {
        DesktopSettings {
            retention_days: DEFAULT_RETENTION_DAYS,
            max_db_size_mb: DEFAULT_MAX_DB_SIZE_MB,
            max_outbox: DEFAULT_MAX_OUTBOX,
            clipboard_capture: false,
            close_to_tray: default_close_to_tray(),
        }
    }
}

impl DesktopSettings {
    /// Clamp every field to its K8 lower bound.
    pub fn clamped(mut self) -> Self {
        self.retention_days = self.retention_days.max(MIN_RETENTION_DAYS);
        self.max_db_size_mb = self.max_db_size_mb.max(MIN_MAX_DB_SIZE_MB);
        self.max_outbox = self.max_outbox.max(MIN_MAX_OUTBOX);
        self
    }
}

/// Server-declared limits from the manifest `policy` object.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct ServerPolicy {
    #[serde(default = "default_retention_days_max")]
    /// Largest window the server will serve.
    pub retention_days_max: u32,
    #[serde(default = "default_push_batch_max")]
    /// Maximum mutations per push request.
    pub push_batch_max: u32,
    #[serde(default = "default_push_bytes_max")]
    /// Maximum push request size in bytes.
    pub push_bytes_max: u64,
    #[serde(default = "default_pull_limit_max")]
    /// Maximum rows per table per pull.
    pub pull_limit_max: u32,
}

fn default_retention_days_max() -> u32 {
    365
}
fn default_push_batch_max() -> u32 {
    200
}
fn default_push_bytes_max() -> u64 {
    2 * 1024 * 1024
}
fn default_pull_limit_max() -> u32 {
    1000
}

impl Default for ServerPolicy {
    fn default() -> Self {
        ServerPolicy {
            retention_days_max: default_retention_days_max(),
            push_batch_max: default_push_batch_max(),
            push_bytes_max: default_push_bytes_max(),
            pull_limit_max: default_pull_limit_max(),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A settings row persisted by a build before `close_to_tray` existed has no such key.
    /// Deserializing it must fill in `true` -- today's fixed behavior -- not `false`, which
    /// `#[serde(default)]` (rather than `#[serde(default = "default_close_to_tray")]`) would
    /// have produced and which would have silently switched every existing user's window
    /// close over to "quit" the first time this build read their settings back.
    #[test]
    fn deserializing_a_settings_row_without_close_to_tray_defaults_it_to_true() {
        let old_row = serde_json::json!({
            "retention_days": 30,
            "max_db_size_mb": 500,
            "max_outbox": 5000,
        });

        let settings: DesktopSettings =
            serde_json::from_value(old_row).expect("old rows must still deserialize");

        assert!(
            settings.close_to_tray,
            "a settings row from before this field existed must read back as close-to-tray"
        );
        // `clipboard_capture` predates `close_to_tray` too and already defaults via
        // `#[serde(default)]`; pinning it here documents that the two fields default
        // independently and to different values.
        assert!(!settings.clipboard_capture);
    }

    /// The constructor default and the serde default must agree, or a freshly constructed
    /// `DesktopSettings` and one round-tripped through a row that only carries the
    /// pre-`clipboard_capture` fields would disagree on what "no explicit setting" means.
    /// (`retention_days`, `max_db_size_mb` and `max_outbox` have no serde default of their
    /// own -- they are required -- so this pins the numeric defaults explicitly rather than
    /// omitting them.)
    #[test]
    fn the_serde_default_matches_the_constructor_default() {
        let old_row = serde_json::json!({
            "retention_days": DEFAULT_RETENTION_DAYS,
            "max_db_size_mb": DEFAULT_MAX_DB_SIZE_MB,
            "max_outbox": DEFAULT_MAX_OUTBOX,
        });
        let from_old_row: DesktopSettings =
            serde_json::from_value(old_row).expect("the omitted fields must default");
        assert_eq!(from_old_row, DesktopSettings::default());
    }
}
