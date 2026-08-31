//! Value types shared by the whole crate: entities, rows, mutations, status.

use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use std::fmt;
use uuid::Uuid;

/// A table that participates in synchronisation.
///
/// `taggables`, `quote_items` and `custom_field_values` are deliberately absent: protocol
/// §1.4, §1.5 and §6.2 P13 embed them into the owning row instead of mirroring them.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Entity {
    // read/write
    /// `companies` -- customer accounts.
    Company,
    /// `contacts` -- people at a company.
    Contact,
    /// `leads` -- unqualified prospects.
    Lead,
    /// `deals` -- Kanban cards / sales opportunities.
    Deal,
    /// `tasks` -- to-dos attached to any record.
    Task,
    /// `activities` -- logged calls, meetings, notes.
    Activity,
    /// `tickets` -- support requests with SLA tracking.
    Ticket,
    /// `quotes` -- quotations; line items travel in `items`.
    Quote,
    /// `conversations` -- chat threads.
    Conversation,
    /// `messages` -- chat messages.
    Message,
    /// `conversation_user` -- chat membership and read state.
    ConversationUser,
    /// `notifications` -- Laravel database notifications.
    Notification,
    /// `tags` -- the tag vocabulary.
    Tag,
    // read-only
    /// `pipeline_stages` -- Kanban columns (read-only).
    PipelineStage,
    /// `custom_fields` -- custom field definitions (read-only).
    CustomField,
    /// `products` -- product catalogue (read-only).
    Product,
    /// `price_lists` -- channel price lists (read-only).
    PriceList,
    /// `price_list_items` -- per-product overrides (read-only).
    PriceListItem,
    /// `exchange_rates` -- recent rates (read-only).
    ExchangeRate,
    /// `saved_views` -- saved filters (read-only).
    SavedView,
    /// `settings` -- public settings (read-only).
    Setting,
    /// `users` -- fixed projection, read-only.
    User,
}

/// Whether the server lets the client push changes for a table.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum TableMode {
    /// Read-write.
    Rw,
    /// Read-only.
    Ro,
}

impl Entity {
    /// Every entity the engine knows about, in a stable order.
    pub const ALL: &'static [Entity] = &[
        Entity::Company,
        Entity::Contact,
        Entity::Lead,
        Entity::Deal,
        Entity::Task,
        Entity::Activity,
        Entity::Ticket,
        Entity::Quote,
        Entity::Conversation,
        Entity::Message,
        Entity::ConversationUser,
        Entity::Notification,
        Entity::Tag,
        Entity::PipelineStage,
        Entity::CustomField,
        Entity::Product,
        Entity::PriceList,
        Entity::PriceListItem,
        Entity::ExchangeRate,
        Entity::SavedView,
        Entity::Setting,
        Entity::User,
    ];

    /// Wire name of the entity, as used in push mutations (`"entity": "deal"`).
    pub fn wire_name(self) -> &'static str {
        match self {
            Entity::Company => "company",
            Entity::Contact => "contact",
            Entity::Lead => "lead",
            Entity::Deal => "deal",
            Entity::Task => "task",
            Entity::Activity => "activity",
            Entity::Ticket => "ticket",
            Entity::Quote => "quote",
            Entity::Conversation => "conversation",
            Entity::Message => "message",
            Entity::ConversationUser => "conversation_user",
            Entity::Notification => "notification",
            Entity::Tag => "tag",
            Entity::PipelineStage => "pipeline_stage",
            Entity::CustomField => "custom_field",
            Entity::Product => "product",
            Entity::PriceList => "price_list",
            Entity::PriceListItem => "price_list_item",
            Entity::ExchangeRate => "exchange_rate",
            Entity::SavedView => "saved_view",
            Entity::Setting => "setting",
            Entity::User => "user",
        }
    }

    /// Server (and local mirror) table name, as used in pull cursors.
    pub fn table(self) -> &'static str {
        match self {
            Entity::Company => "companies",
            Entity::Contact => "contacts",
            Entity::Lead => "leads",
            Entity::Deal => "deals",
            Entity::Task => "tasks",
            Entity::Activity => "activities",
            Entity::Ticket => "tickets",
            Entity::Quote => "quotes",
            Entity::Conversation => "conversations",
            Entity::Message => "messages",
            Entity::ConversationUser => "conversation_user",
            Entity::Notification => "notifications",
            Entity::Tag => "tags",
            Entity::PipelineStage => "pipeline_stages",
            Entity::CustomField => "custom_fields",
            Entity::Product => "products",
            Entity::PriceList => "price_lists",
            Entity::PriceListItem => "price_list_items",
            Entity::ExchangeRate => "exchange_rates",
            Entity::SavedView => "saved_views",
            Entity::Setting => "settings",
            Entity::User => "users",
        }
    }

    /// Resolve a server table name back to an entity.
    pub fn from_table(table: &str) -> Option<Entity> {
        Entity::ALL.iter().copied().find(|e| e.table() == table)
    }

    /// Resolve a wire entity name back to an entity.
    pub fn from_wire_name(name: &str) -> Option<Entity> {
        Entity::ALL.iter().copied().find(|e| e.wire_name() == name)
    }

    /// Default mode; the manifest may narrow the set of tables but never widens a mode.
    pub fn default_mode(self) -> TableMode {
        match self {
            Entity::PipelineStage
            | Entity::CustomField
            | Entity::Product
            | Entity::PriceList
            | Entity::PriceListItem
            | Entity::ExchangeRate
            | Entity::SavedView
            | Entity::Setting
            | Entity::User => TableMode::Ro,
            _ => TableMode::Rw,
        }
    }

    /// Topological push level (`SYNCDESKTOP.md` §5.4).
    ///
    /// The `quote_item(4)` level of the specification is gone: protocol §6.2 P13 carries
    /// quote items inside the `quote` mutation payload. Actions sort at level 5 regardless
    /// of entity, so they always follow the create of their own row.
    pub fn topo_level(self) -> u8 {
        match self {
            Entity::Company | Entity::Tag => 0,
            Entity::Contact | Entity::Lead => 1,
            Entity::Deal | Entity::Conversation => 2,
            Entity::Quote
            | Entity::Task
            | Entity::Activity
            | Entity::Ticket
            | Entity::Message
            | Entity::Notification => 3,
            Entity::ConversationUser => 4,
            // Read-only entities are never pushed; give them the tail of the order.
            _ => 4,
        }
    }
}

impl fmt::Display for Entity {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.wire_name())
    }
}

/// Push operation kind.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Op {
    /// Insert a new row.
    Create,
    /// Write the fields named in `changed_fields`.
    Update,
    /// Invoke a whitelisted domain action.
    Action,
    /// Delete the row.
    Delete,
}

impl Op {
    /// Wire value of the `op` field.
    pub fn wire_name(self) -> &'static str {
        match self {
            Op::Create => "create",
            Op::Update => "update",
            Op::Action => "action",
            Op::Delete => "delete",
        }
    }

    /// Parse a wire `op` value.
    pub fn from_wire_name(s: &str) -> Option<Op> {
        match s {
            "create" => Some(Op::Create),
            "update" => Some(Op::Update),
            "action" => Some(Op::Action),
            "delete" => Some(Op::Delete),
            _ => None,
        }
    }

    /// Ordering rank inside one entity: create < update < action < delete (§5.4).
    pub fn rank(self) -> u8 {
        match self {
            Op::Create => 0,
            Op::Update => 1,
            Op::Action => 2,
            Op::Delete => 3,
        }
    }
}

/// Local row lifecycle marker stored on every mirror table.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum SyncState {
    /// Matches the server.
    Synced,
    /// Has unpushed local edits.
    Pending,
    /// The server refused the local edit; see the Conflict Inbox.
    Conflict,
    /// Deleted; kept until retention sweeps it.
    Tombstone,
}

impl SyncState {
    /// As str.
    pub fn as_str(self) -> &'static str {
        match self {
            SyncState::Synced => "synced",
            SyncState::Pending => "pending",
            SyncState::Conflict => "conflict",
            SyncState::Tombstone => "tombstone",
        }
    }
}

/// One local row, as a JSON object keyed by column name.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(transparent)]
pub struct Row(pub serde_json::Map<String, serde_json::Value>);

impl Row {
    /// Borrow a column value.
    pub fn get(&self, key: &str) -> Option<&serde_json::Value> {
        self.0.get(key)
    }

    /// Read a column as a string.
    pub fn get_str(&self, key: &str) -> Option<&str> {
        self.0.get(key).and_then(|v| v.as_str())
    }

    /// Read a column as an integer.
    pub fn get_i64(&self, key: &str) -> Option<i64> {
        self.0.get(key).and_then(|v| v.as_i64())
    }

    /// The row's stable local identity.
    pub fn client_id(&self) -> Option<&str> {
        self.get_str("client_id")
    }
}

impl std::ops::Deref for Row {
    type Target = serde_json::Map<String, serde_json::Value>;
    fn deref(&self) -> &Self::Target {
        &self.0
    }
}

/// A mutation produced by the UI, queued into the outbox and applied to the local mirror.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct LocalMutation {
    /// Table the row belongs to.
    pub entity: Entity,
    /// Push operation kind.
    pub op: Op,
    /// Action name for `op = action`, e.g. `"move"`, `"complete"`, `"read_all"`.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub action: Option<String>,
    /// Row identity. `None` is only legal for `notification.read_all` (protocol §4.3 P10).
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub client_id: Option<Uuid>,
    /// Fields the caller intends to change; required for `op = update`.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub changed_fields: Option<Vec<String>>,
    /// Field values. Interpreted per op by the local applier and by the server.
    #[serde(default)]
    pub payload: serde_json::Value,
}

impl LocalMutation {
    /// Shorthand for a create.
    pub fn create(entity: Entity, client_id: Uuid, payload: serde_json::Value) -> Self {
        LocalMutation {
            entity,
            op: Op::Create,
            action: None,
            client_id: Some(client_id),
            changed_fields: None,
            payload,
        }
    }

    /// Shorthand for an update.
    pub fn update(
        entity: Entity,
        client_id: Uuid,
        fields: &[&str],
        payload: serde_json::Value,
    ) -> Self {
        LocalMutation {
            entity,
            op: Op::Update,
            action: None,
            client_id: Some(client_id),
            changed_fields: Some(fields.iter().map(|s| s.to_string()).collect()),
            payload,
        }
    }

    /// Shorthand for a delete.
    pub fn delete(entity: Entity, client_id: Uuid) -> Self {
        LocalMutation {
            entity,
            op: Op::Delete,
            action: None,
            client_id: Some(client_id),
            changed_fields: None,
            payload: serde_json::Value::Null,
        }
    }

    /// Shorthand for a whitelisted action.
    pub fn action(
        entity: Entity,
        client_id: Uuid,
        action: &str,
        payload: serde_json::Value,
    ) -> Self {
        LocalMutation {
            entity,
            op: Op::Action,
            action: Some(action.to_string()),
            client_id: Some(client_id),
            changed_fields: None,
            payload,
        }
    }

    /// `notification.read_all` — the only mutation that carries no row identity
    /// (protocol §4.3 P10).
    pub fn notification_read_all() -> Self {
        LocalMutation {
            entity: Entity::Notification,
            op: Op::Action,
            action: Some("read_all".to_string()),
            client_id: None,
            changed_fields: None,
            payload: serde_json::Value::Null,
        }
    }
}

/// Why local writes are refused (`SYNCDESKTOP.md` §5.6).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum WriteBlockReason {
    /// The local database reached `max_db_size_mb`.
    DiskFull,
    /// The outbox reached `max_outbox`.
    OutboxFull,
}

/// Snapshot of engine state, cheap to poll from the UI.
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
pub struct SyncStatus {
    /// Whether the OS reports connectivity.
    pub online: bool,
    /// Whether a sync round is in flight.
    pub syncing: bool,
    /// Mutations still waiting to reach the server.
    pub pending: u32,
    /// Open Conflict Inbox entries.
    pub conflicts: u32,
    /// When the last round completed successfully.
    pub last_sync_at: Option<DateTime<Utc>>,
    /// Set once a retention ceiling refuses local writes.
    pub write_blocked: Option<WriteBlockReason>,
}

/// Local storage accounting, from `page_count * page_size`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct StorageStats {
    /// Database size, from `page_count * page_size`.
    pub db_bytes: u64,
    /// Configured ceiling in bytes.
    pub max_db_bytes: u64,
    /// Bytes held by `cached_files`.
    pub cached_file_bytes: u64,
    /// Total outbox rows, including failed ones.
    pub outbox_count: u32,
    /// Configured outbox ceiling.
    pub max_outbox: u32,
    /// Percentage of the database ceiling in use, 0-100+.
    pub db_usage_percent: u32,
}

/// Device identity sent to `POST /api/auth/device`.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DeviceInfo {
    /// Human-readable device name, shown on the Devices page.
    pub device_name: String,
    /// Stable per-device hash; a repeat login replaces the old token.
    pub device_fingerprint: String,
    /// `windows`, `macos` or `linux`.
    pub platform: String,
    /// Desktop client version.
    pub app_version: String,
}

/// An authenticated desktop session.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Session {
    /// Id of the personal access token backing this session.
    pub token_id: i64,
    /// Signed-in user.
    pub user_id: i64,
    /// The `/api/me` user document.
    pub user: serde_json::Value,
    /// Whether the user must change their password before continuing.
    pub must_change_password: bool,
    /// Token abilities; the desktop token carries `desktop`.
    pub abilities: Vec<String>,
}

/// Result of [`crate::SyncEngine::logout`].
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum LogoutOutcome {
    /// Session dropped and the local database wiped.
    Wiped,
    /// Refused: unpushed mutations exist and `force` was not set.
    PendingMutations(u32),
}

/// Progress callback payload for [`crate::SyncEngine::bootstrap`].
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct BootstrapProgress {
    /// Table the row belongs to.
    pub entity: Entity,
    /// Rows written so far.
    pub rows_loaded: u64,
    /// Tables fully drained so far.
    pub tables_done: u32,
    /// Tables in this bootstrap.
    pub tables_total: u32,
}

/// Summary of one [`crate::SyncEngine::sync_now`] round.
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
pub struct SyncReport {
    /// Mutations sent.
    pub pushed: u32,
    /// Mutations the server accepted.
    pub applied: u32,
    /// Mutations the server had already applied.
    pub duplicates: u32,
    /// Open Conflict Inbox entries.
    pub conflicts: u32,
    /// Mutations the server refused outright.
    pub rejected: u32,
    /// Mutations the server left out of `results`; requeued untouched (protocol §4.3 P10b).
    pub deferred: u32,
    /// Rows written by the pull half.
    pub pulled_rows: u64,
    /// Tombstones applied.
    pub deletions: u64,
    /// Tables whose contents moved.
    pub tables_changed: Vec<Entity>,
}

/// One full-text search hit.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct SearchHit {
    /// Table the row belongs to.
    pub entity: Entity,
    /// Stable local identity of the row.
    pub client_id: String,
    /// Indexed title text.
    pub title: String,
    /// Highlighted excerpt around the match.
    pub snippet: String,
}

/// A push result the client could not apply silently (`SYNCDESKTOP.md` §5.2).
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Conflict {
    /// Identifier of this record.
    pub id: Uuid,
    /// The outbox entry that produced this conflict, if it still exists.
    pub outbox_id: Option<Uuid>,
    /// Table the row belongs to.
    pub entity: Entity,
    /// Stable local identity of the row.
    pub client_id: Option<Uuid>,
    /// Server error code, e.g. `FIELD_CONFLICT`, `ONLINE_ONLY`, `UNRESOLVED_REFERENCE`.
    pub code: String,
    /// Fields both sides changed.
    pub conflicting_fields: Vec<String>,
    /// The local payload that was rejected.
    pub mine: serde_json::Value,
    /// The server row at the time of rejection, when the server sent one.
    pub theirs: serde_json::Value,
    /// Creation timestamp.
    pub created_at: DateTime<Utc>,
}

/// How the user chose to settle a [`Conflict`].
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case", tag = "kind", content = "fields")]
pub enum Resolution {
    /// Re-send the local change against the current `base_sync_version`.
    KeepMine,
    /// Drop the local change and adopt the server row.
    TakeServer,
    /// Keep the listed fields from the local change, adopt the server row for the rest.
    Merge(Vec<String>),
}

/// Realtime hint from Reverb; triggers a targeted pull.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct RealtimeEvent {
    /// Tables the event says have changed.
    pub entities: Vec<Entity>,
}
