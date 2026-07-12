-- =============================================================================
--  Nimbus SaaS — Database Migration Schema (SQLite)
-- =============================================================================
--  Design principles:
--    * Every tenant-owned row carries an explicit tenant_id. That column is
--      the multi-tenant barrier: application queries ALWAYS filter on it, and
--      foreign keys keep the graph honest.
--    * Indexes are declared next to the tables they serve, tuned for the exact
--      lookups the controllers perform (auth by email, usage by tenant+time,
--      invoices by tenant+status, audit trail by actor+time).
--    * ON DELETE CASCADE removes a company's entire footprint atomically.
-- =============================================================================

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;      -- concurrent reads while a writer holds the lock

-- ----------------------------------------------------------------------------
--  tenants — one row per customer company. The root of every isolation scope.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid        TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    slug        TEXT    NOT NULL UNIQUE,
    status      TEXT    NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active', 'suspended')),
    created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_tenants_status ON tenants(status);

-- ----------------------------------------------------------------------------
--  users — authentication principals. Each belongs to exactly one tenant.
--  role drives authorization; super_admins sit on the system tenant (id 1).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid           TEXT    NOT NULL UNIQUE,
    tenant_id      INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    email          TEXT    NOT NULL UNIQUE,
    password_hash  TEXT    NOT NULL,
    name           TEXT    NOT NULL DEFAULT '',
    role           TEXT    NOT NULL DEFAULT 'client'
                       CHECK (role IN ('client', 'owner', 'super_admin')),
    status         TEXT    NOT NULL DEFAULT 'active'
                       CHECK (status IN ('active', 'banned')),
    last_login_at  TEXT,
    created_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_users_tenant      ON users(tenant_id);
CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role, status);
-- Case-insensitive email lookups for the login path.
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_ci ON users(lower(email));

-- ----------------------------------------------------------------------------
--  subscriptions — exactly one active plan per tenant. Mirrors config['tiers'].
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscriptions (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id             INTEGER NOT NULL UNIQUE REFERENCES tenants(id) ON DELETE CASCADE,
    tier                  TEXT    NOT NULL DEFAULT 'starter'
                              CHECK (tier IN ('starter', 'pro', 'enterprise')),
    status                TEXT    NOT NULL DEFAULT 'active'
                              CHECK (status IN ('active', 'trialing', 'past_due', 'canceled')),
    seats                 INTEGER NOT NULL DEFAULT 2,
    resource_limit        INTEGER NOT NULL DEFAULT 1000,
    current_period_start  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    current_period_end    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '+30 days')),
    created_at            TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at            TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_subscriptions_status ON subscriptions(status);
CREATE INDEX IF NOT EXISTS idx_subscriptions_tier   ON subscriptions(tier);

-- ----------------------------------------------------------------------------
--  invoices — billing history surfaced in the client "Subscription" tab.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid             TEXT    NOT NULL UNIQUE,
    tenant_id        INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    subscription_id  INTEGER REFERENCES subscriptions(id) ON DELETE SET NULL,
    number           TEXT    NOT NULL,
    amount_cents     INTEGER NOT NULL DEFAULT 0,
    currency         TEXT    NOT NULL DEFAULT 'USD',
    status           TEXT    NOT NULL DEFAULT 'paid'
                         CHECK (status IN ('paid', 'open', 'void')),
    period_start     TEXT    NOT NULL,
    period_end       TEXT    NOT NULL,
    issued_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    created_at       TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_invoices_tenant_status ON invoices(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_invoices_issued        ON invoices(issued_at);

-- ----------------------------------------------------------------------------
--  usage_logs — per-tenant activity feed and the meter for resource_limit.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usage_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action      TEXT    NOT NULL,
    resource    TEXT    NOT NULL DEFAULT '',
    quantity    INTEGER NOT NULL DEFAULT 1,
    metadata    TEXT    NOT NULL DEFAULT '{}',   -- JSON blob
    created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_usage_tenant_time ON usage_logs(tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_usage_user        ON usage_logs(user_id);

-- ----------------------------------------------------------------------------
--  contacts — per-tenant CRM records (leads/customers). Count is capped by
--  the tier's max_contacts limit, enforced the same way usage_logs enforces
--  resource_limit.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contacts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid        TEXT    NOT NULL UNIQUE,
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name        TEXT    NOT NULL,
    email       TEXT    NOT NULL DEFAULT '',
    phone       TEXT    NOT NULL DEFAULT '',
    company     TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'lead'
                    CHECK (status IN ('lead', 'active', 'customer', 'inactive')),
    notes       TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_contacts_tenant_status ON contacts(tenant_id, status);

-- ----------------------------------------------------------------------------
--  projects / tasks — per-tenant lightweight project tracker. Project count is
--  capped by the tier's max_projects limit; tasks belong to exactly one project
--  and carry tenant_id directly (never inferred via join), same convention as
--  every other tenant-owned table.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid        TEXT    NOT NULL UNIQUE,
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_projects_tenant_status ON projects(tenant_id, status);

CREATE TABLE IF NOT EXISTS tasks (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid              TEXT    NOT NULL UNIQUE,
    tenant_id         INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    project_id        INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title             TEXT    NOT NULL,
    description       TEXT    NOT NULL DEFAULT '',
    status            TEXT    NOT NULL DEFAULT 'todo'
                          CHECK (status IN ('todo', 'in_progress', 'done')),
    assignee_user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    due_date          TEXT,
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_tasks_tenant_project ON tasks(tenant_id, project_id);
CREATE INDEX IF NOT EXISTS idx_tasks_assignee        ON tasks(assignee_user_id);

-- ----------------------------------------------------------------------------
--  audit_logs — immutable trail of privileged (admin override) actions.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action         TEXT    NOT NULL,
    target_type    TEXT    NOT NULL DEFAULT '',
    target_id      TEXT    NOT NULL DEFAULT '',
    detail         TEXT    NOT NULL DEFAULT '{}',   -- JSON blob
    ip_address     TEXT    NOT NULL DEFAULT '',
    created_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_actor_time ON audit_logs(actor_user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_action     ON audit_logs(action);

-- ----------------------------------------------------------------------------
--  global_settings — platform-wide key/value knobs for the operator console.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS global_settings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    key         TEXT    NOT NULL UNIQUE,
    value       TEXT    NOT NULL DEFAULT '',
    updated_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
