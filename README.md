# Nimbus SaaS

A clean, zero-bloat, multi-tenant SaaS starter. Raw object-oriented **PHP + PDO**
over an indexed **SQLite** database, hydrated by **vanilla JS** — no framework, no
bundler. Booted locally with a single **Python** launcher.

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
| 1 | **Public marketing site** | `/` | Hero, how-it-works, feature deep-dive, tenant-isolation explainer, pricing tiers (Starter / Pro / Enterprise), FAQ, CTAs into registration. |
| 2 | **Authentication** | `/register`, `/login` | Session-based auth, CSRF-guarded, password rules, fixation-safe login, role-based post-auth redirect. |
| 3 | **Client Control Center** | `/app` | Private per-tenant dashboard, usage meter, a Contacts tab (tier-limited lead/customer manager), and a Subscription tab (plan, features, invoices). |
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
  Models/                 # Tenant, User, Subscription, Invoice, UsageLog, Contact, AuditLog, GlobalSetting
  Controllers/            # Auth, Dashboard, Subscription, Contact, Admin
public/
  index.php               # Front controller: autoload → boot DB → routes → dispatch
  assets/css/app.css      # Minimalist utility stylesheet
  assets/js/              # api.js (fetch wrapper) + dashboard.js + contacts.js + admin.js
  views/                  # landing / login / register / app / admin HTML shells
storage/app.sqlite        # Generated on first run (gitignored)
```

## Database schema

Core tables — `tenants`, `users`, `subscriptions`, `usage_logs`, `contacts`,
`global_settings` — plus supporting `invoices` and `audit_logs`. All queries use
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

GET  /api/admin/metrics        GET  /api/admin/users       GET  /api/admin/audit
POST /api/admin/users/{id}/status
POST /api/admin/tenants/{id}/tier
POST /api/admin/tenants/{id}/limit
POST /api/admin/tenants/{id}/status
POST /api/admin/settings
```

## Contacts (mini CRM)

Every tenant gets a private contact/lead list in the **Contacts** tab of the
Control Center — name, email, phone, company, status (lead / active / customer /
inactive), and notes. Contact count is capped per subscription tier via
`max_contacts` in [`config/config.php`](config/config.php), enforced the same way
the usage-event `resource_limit` is enforced. See
[`src/Models/Contact.php`](src/Models/Contact.php) and
[`src/Controllers/ContactController.php`](src/Controllers/ContactController.php).

## Requirements

- PHP 8.1+ with the `pdo_sqlite` extension
- Python 3.8+ (launcher only — no packages required)
