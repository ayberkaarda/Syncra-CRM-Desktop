-- syncra-sync local schema (SQLCipher / SQLite).
--
-- Contract references:
--   SYNCDESKTOP.md §5.3            -- mirror table shape, outbox, conflicts, cursors, FTS
--   docs/DESKTOP-SYNC-PROTOCOL.md  -- §1.4 / §1.5 / §6.2 P13: taggables, quote_items and
--                                     custom_field_values are NOT mirror tables; they are
--                                     carried in the owning row's `tags`, `quotes.items`
--                                     and `custom_fields` columns.
--                                  -- §6.1 P12: notifications.client_id IS notifications.id;
--                                     this table has no `server_id INTEGER UNIQUE` column.
--                                  -- §2.5 K-C: one scalar cursor per table.
--
-- Every mirror table carries the sync envelope:
--   client_id TEXT PRIMARY KEY, server_id INTEGER UNIQUE (except notifications),
--   sync_state TEXT, server_sync_version INTEGER, local_updated_at TEXT, deleted_at TEXT
--
-- Foreign keys are carried twice: the raw server id (`*_id`) and the resolved local
-- reference (`*_client_id`), so a row created offline can point at a parent that does not
-- have a server id yet.

PRAGMA foreign_keys = OFF;

-- ---------------------------------------------------------------------------
-- RW mirror tables
-- ---------------------------------------------------------------------------

CREATE TABLE companies (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  name                TEXT,
  email               TEXT,
  phone               TEXT,
  website             TEXT,
  industry            TEXT,
  address             TEXT,
  city                TEXT,
  country             TEXT,
  employee_count      INTEGER,
  annual_revenue      TEXT,
  owner_id            INTEGER,
  owner_client_id     TEXT,
  notes               TEXT,
  tags                TEXT,
  custom_fields       TEXT,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);
CREATE INDEX idx_companies_name ON companies(name);
CREATE INDEX idx_companies_sync_state ON companies(sync_state);

CREATE TABLE contacts (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  first_name          TEXT,
  last_name           TEXT,
  email               TEXT,
  phone               TEXT,
  mobile              TEXT,
  position            TEXT,
  company_id          INTEGER,
  company_client_id   TEXT,
  owner_id            INTEGER,
  owner_client_id     TEXT,
  is_primary          INTEGER,
  address             TEXT,
  city                TEXT,
  country             TEXT,
  notes               TEXT,
  tags                TEXT,
  custom_fields       TEXT,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);
CREATE INDEX idx_contacts_company ON contacts(company_client_id);
CREATE INDEX idx_contacts_email ON contacts(email);
CREATE INDEX idx_contacts_sync_state ON contacts(sync_state);

CREATE TABLE leads (
  client_id                   TEXT PRIMARY KEY,
  server_id                   INTEGER UNIQUE,
  first_name                  TEXT,
  last_name                   TEXT,
  email                       TEXT,
  phone                       TEXT,
  company_name                TEXT,
  position                    TEXT,
  source                      TEXT,
  status                      TEXT,
  score                       INTEGER,
  owner_id                    INTEGER,
  owner_client_id             TEXT,
  converted_at                TEXT,
  converted_contact_id        INTEGER,
  converted_contact_client_id TEXT,
  converted_company_id        INTEGER,
  converted_company_client_id TEXT,
  converted_deal_id           INTEGER,
  converted_deal_client_id    TEXT,
  notes                       TEXT,
  tags                        TEXT,
  custom_fields               TEXT,
  created_at                  TEXT,
  updated_at                  TEXT,
  deleted_at                  TEXT,
  sync_state                  TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version         INTEGER NOT NULL DEFAULT 0,
  local_updated_at            TEXT
);
CREATE INDEX idx_leads_status ON leads(status);
CREATE INDEX idx_leads_sync_state ON leads(sync_state);

CREATE TABLE deals (
  client_id                TEXT PRIMARY KEY,
  server_id                INTEGER UNIQUE,
  title                    TEXT,
  description              TEXT,
  amount                   TEXT,
  currency                 TEXT,
  pipeline_stage_id        INTEGER,
  pipeline_stage_client_id TEXT,
  position                 TEXT,
  version                  INTEGER,
  probability              INTEGER,
  expected_close_date      TEXT,
  closed_at                TEXT,
  status                   TEXT,
  lost_reason              TEXT,
  won_reason               TEXT,
  company_id               INTEGER,
  company_client_id        TEXT,
  contact_id               INTEGER,
  contact_client_id        TEXT,
  owner_id                 INTEGER,
  owner_client_id          TEXT,
  tags                     TEXT,
  custom_fields            TEXT,
  created_at               TEXT,
  updated_at               TEXT,
  deleted_at               TEXT,
  sync_state               TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version      INTEGER NOT NULL DEFAULT 0,
  local_updated_at         TEXT
);
CREATE INDEX idx_deals_board ON deals(pipeline_stage_client_id, position);
CREATE INDEX idx_deals_status ON deals(status);
CREATE INDEX idx_deals_sync_state ON deals(sync_state);

CREATE TABLE tasks (
  client_id             TEXT PRIMARY KEY,
  server_id             INTEGER UNIQUE,
  title                 TEXT,
  description           TEXT,
  due_at                TEXT,
  reminder_at           TEXT,
  priority              TEXT,
  status                TEXT,
  completed_at          TEXT,
  assigned_to           INTEGER,
  assigned_to_client_id TEXT,
  created_by            INTEGER,
  created_by_client_id  TEXT,
  taskable_type         TEXT,
  taskable_id           INTEGER,
  created_at            TEXT,
  updated_at            TEXT,
  deleted_at            TEXT,
  sync_state            TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version   INTEGER NOT NULL DEFAULT 0,
  local_updated_at      TEXT
);
CREATE INDEX idx_tasks_due ON tasks(due_at);
CREATE INDEX idx_tasks_sync_state ON tasks(sync_state);

CREATE TABLE activities (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  type                TEXT,
  subject             TEXT,
  body                TEXT,
  occurred_at         TEXT,
  duration_minutes    INTEGER,
  outcome             TEXT,
  user_id             INTEGER,
  user_client_id      TEXT,
  activityable_type   TEXT,
  activityable_id     INTEGER,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);
CREATE INDEX idx_activities_occurred ON activities(occurred_at);
CREATE INDEX idx_activities_sync_state ON activities(sync_state);

CREATE TABLE tickets (
  client_id               TEXT PRIMARY KEY,
  server_id               INTEGER UNIQUE,
  ticket_number           TEXT,
  subject                 TEXT,
  description             TEXT,
  priority                TEXT,
  status                  TEXT,
  category                TEXT,
  contact_id              INTEGER,
  contact_client_id       TEXT,
  company_id              INTEGER,
  company_client_id       TEXT,
  assigned_to             INTEGER,
  assigned_to_client_id   TEXT,
  created_by              INTEGER,
  created_by_client_id    TEXT,
  sla_due_at              TEXT,
  first_response_at       TEXT,
  resolved_at             TEXT,
  closed_at               TEXT,
  sla_paused_at           TEXT,
  sla_paused_seconds      INTEGER,
  sla_warning_notified_at TEXT,
  sla_breach_notified_at  TEXT,
  tags                    TEXT,
  custom_fields           TEXT,
  created_at              TEXT,
  updated_at              TEXT,
  deleted_at              TEXT,
  sync_state              TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version     INTEGER NOT NULL DEFAULT 0,
  local_updated_at        TEXT
);
CREATE INDEX idx_tickets_status ON tickets(status);
CREATE INDEX idx_tickets_sync_state ON tickets(sync_state);

-- `items` carries the quote_items array (protocol §1.5 / §6.2 P13). quote_items is not a
-- mirror table and never appears in the pull set or in sync_deletions.
CREATE TABLE quotes (
  client_id              TEXT PRIMARY KEY,
  server_id              INTEGER UNIQUE,
  quote_number           TEXT,
  title                  TEXT,
  deal_id                INTEGER,
  deal_client_id         TEXT,
  parent_quote_id        INTEGER,
  parent_quote_client_id TEXT,
  revision               INTEGER,
  company_id             INTEGER,
  company_client_id      TEXT,
  contact_id             INTEGER,
  contact_client_id      TEXT,
  status                 TEXT,
  valid_until            TEXT,
  subtotal               TEXT,
  discount_amount        TEXT,
  discount_type          TEXT,
  discount_value         TEXT,
  tax_amount             TEXT,
  total                  TEXT,
  currency               TEXT,
  exchange_rate          TEXT,
  exchange_rate_date     TEXT,
  notes                  TEXT,
  terms                  TEXT,
  sent_at                TEXT,
  accepted_at            TEXT,
  rejected_at            TEXT,
  created_by             INTEGER,
  created_by_client_id   TEXT,
  items                  TEXT,
  custom_fields          TEXT,
  created_at             TEXT,
  updated_at             TEXT,
  deleted_at             TEXT,
  sync_state             TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version    INTEGER NOT NULL DEFAULT 0,
  local_updated_at       TEXT
);
CREATE INDEX idx_quotes_status ON quotes(status);
CREATE INDEX idx_quotes_sync_state ON quotes(sync_state);

CREATE TABLE conversations (
  client_id            TEXT PRIMARY KEY,
  server_id            INTEGER UNIQUE,
  type                 TEXT,
  name                 TEXT,
  conversable_type     TEXT,
  conversable_id       INTEGER,
  created_by           INTEGER,
  created_by_client_id TEXT,
  last_message_at      TEXT,
  created_at           TEXT,
  updated_at           TEXT,
  deleted_at           TEXT,
  sync_state           TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version  INTEGER NOT NULL DEFAULT 0,
  local_updated_at     TEXT
);
CREATE INDEX idx_conversations_last_message ON conversations(last_message_at);
CREATE INDEX idx_conversations_sync_state ON conversations(sync_state);

CREATE TABLE messages (
  client_id              TEXT PRIMARY KEY,
  server_id              INTEGER UNIQUE,
  conversation_id        INTEGER,
  conversation_client_id TEXT,
  user_id                INTEGER,
  user_client_id         TEXT,
  body                   TEXT,
  attachment_id          INTEGER,
  type                   TEXT,
  edited_at              TEXT,
  created_at             TEXT,
  updated_at             TEXT,
  deleted_at             TEXT,
  sync_state             TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version    INTEGER NOT NULL DEFAULT 0,
  local_updated_at       TEXT
);
CREATE INDEX idx_messages_conv ON messages(conversation_client_id, created_at);
CREATE INDEX idx_messages_sync_state ON messages(sync_state);

-- Tombstone row_key for this table is `conversation_id:user_id` (protocol §2.7), not the
-- surrogate id, so both columns stay addressable.
CREATE TABLE conversation_user (
  client_id              TEXT PRIMARY KEY,
  server_id              INTEGER UNIQUE,
  conversation_id        INTEGER,
  conversation_client_id TEXT,
  user_id                INTEGER,
  user_client_id         TEXT,
  last_read_message_id   INTEGER,
  unread_count           INTEGER,
  joined_at              TEXT,
  is_muted               INTEGER,
  created_at             TEXT,
  updated_at             TEXT,
  deleted_at             TEXT,
  sync_state             TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version    INTEGER NOT NULL DEFAULT 0,
  local_updated_at       TEXT
);
CREATE UNIQUE INDEX idx_conversation_user_pair ON conversation_user(conversation_id, user_id);

-- Protocol §6.1 P12: notifications.id is already a CHAR(36) UUID, so client_id IS the
-- server id. No `server_id INTEGER UNIQUE` column exists here, and the
-- uuid5(namespace, "entity:server_id") derivation of §5.3 is structurally inapplicable.
CREATE TABLE notifications (
  client_id           TEXT PRIMARY KEY,
  type                TEXT,
  notifiable_type     TEXT,
  notifiable_id       INTEGER,
  data                TEXT,
  read_at             TEXT,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);
CREATE INDEX idx_notifications_read ON notifications(read_at);

CREATE TABLE tags (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  name                TEXT,
  slug                TEXT,
  color               TEXT,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);

-- ---------------------------------------------------------------------------
-- RO mirror tables
-- ---------------------------------------------------------------------------

CREATE TABLE pipeline_stages (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  name                TEXT,
  slug                TEXT,
  position            INTEGER,
  probability         INTEGER,
  color               TEXT,
  is_won              INTEGER,
  is_lost             INTEGER,
  is_active           INTEGER,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);
CREATE INDEX idx_pipeline_stages_position ON pipeline_stages(position);

CREATE TABLE custom_fields (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  entity_type         TEXT,
  name                TEXT,
  key                 TEXT,
  type                TEXT,
  options             TEXT,
  is_required         INTEGER,
  position            INTEGER,
  is_active           INTEGER,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);

CREATE TABLE products (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  name                TEXT,
  sku                 TEXT,
  description         TEXT,
  category            TEXT,
  unit_price          TEXT,
  currency            TEXT,
  tax_rate            TEXT,
  unit                TEXT,
  stock_quantity      INTEGER,
  is_active           INTEGER,
  tags                TEXT,
  custom_fields       TEXT,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);

CREATE TABLE price_lists (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  name                TEXT,
  code                TEXT,
  description         TEXT,
  currency            TEXT,
  is_default          INTEGER,
  is_active           INTEGER,
  valid_from          TEXT,
  valid_until         TEXT,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);

CREATE TABLE price_list_items (
  client_id            TEXT PRIMARY KEY,
  server_id            INTEGER UNIQUE,
  price_list_id        INTEGER,
  price_list_client_id TEXT,
  product_id           INTEGER,
  product_client_id    TEXT,
  unit_price           TEXT,
  created_at           TEXT,
  updated_at           TEXT,
  deleted_at           TEXT,
  sync_state           TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version  INTEGER NOT NULL DEFAULT 0,
  local_updated_at     TEXT
);

CREATE TABLE exchange_rates (
  client_id            TEXT PRIMARY KEY,
  server_id            INTEGER UNIQUE,
  currency             TEXT,
  rate                 TEXT,
  unit                 INTEGER,
  rate_date            TEXT,
  source               TEXT,
  entered_by           INTEGER,
  entered_by_client_id TEXT,
  created_at           TEXT,
  updated_at           TEXT,
  deleted_at           TEXT,
  sync_state           TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version  INTEGER NOT NULL DEFAULT 0,
  local_updated_at     TEXT
);

CREATE TABLE saved_views (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  user_id             INTEGER,
  user_client_id      TEXT,
  module              TEXT,
  name                TEXT,
  query_json          TEXT,
  is_shared           INTEGER,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);

CREATE TABLE settings (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  key                 TEXT,
  value               TEXT,
  type                TEXT,
  group_name          TEXT,
  is_public           INTEGER,
  description         TEXT,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);

-- SYNCDESKTOP §4.1: users is RO with a fixed projection; no other column may be stored.
CREATE TABLE users (
  client_id           TEXT PRIMARY KEY,
  server_id           INTEGER UNIQUE,
  name                TEXT,
  email               TEXT,
  avatar_url          TEXT,
  is_active           INTEGER,
  department          TEXT,
  created_at          TEXT,
  updated_at          TEXT,
  deleted_at          TEXT,
  sync_state          TEXT NOT NULL DEFAULT 'synced' CHECK(sync_state IN ('synced','pending','conflict','tombstone')),
  server_sync_version INTEGER NOT NULL DEFAULT 0,
  local_updated_at    TEXT
);

-- ---------------------------------------------------------------------------
-- Engine tables
-- ---------------------------------------------------------------------------

CREATE TABLE outbox (
  id                TEXT PRIMARY KEY,
  seq               INTEGER UNIQUE,
  idempotency_key   TEXT UNIQUE,
  entity            TEXT NOT NULL,
  op                TEXT NOT NULL,
  action            TEXT,
  scope             TEXT,
  client_id         TEXT,
  server_id         INTEGER,
  base_sync_version INTEGER,
  changed_fields    TEXT,
  payload           TEXT,
  occurred_at       TEXT,
  attempts          INTEGER NOT NULL DEFAULT 0,
  last_error        TEXT,
  state             TEXT NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','inflight','failed'))
);
CREATE INDEX idx_outbox_state ON outbox(state);
CREATE INDEX idx_outbox_client ON outbox(entity, client_id);

CREATE TABLE conflicts (
  id                 TEXT PRIMARY KEY,
  outbox_id          TEXT,
  entity             TEXT,
  client_id          TEXT,
  code               TEXT,
  conflicting_fields TEXT,
  mine               TEXT,
  theirs             TEXT,
  created_at         TEXT
);

-- Protocol §2.5 K-C: one scalar cursor per table. There is no composite
-- (sync_version, id) cursor -- the server guarantees a unique version per row.
CREATE TABLE cursors (
  table_name   TEXT PRIMARY KEY,
  sync_version INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE desktop_settings (
  key   TEXT PRIMARY KEY,
  value TEXT
);

CREATE TABLE cached_files (
  id         TEXT PRIMARY KEY,
  kind       TEXT,
  ref        TEXT,
  path       TEXT,
  bytes      INTEGER,
  fetched_at TEXT
);

-- When a pull lands on a row that still has unpushed local edits, the server row is kept
-- here as "theirs" instead of overwriting the pending fields (SYNCDESKTOP §5.5).
CREATE TABLE pending_shadows (
  entity              TEXT NOT NULL,
  client_id           TEXT NOT NULL,
  row_json            TEXT NOT NULL,
  server_sync_version INTEGER NOT NULL,
  PRIMARY KEY (entity, client_id)
);

CREATE VIRTUAL TABLE fts_records USING fts5(
  entity UNINDEXED,
  client_id UNINDEXED,
  title,
  body,
  tokenize='unicode61 remove_diacritics 2'
);

-- ---------------------------------------------------------------------------
-- FTS triggers (SYNCDESKTOP §5.3)
--
-- `syncra_fold` is a Rust scalar function registered on every connection; it applies the
-- same `to_lowercase()` normalisation used on the query side, so Turkish dotted/dotless
-- I round-trips identically on both ends of the index.
-- ---------------------------------------------------------------------------

CREATE TRIGGER fts_companies_ai AFTER INSERT ON companies BEGIN
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('company', NEW.client_id, syncra_fold(NEW.name),
          syncra_fold(COALESCE(NEW.email,'') || ' ' || COALESCE(NEW.phone,'') || ' ' || COALESCE(NEW.city,'')));
END;
CREATE TRIGGER fts_companies_ad AFTER DELETE ON companies BEGIN
  DELETE FROM fts_records WHERE entity = 'company' AND client_id = OLD.client_id;
END;
CREATE TRIGGER fts_companies_au AFTER UPDATE ON companies BEGIN
  DELETE FROM fts_records WHERE entity = 'company' AND client_id = OLD.client_id;
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('company', NEW.client_id, syncra_fold(NEW.name),
          syncra_fold(COALESCE(NEW.email,'') || ' ' || COALESCE(NEW.phone,'') || ' ' || COALESCE(NEW.city,'')));
END;

CREATE TRIGGER fts_contacts_ai AFTER INSERT ON contacts BEGIN
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('contact', NEW.client_id,
          syncra_fold(COALESCE(NEW.first_name,'') || ' ' || COALESCE(NEW.last_name,'')),
          syncra_fold(COALESCE(NEW.email,'') || ' ' || COALESCE(NEW.phone,'') || ' ' || COALESCE(NEW.mobile,'')));
END;
CREATE TRIGGER fts_contacts_ad AFTER DELETE ON contacts BEGIN
  DELETE FROM fts_records WHERE entity = 'contact' AND client_id = OLD.client_id;
END;
CREATE TRIGGER fts_contacts_au AFTER UPDATE ON contacts BEGIN
  DELETE FROM fts_records WHERE entity = 'contact' AND client_id = OLD.client_id;
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('contact', NEW.client_id,
          syncra_fold(COALESCE(NEW.first_name,'') || ' ' || COALESCE(NEW.last_name,'')),
          syncra_fold(COALESCE(NEW.email,'') || ' ' || COALESCE(NEW.phone,'') || ' ' || COALESCE(NEW.mobile,'')));
END;

CREATE TRIGGER fts_leads_ai AFTER INSERT ON leads BEGIN
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('lead', NEW.client_id,
          syncra_fold(COALESCE(NEW.first_name,'') || ' ' || COALESCE(NEW.last_name,'')),
          syncra_fold(COALESCE(NEW.email,'') || ' ' || COALESCE(NEW.phone,'') || ' ' || COALESCE(NEW.company_name,'')));
END;
CREATE TRIGGER fts_leads_ad AFTER DELETE ON leads BEGIN
  DELETE FROM fts_records WHERE entity = 'lead' AND client_id = OLD.client_id;
END;
CREATE TRIGGER fts_leads_au AFTER UPDATE ON leads BEGIN
  DELETE FROM fts_records WHERE entity = 'lead' AND client_id = OLD.client_id;
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('lead', NEW.client_id,
          syncra_fold(COALESCE(NEW.first_name,'') || ' ' || COALESCE(NEW.last_name,'')),
          syncra_fold(COALESCE(NEW.email,'') || ' ' || COALESCE(NEW.phone,'') || ' ' || COALESCE(NEW.company_name,'')));
END;

CREATE TRIGGER fts_deals_ai AFTER INSERT ON deals BEGIN
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('deal', NEW.client_id, syncra_fold(NEW.title),
          syncra_fold(COALESCE((SELECT name FROM companies WHERE client_id = NEW.company_client_id), '')));
END;
CREATE TRIGGER fts_deals_ad AFTER DELETE ON deals BEGIN
  DELETE FROM fts_records WHERE entity = 'deal' AND client_id = OLD.client_id;
END;
CREATE TRIGGER fts_deals_au AFTER UPDATE ON deals BEGIN
  DELETE FROM fts_records WHERE entity = 'deal' AND client_id = OLD.client_id;
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('deal', NEW.client_id, syncra_fold(NEW.title),
          syncra_fold(COALESCE((SELECT name FROM companies WHERE client_id = NEW.company_client_id), '')));
END;

CREATE TRIGGER fts_tickets_ai AFTER INSERT ON tickets BEGIN
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('ticket', NEW.client_id, syncra_fold(NEW.subject), syncra_fold(COALESCE(NEW.ticket_number,'')));
END;
CREATE TRIGGER fts_tickets_ad AFTER DELETE ON tickets BEGIN
  DELETE FROM fts_records WHERE entity = 'ticket' AND client_id = OLD.client_id;
END;
CREATE TRIGGER fts_tickets_au AFTER UPDATE ON tickets BEGIN
  DELETE FROM fts_records WHERE entity = 'ticket' AND client_id = OLD.client_id;
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('ticket', NEW.client_id, syncra_fold(NEW.subject), syncra_fold(COALESCE(NEW.ticket_number,'')));
END;

CREATE TRIGGER fts_quotes_ai AFTER INSERT ON quotes BEGIN
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('quote', NEW.client_id, syncra_fold(COALESCE(NEW.quote_number,'')), syncra_fold(COALESCE(NEW.title,'')));
END;
CREATE TRIGGER fts_quotes_ad AFTER DELETE ON quotes BEGIN
  DELETE FROM fts_records WHERE entity = 'quote' AND client_id = OLD.client_id;
END;
CREATE TRIGGER fts_quotes_au AFTER UPDATE ON quotes BEGIN
  DELETE FROM fts_records WHERE entity = 'quote' AND client_id = OLD.client_id;
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('quote', NEW.client_id, syncra_fold(COALESCE(NEW.quote_number,'')), syncra_fold(COALESCE(NEW.title,'')));
END;

CREATE TRIGGER fts_messages_ai AFTER INSERT ON messages BEGIN
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('message', NEW.client_id, '', syncra_fold(COALESCE(NEW.body,'')));
END;
CREATE TRIGGER fts_messages_ad AFTER DELETE ON messages BEGIN
  DELETE FROM fts_records WHERE entity = 'message' AND client_id = OLD.client_id;
END;
CREATE TRIGGER fts_messages_au AFTER UPDATE ON messages BEGIN
  DELETE FROM fts_records WHERE entity = 'message' AND client_id = OLD.client_id;
  INSERT INTO fts_records(entity, client_id, title, body)
  VALUES ('message', NEW.client_id, '', syncra_fold(COALESCE(NEW.body,'')));
END;
