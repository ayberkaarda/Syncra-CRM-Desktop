//! Static description of the local mirror tables.
//!
//! The DDL in `migrations/0001_init.sql` is the single source of truth for *which columns
//! exist*; this module only records the things SQL cannot express: which columns are
//! foreign keys (and to what), which columns carry embedded JSON, and which tables use the
//! notification-shaped identity from protocol §6.1 P12.
//!
//! `tests/schema_registry.rs` asserts the two stay in agreement.

use crate::types::Entity;

/// A foreign key, carried twice on every mirror table: the raw server id and the resolved
/// local `client_id`.
#[derive(Debug, Clone, Copy)]
pub struct Fk {
    /// Column holding the server-side id, e.g. `company_id`.
    pub server_col: &'static str,
    /// Column holding the local reference, e.g. `company_client_id`.
    pub client_col: &'static str,
    /// Table the key points at.
    pub target: Entity,
}

/// Everything the pull/upsert path needs to know about one mirror table.
#[derive(Debug, Clone, Copy)]
pub struct TableSpec {
    /// Table the row belongs to.
    pub entity: Entity,
    /// `false` only for `notifications`, whose `client_id` *is* the server id
    /// (protocol §6.1 P12).
    pub has_server_id: bool,
    /// Foreign keys carried by the table.
    pub fks: &'static [Fk],
    /// Columns that store a JSON document rather than a scalar: `tags`, `custom_fields`
    /// and, on quotes, `items` (protocol §1.5 / §6.2 P13).
    pub embedded: &'static [&'static str],
    /// Whether the server soft-deletes this table, i.e. whether tombstones arrive as rows
    /// with a non-null `deleted_at` rather than through `sync_deletions`.
    pub soft_delete: bool,
    /// Server column names that are spelled differently locally.
    ///
    /// Only `settings.group` needs this: `group` is a reserved word, and the SQL this crate
    /// generates does not quote identifiers. Without the alias the column would be silently
    /// dropped on every pull.
    pub aliases: &'static [(&'static str, &'static str)],
}

macro_rules! fk {
    ($server:literal => $target:expr, $client:literal) => {
        Fk {
            server_col: $server,
            client_col: $client,
            target: $target,
        }
    };
}

const COMPANY_FKS: &[Fk] = &[fk!("owner_id" => Entity::User, "owner_client_id")];

const CONTACT_FKS: &[Fk] = &[
    fk!("company_id" => Entity::Company, "company_client_id"),
    fk!("owner_id" => Entity::User, "owner_client_id"),
];

const LEAD_FKS: &[Fk] = &[
    fk!("owner_id" => Entity::User, "owner_client_id"),
    fk!("converted_contact_id" => Entity::Contact, "converted_contact_client_id"),
    fk!("converted_company_id" => Entity::Company, "converted_company_client_id"),
    fk!("converted_deal_id" => Entity::Deal, "converted_deal_client_id"),
];

const DEAL_FKS: &[Fk] = &[
    fk!("pipeline_stage_id" => Entity::PipelineStage, "pipeline_stage_client_id"),
    fk!("company_id" => Entity::Company, "company_client_id"),
    fk!("contact_id" => Entity::Contact, "contact_client_id"),
    fk!("owner_id" => Entity::User, "owner_client_id"),
];

const TASK_FKS: &[Fk] = &[
    fk!("assigned_to" => Entity::User, "assigned_to_client_id"),
    fk!("created_by" => Entity::User, "created_by_client_id"),
];

const ACTIVITY_FKS: &[Fk] = &[fk!("user_id" => Entity::User, "user_client_id")];

const TICKET_FKS: &[Fk] = &[
    fk!("contact_id" => Entity::Contact, "contact_client_id"),
    fk!("company_id" => Entity::Company, "company_client_id"),
    fk!("assigned_to" => Entity::User, "assigned_to_client_id"),
    fk!("created_by" => Entity::User, "created_by_client_id"),
];

const QUOTE_FKS: &[Fk] = &[
    fk!("deal_id" => Entity::Deal, "deal_client_id"),
    fk!("parent_quote_id" => Entity::Quote, "parent_quote_client_id"),
    fk!("company_id" => Entity::Company, "company_client_id"),
    fk!("contact_id" => Entity::Contact, "contact_client_id"),
    fk!("created_by" => Entity::User, "created_by_client_id"),
];

const CONVERSATION_FKS: &[Fk] = &[fk!("created_by" => Entity::User, "created_by_client_id")];

const MESSAGE_FKS: &[Fk] = &[
    fk!("conversation_id" => Entity::Conversation, "conversation_client_id"),
    fk!("user_id" => Entity::User, "user_client_id"),
];

const CONVERSATION_USER_FKS: &[Fk] = &[
    fk!("conversation_id" => Entity::Conversation, "conversation_client_id"),
    fk!("user_id" => Entity::User, "user_client_id"),
];

const PRICE_LIST_ITEM_FKS: &[Fk] = &[
    fk!("price_list_id" => Entity::PriceList, "price_list_client_id"),
    fk!("product_id" => Entity::Product, "product_client_id"),
];

const EXCHANGE_RATE_FKS: &[Fk] = &[fk!("entered_by" => Entity::User, "entered_by_client_id")];

const SAVED_VIEW_FKS: &[Fk] = &[fk!("user_id" => Entity::User, "user_client_id")];

const NO_FKS: &[Fk] = &[];

/// `tags` plus `custom_fields`: the two documents protocol §1.4 / §1.5 embed into every
/// taggable, customisable owner row.
const TAGGED: &[&str] = &["tags", "custom_fields"];
/// Quotes additionally carry their line items inline (protocol §1.5).
const QUOTE_EMBEDDED: &[&str] = &["tags", "custom_fields", "items"];
const NO_EMBEDDED: &[&str] = &[];

/// Every mirror table, in the order the pull loop walks them (parents before children).
pub const TABLES: &[TableSpec] = &[
    // Read-only reference data first: the FK resolution of the RW tables depends on it.
    spec(Entity::User, NO_FKS, NO_EMBEDDED, true),
    spec(Entity::PipelineStage, NO_FKS, NO_EMBEDDED, false),
    spec(Entity::CustomField, NO_FKS, NO_EMBEDDED, false),
    spec(Entity::Product, TAGGED_FKS, TAGGED, true),
    spec(Entity::PriceList, NO_FKS, NO_EMBEDDED, true),
    spec(Entity::PriceListItem, PRICE_LIST_ITEM_FKS, NO_EMBEDDED, false),
    spec(Entity::ExchangeRate, EXCHANGE_RATE_FKS, NO_EMBEDDED, false),
    spec(Entity::SavedView, SAVED_VIEW_FKS, NO_EMBEDDED, false),
    TableSpec {
        entity: Entity::Setting,
        has_server_id: true,
        fks: NO_FKS,
        embedded: NO_EMBEDDED,
        soft_delete: false,
        aliases: SETTING_ALIASES,
    },
    spec(Entity::Tag, NO_FKS, NO_EMBEDDED, false),
    // Read-write data.
    spec(Entity::Company, COMPANY_FKS, TAGGED, true),
    spec(Entity::Contact, CONTACT_FKS, TAGGED, true),
    spec(Entity::Lead, LEAD_FKS, TAGGED, true),
    spec(Entity::Deal, DEAL_FKS, TAGGED, true),
    spec(Entity::Task, TASK_FKS, NO_EMBEDDED, true),
    spec(Entity::Activity, ACTIVITY_FKS, NO_EMBEDDED, true),
    spec(Entity::Ticket, TICKET_FKS, TAGGED, true),
    spec(Entity::Quote, QUOTE_FKS, QUOTE_EMBEDDED, true),
    spec(Entity::Conversation, CONVERSATION_FKS, NO_EMBEDDED, true),
    // `attachment_*` (KARAR A29, defter O90, migration 0004) stays NO_EMBEDDED: the four
    // fields SyncPullService::attachMessageAttachments() flattens onto a message row are
    // plain scalars (a name, a mime string, a byte count, a bool), each in its own column —
    // not a JSON document the way `tags`/`custom_fields`/quote `items` are. `embedded` exists
    // to tell `row_to_json` which columns to re-parse as JSON on the way out; none of these
    // four needs that.
    spec(Entity::Message, MESSAGE_FKS, NO_EMBEDDED, true),
    spec(Entity::ConversationUser, CONVERSATION_USER_FKS, NO_EMBEDDED, false),
    // notifications: client_id == server id, no server_id column (protocol §6.1 P12).
    TableSpec {
        entity: Entity::Notification,
        has_server_id: false,
        fks: NO_FKS,
        embedded: NO_EMBEDDED,
        soft_delete: false,
        aliases: NO_ALIASES,
    },
];

/// Products carry tags and custom fields but no foreign key of their own.
const TAGGED_FKS: &[Fk] = &[];

const NO_ALIASES: &[(&str, &str)] = &[];
/// `group` is a reserved word in SQL, so the local mirror spells it `group_name`.
const SETTING_ALIASES: &[(&str, &str)] = &[("group", "group_name")];

const fn spec(
    entity: Entity,
    fks: &'static [Fk],
    embedded: &'static [&'static str],
    soft_delete: bool,
) -> TableSpec {
    TableSpec {
        entity,
        has_server_id: true,
        fks,
        embedded,
        soft_delete,
        aliases: NO_ALIASES,
    }
}

/// Look up a table spec.
pub fn spec_for(entity: Entity) -> &'static TableSpec {
    TABLES
        .iter()
        .find(|t| t.entity == entity)
        .expect("every Entity has a TableSpec")
}

/// Namespace for the deterministic `client_id` of rows created on the web
/// (`SYNCDESKTOP.md` §5.3: `uuid5(namespace, "entity:server_id")`).
///
/// This is a fixed, arbitrary v4 UUID; it must never change, or every web-created row
/// would get a second local identity on the next bootstrap.
pub const CLIENT_ID_NAMESPACE: uuid::Uuid = uuid::uuid!("6f9e9b4c-2a1e-4a58-9f2f-0b6f5f1a1d77");

/// Deterministic local identity for a server row that has no `client_id`.
///
/// Not used for `notifications`: protocol §6.1 P12 makes `notifications.id` the
/// `client_id` directly, so the "web row without a client_id" case cannot exist there.
pub fn derive_client_id(entity: Entity, server_id: i64) -> uuid::Uuid {
    let name = format!("{}:{}", entity.wire_name(), server_id);
    uuid::Uuid::new_v5(&CLIENT_ID_NAMESPACE, name.as_bytes())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_entity_has_a_spec() {
        for entity in Entity::ALL {
            let spec = spec_for(*entity);
            assert_eq!(spec.entity, *entity);
        }
        assert_eq!(TABLES.len(), Entity::ALL.len());
    }

    #[test]
    fn only_notifications_lack_a_server_id_column() {
        let without: Vec<Entity> = TABLES
            .iter()
            .filter(|t| !t.has_server_id)
            .map(|t| t.entity)
            .collect();
        assert_eq!(without, vec![Entity::Notification]);
    }

    #[test]
    fn derived_client_id_is_deterministic() {
        let a = derive_client_id(Entity::Deal, 18342);
        let b = derive_client_id(Entity::Deal, 18342);
        assert_eq!(a, b);
        assert_ne!(a, derive_client_id(Entity::Deal, 18343));
        assert_ne!(a, derive_client_id(Entity::Task, 18342));
    }

    #[test]
    fn the_reserved_word_group_is_aliased() {
        let spec = spec_for(Entity::Setting);
        assert_eq!(spec.aliases, &[("group", "group_name")]);
    }

    #[test]
    fn quote_items_are_embedded_not_mirrored() {
        assert!(spec_for(Entity::Quote).embedded.contains(&"items"));
        assert!(Entity::from_table("quote_items").is_none());
        assert!(Entity::from_table("taggables").is_none());
        assert!(Entity::from_table("custom_field_values").is_none());
    }
}
