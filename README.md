# Nimbus SaaS

A clean, zero-bloat, multi-tenant SaaS starter, positioned as practice management
for accounting firms — clients, engagements, and deadlines on top of the same
tenant-isolated core. Raw object-oriented **PHP + PDO** over an indexed **SQLite**
database, hydrated by **vanilla JS** — no framework, no bundler. Booted locally
with a single **Python** launcher.

> **Naming note**: the UI says "Clients" and "Engagements", but the underlying
> tables/models/routes are still `contacts`/`Contact`/`/api/contacts` and
> `projects`/`Project`/`/api/projects` — a deliberate light rebrand (relabel +
> add accounting-specific fields) rather than a full rename. See
> [Clients](#clients-mini-crm) and [Engagements](#engagements--tasks) below.

## Quick start

**1. Pull the code**

```bash
# clone (first time)
git clone https://github.com/calebprince/sass-app.git
cd sass-app
git checkout claude/saas-platform-architecture-cf8qsl

# already cloned — grab the latest
git pull origin claude/saas-platform-architecture-cf8qsl
```

**2. Start the server**

```bash
python server.py                    # → http://127.0.0.1:8000
# options:
python server.py --port 9000        # different port
python server.py --host 0.0.0.0     # expose on your LAN
```

Then open **http://127.0.0.1:8000** and sign in with a
[seeded demo account](#seeded-demo-accounts) — or register a fresh company.

The first boot creates the SQLite file, runs the migration schema, and seeds demo
data automatically. No build step, no `composer install`, no npm. Press `Ctrl+C`
to stop. To reset everything to a clean slate, delete `storage/app.sqlite` and
restart.

## The four layers

| # | Layer | Route | Notes |
|---|-------|-------|-------|
| 1 | **Public marketing site** | `/` | Hero, how-it-works, feature deep-dive, tenant-isolation explainer, pricing tiers (Starter / Pro / Enterprise), FAQ, CTAs into registration — copy positioned for accounting firms. |
| 2 | **Authentication** | `/register`, `/login` | Session-based auth, CSRF-guarded, password rules, fixation-safe login, role-based post-auth redirect. |
| 3 | **Client Control Center** | `/app` | Private per-tenant dashboard — usage meter, live Clients/Engagements stat tiles and activity feed, a Clients tab (tier-limited client manager with entity type/tax ID/fiscal year end), an Engagements tab (engagement/task tracker with type, deadline, and assignees), and a Subscription tab (plan, features, invoices). |
| 4 | **Global Admin Console** | `/admin` | Super-admin only: live metrics (active users, MRR/ARR), user/tenant table with ban + tier + limit overrides, audit log, system switches. |

## Seeded demo accounts

| Role | Email | Password |
|------|-------|----------|
| Platform operator | `admin@nimbus.test` | `Admin1234` |
| Acme Corp (Pro) | `owner@acme.test` | `Acme1234` |
| Globex Inc (Starter) | `owner@globex.test` | `Globex1234` |

## Multi-tenant isolation

Every tenant-owned row carries an explicit `tenant_id`. The client-facing
controllers **only ever** query with the tenant id taken from the server session
(`Session::tenantId()`) — the browser never supplies it. Company A therefore
cannot address, let alone read, Company B's rows. The admin console deliberately
crosses that boundary, but only behind the `super_admin` guard.

## Project layout

```
server.py                 # Python dev launcher → boots `php -S` with router.php
router.php                # Built-in-server router (static passthrough + front controller)
config/config.php         # App config: security, tiers, roles
database/
  schema.sql              # Migration schema (tables + indexes)
  seed.php                # First-run demo data
src/
  Core/                   # Database (PDO), Router, Request, Response, Session, Controller, Migration
  Middleware/Guards.php   # auth / active / csrf / admin guards
  Models/                 # Tenant, User, Subscription, Invoice, UsageLog, Contact, Project, Task, AuditLog, GlobalSetting
  Controllers/            # Auth, Dashboard, Subscription, Contact, Project, Task, Admin
public/
  index.php               # Front controller: autoload → boot DB → routes → dispatch
  assets/css/app.css      # Minimalist utility stylesheet
  assets/js/              # api.js (fetch wrapper) + dashboard.js + contacts.js + projects.js + admin.js
  views/                  # landing / login / register / app / admin HTML shells
storage/app.sqlite        # Generated on first run (gitignored)
```

## Database schema

Core tables — `tenants`, `users`, `subscriptions`, `usage_logs`, `contacts`,
`projects`, `tasks`, `global_settings` — plus supporting `invoices` and
`audit_logs`. All queries use
**prepared statements** through the `Database` wrapper, so user input is always
bound, never concatenated. See [`database/schema.sql`](database/schema.sql) for
the indexed DDL.

## API surface

```
POST /api/auth/register        POST /api/auth/login       POST /api/auth/logout
GET  /api/auth/me              POST /api/auth/password

GET  /api/dashboard/overview   POST /api/dashboard/track
GET  /api/subscription         POST /api/subscription/change

GET  /api/contacts             POST /api/contacts
POST /api/contacts/{id}        POST /api/contacts/{id}/delete

GET  /api/projects             POST /api/projects
POST /api/projects/{id}        POST /api/projects/{id}/delete
GET  /api/projects/{id}/tasks  POST /api/projects/{id}/tasks
POST /api/tasks/{id}           POST /api/tasks/{id}/delete
GET  /api/team

GET  /api/admin/metrics        GET  /api/admin/users       GET  /api/admin/audit
POST /api/admin/users/{id}/status
POST /api/admin/tenants/{id}/tier
POST /api/admin/tenants/{id}/limit
POST /api/admin/tenants/{id}/status
POST /api/admin/settings
```

## Clients (mini CRM)

Every tenant gets a private client list in the **Clients** tab of the Control
Center — name, email, phone, company, status (lead / active / customer /
inactive), notes, and three accounting-specific fields: entity type (individual,
sole prop, partnership, LLC, S-Corp, C-Corp, nonprofit, trust/estate, other), tax
ID, and fiscal year end. `tax_id` is a **plaintext demo field only** — not
encrypted, not production-ready PII handling. Client count is capped per
subscription tier via `max_contacts` in [`config/config.php`](config/config.php),
enforced the same way the usage-event `resource_limit` is enforced. Backed by the
`contacts` table / `Contact` model — see [`src/Models/Contact.php`](src/Models/Contact.php)
and [`src/Controllers/ContactController.php`](src/Controllers/ContactController.php).

## Engagements & tasks

Every tenant also gets a lightweight engagement tracker in the **Engagements**
tab — create engagements (tax return, bookkeeping, audit, advisory, payroll,
other) with a deadline, then select one to manage its tasks (title, description,
status, assignee, due date). Assignees are drawn only from that tenant's own team
(`GET /api/team`), so a task can never be handed to a user outside the firm.
Engagement count is capped per subscription tier via `max_projects` in
[`config/config.php`](config/config.php), the same enforcement pattern as
Clients. Deleting an engagement cascades to its tasks (`ON DELETE CASCADE`).
Backed by the `projects`/`tasks` tables / `Project`/`Task` models — see
[`src/Models/Project.php`](src/Models/Project.php),
[`src/Models/Task.php`](src/Models/Task.php),
[`src/Controllers/ProjectController.php`](src/Controllers/ProjectController.php), and
[`src/Controllers/TaskController.php`](src/Controllers/TaskController.php).

## Overview activity feed

Creating a client, creating an engagement or task, and marking a task done all
log a `UsageLog` entry (`contact.created`, `project.created`, `task.created`,
`task.completed`), the same table that already backs auth events and the demo
"Simulate work" action. The Overview tab's Recent Activity table and its Clients /
Engagements stat tiles (clickable to jump to that tab) read from this feed and
from `Contact::countForTenant()` / `Project::countForTenant()`, so the landing
dashboard stays current with what's actually happening in the account rather than
only showing login history.

## Evolving the schema after launch

`Migration::run()` (`src/Core/Migration.php`) originally only ever ran
`CREATE TABLE IF NOT EXISTS` statements from `schema.sql` — safe to repeat, but
unable to add a column to a table that already exists on a booted database. It
now also calls `Migration::addColumnIfMissing()`, which checks
`PRAGMA table_info()` before running an `ALTER TABLE ... ADD COLUMN`, so adding a
column to an existing table (as done for `contacts.entity_type`/`tax_id`/
`fiscal_year_end` and `projects.engagement_type`/`deadline`) is safe on both a
fresh install and an already-running database — no need to delete
`storage/app.sqlite` when the schema grows.

## Requirements

- PHP 8.1+ with the `pdo_sqlite` extension
- Python 3.8+ (launcher only — no packages required)
