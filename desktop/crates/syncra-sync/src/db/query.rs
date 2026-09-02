//! The read whitelist.
//!
//! `SYNCDESKTOP.md` §5.2 is explicit: **raw SQL from the UI is forbidden**. The UI names a
//! query; this module owns the SQL. Every value the caller supplies is bound, never
//! interpolated, and the only identifiers that can reach the statement are the ones listed
//! in [`SortField::column`] and the constants below.
//!
//! ## Two conventions the adapter depends on
//!
//! * **Filters are expressed with *server* ids** (`owner_id`, `company_id`, …), not local
//!   `client_id`s. The web list screens hand their filters down as the numeric ids the REST
//!   API uses, so anything else would force the adapter to translate on every keystroke.
//!   The two structural exceptions (`DealsBoard`, which draws columns the pull produced
//!   locally, and [`NamedQuery::RowsByClientIds`], which hydrates FTS hits) keep client ids.
//! * **Every page carries `local_rowid`.** A row created offline has no `server_id` yet, but
//!   the feature DTOs type `id` as a number. The adapter therefore addresses such a row by
//!   `-local_rowid`, and [`NamedQuery::RowsByServerIds`] accepts those negative ids back.
//!   The surrogate is transient by design: it is replaced by the real id as soon as the push
//!   lands, and it is only ever meaningful inside one installation.

use crate::error::{Result, SyncError};
use crate::types::Entity;
use rusqlite::types::Value as SqlValue;
use serde::{Deserialize, Serialize};

/// Hard ceiling on any single page, regardless of what the caller asks for.
pub const MAX_LIMIT: u32 = 500;
/// Page size used when the caller does not specify one.
pub const DEFAULT_LIMIT: u32 = 50;
/// Hard ceiling on an id list handed to [`NamedQuery::RowsByServerIds`] and friends.
///
/// A page is capped at [`MAX_LIMIT`] rows and the adapter resolves references one page at a
/// time, so nothing legitimate needs more than this. It also keeps the generated `IN (…)`
/// list well under SQLite's `SQLITE_MAX_VARIABLE_NUMBER`.
pub const MAX_IDS: usize = 600;

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
    /// `first_name`
    FirstName,
    /// `last_name`
    LastName,
    /// `email`
    Email,
    /// `priority`
    Priority,
    /// `score`
    Score,
    /// `subject`
    Subject,
    /// `ticket_number`
    TicketNumber,
    /// `quote_number`
    QuoteNumber,
    /// `total`
    Total,
    /// `valid_until`
    ValidUntil,
    /// `sla_due_at`
    SlaDueAt,
    /// `unit_price`
    UnitPrice,
    /// `sku`
    Sku,
    /// `category`
    Category,
    /// `code`
    Code,
    /// `expected_close_date`
    ExpectedCloseDate,
    /// `read_at`
    ReadAt,
    /// `rate_date`
    RateDate,
    /// `revision`
    Revision,
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
            SortField::FirstName => "first_name",
            SortField::LastName => "last_name",
            SortField::Email => "email",
            SortField::Priority => "priority",
            SortField::Score => "score",
            SortField::Subject => "subject",
            SortField::TicketNumber => "ticket_number",
            SortField::QuoteNumber => "quote_number",
            SortField::Total => "total",
            SortField::ValidUntil => "valid_until",
            SortField::SlaDueAt => "sla_due_at",
            SortField::UnitPrice => "unit_price",
            SortField::Sku => "sku",
            SortField::Category => "category",
            SortField::Code => "code",
            SortField::ExpectedCloseDate => "expected_close_date",
            SortField::ReadAt => "read_at",
            SortField::RateDate => "rate_date",
            SortField::Revision => "revision",
        }
    }

    /// Resolve the `sort=<column>` value the web list screens already send.
    ///
    /// Returning `None` rather than erroring is deliberate: an unknown sort falls back to the
    /// query's natural order instead of turning a list screen into an error state.
    pub fn from_column(column: &str) -> Option<SortField> {
        const ALL: &[SortField] = &[
            SortField::CreatedAt,
            SortField::UpdatedAt,
            SortField::Name,
            SortField::Title,
            SortField::Amount,
            SortField::Status,
            SortField::DueAt,
            SortField::OccurredAt,
            SortField::Position,
            SortField::LastMessageAt,
            SortField::FirstName,
            SortField::LastName,
            SortField::Email,
            SortField::Priority,
            SortField::Score,
            SortField::Subject,
            SortField::TicketNumber,
            SortField::QuoteNumber,
            SortField::Total,
            SortField::ValidUntil,
            SortField::SlaDueAt,
            SortField::UnitPrice,
            SortField::Sku,
            SortField::Category,
            SortField::Code,
            SortField::ExpectedCloseDate,
            SortField::ReadAt,
            SortField::RateDate,
            SortField::Revision,
        ];
        ALL.iter().copied().find(|f| f.column() == column)
    }
}

/// The parent -> child relations whose row counts the detail and list DTOs carry
/// (`contacts_count`, `deals_count`, `tickets_count`, `items_count`).
///
/// An enum rather than a `(table, column)` pair: it keeps the only identifiers that can reach
/// the aggregate inside this file, exactly like [`SortField`].
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum CountScope {
    /// `contacts.company_id` -> `Company.contacts_count`.
    CompanyContacts,
    /// `deals.company_id` -> `Company.deals_count`.
    CompanyDeals,
    /// `deals.contact_id` -> `Contact.deals_count`.
    ContactDeals,
    /// `tickets.contact_id` -> `Contact.tickets_count`.
    ContactTickets,
    /// `price_list_items.price_list_id` -> `PriceList.items_count`.
    PriceListItems,
}

impl CountScope {
    /// Child table and the foreign-key column that points at the parent.
    const fn table_and_column(self) -> (&'static str, &'static str) {
        match self {
            CountScope::CompanyContacts => ("contacts", "company_id"),
            CountScope::CompanyDeals => ("deals", "company_id"),
            CountScope::ContactDeals => ("deals", "contact_id"),
            CountScope::ContactTickets => ("tickets", "contact_id"),
            CountScope::PriceListItems => ("price_list_items", "price_list_id"),
        }
    }

    /// Table the counted rows live in.
    pub fn entity(self) -> Entity {
        match self {
            CountScope::CompanyContacts => Entity::Contact,
            CountScope::CompanyDeals | CountScope::ContactDeals => Entity::Deal,
            CountScope::ContactTickets => Entity::Ticket,
            CountScope::PriceListItems => Entity::PriceListItem,
        }
    }
}

/// The `filter[read]` values the notification list accepts.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum ReadFilter {
    /// Only notifications that have not been read.
    Unread,
    /// Only notifications that have been read.
    Read,
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
    /// Return `[{ "total": n }]` — the row count the same filters would produce — instead of
    /// the page itself.
    ///
    /// The list DTOs carry `meta.pagination.total` and `last_page`; without a count the
    /// desktop pager could only guess, and a guessed page count is a silently wrong UI. The
    /// flag lives on the params rather than in a second command so that the count and the
    /// page are built from *the same* [`NamedQuery`] value and cannot drift apart.
    #[serde(default)]
    pub count_only: bool,
}

impl Default for QueryParams {
    fn default() -> Self {
        QueryParams {
            limit: None,
            offset: 0,
            sort_by: None,
            sort_dir: SortDir::Desc,
            include_tombstones: false,
            count_only: false,
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
    /// Rows addressed by server id. A negative id addresses `-local_rowid`, i.e. a row that
    /// was created offline and has no server id yet.
    RowsByServerIds {
        /// Table to read from.
        entity: Entity,
        /// Server ids (or negated local rowids).
        ids: Vec<i64>,
    },
    /// Rows addressed by local identity; hydrates FTS hits back into full rows.
    RowsByClientIds {
        /// Table to read from.
        entity: Entity,
        /// Local identities.
        client_ids: Vec<String>,
    },
    /// Kanban board: open deals in the given stages, in fractional-index order.
    DealsBoard {
        /// Pipeline stages to draw, as local ids.
        stage_client_ids: Vec<String>,
    },
    /// Flat deal list.
    DealsList {
        #[serde(default)]
        /// Free-text filter over title and description.
        q: Option<String>,
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
        #[serde(default)]
        /// Pipeline stage filter, by server id.
        stage_id: Option<i64>,
        #[serde(default)]
        /// Owner filter, by server id.
        owner_id: Option<i64>,
        #[serde(default)]
        /// Company filter, by server id.
        company_id: Option<i64>,
        #[serde(default)]
        /// Contact filter, by server id.
        contact_id: Option<i64>,
        #[serde(default)]
        /// Tag filter, by server id (matched inside the embedded `tags` document).
        tag_id: Option<i64>,
        #[serde(default)]
        /// Lowest amount.
        amount_min: Option<f64>,
        #[serde(default)]
        /// Highest amount.
        amount_max: Option<f64>,
        #[serde(default)]
        /// Earliest `expected_close_date`.
        from: Option<String>,
        #[serde(default)]
        /// Latest `expected_close_date`.
        to: Option<String>,
    },
    /// Company list.
    CompanyList {
        #[serde(default)]
        /// Free-text filter over name, email and phone.
        q: Option<String>,
        #[serde(default)]
        /// Industry filter.
        industry: Option<String>,
        #[serde(default)]
        /// Owner filter, by server id.
        owner_id: Option<i64>,
        #[serde(default)]
        /// City filter.
        city: Option<String>,
        #[serde(default)]
        /// Country filter.
        country: Option<String>,
        #[serde(default)]
        /// Tag filter, by server id.
        tag_id: Option<i64>,
        #[serde(default)]
        /// Earliest `created_at`.
        from: Option<String>,
        #[serde(default)]
        /// Latest `created_at`.
        to: Option<String>,
    },
    /// Contact list, optionally scoped to one company.
    ContactList {
        #[serde(default)]
        /// Free-text filter over names, email and phone.
        q: Option<String>,
        #[serde(default)]
        /// Company filter, by server id.
        company_id: Option<i64>,
        #[serde(default)]
        /// Owner filter, by server id.
        owner_id: Option<i64>,
        #[serde(default)]
        /// Primary-contact filter.
        is_primary: Option<bool>,
        #[serde(default)]
        /// City filter.
        city: Option<String>,
        #[serde(default)]
        /// Tag filter, by server id.
        tag_id: Option<i64>,
        #[serde(default)]
        /// Earliest `created_at`.
        from: Option<String>,
        #[serde(default)]
        /// Latest `created_at`.
        to: Option<String>,
    },
    /// Lead list.
    LeadList {
        #[serde(default)]
        /// Free-text filter over names, email, phone and company name.
        q: Option<String>,
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
        #[serde(default)]
        /// Source filter.
        source: Option<String>,
        #[serde(default)]
        /// Owner filter, by server id.
        owner_id: Option<i64>,
        #[serde(default)]
        /// Lowest score.
        score_min: Option<i64>,
        #[serde(default)]
        /// Highest score.
        score_max: Option<i64>,
        #[serde(default)]
        /// Tag filter, by server id.
        tag_id: Option<i64>,
        #[serde(default)]
        /// Earliest `created_at`.
        from: Option<String>,
        #[serde(default)]
        /// Latest `created_at`.
        to: Option<String>,
    },
    /// Task list.
    TaskList {
        #[serde(default)]
        /// Free-text filter over title and description.
        q: Option<String>,
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
        #[serde(default)]
        /// Priority filter.
        priority: Option<String>,
        #[serde(default)]
        /// Assignee filter, by server id.
        assigned_to: Option<i64>,
        #[serde(default)]
        /// Creator filter, by server id.
        created_by: Option<i64>,
        #[serde(default)]
        /// Related-record type (short morph name, e.g. `deal`).
        taskable_type: Option<String>,
        #[serde(default)]
        /// Related-record server id.
        taskable_id: Option<i64>,
        #[serde(default)]
        /// Only tasks past their due date and not yet finished.
        overdue: Option<bool>,
        #[serde(default)]
        /// Earliest `due_at`.
        from: Option<String>,
        #[serde(default)]
        /// Latest `due_at`.
        to: Option<String>,
    },
    /// Activity timeline.
    ActivityList {
        #[serde(default)]
        /// Free-text filter over subject and body.
        q: Option<String>,
        #[serde(default)]
        /// Activity type filter.
        kind: Option<String>,
        #[serde(default)]
        /// Author filter, by server id.
        user_id: Option<i64>,
        #[serde(default)]
        /// Related-record type (short morph name).
        activityable_type: Option<String>,
        #[serde(default)]
        /// Related-record server id.
        activityable_id: Option<i64>,
        #[serde(default)]
        /// Earliest `occurred_at`.
        from: Option<String>,
        #[serde(default)]
        /// Latest `occurred_at`.
        to: Option<String>,
    },
    /// Ticket list.
    TicketList {
        #[serde(default)]
        /// Free-text filter over subject, description and ticket number.
        q: Option<String>,
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
        #[serde(default)]
        /// Priority filter.
        priority: Option<String>,
        #[serde(default)]
        /// Assignee filter, by server id.
        assigned_to: Option<i64>,
        #[serde(default)]
        /// Company filter, by server id.
        company_id: Option<i64>,
        #[serde(default)]
        /// Contact filter, by server id.
        contact_id: Option<i64>,
        #[serde(default)]
        /// Category filter.
        category: Option<String>,
        #[serde(default)]
        /// Tag filter, by server id.
        tag_id: Option<i64>,
        #[serde(default)]
        /// Only open tickets whose SLA target has passed.
        sla_breached: Option<bool>,
        #[serde(default)]
        /// Earliest `created_at`.
        from: Option<String>,
        #[serde(default)]
        /// Latest `created_at`.
        to: Option<String>,
    },
    /// Filter-independent ticket totals, as a single aggregate row.
    ///
    /// `GET /api/tickets/stats` is deliberately independent of the list filters, so it cannot
    /// be derived from a page. The SQL is fully static — no caller value reaches it.
    TicketStats,
    /// Quote list.
    QuoteList {
        #[serde(default)]
        /// Free-text filter over quote number and title.
        q: Option<String>,
        #[serde(default)]
        /// Status filter.
        status: Option<String>,
        #[serde(default)]
        /// Deal filter, by server id.
        deal_id: Option<i64>,
        #[serde(default)]
        /// Company filter, by server id.
        company_id: Option<i64>,
        #[serde(default)]
        /// Contact filter, by server id.
        contact_id: Option<i64>,
        #[serde(default)]
        /// Only quotes whose validity date has passed.
        expired: Option<bool>,
        #[serde(default)]
        /// Earliest `created_at`.
        from: Option<String>,
        #[serde(default)]
        /// Latest `created_at`.
        to: Option<String>,
    },
    /// Every revision of one quote family, oldest first.
    ///
    /// The family is identified by the root quote number; revisions carry it as a prefix,
    /// which is why this is a `LIKE` rather than an equality.
    QuoteRevisionFamily {
        /// Root quote number, e.g. `TKF-2026-0001`.
        root_number: String,
    },
    /// Conversation list, most recently active first.
    ConversationList {
        #[serde(default)]
        /// Conversation type filter (`dm`, `group`, `record`).
        kind: Option<String>,
        #[serde(default)]
        /// Free-text filter over the conversation name.
        q: Option<String>,
    },
    /// Messages of one conversation, newest first.
    ConversationMessages {
        /// Conversation to read messages from, by server id.
        conversation_id: i64,
        /// Keyset cursor: only messages older than this message id.
        #[serde(default)]
        before_server_id: Option<i64>,
    },
    /// Chat membership rows — the source of the member lists and the unread badges.
    ///
    /// Both filters are optional: a conversation list resolves the membership of a whole page
    /// in one call, while the global unread badge asks for one user's rows.
    ConversationMembership {
        #[serde(default)]
        /// User filter, by server id.
        user_id: Option<i64>,
        #[serde(default)]
        /// Conversation filter, by server id.
        conversation_id: Option<i64>,
    },
    /// Notification list.
    NotificationList {
        #[serde(default)]
        /// Read-state filter; `None` returns both.
        read: Option<ReadFilter>,
    },
    /// Kanban columns, in display order.
    PipelineStages,
    /// Product catalogue.
    ProductList {
        #[serde(default)]
        /// Free-text filter over name, sku and description.
        q: Option<String>,
        #[serde(default)]
        /// Category filter.
        category: Option<String>,
        #[serde(default)]
        /// Active-only filter.
        is_active: Option<bool>,
        #[serde(default)]
        /// Tag filter, by server id.
        tag_id: Option<i64>,
        #[serde(default)]
        /// Lowest catalogue price.
        price_min: Option<f64>,
        #[serde(default)]
        /// Highest catalogue price.
        price_max: Option<f64>,
        #[serde(default)]
        /// Only products with stock on hand.
        in_stock: Option<bool>,
    },
    /// Distinct, non-empty product categories, alphabetically.
    ProductCategories,
    /// Price list index.
    PriceListList {
        #[serde(default)]
        /// Free-text filter over name and code.
        q: Option<String>,
        #[serde(default)]
        /// Active-only filter.
        is_active: Option<bool>,
        #[serde(default)]
        /// Default-only filter.
        is_default: Option<bool>,
    },
    /// Per-product price overrides.
    PriceListItemList {
        #[serde(default)]
        /// Price list filter, by server id.
        price_list_id: Option<i64>,
        #[serde(default)]
        /// Product filter, by server id.
        product_id: Option<i64>,
    },
    /// Mirrored exchange rates (the server keeps the last seven days).
    ExchangeRateList,
    /// Saved views, optionally for one module.
    SavedViewList {
        #[serde(default)]
        /// Module filter, e.g. `deals`.
        module: Option<String>,
    },
    /// Public settings.
    SettingList,
    /// The tag vocabulary.
    TagList {
        #[serde(default)]
        /// Free-text filter over the tag name.
        q: Option<String>,
    },
    /// Custom field definitions, optionally for one entity type.
    CustomFieldList {
        #[serde(default)]
        /// Entity type filter, e.g. `deals`.
        entity_type: Option<String>,
    },
    /// Users, for owner and assignee pickers.
    UserList {
        #[serde(default)]
        /// Free-text filter over name and email.
        q: Option<String>,
        #[serde(default)]
        /// Active-only filter.
        is_active: Option<bool>,
    },
    /// One `{parent_id, total}` row per parent, for the `*_count` fields the list DTOs carry.
    ///
    /// A page of 50 companies would otherwise cost 100 separate count queries; this is the
    /// same information in one statement per relation.
    RelatedCounts {
        /// Which parent -> child relation to count.
        scope: CountScope,
        /// Parent server ids.
        parent_ids: Vec<i64>,
    },
    /// Rows with unpushed edits, for the pending badge.
    PendingRows {
        /// Table to look in.
        entity: Entity,
    },
}

/// Accumulates the `WHERE` fragments and their bound values for one query.
struct Predicates {
    wheres: Vec<String>,
    binds: Vec<SqlValue>,
}

impl Predicates {
    fn new() -> Self {
        Predicates {
            wheres: Vec::new(),
            binds: Vec::new(),
        }
    }

    /// Bind one value and return its `?n` slot.
    fn bind(&mut self, value: SqlValue) -> String {
        self.binds.push(value);
        format!("?{}", self.binds.len())
    }

    fn eq_text(&mut self, column: &str, value: Option<&String>) {
        if let Some(v) = value {
            let slot = self.bind(SqlValue::Text(v.clone()));
            self.wheres.push(format!("{column} = {slot}"));
        }
    }

    fn eq_int(&mut self, column: &str, value: Option<i64>) {
        if let Some(v) = value {
            let slot = self.bind(SqlValue::Integer(v));
            self.wheres.push(format!("{column} = {slot}"));
        }
    }

    fn eq_bool(&mut self, column: &str, value: Option<bool>) {
        if let Some(v) = value {
            let slot = self.bind(SqlValue::Integer(i64::from(v)));
            self.wheres.push(format!("{column} = {slot}"));
        }
    }

    fn cmp_text(&mut self, column: &str, op: &str, value: Option<&String>) {
        if let Some(v) = value {
            let slot = self.bind(SqlValue::Text(v.clone()));
            self.wheres.push(format!("{column} {op} {slot}"));
        }
    }

    fn cmp_int(&mut self, column: &str, op: &str, value: Option<i64>) {
        if let Some(v) = value {
            let slot = self.bind(SqlValue::Integer(v));
            self.wheres.push(format!("{column} {op} {slot}"));
        }
    }

    /// Numeric comparison against a column the mirror stores as TEXT.
    ///
    /// Money and quantities arrive from the server as decimal strings and are stored
    /// verbatim; `'900' < '1000'` is false as text, so the range filters have to cast.
    fn cmp_decimal(&mut self, column: &str, op: &str, value: Option<f64>) {
        if let Some(v) = value {
            let slot = self.bind(SqlValue::Real(v));
            self.wheres
                .push(format!("CAST({column} AS REAL) {op} {slot}"));
        }
    }

    /// Membership in the embedded `tags` document (protocol §1.4 — there is no `taggables`
    /// table to join). `json_each` walks the array of tag server ids in place.
    fn has_tag(&mut self, value: Option<i64>) {
        if let Some(tag_id) = value {
            let slot = self.bind(SqlValue::Integer(tag_id));
            self.wheres.push(format!(
                "EXISTS (SELECT 1 FROM json_each(tags) WHERE json_each.value = {slot})"
            ));
        }
    }

    /// A predicate that is added only when the caller passes `Some(true)`.
    ///
    /// The list screens send these flags one-way (`features/tickets/types.ts`: "true ise
    /// filtre gönderilir; false/undefined ise filtre hiç eklenmez"), so `Some(false)` must
    /// mean "unfiltered", not "the negation".
    fn flag(&mut self, value: Option<bool>, predicate: &str) {
        if value == Some(true) {
            self.wheres.push(predicate.to_string());
        }
    }

    /// `q` becomes one `LIKE` per column, OR-ed together, all sharing a single bound value.
    fn free_text(&mut self, columns: &[&str], value: Option<&String>) {
        let Some(term) = value else { return };
        let trimmed = term.trim();
        if trimmed.is_empty() || columns.is_empty() {
            return;
        }
        let slot = self.bind(SqlValue::Text(format!("%{trimmed}%")));
        let ors: Vec<String> = columns
            .iter()
            .map(|c| format!("{c} LIKE {slot}"))
            .collect();
        self.wheres.push(format!("({})", ors.join(" OR ")));
    }

    fn text_in(&mut self, column: &str, values: &[String]) -> Result<()> {
        if values.len() > MAX_IDS {
            return Err(SyncError::Validation(format!(
                "{column}: at most {MAX_IDS} ids per query"
            )));
        }
        if values.is_empty() {
            self.wheres.push("1 = 0".to_string());
            return Ok(());
        }
        let slots: Vec<String> = values
            .iter()
            .map(|v| self.bind(SqlValue::Text(v.clone())))
            .collect();
        self.wheres.push(format!("{column} IN ({})", slots.join(", ")));
        Ok(())
    }

    fn clause(&self) -> String {
        if self.wheres.is_empty() {
            String::new()
        } else {
            format!(" WHERE {}", self.wheres.join(" AND "))
        }
    }
}

impl NamedQuery {
    /// Unfiltered company list.
    pub fn companies() -> Self {
        NamedQuery::CompanyList {
            q: None,
            industry: None,
            owner_id: None,
            city: None,
            country: None,
            tag_id: None,
            from: None,
            to: None,
        }
    }

    /// Unfiltered contact list.
    pub fn contacts() -> Self {
        NamedQuery::ContactList {
            q: None,
            company_id: None,
            owner_id: None,
            is_primary: None,
            city: None,
            tag_id: None,
            from: None,
            to: None,
        }
    }

    /// Unfiltered deal list.
    pub fn deals() -> Self {
        NamedQuery::DealsList {
            q: None,
            status: None,
            stage_id: None,
            owner_id: None,
            company_id: None,
            contact_id: None,
            tag_id: None,
            amount_min: None,
            amount_max: None,
            from: None,
            to: None,
        }
    }

    /// Unfiltered lead list.
    pub fn leads() -> Self {
        NamedQuery::LeadList {
            q: None,
            status: None,
            source: None,
            owner_id: None,
            score_min: None,
            score_max: None,
            tag_id: None,
            from: None,
            to: None,
        }
    }

    /// Unfiltered task list.
    pub fn tasks() -> Self {
        NamedQuery::TaskList {
            q: None,
            status: None,
            priority: None,
            assigned_to: None,
            created_by: None,
            taskable_type: None,
            taskable_id: None,
            overdue: None,
            from: None,
            to: None,
        }
    }

    /// Unfiltered activity list.
    pub fn activities() -> Self {
        NamedQuery::ActivityList {
            q: None,
            kind: None,
            user_id: None,
            activityable_type: None,
            activityable_id: None,
            from: None,
            to: None,
        }
    }

    /// Unfiltered ticket list.
    pub fn tickets() -> Self {
        NamedQuery::TicketList {
            q: None,
            status: None,
            priority: None,
            assigned_to: None,
            company_id: None,
            contact_id: None,
            category: None,
            tag_id: None,
            sla_breached: None,
            from: None,
            to: None,
        }
    }

    /// Unfiltered quote list.
    pub fn quotes() -> Self {
        NamedQuery::QuoteList {
            q: None,
            status: None,
            deal_id: None,
            company_id: None,
            contact_id: None,
            expired: None,
            from: None,
            to: None,
        }
    }

    /// Unfiltered conversation list.
    pub fn conversations() -> Self {
        NamedQuery::ConversationList {
            kind: None,
            q: None,
        }
    }

    /// Notification list, optionally narrowed to one read state.
    pub fn notifications(read: Option<ReadFilter>) -> Self {
        NamedQuery::NotificationList { read }
    }

    /// Unfiltered product list.
    pub fn products() -> Self {
        NamedQuery::ProductList {
            q: None,
            category: None,
            is_active: None,
            tag_id: None,
            price_min: None,
            price_max: None,
            in_stock: None,
        }
    }

    /// Unfiltered price list index.
    pub fn price_lists() -> Self {
        NamedQuery::PriceListList {
            q: None,
            is_active: None,
            is_default: None,
        }
    }

    /// Unfiltered user list.
    pub fn users() -> Self {
        NamedQuery::UserList {
            q: None,
            is_active: None,
        }
    }

    /// Unfiltered tag vocabulary.
    pub fn tags() -> Self {
        NamedQuery::TagList { q: None }
    }

    /// Entity this query returns rows of.
    pub fn entity(&self) -> Entity {
        match self {
            NamedQuery::RowsByServerIds { entity, .. }
            | NamedQuery::RowsByClientIds { entity, .. }
            | NamedQuery::PendingRows { entity } => *entity,
            NamedQuery::DealsBoard { .. } | NamedQuery::DealsList { .. } => Entity::Deal,
            NamedQuery::CompanyList { .. } => Entity::Company,
            NamedQuery::ContactList { .. } => Entity::Contact,
            NamedQuery::LeadList { .. } => Entity::Lead,
            NamedQuery::TaskList { .. } => Entity::Task,
            NamedQuery::ActivityList { .. } => Entity::Activity,
            NamedQuery::TicketList { .. } | NamedQuery::TicketStats => Entity::Ticket,
            NamedQuery::QuoteList { .. } | NamedQuery::QuoteRevisionFamily { .. } => Entity::Quote,
            NamedQuery::ConversationList { .. } => Entity::Conversation,
            NamedQuery::ConversationMessages { .. } => Entity::Message,
            NamedQuery::ConversationMembership { .. } => Entity::ConversationUser,
            NamedQuery::NotificationList { .. } => Entity::Notification,
            NamedQuery::PipelineStages => Entity::PipelineStage,
            NamedQuery::ProductList { .. } | NamedQuery::ProductCategories => Entity::Product,
            NamedQuery::PriceListList { .. } => Entity::PriceList,
            NamedQuery::PriceListItemList { .. } => Entity::PriceListItem,
            NamedQuery::ExchangeRateList => Entity::ExchangeRate,
            NamedQuery::SavedViewList { .. } => Entity::SavedView,
            NamedQuery::SettingList => Entity::Setting,
            NamedQuery::TagList { .. } => Entity::Tag,
            NamedQuery::CustomFieldList { .. } => Entity::CustomField,
            NamedQuery::UserList { .. } => Entity::User,
            NamedQuery::RelatedCounts { scope, .. } => scope.entity(),
        }
    }

    /// Queries whose SQL is a fixed aggregate rather than a page of mirror rows.
    ///
    /// They ignore paging, ordering and `count_only`: there is exactly one shape to return.
    fn aggregate_sql(&self) -> Option<&'static str> {
        match self {
            // `sla_breached` is the definition `features/tickets/types.ts` documents for an
            // OPEN ticket: the target passed and the ticket is neither resolved nor closed.
            // The *derived* SLA countdown fields stay server-owned (`docs/SLA-DESIGN.md`) and
            // are deliberately not reconstructed here.
            NamedQuery::TicketStats => Some(
                "SELECT
                   COUNT(*)                                                          AS total,
                   SUM(status = 'open')                                              AS status_open,
                   SUM(status = 'pending')                                           AS status_pending,
                   SUM(status = 'in_progress')                                       AS status_in_progress,
                   SUM(status = 'resolved')                                          AS status_resolved,
                   SUM(status = 'closed')                                            AS status_closed,
                   SUM(priority = 'low')                                             AS priority_low,
                   SUM(priority = 'normal')                                          AS priority_normal,
                   SUM(priority = 'high')                                            AS priority_high,
                   SUM(priority = 'urgent')                                          AS priority_urgent,
                   SUM(sla_due_at IS NOT NULL
                       AND sla_due_at < strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
                       AND resolved_at IS NULL
                       AND closed_at IS NULL)                                        AS breached_count,
                   AVG(CASE WHEN resolved_at IS NOT NULL AND created_at IS NOT NULL
                            THEN (julianday(resolved_at) - julianday(created_at)) * 24.0
                       END)                                                          AS avg_resolution_hours
                 FROM tickets
                 WHERE sync_state <> 'tombstone' AND deleted_at IS NULL",
            ),
            NamedQuery::ProductCategories => Some(
                "SELECT DISTINCT category
                   FROM products
                  WHERE sync_state <> 'tombstone'
                    AND category IS NOT NULL
                    AND category <> ''
                  ORDER BY category ASC",
            ),
            _ => None,
        }
    }

    /// Default ordering when the caller does not name one.
    fn default_sort(&self) -> (&'static str, SortDir) {
        match self {
            NamedQuery::DealsBoard { .. } | NamedQuery::PipelineStages => {
                ("position", SortDir::Asc)
            }
            NamedQuery::ConversationMessages { .. } => ("created_at", SortDir::Desc),
            NamedQuery::ConversationList { .. } => ("last_message_at", SortDir::Desc),
            NamedQuery::QuoteRevisionFamily { .. } => ("revision", SortDir::Asc),
            NamedQuery::PriceListItemList { .. } => ("product_id", SortDir::Asc),
            NamedQuery::ExchangeRateList => ("rate_date", SortDir::Desc),
            NamedQuery::SavedViewList { .. } | NamedQuery::CustomFieldList { .. } => {
                ("name", SortDir::Asc)
            }
            NamedQuery::SettingList => ("key", SortDir::Asc),
            NamedQuery::CompanyList { .. }
            | NamedQuery::ProductList { .. }
            | NamedQuery::PriceListList { .. }
            | NamedQuery::TagList { .. }
            | NamedQuery::UserList { .. } => ("name", SortDir::Asc),
            _ => ("created_at", SortDir::Desc),
        }
    }

    /// Build the statement. Returns the SQL and its bound parameters, in order.
    pub fn build(&self, params: &QueryParams) -> Result<(String, Vec<SqlValue>)> {
        if let Some(sql) = self.aggregate_sql() {
            return Ok((sql.to_string(), Vec::new()));
        }

        if let NamedQuery::RelatedCounts { scope, parent_ids } = self {
            if parent_ids.len() > MAX_IDS {
                return Err(SyncError::Validation(format!(
                    "RelatedCounts: at most {MAX_IDS} parents per query"
                )));
            }
            let (table, column) = scope.table_and_column();
            let mut binds: Vec<SqlValue> = Vec::new();
            let slots: Vec<String> = parent_ids
                .iter()
                .map(|id| {
                    binds.push(SqlValue::Integer(*id));
                    format!("?{}", binds.len())
                })
                .collect();
            if slots.is_empty() {
                // No parents means no rows; `IN ()` is not valid SQL.
                return Ok((
                    format!(
                        "SELECT {column} AS parent_id, COUNT(*) AS total FROM {table} WHERE 1 = 0 GROUP BY {column}"
                    ),
                    Vec::new(),
                ));
            }
            let sql = format!(
                "SELECT {column} AS parent_id, COUNT(*) AS total
                   FROM {table}
                  WHERE sync_state <> 'tombstone'
                    AND deleted_at IS NULL
                    AND {column} IN ({})
                  GROUP BY {column}",
                slots.join(", ")
            );
            return Ok((sql, binds));
        }

        let entity = self.entity();
        let mut p = Predicates::new();

        if !params.include_tombstones {
            p.wheres.push("sync_state <> 'tombstone'".to_string());
        }

        match self {
            NamedQuery::RowsByServerIds { entity, ids } => {
                if ids.len() > MAX_IDS {
                    return Err(SyncError::Validation(format!(
                        "RowsByServerIds: at most {MAX_IDS} ids per query"
                    )));
                }
                // A negative id addresses a row that has no server id yet, by `-rowid`.
                let (server_ids, local_ids): (Vec<i64>, Vec<i64>) =
                    ids.iter().partition(|id| **id > 0);
                let mut branches: Vec<String> = Vec::new();
                // `notifications` has no `server_id` column at all (protocol §6.1 P12): its
                // `client_id` *is* the server identity, so a positive id can never match and
                // the branch is dropped rather than generating invalid SQL.
                if !server_ids.is_empty() && crate::db::schema::spec_for(*entity).has_server_id {
                    let slots: Vec<String> = server_ids
                        .iter()
                        .map(|v| p.bind(SqlValue::Integer(*v)))
                        .collect();
                    branches.push(format!("server_id IN ({})", slots.join(", ")));
                }
                if !local_ids.is_empty() {
                    let slots: Vec<String> = local_ids
                        .iter()
                        .map(|v| p.bind(SqlValue::Integer(-*v)))
                        .collect();
                    branches.push(format!("rowid IN ({})", slots.join(", ")));
                }
                if branches.is_empty() {
                    p.wheres.push("1 = 0".to_string());
                } else {
                    p.wheres.push(format!("({})", branches.join(" OR ")));
                }
            }
            NamedQuery::RowsByClientIds { client_ids, .. } => {
                p.text_in("client_id", client_ids)?;
            }
            NamedQuery::DealsBoard { stage_client_ids } => {
                if stage_client_ids.is_empty() {
                    return Err(SyncError::Validation(
                        "DealsBoard requires at least one stage".into(),
                    ));
                }
                if stage_client_ids.len() > 64 {
                    return Err(SyncError::Validation("DealsBoard: too many stages".into()));
                }
                p.text_in("pipeline_stage_client_id", stage_client_ids)?;
                p.wheres.push("status = 'open'".to_string());
            }
            NamedQuery::DealsList {
                q,
                status,
                stage_id,
                owner_id,
                company_id,
                contact_id,
                tag_id,
                amount_min,
                amount_max,
                from,
                to,
            } => {
                p.free_text(&["title", "description"], q.as_ref());
                p.eq_text("status", status.as_ref());
                p.eq_int("pipeline_stage_id", *stage_id);
                p.eq_int("owner_id", *owner_id);
                p.eq_int("company_id", *company_id);
                p.eq_int("contact_id", *contact_id);
                p.has_tag(*tag_id);
                p.cmp_decimal("amount", ">=", *amount_min);
                p.cmp_decimal("amount", "<=", *amount_max);
                p.cmp_text("expected_close_date", ">=", from.as_ref());
                p.cmp_text("expected_close_date", "<=", to.as_ref());
            }
            NamedQuery::CompanyList {
                q,
                industry,
                owner_id,
                city,
                country,
                tag_id,
                from,
                to,
            } => {
                p.free_text(&["name", "email", "phone"], q.as_ref());
                p.eq_text("industry", industry.as_ref());
                p.eq_int("owner_id", *owner_id);
                p.eq_text("city", city.as_ref());
                p.eq_text("country", country.as_ref());
                p.has_tag(*tag_id);
                p.cmp_text("created_at", ">=", from.as_ref());
                p.cmp_text("created_at", "<=", to.as_ref());
            }
            NamedQuery::ContactList {
                q,
                company_id,
                owner_id,
                is_primary,
                city,
                tag_id,
                from,
                to,
            } => {
                p.free_text(
                    &["first_name", "last_name", "email", "phone", "mobile"],
                    q.as_ref(),
                );
                p.eq_int("company_id", *company_id);
                p.eq_int("owner_id", *owner_id);
                p.eq_bool("is_primary", *is_primary);
                p.eq_text("city", city.as_ref());
                p.has_tag(*tag_id);
                p.cmp_text("created_at", ">=", from.as_ref());
                p.cmp_text("created_at", "<=", to.as_ref());
            }
            NamedQuery::LeadList {
                q,
                status,
                source,
                owner_id,
                score_min,
                score_max,
                tag_id,
                from,
                to,
            } => {
                p.free_text(
                    &["first_name", "last_name", "email", "phone", "company_name"],
                    q.as_ref(),
                );
                p.eq_text("status", status.as_ref());
                p.eq_text("source", source.as_ref());
                p.eq_int("owner_id", *owner_id);
                p.cmp_int("score", ">=", *score_min);
                p.cmp_int("score", "<=", *score_max);
                p.has_tag(*tag_id);
                p.cmp_text("created_at", ">=", from.as_ref());
                p.cmp_text("created_at", "<=", to.as_ref());
            }
            NamedQuery::TaskList {
                q,
                status,
                priority,
                assigned_to,
                created_by,
                taskable_type,
                taskable_id,
                overdue,
                from,
                to,
            } => {
                p.free_text(&["title", "description"], q.as_ref());
                p.eq_text("status", status.as_ref());
                p.eq_text("priority", priority.as_ref());
                p.eq_int("assigned_to", *assigned_to);
                p.eq_int("created_by", *created_by);
                p.eq_text("taskable_type", taskable_type.as_ref());
                p.eq_int("taskable_id", *taskable_id);
                p.flag(
                    *overdue,
                    "(due_at IS NOT NULL AND due_at < strftime('%Y-%m-%dT%H:%M:%SZ', 'now') \
                      AND status NOT IN ('completed', 'cancelled'))",
                );
                p.cmp_text("due_at", ">=", from.as_ref());
                p.cmp_text("due_at", "<=", to.as_ref());
            }
            NamedQuery::ActivityList {
                q,
                kind,
                user_id,
                activityable_type,
                activityable_id,
                from,
                to,
            } => {
                p.free_text(&["subject", "body"], q.as_ref());
                p.eq_text("type", kind.as_ref());
                p.eq_int("user_id", *user_id);
                p.eq_text("activityable_type", activityable_type.as_ref());
                p.eq_int("activityable_id", *activityable_id);
                p.cmp_text("occurred_at", ">=", from.as_ref());
                p.cmp_text("occurred_at", "<=", to.as_ref());
            }
            NamedQuery::TicketList {
                q,
                status,
                priority,
                assigned_to,
                company_id,
                contact_id,
                category,
                tag_id,
                sla_breached,
                from,
                to,
            } => {
                p.free_text(&["subject", "description", "ticket_number"], q.as_ref());
                p.eq_text("status", status.as_ref());
                p.eq_text("priority", priority.as_ref());
                p.eq_int("assigned_to", *assigned_to);
                p.eq_int("company_id", *company_id);
                p.eq_int("contact_id", *contact_id);
                p.eq_text("category", category.as_ref());
                p.has_tag(*tag_id);
                p.flag(
                    *sla_breached,
                    "(sla_due_at IS NOT NULL AND sla_due_at < strftime('%Y-%m-%dT%H:%M:%SZ', 'now') \
                      AND resolved_at IS NULL AND closed_at IS NULL)",
                );
                p.cmp_text("created_at", ">=", from.as_ref());
                p.cmp_text("created_at", "<=", to.as_ref());
            }
            NamedQuery::QuoteList {
                q,
                status,
                deal_id,
                company_id,
                contact_id,
                expired,
                from,
                to,
            } => {
                p.free_text(&["quote_number", "title"], q.as_ref());
                p.eq_text("status", status.as_ref());
                p.eq_int("deal_id", *deal_id);
                p.eq_int("company_id", *company_id);
                p.eq_int("contact_id", *contact_id);
                p.flag(*expired, "(valid_until IS NOT NULL AND valid_until < strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))");
                p.cmp_text("created_at", ">=", from.as_ref());
                p.cmp_text("created_at", "<=", to.as_ref());
            }
            NamedQuery::QuoteRevisionFamily { root_number } => {
                let trimmed = root_number.trim();
                if trimmed.is_empty() {
                    return Err(SyncError::Validation(
                        "QuoteRevisionFamily requires a root quote number".into(),
                    ));
                }
                let slot = p.bind(SqlValue::Text(format!("{trimmed}%")));
                p.wheres.push(format!("quote_number LIKE {slot}"));
            }
            NamedQuery::ConversationList { kind, q } => {
                p.eq_text("type", kind.as_ref());
                p.free_text(&["name"], q.as_ref());
            }
            NamedQuery::ConversationMessages {
                conversation_id,
                before_server_id,
            } => {
                p.eq_int("conversation_id", Some(*conversation_id));
                if let Some(before) = before_server_id {
                    let slot = p.bind(SqlValue::Integer(*before));
                    // Keyset over the cursor row's own timestamp: the id is the cursor the
                    // REST contract exposes, but `created_at` is what the index is on.
                    p.wheres.push(format!(
                        "created_at < (SELECT created_at FROM messages WHERE server_id = {slot})"
                    ));
                }
            }
            NamedQuery::ConversationMembership {
                user_id,
                conversation_id,
            } => {
                p.eq_int("user_id", *user_id);
                p.eq_int("conversation_id", *conversation_id);
            }
            NamedQuery::NotificationList { read } => match read {
                Some(ReadFilter::Unread) => p.wheres.push("read_at IS NULL".to_string()),
                Some(ReadFilter::Read) => p.wheres.push("read_at IS NOT NULL".to_string()),
                None => {}
            },
            NamedQuery::ProductList {
                q,
                category,
                is_active,
                tag_id,
                price_min,
                price_max,
                in_stock,
            } => {
                p.free_text(&["name", "sku", "description"], q.as_ref());
                p.eq_text("category", category.as_ref());
                p.eq_bool("is_active", *is_active);
                p.has_tag(*tag_id);
                p.cmp_decimal("unit_price", ">=", *price_min);
                p.cmp_decimal("unit_price", "<=", *price_max);
                p.flag(*in_stock, "(stock_quantity IS NOT NULL AND stock_quantity > 0)");
            }
            NamedQuery::PriceListList {
                q,
                is_active,
                is_default,
            } => {
                p.free_text(&["name", "code"], q.as_ref());
                p.eq_bool("is_active", *is_active);
                p.eq_bool("is_default", *is_default);
            }
            NamedQuery::PriceListItemList {
                price_list_id,
                product_id,
            } => {
                p.eq_int("price_list_id", *price_list_id);
                p.eq_int("product_id", *product_id);
            }
            NamedQuery::SavedViewList { module } => {
                p.eq_text("module", module.as_ref());
            }
            NamedQuery::TagList { q } => {
                p.free_text(&["name"], q.as_ref());
            }
            NamedQuery::CustomFieldList { entity_type } => {
                p.eq_text("entity_type", entity_type.as_ref());
            }
            NamedQuery::UserList { q, is_active } => {
                p.free_text(&["name", "email"], q.as_ref());
                p.eq_bool("is_active", *is_active);
            }
            NamedQuery::PendingRows { .. } => {
                p.wheres.clear();
                p.wheres
                    .push("sync_state IN ('pending', 'conflict')".to_string());
            }
            NamedQuery::PipelineStages
            | NamedQuery::ExchangeRateList
            | NamedQuery::SettingList => {}
            // All three return before this match: the first two from `aggregate_sql`, the
            // last from the `RelatedCounts` branch above.
            NamedQuery::TicketStats
            | NamedQuery::ProductCategories
            | NamedQuery::RelatedCounts { .. } => unreachable!(),
        }

        let table = entity.table();
        let where_clause = p.clause();

        if params.count_only {
            let sql = format!("SELECT COUNT(*) AS total FROM {table}{where_clause}");
            return Ok((sql, p.binds));
        }

        let (default_col, default_dir) = self.default_sort();
        let (sort_col, sort_dir) = match params.sort_by {
            Some(field) => (field.column(), params.sort_dir),
            None => (default_col, default_dir),
        };

        let limit = params.effective_limit();
        let limit_slot = p.bind(SqlValue::Integer(i64::from(limit)));
        let offset_slot = p.bind(SqlValue::Integer(i64::from(params.offset)));

        // `local_rowid` is what lets the adapter address a row that has no `server_id` yet.
        let sql = format!(
            "SELECT rowid AS local_rowid, * FROM {table}{where_clause} \
             ORDER BY {sort_col} {dir}, client_id ASC LIMIT {limit_slot} OFFSET {offset_slot}",
            dir = sort_dir.sql(),
        );

        Ok((sql, p.binds))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn deals_list() -> NamedQuery {
        NamedQuery::deals()
    }

    #[test]
    fn every_value_is_bound_not_interpolated() {
        let q = NamedQuery::DealsList {
            status: Some("open'; DROP TABLE deals; --".into()),
            q: None,
            stage_id: None,
            owner_id: None,
            company_id: None,
            contact_id: None,
            tag_id: None,
            amount_min: None,
            amount_max: None,
            from: None,
            to: None,
        };
        let (sql, binds) = q.build(&QueryParams::default()).unwrap();
        assert!(!sql.contains("DROP"), "value leaked into SQL: {sql}");
        assert!(matches!(binds[0], SqlValue::Text(_)));
    }

    #[test]
    fn free_text_is_bound_and_wrapped() {
        let q = NamedQuery::CompanyList {
            q: Some("acme%".into()),
            industry: None,
            owner_id: None,
            city: None,
            country: None,
            tag_id: None,
            from: None,
            to: None,
        };
        let (sql, binds) = q.build(&QueryParams::default()).unwrap();
        assert!(sql.contains("name LIKE ?1 OR email LIKE ?1 OR phone LIKE ?1"), "{sql}");
        assert_eq!(binds[0], SqlValue::Text("%acme%%".into()));
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
        let (sql, _) = deals_list().build(&params).unwrap();
        assert!(sql.contains("ORDER BY amount ASC"));
    }

    #[test]
    fn unknown_sort_column_is_not_resolvable() {
        assert!(SortField::from_column("amount; DROP TABLE deals").is_none());
        assert_eq!(SortField::from_column("amount"), Some(SortField::Amount));
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
        let (sql, _) = NamedQuery::companies()
            .build(&QueryParams::default())
            .unwrap();
        assert!(sql.contains("sync_state <> 'tombstone'"));
    }

    #[test]
    fn every_page_exposes_the_local_rowid() {
        let (sql, _) = deals_list().build(&QueryParams::default()).unwrap();
        assert!(sql.starts_with("SELECT rowid AS local_rowid, *"), "{sql}");
    }

    #[test]
    fn count_only_drops_paging_and_ordering() {
        let params = QueryParams {
            count_only: true,
            ..Default::default()
        };
        let (sql, binds) = deals_list().build(&params).unwrap();
        assert!(sql.starts_with("SELECT COUNT(*) AS total FROM deals"), "{sql}");
        assert!(!sql.contains("LIMIT"), "{sql}");
        assert!(!sql.contains("ORDER BY"), "{sql}");
        assert!(binds.is_empty(), "count must not bind paging values");
    }

    #[test]
    fn count_and_page_share_the_same_predicates() {
        let q = NamedQuery::TicketList {
            status: Some("open".into()),
            assigned_to: Some(7),
            q: None,
            priority: None,
            company_id: None,
            contact_id: None,
            category: None,
            tag_id: None,
            sla_breached: None,
            from: None,
            to: None,
        };
        let (page_sql, page_binds) = q.build(&QueryParams::default()).unwrap();
        let (count_sql, count_binds) = q
            .build(&QueryParams {
                count_only: true,
                ..Default::default()
            })
            .unwrap();
        let page_where = page_sql.split(" ORDER BY ").next().unwrap();
        let count_where = count_sql.split_once(" WHERE ").unwrap().1;
        assert!(page_where.ends_with(count_where), "{page_where} vs {count_where}");
        // The page adds exactly two binds: LIMIT and OFFSET.
        assert_eq!(page_binds.len(), count_binds.len() + 2);
    }

    #[test]
    fn negative_ids_address_the_local_rowid() {
        let (sql, binds) = NamedQuery::RowsByServerIds {
            entity: Entity::Deal,
            ids: vec![12, -3],
        }
        .build(&QueryParams::default())
        .unwrap();
        assert!(sql.contains("server_id IN (?1)"), "{sql}");
        assert!(sql.contains("rowid IN (?2)"), "{sql}");
        assert_eq!(binds[0], SqlValue::Integer(12));
        assert_eq!(binds[1], SqlValue::Integer(3));
    }

    #[test]
    fn notifications_are_addressed_by_client_id_only() {
        // Protocol §6.1 P12: this table has no `server_id`, so a positive id cannot match.
        let (sql, _) = NamedQuery::RowsByServerIds {
            entity: Entity::Notification,
            ids: vec![5],
        }
        .build(&QueryParams::default())
        .unwrap();
        assert!(sql.contains("1 = 0"), "{sql}");
    }

    #[test]
    fn empty_id_list_returns_nothing_not_everything() {
        let (sql, _) = NamedQuery::RowsByClientIds {
            entity: Entity::Company,
            client_ids: vec![],
        }
        .build(&QueryParams::default())
        .unwrap();
        assert!(sql.contains("1 = 0"), "{sql}");
    }

    #[test]
    fn id_lists_are_capped() {
        let err = NamedQuery::RowsByClientIds {
            entity: Entity::Company,
            client_ids: vec!["x".to_string(); MAX_IDS + 1],
        }
        .build(&QueryParams::default())
        .unwrap_err();
        assert!(matches!(err, SyncError::Validation(_)));
    }

    #[test]
    fn aggregates_ignore_paging_and_bind_nothing() {
        for q in [NamedQuery::TicketStats, NamedQuery::ProductCategories] {
            let (sql, binds) = q
                .build(&QueryParams {
                    limit: Some(3),
                    offset: 9,
                    count_only: true,
                    ..Default::default()
                })
                .unwrap();
            assert!(binds.is_empty(), "{sql}");
            assert!(!sql.contains("OFFSET"), "{sql}");
        }
    }

    #[test]
    fn revision_family_rejects_an_empty_root() {
        let err = NamedQuery::QuoteRevisionFamily {
            root_number: "  ".into(),
        }
        .build(&QueryParams::default())
        .unwrap_err();
        assert!(matches!(err, SyncError::Validation(_)));
    }

    #[test]
    fn message_cursor_is_a_bound_subquery() {
        let (sql, binds) = NamedQuery::ConversationMessages {
            conversation_id: 4,
            before_server_id: Some(90),
        }
        .build(&QueryParams::default())
        .unwrap();
        assert!(
            sql.contains("created_at < (SELECT created_at FROM messages WHERE server_id = ?2)"),
            "{sql}"
        );
        assert_eq!(binds[1], SqlValue::Integer(90));
    }

    #[test]
    fn read_filter_maps_to_both_directions() {
        let (unread, _) = NamedQuery::NotificationList {
            read: Some(ReadFilter::Unread),
        }
        .build(&QueryParams::default())
        .unwrap();
        let (read, _) = NamedQuery::NotificationList {
            read: Some(ReadFilter::Read),
        }
        .build(&QueryParams::default())
        .unwrap();
        let (both, _) = NamedQuery::NotificationList { read: None }
            .build(&QueryParams::default())
            .unwrap();
        assert!(unread.contains("read_at IS NULL"));
        assert!(read.contains("read_at IS NOT NULL"));
        assert!(!both.contains("read_at IS"));
    }

    #[test]
    fn related_counts_group_by_the_parent_and_bind_every_id() {
        let (sql, binds) = NamedQuery::RelatedCounts {
            scope: CountScope::CompanyDeals,
            parent_ids: vec![3, 9],
        }
        .build(&QueryParams::default())
        .unwrap();
        assert!(sql.contains("SELECT company_id AS parent_id, COUNT(*) AS total"), "{sql}");
        assert!(sql.contains("FROM deals"), "{sql}");
        assert!(sql.contains("company_id IN (?1, ?2)"), "{sql}");
        assert!(sql.trim_end().ends_with("GROUP BY company_id"), "{sql}");
        assert_eq!(binds, vec![SqlValue::Integer(3), SqlValue::Integer(9)]);
    }

    #[test]
    fn related_counts_with_no_parents_is_still_valid_sql() {
        let (sql, binds) = NamedQuery::RelatedCounts {
            scope: CountScope::PriceListItems,
            parent_ids: vec![],
        }
        .build(&QueryParams::default())
        .unwrap();
        assert!(!sql.contains("IN ()"), "{sql}");
        assert!(sql.contains("WHERE 1 = 0"), "{sql}");
        assert!(binds.is_empty());
    }

    #[test]
    fn each_query_targets_its_own_table() {
        let cases: Vec<(NamedQuery, &str)> = vec![
            (NamedQuery::PipelineStages, "pipeline_stages"),
            (NamedQuery::SettingList, "settings"),
            (NamedQuery::ExchangeRateList, "exchange_rates"),
            (NamedQuery::TagList { q: None }, "tags"),
            (
                NamedQuery::CustomFieldList { entity_type: None },
                "custom_fields",
            ),
            (NamedQuery::SavedViewList { module: None }, "saved_views"),
            (
                NamedQuery::ConversationMembership {
                    user_id: Some(1),
                    conversation_id: None,
                },
                "conversation_user",
            ),
            (
                NamedQuery::PriceListItemList {
                    price_list_id: None,
                    product_id: None,
                },
                "price_list_items",
            ),
        ];
        for (query, table) in cases {
            let (sql, _) = query.build(&QueryParams::default()).unwrap();
            assert!(sql.contains(&format!("FROM {table}")), "{sql}");
        }
    }
}
