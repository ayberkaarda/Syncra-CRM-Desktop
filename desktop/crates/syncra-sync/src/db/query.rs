//! The read whitelist.
//!
//! `SYNCDESKTOP.md` §5.2 is explicit: **raw SQL from the UI is forbidden**. The UI names a
//! query; this module owns the SQL. Every value the caller supplies is bound, never
//! interpolated, and the only identifiers that can reach the statement are the ones listed
//! in [`SortField::column`] and the constants below.

use crate::error::{Result, SyncError};
use crate::types::Entity;
use rusqlite::types::Value as SqlValue;
use serde::{Deserialize, Serialize};

/// Hard ceiling on any single page, regardless of what the caller asks for.
pub const MAX_LIMIT: u32 = 500;
/// Page size used when the caller does not specify one.
pub const DEFAULT_LIMIT: u32 = 50;

/// Sort direction.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum SortDir {
    /// Ascending.
    Asc,
    #[default]
    /// Descending.
    Desc,
}

impl SortDir {
    fn sql(self) -> &'static str {
        match self {
            SortDir::Asc => "ASC",
            SortDir::Desc => "DESC",
        }
    }
}

/// The only columns a caller may sort by. Anything else is a validation error, which keeps
/// identifier injection structurally impossible.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum SortField {
    /// `created_at`
    CreatedAt,
    /// `updated_at`
    UpdatedAt,
    /// `name`
    Name,
    /// `title`
    Title,
    /// `amount`
    Amount,
    /// `status`
    Status,
    /// `due_at`
    DueAt,
    /// `occurred_at`
    OccurredAt,
    /// `position`
    Position,
    /// `last_message_at`
    LastMessageAt,
}

impl SortField {
    /// The column this sort maps to.
    pub const fn column(self) -> &'static str {
        match self {
            SortField::CreatedAt => "created_at",
            SortField::UpdatedAt => "updated_at",
            SortField::Name => "name",
            SortField::Title => "title",
            SortField::Amount => "amount",
            SortField::Status => "status",
            SortField::DueAt => "due_at",
            SortField::OccurredAt => "occurred_at",
            SortField::Position => "position",
            SortField::LastMessageAt => "last_message_at",
        }
    }
}

/// Paging and ordering, shared by every named query.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct QueryParams {
    #[serde(default)]
    /// Page size; `None` uses the default.
    pub limit: Option<u32>,
    #[serde(default)]
    /// Rows to skip.
    pub offset: u32,
    #[serde(default)]
    /// Column to order by; `None` uses the query's natural order.
    pub sort_by: Option<SortField>,
    #[serde(default)]
    /// Order direction.
    pub sort_dir: SortDir,
    /// Whether rows the user has locally deleted should be included.
    #[serde(default)]
    pub include_tombstones: bool,
}

impl Default for QueryParams {
    fn default() -> Self {
        QueryParams {
            limit: None,
            offset: 0,
            sort_by: None,
            sort_dir: SortDir::Desc,
            include_tombstones: false,
        }
    }
}

impl QueryParams {
    /// Page size, clamped to [`MAX_LIMIT`].
    pub fn effective_limit(&self) -> u32 {
        self.limit.unwrap_or(DEFAULT_LIMIT).clamp(1, MAX_LIMIT)
    }
}

/// The whitelist of reads the UI may ask for.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case", tag = "query")]
pub enum NamedQuery {
    /// Kanban board: open deals in the given stages, in fractional-index order.
    DealsBoard {
        /// Pipeline stages to draw, as local ids.
        stage_client_ids: Vec<String>,
    },
    /// Flat deal list.
    DealsList {
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
        #[serde(default)]
        /// Owner filter.
        owner_client_id: Option<String>,
    },
    /// Company list.
    CompanyList,
    /// Contact list, optionally scoped to one company.
    ContactList {
        #[serde(default)]
        /// Company filter.
        company_client_id: Option<String>,
    },
    /// Lead list.
    LeadList {
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
    },
    /// Task list.
    TaskList {
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
        #[serde(default)]
        /// Assignee filter.
        assigned_to_client_id: Option<String>,
    },
    /// Activity timeline.
    ActivityList {
        #[serde(default)]
        /// Related record filter.
        activityable_client_id: Option<String>,
    },
    /// Ticket list.
    TicketList {
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
    },
    /// Quote list.
    QuoteList {
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
    },
    /// Conversation list, most recently active first.
    ConversationList,
    /// Messages of one conversation, newest first.
    ConversationMessages {
        /// Conversation to read messages from.
        conversation_client_id: String,
        /// Keyset cursor: only messages created strictly before this timestamp.
        #[serde(default)]
        before: Option<String>,
    },
    /// Notification list.
    NotificationList {
        #[serde(default)]
        /// Restrict to notifications that have not been read.
        unread_only: bool,
    },
    /// Kanban columns, in display order.
    PipelineStages,
    /// Product catalogue.
    ProductList,
    /// Users, for owner and assignee pickers.
    UserList,
    /// Rows with unpushed edits, for the pending badge.
    PendingRows {
        /// Table to look in.
        entity: Entity,
    },
}

impl NamedQuery {
    /// Entity this query returns rows of.
    pub fn entity(&self) -> Entity {
        match self {
            NamedQuery::DealsBoard { .. } | NamedQuery::DealsList { .. } => Entity::Deal,
            NamedQuery::CompanyList => Entity::Company,
            NamedQuery::ContactList { .. } => Entity::Contact,
            NamedQuery::LeadList { .. } => Entity::Lead,
            NamedQuery::TaskList { .. } => Entity::Task,
            NamedQuery::ActivityList { .. } => Entity::Activity,
            NamedQuery::TicketList { .. } => Entity::Ticket,
            NamedQuery::QuoteList { .. } => Entity::Quote,
            NamedQuery::ConversationList => Entity::Conversation,
            NamedQuery::ConversationMessages { .. } => Entity::Message,
            NamedQuery::NotificationList { .. } => Entity::Notification,
            NamedQuery::PipelineStages => Entity::PipelineStage,
            NamedQuery::ProductList => Entity::Product,
            NamedQuery::UserList => Entity::User,
            NamedQuery::PendingRows { entity } => *entity,
        }
    }

    /// Default ordering when the caller does not name one.
    fn default_sort(&self) -> (&'static str, SortDir) {
        match self {
            NamedQuery::DealsBoard { .. } => ("position", SortDir::Asc),
            NamedQuery::PipelineStages => ("position", SortDir::Asc),
            NamedQuery::ConversationMessages { .. } => ("created_at", SortDir::Desc),
            NamedQuery::ConversationList => ("last_message_at", SortDir::Desc),
            NamedQuery::CompanyList | NamedQuery::ProductList | NamedQuery::UserList => {
                ("name", SortDir::Asc)
            }
            _ => ("created_at", SortDir::Desc),
        }
    }

    /// Build the statement. Returns the SQL and its bound parameters, in order.
    pub fn build(&self, params: &QueryParams) -> Result<(String, Vec<SqlValue>)> {
        let entity = self.entity();
        let mut wheres: Vec<String> = Vec::new();
        let mut binds: Vec<SqlValue> = Vec::new();

        if !params.include_tombstones {
            wheres.push("sync_state <> 'tombstone'".to_string());
        }

        let push_eq = |column: &str, value: Option<&String>, binds: &mut Vec<SqlValue>, wheres: &mut Vec<String>| {
            if let Some(v) = value {
                binds.push(SqlValue::Text(v.clone()));
                wheres.push(format!("{column} = ?{}", binds.len()));
            }
        };

        match self {
            NamedQuery::DealsBoard { stage_client_ids } => {
                if stage_client_ids.is_empty() {
                    return Err(SyncError::Validation(
                        "DealsBoard requires at least one stage".into(),
                    ));
                }
                if stage_client_ids.len() > 64 {
                    return Err(SyncError::Validation("DealsBoard: too many stages".into()));
                }
                let mut slots = Vec::with_capacity(stage_client_ids.len());
                for stage in stage_client_ids {
                    binds.push(SqlValue::Text(stage.clone()));
                    slots.push(format!("?{}", binds.len()));
                }
                wheres.push(format!(
                    "pipeline_stage_client_id IN ({})",
                    slots.join(", ")
                ));
                wheres.push("status = 'open'".to_string());
            }
            NamedQuery::DealsList {
                status,
                owner_client_id,
            } => {
                push_eq("status", status.as_ref(), &mut binds, &mut wheres);
                push_eq(
                    "owner_client_id",
                    owner_client_id.as_ref(),
                    &mut binds,
                    &mut wheres,
                );
            }
            NamedQuery::ContactList { company_client_id } => {
                push_eq(
                    "company_client_id",
                    company_client_id.as_ref(),
                    &mut binds,
                    &mut wheres,
                );
            }
            NamedQuery::LeadList { status }
            | NamedQuery::TicketList { status }
            | NamedQuery::QuoteList { status } => {
                push_eq("status", status.as_ref(), &mut binds, &mut wheres);
            }
            NamedQuery::TaskList {
                status,
                assigned_to_client_id,
            } => {
                push_eq("status", status.as_ref(), &mut binds, &mut wheres);
                push_eq(
                    "assigned_to_client_id",
                    assigned_to_client_id.as_ref(),
                    &mut binds,
                    &mut wheres,
                );
            }
            NamedQuery::ActivityList {
                activityable_client_id,
            } => {
                push_eq(
                    "activityable_id",
                    activityable_client_id.as_ref(),
                    &mut binds,
                    &mut wheres,
                );
            }
            NamedQuery::ConversationMessages {
                conversation_client_id,
                before,
            } => {
                binds.push(SqlValue::Text(conversation_client_id.clone()));
                wheres.push(format!("conversation_client_id = ?{}", binds.len()));
                if let Some(before) = before {
                    binds.push(SqlValue::Text(before.clone()));
                    wheres.push(format!("created_at < ?{}", binds.len()));
                }
            }
            NamedQuery::NotificationList { unread_only } => {
                if *unread_only {
                    wheres.push("read_at IS NULL".to_string());
                }
            }
            NamedQuery::PendingRows { .. } => {
                wheres.clear();
                wheres.push("sync_state IN ('pending', 'conflict')".to_string());
            }
            NamedQuery::CompanyList
            | NamedQuery::ConversationList
            | NamedQuery::PipelineStages
            | NamedQuery::ProductList
            | NamedQuery::UserList => {}
        }

        let (default_col, default_dir) = self.default_sort();
        let (sort_col, sort_dir) = match params.sort_by {
            Some(field) => (field.column(), params.sort_dir),
            None => (default_col, default_dir),
        };

        let limit = params.effective_limit();
        binds.push(SqlValue::Integer(i64::from(limit)));
        let limit_slot = binds.len();
        binds.push(SqlValue::Integer(i64::from(params.offset)));
        let offset_slot = binds.len();

        let where_clause = if wheres.is_empty() {
            String::new()
        } else {
            format!(" WHERE {}", wheres.join(" AND "))
        };

        let sql = format!(
            "SELECT * FROM {table}{where_clause} ORDER BY {sort_col} {dir}, client_id ASC LIMIT ?{limit_slot} OFFSET ?{offset_slot}",
            table = entity.table(),
            dir = sort_dir.sql(),
        );

        Ok((sql, binds))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_value_is_bound_not_interpolated() {
        let q = NamedQuery::DealsList {
            status: Some("open'; DROP TABLE deals; --".into()),
            owner_client_id: None,
        };
        let (sql, binds) = q.build(&QueryParams::default()).unwrap();
        assert!(!sql.contains("DROP"), "value leaked into SQL: {sql}");
        assert!(matches!(binds[0], SqlValue::Text(_)));
    }

    #[test]
    fn limit_is_clamped() {
        let params = QueryParams {
            limit: Some(100_000),
            ..Default::default()
        };
        assert_eq!(params.effective_limit(), MAX_LIMIT);
    }

    #[test]
    fn sort_column_comes_from_the_enum_only() {
        let params = QueryParams {
            sort_by: Some(SortField::Amount),
            sort_dir: SortDir::Asc,
            ..Default::default()
        };
        let (sql, _) = NamedQuery::DealsList {
            status: None,
            owner_client_id: None,
        }
        .build(&params)
        .unwrap();
        assert!(sql.contains("ORDER BY amount ASC"));
    }

    #[test]
    fn board_requires_stages() {
        let err = NamedQuery::DealsBoard {
            stage_client_ids: vec![],
        }
        .build(&QueryParams::default())
        .unwrap_err();
        assert!(matches!(err, SyncError::Validation(_)));
    }

    #[test]
    fn tombstones_are_hidden_by_default() {
        let (sql, _) = NamedQuery::CompanyList
            .build(&QueryParams::default())
            .unwrap();
        assert!(sql.contains("sync_state <> 'tombstone'"));
    }
}
