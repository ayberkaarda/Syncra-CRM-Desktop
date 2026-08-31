//! Engine event stream.

use crate::types::{Entity, StorageStats, SyncStatus};
use serde::{Deserialize, Serialize};
use uuid::Uuid;

/// Capacity of the broadcast channel. Slow subscribers lag rather than block the engine.
pub(crate) const EVENT_CHANNEL_CAPACITY: usize = 256;

/// Everything the engine tells the shell about, on `subscribe()`.
///
/// `TablesChanged` is what the desktop shell turns into
/// `queryClient.invalidateQueries({ queryKey: [entity] })`.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case", tag = "type")]
pub enum EngineEvent {
    /// One or more mirror tables changed on disk.
    TablesChanged(Vec<Entity>),
    /// Online/syncing/pending/conflict counters moved.
    StatusChanged(SyncStatus),
    /// A push result landed in the Conflict Inbox.
    ConflictAdded(Uuid),
    /// The local database crossed 80% of its ceiling (`SYNCDESKTOP.md` §5.6).
    StorageWarning(StorageStats),
    /// The server rejected the token. The outbox is preserved; a login by the same user
    /// resumes, a login by a different user wipes the database (§5.5).
    AuthLost,
    /// The server speaks a different protocol version; the engine stopped.
    ProtocolMismatch {
        /// Protocol version the server reported.
        server: u32,
    },
}
