//! Engine event stream.

use crate::types::{Entity, StorageStats, SyncStatus};
use serde::{Deserialize, Serialize};
use uuid::Uuid;

/// Capacity of the broadcast channel. Slow subscribers lag rather than block the engine.
pub(crate) const EVENT_CHANNEL_CAPACITY: usize = 256;

/// Everything the engine tells the shell about, on `subscribe()`.
///
/// `TablesChanged` is what the desktop shell turns into
/// `queryClient.invalidateQueries({ queryKey: … })` through its hand-written entity -> key
/// table (KARAR D-5; `desktop/src/bridge/events.ts`).
///
/// **Every variant carries named fields on purpose.** The tag is internal (`{"type": …}`) so
/// that the Tauri layer can forward one JSON payload straight to the webview, and serde
/// *cannot* serialise an internally tagged newtype variant whose content is a sequence or a
/// bare string — `TablesChanged(Vec<Entity>)` and `ConflictAdded(Uuid)` would both fail at
/// runtime, at the moment of emission, with nothing to catch them at compile time.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case", tag = "type")]
pub enum EngineEvent {
    /// One or more mirror tables changed on disk.
    TablesChanged {
        /// Tables whose contents moved.
        entities: Vec<Entity>,
    },
    /// Online/syncing/pending/conflict counters moved.
    StatusChanged {
        /// The new snapshot.
        status: SyncStatus,
    },
    /// A push result landed in the Conflict Inbox.
    ConflictAdded {
        /// Identifier of the new Conflict Inbox record.
        id: Uuid,
    },
    /// The local database crossed 80% of its ceiling (`SYNCDESKTOP.md` §5.6).
    StorageWarning {
        /// Storage accounting at the moment the ceiling was crossed.
        stats: StorageStats,
    },
    /// The server rejected the token. The outbox is preserved; a login by the same user
    /// resumes, a login by a different user wipes the database (§5.5).
    AuthLost,
    /// The server speaks a different protocol version; the engine stopped.
    ProtocolMismatch {
        /// Protocol version the server reported.
        server: u32,
    },
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Guards the reason every variant is a struct variant: an internally tagged newtype
    /// variant holding a sequence (or a bare string) is a *runtime* serde failure, and the
    /// only place it would surface is the moment the shell tries to forward the event.
    #[test]
    fn every_variant_round_trips_through_json() {
        let cases = vec![
            EngineEvent::TablesChanged {
                entities: vec![Entity::Deal, Entity::Company],
            },
            EngineEvent::StatusChanged {
                status: SyncStatus::default(),
            },
            EngineEvent::ConflictAdded { id: Uuid::nil() },
            EngineEvent::StorageWarning {
                stats: StorageStats {
                    db_bytes: 1,
                    max_db_bytes: 2,
                    cached_file_bytes: 0,
                    outbox_count: 0,
                    max_outbox: 10,
                    db_usage_percent: 50,
                },
            },
            EngineEvent::AuthLost,
            EngineEvent::ProtocolMismatch { server: 2 },
        ];
        for event in cases {
            let json = serde_json::to_string(&event).expect("serialize");
            assert!(json.contains("\"type\""), "{json}");
            let back: EngineEvent = serde_json::from_str(&json).expect("deserialize");
            assert_eq!(back, event);
        }
    }

    #[test]
    fn tables_changed_names_entities_by_wire_name() {
        let json = serde_json::to_string(&EngineEvent::TablesChanged {
            entities: vec![Entity::PriceListItem],
        })
        .unwrap();
        assert_eq!(
            json,
            r#"{"type":"tables_changed","entities":["price_list_item"]}"#
        );
    }
}
