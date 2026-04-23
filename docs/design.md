# VetOps Portal — System Design

This document is the authoritative technical design reference for the VetOps Portal. It describes architecture decisions, data models, service boundaries, security posture, and the rationale behind each significant design choice.

---

## 1. System Overview

VetOps Portal is a multi-facility veterinary operations platform. It consolidates six distinct operational domains under a single authenticated API and a single-page application:

1. **Rental Asset Management** — Equipment tracking, checkout, return, and overdue enforcement.
2. **Inventory Management** — Multi-storeroom stock tracking with full ledger audit trail.
3. **Service Orders** — Clinical service orders with inventory reservation and deduction.
4. **Patient & Visit Management** — Veterinary patient records, visit scheduling, and review collection.
5. **Content Management** — Announcement and carousel publishing with approval workflow.
6. **Administration** — User provisioning, facility management, audit logs, and deduplication.

The platform is designed for **operational reliability over developer ergonomics**: immutable audit trails, pessimistic locking where needed, strict RBAC, and encrypted PII at rest. It assumes a single veterinary group with multiple clinic facilities and a small number of privileged operators.

---

## 2. Technology Stack

| Layer | Technology |
|---|---|
| Runtime | PHP 8.4 |
| Framework | Laravel 13 |
| Auth | Laravel Sanctum (token + HttpOnly cookie) |
| Database | MySQL 8.0 |
| Queue/Scheduler | Laravel Queue (DB driver), Scheduler |
| Container | Docker (multi-stage: base / development / production) |
| Process Manager | Supervisor (nginx + php-fpm in one container) |
| Frontend Framework | Vue 3 (Composition API) |
| Frontend Router | vue-router 4 |
| Frontend State | Pinia |
| Frontend HTTP | Axios |
| Frontend Tests | Vitest + @vue/test-utils |
| Backend Tests | PHPUnit |

---

## 3. Architecture

### 3.1 Backend Architecture

The backend follows a **layered service architecture**:

```
HTTP Request
    ↓
Route (middleware: throttle, auth:sanctum, vetops.inactivity, role:*)
    ↓
Form Request (input validation + authorization)
    ↓
Controller (thin: orchestrate service calls, build response)
    ↓
Service Layer (domain logic, pessimistic locking, business rules)
    ↓
Eloquent Model (queries, scopes, relationships)
    ↓
MySQL 8.0
```

Controllers are deliberately thin. Business logic lives in service classes. This makes logic independently testable and keeps controllers under ~100 lines each.

### 3.2 Service Classes

| Service | Responsibility |
|---|---|
| `AuthService` | Login, CAPTCHA, password policy, cookie session, refresh token |
| `InventoryService` | Receive, issue, transfer, stocktake lifecycle, ATP enforcement, low-stock alerts |
| `RentalService` | Checkout (pessimistic lock), return, overdue batch marking |
| `ContentService` | Draft, version, approval workflow, simhash deduplication |
| `ReviewService` | Review submission, moderation, response, appeal resolution |
| `AuditService` | Immutable audit log writes, structured action constants |
| `DataVersioningService` | Full entity snapshots, change history retrieval |
| `FileStorageService` | Upload handling, checksum generation, storage path abstraction |
| `ImportService` | CSV import queue dispatch, async processing |
| `DeduplicationService` | Simhash computation and similarity comparison |

### 3.3 Frontend Architecture

The frontend is a Vue 3 SPA served by nginx at the container root. All API communication goes through a single Axios client instance (`resources/js/api/client.js`).

```
App.vue (session restore on mount)
    ↓
vue-router (route guards: requiresAuth, requiresPasswordChange, role checks)
    ↓
View Components (per-domain pages)
    ↓
Pinia Store (auth state: token, user, requiresPasswordChange)
    ↓
api/index.js (typed API surface)
    ↓
api/client.js (Axios instance, withCredentials, token injection, 401 handler)
```

---

## 4. Authentication & Session Model

### 4.1 Token Storage

Bearer tokens are stored **in-memory only** (Pinia `auth.token`). They are never written to `localStorage` or `sessionStorage`. This eliminates the XSS token-theft vector.

Session persistence across hard page-refreshes is handled by an HttpOnly `vetops_session` cookie (8-hour lifetime, SameSite=Strict). On `App.vue` mount, if the auth store has no user, the frontend calls `POST /api/auth/refresh`. The server reads the cookie, validates the session, and returns a fresh token.

### 4.2 CSRF Protection

CSRF is handled through two complementary mechanisms:

1. **SameSite=Strict** on the session cookie prevents cross-origin POSTs from including the cookie.
2. **XSRF-TOKEN cookie + X-XSRF-TOKEN header**: Axios is configured with `withCredentials: true`. Laravel sets the `XSRF-TOKEN` cookie and Axios automatically sends it as the `X-XSRF-TOKEN` header on mutating requests. Laravel's `VerifyCsrfToken` middleware validates the pair.

### 4.3 CAPTCHA

After `captcha_after` (default: 5) failed login attempts from a single IP within `login_window_minutes` (default: 10), the server requires a CAPTCHA token.

The CAPTCHA is a simple math challenge ("a + b"). The challenge is generated by `AuthService::getCaptchaChallenge()`, which computes two random single-digit integers, caches the expected answer under the key `vetops.captcha:{ip}` with a 10-minute TTL, and returns the challenge string. The answer is verified in `AuthService::validateCaptchaToken()` by reading the cached value.

This is a custom implementation that avoids third-party CAPTCHA dependencies. It is sufficient for rate limiting automated brute-force attempts; it is not designed to resist humans.

### 4.4 Password Policy

- Minimum 12 characters (enforced server-side in `AuthService::validatePasswordStrength()`).
- All seeded accounts have `password_changed_at = null`, forcing a password change on first login.
- The `requires_password_change` flag is included in every login and refresh response. The frontend router redirects to `/change-password` if this flag is true, blocking navigation to any other protected route.

### 4.5 Inactivity Timeout

The `vetops.inactivity` middleware (applied to all protected routes) tracks the timestamp of last activity per Sanctum token. If more than `inactivity_timeout` minutes (default: 15, configurable per user) have elapsed since the last authenticated request, the token is revoked and the response is 401. The frontend `onUnauthorized` callback calls `auth.clear()` and redirects to login.

### 4.6 Role-Based Access Control

In summary:

- Each user has exactly one `role` and an optional `facility_id`.
- Enforcement is four-layered:
  1. **Route middleware** (coarse role allow-lists, e.g. `role:system_admin,clinic_manager`).
  2. **Controller `authorize()` calls** that invoke the Eloquent policy for the target model on every `show`, `update`, `destroy`, and domain action (checkout, publish, hide, etc.).
  3. **Policies** (`app/Policies/*`) encode the ownership rule — primarily facility match + role check.
  4. **Tenant scope trait** (`App\Http\Controllers\Concerns\ScopesByFacility`) applied to every list/index endpoint. A non-admin user with `facility_id = N` receives only rows where `facility_id = N`, regardless of any `?facility_id=` query param they send. Admins may use the query param freely.

### 4.7 Tenant Isolation (Cross-Facility IDOR Prevention)

All facility-scoped list endpoints call `applyFacilityScope($query, $user, $requestedFacilityId)`. The trait locks non-admin users to their own `facility_id` before any other filter runs — a manager at facility A cannot retrieve facility B rows even by supplying `?facility_id=B`. `CrossFacilityIsolationTest` (under `tests/Feature/`) pins this behaviour for patients, visits, reviews, rentals, and service orders.

Object-level actions (show, update, cancel, publish, hide, etc.) enforce the same isolation through policies: `$this->authorize($ability, $model)` returns 403 when facility match fails. The `system_admin` role bypasses both scopes via the `Gate::before` hook.
- `system_admin` has a `Gate::before` bypass — it grants every ability check unconditionally.

---

## 5. Data Model Design

### 5.1 Multi-Facility Scoping

Every domain entity that belongs to a facility has a `facility_id` column. Query scopes enforce facility isolation so that authenticated users only see rows for their own facility unless they are `system_admin`. This prevents IDOR via enumeration.

### 5.2 Soft Deletes

Most mutable entities (Facility, Department, User, RentalAsset, InventoryItem, Doctor, Patient, ContentItem) use Laravel's `SoftDeletes` trait. Deleted rows are retained for audit purposes and can be restored. Hard deletes are not supported through the API.

### 5.3 Immutable Records

Three record types are strictly immutable — they have no `updated_at` and their models block update/delete in `boot()`:

| Model | Purpose |
|---|---|
| `StockLedger` | Inventory transaction log — every stock movement |
| `AuditLog` | Security and operational audit trail |
| `ContentVersion` | Content edit history snapshots |
| `DataVersion` | Full entity snapshots for change history |

### 5.4 Encrypted PII

Phone numbers for Users, Facilities, Doctors, and Patients are stored encrypted (columns named `phone_encrypted`, `owner_phone_encrypted`). The plain-text value is returned only to `system_admin` and `clinic_manager` roles. Other callers receive a masked representation (e.g., `***-***-1234`).

### 5.5 External Keys

Most domain entities have an `external_key` column (unique, indexed). This is an opaque string identifier for integration with external HR, PMS, or ERP systems via CSV import. It is distinct from the internal auto-increment `id`.

### 5.6 JSON Columns

Columns that store structured data without their own normalized table use MySQL `json` type (not `jsonb` — MySQL does not support jsonb):

- `Facility.business_hours` — store hours per day-of-week
- `InventoryItem.supplier_info` — supplier contact data
- `ContentItem.facility_ids`, `department_ids`, `role_targets`, `tags` — targeting arrays
- `VisitReview.tags` — review tags
- `AuditLog.old_values`, `new_values` — full diff snapshots
- `DataVersion.data` — full entity snapshot
- `MergeRequest.conflict_data`, `resolution_rules` — merge metadata
- `RentalAsset.specs` — flexible hardware specifications

---

## 6. Inventory Domain Design

### 6.1 Stock Level vs. Ledger

`StockLevel` is a **summary table** — one row per (item × storeroom) pair — storing current `on_hand`, `reserved`, and `available_to_promise` (ATP). It is updated in place by service operations.

`StockLedger` is an **immutable log** — one row per stock movement — storing the quantity change (signed), the balance after the change, the transaction type, and the reference (who/what caused it). It can never be modified or deleted.

This separation gives fast current-balance reads (via StockLevel) and a complete, tamper-proof history (via StockLedger).

### 6.2 Available to Promise (ATP)

ATP = `on_hand − reserved`. Reserved quantity is increased when a Service Order creates an `OrderInventoryReservation` under the `lock_at_creation` strategy. Issuance and closing operations reduce both `on_hand` and `reserved` together. This prevents overselling inventory that is promised to open orders.

### 6.3 Safety Stock and Low-Stock Alerts

Each InventoryItem has a `safety_stock_days` integer and a `reorder_point` decimal. The `InventoryService::getLowStockAlerts()` method returns StockLevel rows where `available_to_promise < reorder_point`.

### 6.4 Stocktake Workflow

A stocktake session has four states: `open → pending_approval → approved → closed`.

1. A manager or clerk starts a session for a storeroom.
2. Staff record counted quantities per item. The service computes variance against the system quantity.
3. If variance exceeds `stocktake_variance_pct` (default: 5%), the entry is flagged `requires_approval = true`.
4. A manager approves each flagged entry with a reason.
5. The session is closed (→ `pending_approval`).
6. Once all flagged entries are approved, the session can be approved (→ `approved`). Applying the stocktake writes `adjustment` ledger entries and updates StockLevel `on_hand` values.

### 6.5 Service Order Reservation Strategies

A ServiceOrder has a `reservation_strategy` field:

- **`lock_at_creation`**: Items are reserved (ATP decremented) immediately when the order is created or items are added. When the order closes, items are deducted from `on_hand`.
- **`deduct_at_close`**: No reservation is made upfront. Items are deducted at close time. This risks overissuance but is simpler for low-contention scenarios.

---

## 7. Rental Domain Design

### 7.1 Asset Identity

A rental asset can be identified by three identifiers: `barcode`, `qr_code`, and `serial_number` — all unique. The `/rental-assets/scan` endpoint accepts any of these and returns the matching asset, enabling POS scanning workflows.

### 7.2 Deposit Calculation

Deposit is calculated as `max(replacement_cost × deposit_rate, deposit_min)` where `deposit_rate` defaults to 0.20 and `deposit_min` to $50.00. These are configurable in `config/vetops.php`. Deposit is recalculated automatically when `replacement_cost` is updated.

### 7.3 Double-Booking Prevention

The checkout flow uses **pessimistic locking** to prevent race conditions when two simultaneous requests try to rent the same asset:

```php
DB::transaction(function() use ($asset) {
    $locked = RentalAsset::lockForUpdate()->findOrFail($asset->id);
    // Check status and active bookings inside the lock
    $alreadyBooked = RentalTransaction::where('asset_id', $locked->id)
        ->whereIn('status', ['active', 'overdue'])
        ->lockForUpdate()
        ->exists();
    ...
});
```

All availability checks happen inside the transaction, after acquiring the row lock. A second concurrent request waits for the lock, then sees the updated status and fails validation.

### 7.4 Overdue Detection

The `vetops:mark-overdue-rentals` artisan command runs every 15 minutes (via the scheduler). It marks transactions as `overdue` when `expected_return_at + overdue_hours` has passed without a return. The `overdue_hours` threshold defaults to 2.

The `RentalTransaction::isOverdue()` method computes the flag at read time for individual record display (show endpoint), while the command batch-marks records for list endpoints and alerts.

---

## 8. Content Domain Design

### 8.1 Approval Workflow

Content items move through a linear state machine:

```
draft → in_review → approved → published → archived
```

Transitions are gated by role:

- `draft → in_review`: any `content_editor` or higher.
- `in_review → approved`: `content_approver` or `system_admin` only (cannot be the same person who submitted).
- `approved → published`: `content_approver` or higher; optionally with a future `publish_at` timestamp.
- Any state → `archived`: `content_approver` or higher.

### 8.2 Versioning

Every update to a ContentItem creates a new `ContentVersion` record that snapshots `title`, `body`, `version`, `changed_by`, and `change_note`. The `ContentItem.version` counter is incremented. Rollback replaces the live `title` and `body` with a prior version snapshot and records a new ContentVersion for the rollback itself.

### 8.3 Targeting

Published content is filtered by `facility_ids`, `department_ids`, and `role_targets` JSON arrays. The `ContentItem::scopeForUser(User)` scope returns only content where the user's facility is in `facility_ids` (or the array is empty/null, meaning "all facilities") and the user's role matches `role_targets`.

### 8.4 Duplicate Detection

Before creating a new draft, `ContentService::draft()` computes a **simhash** of the content body via `DeduplicationService::computeSimHash()`. If any existing non-archived item has a similar simhash, the service raises a warning (or optionally blocks creation depending on configuration). This prevents accidental near-duplicate content.

---

## 9. Review Domain Design

### 9.1 Submission Flow

Reviews are submitted by patients/owners at a tablet kiosk. The `POST /api/reviews/visits/{visit}/submit` endpoint is **intentionally unauthenticated**: the route is declared outside the `auth:sanctum` group in `routes/api.php` and protected only by a `throttle:10,60` rate limit. Submission is scoped by visit id (an opaque integer provided by staff on the tablet).

Request body (multipart):

- `rating` (1–5, required)
- `body` (optional free text)
- `tags[]` (optional)
- `submitted_by_name` (**optional** — owners may submit anonymously)
- `images[]` (optional, up to 5 image files, each ≤ `upload_max_mb`)

Each image is passed through `FileStorageService::store()`, which computes a SHA-256 checksum and returns the storage path. The `ReviewImage` rows reference the path and checksum; originals are never mutated. The tablet UI renders thumbnail previews, enforces the 5-image cap client-side, and submits the whole form as `multipart/form-data`.

### 9.2 Moderation Lifecycle

```
pending → published   (manager publishes)
pending → hidden      (manager hides with reason)
published → hidden    (manager hides after publication)
published → appealed  (manager raises appeal)
appealed → published  (appeal resolved, review reinstated)
appealed → hidden     (appeal resolved, review stays hidden)
```

### 9.3 Manager Response

A manager can attach one `ReviewResponse` to any review. Responses are visible alongside the review in the published view.

---

## 10. Audit & Versioning Design

### 10.1 AuditLog

Every create, update, delete, import, export, login (success and failure), and password change writes to `audit_logs`. The `AuditService` is called explicitly from controllers and services — there is no automatic model observer. Explicit calls mean the action label and diff values are exactly what the developer intended, not an ORM-level guess.

Fields:
- `action`: dot-namespaced string (e.g., `facility.updated`, `auth.password_changed`, `rental.checked_out`)
- `entity_type` / `entity_id`: the affected record
- `old_values` / `new_values`: JSON diff, after redaction (see below)
- `ip_address`: IPv4/IPv6 from request, 45 chars max
- `user_id`: null for unauthenticated events (e.g., failed logins)

**Redaction.** Before `old_values` or `new_values` are persisted, `AuditService::redact()` walks the arrays and replaces any value under a sensitive key with the sentinel `***REDACTED***`. The key list is maintained in `AuditService::REDACT_KEYS` and covers `password`, `password_confirmation`, `current_password`, `remember_token`, `api_token`, `token`, `captcha_token`, `phone_encrypted`, `owner_phone_encrypted`, and `license_number`. Matching is case-insensitive and recursive into nested arrays, so snapshots emitted by `Model::toArray()` pass through the same filter.

AuditLog rows are never updated or deleted through the application (the model `boot()` method throws on update/delete). A scheduled `vetops:purge-audit-logs` command can hard-delete logs older than `audit_retention_years` (default: 7) — this is the only authorized deletion path.

### 10.2 DataVersion

For every mutable domain entity (Facility, Doctor, Patient, RentalAsset, InventoryItem, ContentItem), the `DataVersioningService` stores full snapshots on create and update. This is separate from AuditLog: AuditLog records the intent and diff; DataVersion records the full before-state for forensic reconstruction and reverse via `DataVersioningService::revert()`. ContentItem additionally maintains `ContentVersion` rows, which carry the edit comment and feed the rollback UI directly.

---

## 11. CSV Import/Export Design

Imports are processed **synchronously** by `ImportService::process()`. The controller stores the uploaded file, creates a `CsvImport` record, and calls `process()` immediately. The response is the same `CsvImport` row with final `status` (`completed` or `failed`), `total_rows`, `processed_rows`, and a row-by-row `errors` array. A queued variant can be added later without API changes — clients already receive the record and can poll it if needed.

Exports are **synchronous streaming responses**. The controller calls the export service, which returns a CSV string that is streamed back with a `Content-Type: text/csv` header and a filename scoped to the export timestamp.

Supported entity types — symmetric on both sides:

| Entity | Import | Export |
|---|---|---|
| `facility` | yes | yes |
| `department` | yes | yes |
| `inventory_item` | yes | yes |
| `doctor` | yes | yes |
| `patient` | yes | yes |
| `rental_asset` | yes | yes |
| `service` | yes | yes |
| `service_pricing` | yes | yes |

Every import row is pushed through `DataVersioningService::record()` on both create and update (not only the facility branch). CSV-driven changes are revertible the same way as API-driven changes via `DataVersioningService::revert()`.

---

## 12. Deduplication (Merge Requests)

When staff suspects two records represent the same real-world entity, they create a `MergeRequest` specifying `entity_type`, `source_id`, `target_id`, and optional `conflict_data`/`resolution_rules`. A manager reviews and either approves or rejects.

**Execution (`App\Services\MergeService`)**: an approval is not a status flip. `MergeService::execute()` runs inside a DB transaction and performs:

1. **Guard checks** — merge request must be `pending`; entity type must be in the supported list (`patient`, `doctor`, `rental_transaction_asset`); source and target must share the same `facility_id`.
2. **Pre-merge snapshot** — `DataVersioningService::record()` snapshots both source and target so the operation is reversible.
3. **Foreign-key relink** — for each referencing model registered in the `relink` map (e.g. `Visit.patient_id → target_id`, `VisitReview.doctor_id → target_id`), a single `UPDATE` reassigns all rows from the source to the target. The number relinked per table is captured.
4. **Audit write** — `entity.merge` entry logged on the target model with `{ merge_request_id, entity_type, source_id, target_id, resolution_rules, relinked_counts }` so the provenance is permanent (and subject to the redaction layer from §10.1).
5. **Source soft-delete** — the source row is soft-deleted. It is retained for forensic review and can be restored if a merge is later disputed.
6. **Status flip** — the `MergeRequest` is marked `approved` and stamped with the resolver.

Rejections stay status-only.

---

## 13. Configuration Reference

All VetOps-specific tunables live in `config/vetops.php` and are overridable via environment variables:

| Key | Default | Meaning |
|---|---|---|
| `inactivity_timeout` | 15 min | Minutes before auto-logout |
| `max_login_attempts` | 10 | Login failures before lockout |
| `login_window_minutes` | 10 | Window for counting failures |
| `captcha_after` | 5 | Failures before CAPTCHA required |
| `audit_retention_years` | 7 | Years before audit log purge |
| `overdue_hours` | 2 | Hours after expected return to mark overdue |
| `safety_stock_days` | 14 | Default days for safety stock calculation |
| `deposit_rate` | 0.20 | Fraction of replacement_cost for deposit |
| `deposit_min` | 50.00 | Minimum deposit amount |
| `stocktake_variance_pct` | 5 | Variance % requiring manager approval |
| `upload_max_mb` | 20 | Max file upload size |

---

## 14. Container & Deployment Design

### 14.1 Multi-Stage Dockerfile

The Dockerfile has three stages:

1. **`base`**: PHP 8.4-fpm on Debian Bookworm, nginx, supervisor, MySQL client, all PHP extensions (`pdo_mysql`, `mbstring`, `zip`, `bcmath`, `opcache`, `pcntl`, `exif`, `gd`), Composer 2. BuildKit cache mounts accelerate repeated builds.
2. **`development`**: Extends `base`. Installs all Composer deps (including dev). Bind-mounts in docker-compose.yml allow live code changes without rebuilds.
3. **`production`**: Extends `base`. Installs `--no-dev` deps, runs as `www-data` (non-root). Optimized autoloader.

### 14.2 Process Management

Supervisor runs inside the container and manages two processes:

- **nginx**: Listens on port 8000. Terminates HTTP, proxies dynamic requests to php-fpm via a Unix socket, serves static assets directly from `public/`.
- **php-fpm**: Processes PHP requests from nginx.

The container exposes port 8000. The host maps it via `VETOPS_HOST_PORT` (default: 8000).

### 14.3 Entrypoint

`/usr/local/bin/entrypoint.sh` runs before supervisor starts:

1. Waits for MySQL to become ready (`mysqladmin ping` loop, max 30 attempts × 2s).
2. If `$# -gt 0`, runs the passed command directly (worker/scheduler mode — bypasses server boot).
3. In server mode:
   - `php artisan config:clear`
   - `php artisan migrate --force`
   - `php artisan db:seed --force`
   - `php artisan storage:link --force`
   - Sets correct permissions on `storage/` and `bootstrap/cache/`
   - Starts Supervisor

### 14.4 Auxiliary Containers

The docker-compose stack runs four containers:

| Container | Image | Role |
|---|---|---|
| `mysql` | `mysql:8.0` | Database |
| `app` | built from Dockerfile (dev target) | HTTP server (nginx + php-fpm) |
| `queue` | same build | Queue worker (`php artisan queue:work`) |
| `scheduler` | same build | Artisan scheduler (cron loop every 60s) |

---

## 15. Security Design Summary

| Concern | Mechanism |
|---|---|
| Token storage | In-memory (Pinia) only — no localStorage |
| Session persistence | HttpOnly SameSite=Strict cookie (8h TTL) |
| CSRF | SameSite=Strict + XSRF-TOKEN/X-XSRF-TOKEN pair |
| Brute force | Rate limiting + CAPTCHA after N failures |
| PII at rest | Encrypted phone columns; masked for low-privilege roles |
| Authorization | Three-layer RBAC (route middleware, Gates, Policies) |
| Audit trail | Immutable AuditLog + DataVersion snapshots |
| Content security | CSP header (`default-src 'self'`), X-Frame-Options: DENY |
| Production source maps | Disabled (`build.sourcemap: false` in vite.config.js) |
| Race conditions | Pessimistic locking in checkout and ATP-check paths |
| Soft deletes | Records retained for forensic audit; hard deletes not exposed |
| Input validation | Form Requests with type-safe rules on every mutating endpoint |

---

## 16. Artisan Commands

| Command | Schedule | Purpose |
|---|---|---|
| `vetops:mark-overdue-rentals` | Every 15 minutes | Marks active transactions past expected_return_at + overdue_hours as overdue |
| `vetops:purge-audit-logs` | Yearly | Hard-deletes audit_log rows older than audit_retention_years |

---

## 17. Seeded Accounts

Six accounts are seeded at first run, one per role. All have `password_changed_at = null`, forcing a password change on first login. Temporary passwords are documented in `README.md §Default Accounts`.

| Role | Username |
|---|---|
| `system_admin` | `admin` |
| `clinic_manager` | `manager` |
| `inventory_clerk` | `clerk` |
| `technician_doctor` | `doctor` |
| `content_editor` | `editor` |
| `content_approver` | `approver` |
