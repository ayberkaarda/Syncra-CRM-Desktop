//! The wire contract.
//!
//! These types are a literal transcription of `docs/DESKTOP-SYNC-PROTOCOL.md` §4 (which in
//! turn concretises `SYNCDESKTOP.md` §4.3-§4.5). The contract is frozen for wave 1, so
//! nothing here may be reshaped without changing that document first.

use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;

// ---------------------------------------------------------------------------
// POST /api/auth/device
// ---------------------------------------------------------------------------

/// Request body of `POST /api/auth/device` (§4.3).
#[derive(Debug, Clone, Serialize)]
pub struct DeviceLoginRequest<'a> {
    /// Account e-mail address.
    pub email: &'a str,
    /// Account password.
    pub password: &'a str,
    /// Human-readable device name, shown on the Devices page.
    pub device_name: &'a str,
    /// Stable per-device hash; a repeat login replaces the old token.
    pub device_fingerprint: &'a str,
    /// `windows`, `macos` or `linux`.
    pub platform: &'a str,
    /// Desktop client version.
    pub app_version: &'a str,
}

/// 200 response of `POST /api/auth/device` (§4.3).
#[derive(Debug, Clone, Deserialize)]
pub struct DeviceLoginResponse {
    /// The plaintext bearer token; stored in the OS keychain and never on disk.
    pub token: String,
    /// Id of the personal access token backing this session.
    pub token_id: i64,
    /// The `/api/me` user document.
    pub user: serde_json::Value,
    #[serde(default)]
    /// Whether the user must change their password before continuing.
    pub must_change_password: bool,
    #[serde(default)]
    /// Token abilities; the desktop token carries `desktop`.
    pub abilities: Vec<String>,
}

/// Error body shared by the auth endpoints: `{"code": "...", "retry_after": 120}`.
#[derive(Debug, Clone, Deserialize)]
pub struct ApiErrorBody {
    #[serde(default)]
    /// Server error code, e.g. `FIELD_CONFLICT` or `ONLINE_ONLY`.
    pub code: Option<String>,
    #[serde(default)]
    /// Human-readable server message, when present.
    pub message: Option<String>,
    #[serde(default)]
    /// Seconds until the lockout expires.
    pub retry_after: Option<u64>,
}

// ---------------------------------------------------------------------------
// GET /api/sync/manifest
// ---------------------------------------------------------------------------

/// Per-table entry of the manifest `tables` map.
#[derive(Debug, Clone, Deserialize)]
pub struct ManifestTable {
    /// `rw` or `ro`.
    pub mode: String,
}

/// `GET /api/sync/manifest` (§4.1).
///
/// Tables the user has no `.view` permission for are absent from `tables` entirely — the
/// key is not present, not merely empty.
#[derive(Debug, Clone, Deserialize)]
pub struct Manifest {
    /// Wire protocol the server implements.
    pub protocol_version: u32,
    #[serde(default)]
    /// Server clock at the time of the response.
    pub server_time: Option<String>,
    #[serde(default)]
    /// Per-table payload, keyed by server table name.
    pub tables: BTreeMap<String, ManifestTable>,
    #[serde(default)]
    /// The caller's effective permissions.
    pub permissions: Vec<String>,
    #[serde(default)]
    /// The `/api/me` user document.
    pub user: serde_json::Value,
    #[serde(default)]
    /// Server-declared limits.
    pub policy: crate::config::ServerPolicy,
}

// ---------------------------------------------------------------------------
// POST /api/sync/pull
// ---------------------------------------------------------------------------

/// Request body of `POST /api/sync/pull` (§4.2).
///
/// `cursors` holds one scalar `sync_version` per table — protocol §2.5 K-C rules out a
/// composite `(sync_version, id)` cursor. `window_days` only applies while a cursor is 0.
#[derive(Debug, Clone, Serialize)]
pub struct PullRequest {
    /// One scalar `sync_version` per table (protocol 2.5 K-C).
    pub cursors: BTreeMap<String, i64>,
    /// Page size; `None` uses the default.
    pub limit: u32,
    /// Retention window; only applied while a cursor is 0.
    pub window_days: u32,
}

/// One `sync_deletions` tombstone (§2.7).
#[derive(Debug, Clone, Deserialize)]
pub struct Deletion {
    /// Primary key for `tags` and `notifications`; `conversation_id:user_id` for
    /// `conversation_user`.
    pub row_key: String,
    #[serde(default)]
    /// Version the change was assigned.
    pub sync_version: i64,
}

/// Per-table payload of a pull response.
#[derive(Debug, Clone, Default, Deserialize)]
pub struct PullTable {
    #[serde(default)]
    /// Full rows at or beyond the cursor.
    pub rows: Vec<serde_json::Value>,
    #[serde(default)]
    /// Tombstones applied.
    pub deletions: Vec<Deletion>,
    #[serde(default)]
    /// Cursor to send on the next pull.
    pub next_cursor: Option<i64>,
    #[serde(default)]
    /// Whether more rows remain behind the cursor.
    pub has_more: bool,
}

/// 200 response of `POST /api/sync/pull` (§4.2).
#[derive(Debug, Clone, Deserialize)]
pub struct PullResponse {
    #[serde(default)]
    /// Server clock at the time of the response.
    pub server_time: Option<String>,
    #[serde(default)]
    /// Per-table payload, keyed by server table name.
    pub tables: BTreeMap<String, PullTable>,
}

// ---------------------------------------------------------------------------
// POST /api/sync/push
// ---------------------------------------------------------------------------

/// One outgoing mutation.
///
/// Field presence is op-dependent and matches §4.4 exactly:
///
/// * `create` — `client_id`, `payload`
/// * `update` — `server_id`, `base_sync_version`, `changed_fields`, `payload`
/// * `action` — `server_id`, `action`, `payload`
/// * `delete` — `server_id`, `base_sync_version`
/// * `notification.read_all` — `action`, `scope`, and **no** row identity at all
///   (protocol §4.3 P10)
///
/// When a row has not been pushed yet its `server_id` is unknown; the mutation then
/// carries `client_id` instead, which the server can resolve through the unique
/// `client_id` column.
#[derive(Debug, Clone, Serialize)]
pub struct WireMutation {
    /// Monotonic position in the outbox; also the key of the push result.
    pub seq: i64,
    /// Makes a resend safe after a partial or lost response.
    pub idempotency_key: String,
    /// Push operation kind.
    pub op: String,
    /// Table the row belongs to.
    pub entity: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    /// Stable local identity of the row.
    pub client_id: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    /// Server primary key, once the row has been accepted.
    pub server_id: Option<i64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    /// Action name, for `op = action`.
    pub action: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    /// `user` for the user-scoped `notification.read_all` action.
    pub scope: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    /// Server version the local edit was based on.
    pub base_sync_version: Option<i64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    /// Fields the mutation intends to write; nothing outside them is applied.
    pub changed_fields: Option<Vec<String>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    /// When the edit happened on this device (RFC 3339, UTC).
    pub occurred_at: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    /// Field values carried by the mutation.
    pub payload: Option<serde_json::Value>,
}

/// Request body of `POST /api/sync/push` (§4.3).
#[derive(Debug, Clone, Serialize)]
pub struct PushRequest {
    /// Identifier of this push batch.
    pub batch_id: String,
    /// Mutations in push order.
    pub mutations: Vec<WireMutation>,
}

/// Status of one mutation in the push response.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum PushStatus {
    /// The server applied the mutation.
    Applied,
    /// The idempotency key had already been seen.
    Duplicate,
    /// The server refused the local edit; see the Conflict Inbox.
    Conflict,
    /// The server refused the mutation outright.
    Rejected,
}

/// One entry of the push response `results` array.
#[derive(Debug, Clone, Deserialize)]
pub struct PushResult {
    /// Monotonic position in the outbox; also the key of the push result.
    pub seq: i64,
    /// Status filter.
    pub status: PushStatus,
    #[serde(default)]
    /// Server primary key, once the row has been accepted.
    pub server_id: Option<i64>,
    #[serde(default)]
    /// Version the change was assigned.
    pub sync_version: Option<i64>,
    #[serde(default)]
    /// Server error code, e.g. `FIELD_CONFLICT` or `ONLINE_ONLY`.
    pub code: Option<String>,
    #[serde(default)]
    /// Fields both sides changed.
    pub conflicting_fields: Vec<String>,
    #[serde(default)]
    /// The server's version of the row, when it sent one.
    pub server_row: Option<serde_json::Value>,
    /// Rows touched by `notification.read_all` (protocol §4.3 P10).
    #[serde(default)]
    pub affected: Option<i64>,
}

/// 200 response of `POST /api/sync/push` (§4.3).
///
/// The array may be **shorter** than the request: protocol §4.3 P10b says every mutation
/// whose `seq` is absent from `results` was not processed, stays `queued`, and is resent
/// on the next round. See [`crate::sync::push`].
#[derive(Debug, Clone, Deserialize)]
pub struct PushResponse {
    #[serde(default)]
    /// Identifier of this push batch.
    pub batch_id: Option<String>,
    #[serde(default)]
    /// One entry per processed mutation; may be shorter than the request.
    pub results: Vec<PushResult>,
    #[serde(default)]
    /// Server clock at the time of the response.
    pub server_time: Option<String>,
}

// ---------------------------------------------------------------------------
// Error codes (§4.4)
// ---------------------------------------------------------------------------

/// Codes introduced by the sync API.
pub mod codes {
    /// The action is only available online.
    pub const ONLINE_ONLY: &str = "ONLINE_ONLY";
    /// A `*_client_id` in the payload could not be resolved.
    pub const UNRESOLVED_REFERENCE: &str = "UNRESOLVED_REFERENCE";
    /// Another writer changed one of the same fields.
    pub const FIELD_CONFLICT: &str = "FIELD_CONFLICT";
    /// The target row no longer exists.
    pub const RECORD_DELETED: &str = "RECORD_DELETED";
    /// Client and server protocol versions differ.
    pub const PROTOCOL_VERSION_MISMATCH: &str = "PROTOCOL_VERSION_MISMATCH";
    /// The batch exceeded the mutation or byte ceiling.
    pub const PUSH_BATCH_TOO_LARGE: &str = "PUSH_BATCH_TOO_LARGE";
    /// The mutation did not satisfy the wire contract.
    pub const INVALID_MUTATION: &str = "INVALID_MUTATION";
    /// The token lacks the `desktop` ability.
    pub const ABILITY_REQUIRED: &str = "ABILITY_REQUIRED";

    /// Pre-existing codes the sync path can also surface unchanged.
    pub const DEAL_VERSION_CONFLICT: &str = "DEAL_VERSION_CONFLICT";
    /// The quote is no longer editable.
    pub const QUOTE_LOCKED: &str = "QUOTE_LOCKED";
    /// The state machine forbids this transition.
    pub const INVALID_STATUS_TRANSITION: &str = "INVALID_STATUS_TRANSITION";
    /// The role cannot be edited.
    pub const ROLE_NOT_EDITABLE: &str = "ROLE_NOT_EDITABLE";

    /// Every code the sync API may return, new and pre-existing.
    pub const ALL: &[&str] = &[
        ONLINE_ONLY,
        UNRESOLVED_REFERENCE,
        FIELD_CONFLICT,
        RECORD_DELETED,
        PROTOCOL_VERSION_MISMATCH,
        PUSH_BATCH_TOO_LARGE,
        INVALID_MUTATION,
        ABILITY_REQUIRED,
        DEAL_VERSION_CONFLICT,
        QUOTE_LOCKED,
        INVALID_STATUS_TRANSITION,
        ROLE_NOT_EDITABLE,
    ];
}

/// Actions the server accepts for `op = action` (`SYNCDESKTOP.md` §4.4).
///
/// `lead.convert`, `quote.send` and `quote.revise` are deliberately absent — they answer
/// `rejected` with `ONLINE_ONLY`.
pub const ACTION_WHITELIST: &[(&str, &str)] = &[
    ("deal", "move"),
    ("deal", "assign"),
    ("task", "complete"),
    ("task", "assign"),
    ("ticket", "status"),
    ("ticket", "assign"),
    ("lead", "assign"),
    ("quote", "status"),
    ("conversation", "read"),
    ("conversation", "delivered"),
    ("notification", "read"),
    ("notification", "read_all"),
];

/// Whether `entity.action` is one the server will accept offline.
pub fn is_whitelisted_action(entity: &str, action: &str) -> bool {
    ACTION_WHITELIST
        .iter()
        .any(|(e, a)| *e == entity && *a == action)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn read_all_serialises_without_a_row_identity() {
        let m = WireMutation {
            seq: 7,
            idempotency_key: "k".into(),
            op: "action".into(),
            entity: "notification".into(),
            client_id: None,
            server_id: None,
            action: Some("read_all".into()),
            scope: Some("user".into()),
            base_sync_version: None,
            changed_fields: None,
            occurred_at: Some("2026-08-26T09:12:11.482Z".into()),
            payload: None,
        };
        let json = serde_json::to_value(&m).unwrap();
        let obj = json.as_object().unwrap();
        assert!(!obj.contains_key("server_id"));
        assert!(!obj.contains_key("client_id"));
        assert_eq!(obj["scope"], "user");
        assert_eq!(obj["action"], "read_all");
    }

    #[test]
    fn online_only_actions_are_not_whitelisted() {
        assert!(!is_whitelisted_action("lead", "convert"));
        assert!(!is_whitelisted_action("quote", "send"));
        assert!(!is_whitelisted_action("quote", "revise"));
        assert!(is_whitelisted_action("deal", "move"));
        assert!(is_whitelisted_action("notification", "read_all"));
    }

    #[test]
    fn push_response_tolerates_a_short_results_array() {
        let body = serde_json::json!({
            "batch_id": "b",
            "results": [{ "seq": 1, "status": "applied", "server_id": 5012, "sync_version": 185002 }]
        });
        let parsed: PushResponse = serde_json::from_value(body).unwrap();
        assert_eq!(parsed.results.len(), 1);
        assert_eq!(parsed.results[0].status, PushStatus::Applied);
    }
}
