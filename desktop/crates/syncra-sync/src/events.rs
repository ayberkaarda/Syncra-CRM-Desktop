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
    /// resumes, a login by a different user wipes the database (§5.5). Also raised after
    /// `403 USER_DEACTIVATED`, which wipes the mirror as well.
    ///
    /// The event reaches the webview asynchronously, so by the time it is handled the user
    /// may already have signed in again. `token_id` names the session that was lost, and the
    /// webview ignores the event when that is not the session it is showing.
    AuthLost {
        /// [`crate::Session::token_id`] of the session whose token was rejected, or `None`
        /// when the engine held a token without a session document. Absent on the wire means
        /// `None`, so the payload-less `{"type":"auth_lost"}` of earlier engines still parses.
        #[serde(default)]
        token_id: Option<i64>,
    },
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
            EngineEvent::AuthLost { token_id: Some(70) },
            EngineEvent::AuthLost { token_id: None },
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

    /// The shape `desktop/src/bridge/events.ts` reads for `auth_lost`.
    #[test]
    fn auth_lost_names_the_lost_session_on_the_wire() {
        let json = serde_json::to_string(&EngineEvent::AuthLost { token_id: Some(70) }).unwrap();
        assert_eq!(json, r#"{"type":"auth_lost","token_id":70}"#);
        let json = serde_json::to_string(&EngineEvent::AuthLost { token_id: None }).unwrap();
        assert_eq!(json, r#"{"type":"auth_lost","token_id":null}"#);
    }

    /// The payload-less shape earlier engines emitted still parses.
    #[test]
    fn a_payload_less_auth_lost_still_deserializes() {
        let back: EngineEvent = serde_json::from_str(r#"{"type":"auth_lost"}"#).unwrap();
        assert_eq!(back, EngineEvent::AuthLost { token_id: None });
    }
}
