# VetOps API Specification

Base path: `/api`
Auth: `Authorization: Bearer <token>` on all protected routes.
All requests/responses use `application/json`.
Pagination: `?page=N&per_page=N` unless noted. List responses include `data[]`, `total`, `per_page`, `current_page`.

---

## Authentication

### POST /auth/login
Public. Authenticate and open a session.

**Body**
```json
{ "username": "string", "password": "string", "captcha_token": "string|optional" }
```
**Response 200**
```json
{ "token": "string", "user": { ...user }, "captcha_required": false, "requires_password_change": false }
```
**Response 422** — Invalid credentials, locked out, CAPTCHA required/wrong, inactive user.

---

### POST /auth/refresh
Public. Restore session from `vetops_session` HttpOnly cookie.

**Response 200**
```json
{ "token": "string", "user": { ...user }, "requires_password_change": false }
```
**Response 401** — Cookie missing or expired.

---

### GET /auth/captcha-status
Public. Check whether CAPTCHA is required for the caller's IP.

**Response 200**
```json
{ "captcha_required": true, "challenge": "4 + 7" }
```

---

### POST /auth/logout
Auth required. Revoke current token and clear session cookie.

**Response 200** `{ "message": "Logged out" }`

---

### GET /auth/me
Auth required. Returns the authenticated user with facility and department.

**Response 200**
```json
{ "user": { "id": 1, "username": "...", "role": "...", "facility": {...}, "department": {...} } }
```

---

### POST /auth/change-password
Auth required.

**Body**
```json
{ "current_password": "string", "password": "string (min 12)", "password_confirmation": "string" }
```
**Response 200** `{ "message": "Password updated" }`
**Response 422** — Wrong current password, too short, mismatch.

---

## Facilities

### GET /facilities
Auth. `?search=&active=1&page=`.

### POST /facilities
Auth. Roles: `system_admin`, `clinic_manager`.

**Body** `{ external_key, name, address, city, state (2), zip, phone, email, business_hours (json), active }`

### GET /facilities/{id}
Auth.

### PUT /facilities/{id}
Auth. Roles: `system_admin`, `clinic_manager`. Same body shape as POST (partial).

### DELETE /facilities/{id}
Auth. Role: `system_admin`.

### POST /facilities/import
Auth. Role: `system_admin`. Multipart `file` (CSV). Processed synchronously. **Response 200** — CsvImport record (`status`, `total_rows`, `processed_rows`, `errors`).

### GET /facilities/export
Auth. Roles: `system_admin`, `clinic_manager`. Streams `text/csv`.

### GET /facilities/{id}/history
Auth. Returns array of `DataVersion` snapshots for the facility.

---

## Departments

### GET /departments
Auth. `?facility_id=&active=`.

### POST /departments
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ facility_id, external_key, name, code, active }`

### PUT /departments/{id}
Auth. Roles: `system_admin`, `clinic_manager`.

### DELETE /departments/{id}
Auth. Roles: `system_admin`, `clinic_manager`.

---

## Users

All routes: Role `system_admin` only.

### GET /users
`?facility_id=&role=&active=`

### POST /users
**Body** `{ username, name, email, password (min 12), password_confirmation, role, facility_id, department_id, phone, active }`

### GET /users/{id}

### PUT /users/{id}

### DELETE /users/{id}
Cannot delete own account.

---

## Rental Assets

### GET /rental-assets
Auth. `?facility_id=&status=&category=&search= (name/barcode/serial/QR)&page=`

### POST /rental-assets
Auth. Roles: `system_admin`, `clinic_manager`, `inventory_clerk`.
**Body** `{ facility_id, external_key, name, category, manufacturer, model_number, serial_number, barcode, qr_code, replacement_cost, daily_rate, weekly_rate, specs (json), notes }`
Deposit auto-calculated.

### GET /rental-assets/scan
Auth. `?code=<barcode|qr_code|serial_number>`. Returns asset + active transaction.

### GET /rental-assets/{id}

### PUT /rental-assets/{id}
Auth. Roles: `system_admin`, `clinic_manager`, `inventory_clerk`.

### DELETE /rental-assets/{id}
Auth. Roles: `system_admin`, `clinic_manager`. Fails 422 if currently rented.

### POST /rental-assets/{id}/photo
Auth. Multipart `photo` (image file, max 20 MB).

---

## Rental Transactions

### GET /rental-transactions
Auth. `?facility_id=&status=&overdue_only=1&page=`

### POST /rental-transactions/checkout
Auth. Roles: all except `content_editor`, `content_approver`.
**Body**
```json
{
  "asset_id": 1,
  "renter_type": "department|clinician",
  "renter_id": 1,
  "facility_id": 1,
  "expected_return_at": "2025-01-01T12:00:00Z",
  "notes": "optional"
}
```
**Response 201** — Transaction object.
**Response 422** — Asset not available or already rented.

### GET /rental-transactions/overdue
Auth.

### GET /rental-transactions/{id}
Auth. Includes computed `is_overdue` and `overdue_minutes`.

### POST /rental-transactions/{id}/return
Auth.
**Body** `{ notes: "optional" }`
**Response 200** `{ ...transaction, fee_amount, status: "returned" }`

### POST /rental-transactions/{id}/cancel
Auth. Roles: `system_admin`, `clinic_manager`.
**Response 200** `{ ...transaction, status: "cancelled" }`
**Response 422** — Transaction is not active/overdue.

---

## Storerooms

### GET /storerooms
Auth. `?facility_id=&active=`

### POST /storerooms
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ facility_id, name, code, active }`

### PUT /storerooms/{id}
Auth. Roles: `system_admin`, `clinic_manager`.

### DELETE /storerooms/{id}
Auth. Roles: `system_admin`, `clinic_manager`.

---

## Inventory

### GET /inventory/items
Auth. `?search=&category=&active=&page=`

### POST /inventory/items
Auth. Roles: `system_admin`, `inventory_clerk`.
**Body** `{ external_key, name, sku, category, unit_of_measure, safety_stock_days, reorder_point, supplier_info (json), active }`

### PUT /inventory/items/{id}
Auth. Roles: `system_admin`, `inventory_clerk`.

### POST /inventory/receive
Auth. Roles: `system_admin`, `clinic_manager`, `inventory_clerk`.
**Body** `{ item_id, storeroom_id, quantity, unit_cost, notes, reference_type, reference_id }`
**Response 201** — StockLedger entry.

### POST /inventory/issue
Auth. Roles: `system_admin`, `clinic_manager`, `inventory_clerk`, `technician_doctor`.
**Body** `{ item_id, storeroom_id, quantity, notes, reference_type, reference_id }`
**Response 201** — StockLedger entry.
**Response 422** — Insufficient ATP.

### POST /inventory/transfer
Auth. Roles: `system_admin`, `clinic_manager`, `inventory_clerk`.
**Body** `{ item_id, from_storeroom_id, to_storeroom_id, quantity, notes }`
**Response 201** `{ outbound: {...ledger}, inbound: {...ledger} }`

### GET /inventory/stock-levels
Auth. `?item_id=&storeroom_id=&page=`

### GET /inventory/low-stock-alerts
Auth.

### GET /inventory/ledger
Auth. `?item_id=&storeroom_id=&type=&from=&to=&page=`

### POST /inventory/items/import
Auth. Roles: `system_admin`, `inventory_clerk`. Multipart `file`. Processed synchronously. **Response 200** — CsvImport record.

### GET /inventory/items/export
Auth. Roles: `system_admin`, `clinic_manager`. Streams `text/csv`.

---

## Stocktake

### GET /stocktake
Auth. `?storeroom_id=&status=`

### POST /stocktake/start
Auth. Roles: `system_admin`, `clinic_manager`, `inventory_clerk`.
**Body** `{ storeroom_id }`
**Response 201** — StocktakeSession.

### GET /stocktake/{id}
Auth. Includes all entries.

### POST /stocktake/{id}/entries
Auth. Roles: `system_admin`, `clinic_manager`, `inventory_clerk`.
**Body** `{ item_id, counted_quantity }`
**Response 201** — StocktakeEntry with variance_pct and requires_approval flag.

### POST /stocktake/{id}/entries/{entryId}/approve
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ reason }`

### POST /stocktake/{id}/close
Auth. Roles: `system_admin`, `clinic_manager`, `inventory_clerk`.
Sets session status to `pending_approval`.

### POST /stocktake/{id}/approve
Auth. Roles: `system_admin`, `clinic_manager`.
Applies all stocktake adjustments. All flagged entries must be approved first.

---

## Services (catalog)

### GET /services
Auth. `?search=&category=&active_only=&page=`

### POST /services
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ external_key, name, category, code, description, duration_minutes, active }`

### GET /services/{id}
Auth. Includes `pricings[]`.

### PUT /services/{id}
Auth. Roles: `system_admin`, `clinic_manager`.

### DELETE /services/{id}
Auth. Role: `system_admin`.

### GET /services/{id}/pricings
Auth. Scoped to caller's facility (non-admin). Returns pricing records.

### POST /services/{id}/pricings
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ facility_id, base_price, currency, effective_from, effective_to, active }`

### POST /services/import
Auth. Roles: `system_admin`, `clinic_manager`. Multipart `file`. Response 200 — CsvImport record.

### GET /services/export
Auth. Streams `text/csv`.

---

## Service Orders

### GET /service-orders
Auth. `?facility_id=&status=&page=`

### POST /service-orders
Auth.
**Body** `{ facility_id, patient_id, doctor_id, reservation_strategy: "lock_at_creation|deduct_at_close", reservations: [{ item_id, storeroom_id, quantity }] }`

### GET /service-orders/{id}
Auth. Includes reservations.

### POST /service-orders/{id}/close
Auth.

### POST /service-orders/{id}/reservations
Auth.
**Body** `{ item_id, storeroom_id, quantity }`

---

## Content

### GET /content
Auth. `?type=&status=&search=&page=`

### GET /content/published
Auth. Returns published content scoped to the caller's facility and role.

### POST /content
Auth. Roles: `system_admin`, `content_editor`, `content_approver`.
**Body** `{ type: "announcement|carousel", title, body, excerpt, facility_ids (json), department_ids (json), role_targets (json), tags (json), priority }`
**Response 201** — ContentItem in `draft` status.
**Response 422** — Duplicate detected via simhash.

### GET /content/{id}
Auth. Includes versions and media.

### PUT /content/{id}
Auth. Creates a new ContentVersion.
**Body** `{ title, body, excerpt, change_note }`

### POST /content/{id}/submit-review
Auth. Roles: `content_editor`, `content_approver`, `system_admin`.

### POST /content/{id}/approve
Auth. Roles: `content_approver`, `system_admin`.

### POST /content/{id}/publish
Auth. Roles: `content_approver`, `system_admin`.
**Body** `{ publish_at: "ISO8601|optional" }`

### POST /content/{id}/rollback
Auth. Roles: `content_editor`, `content_approver`, `system_admin`.
**Body** `{ version: 3, change_note: "optional" }`

### GET /content/{id}/versions
Auth.

### POST /content/{id}/media
Auth. Multipart `files[]` (up to 10). **Response 201** — Array of ContentMedia.

### DELETE /content/{id}
Auth. Roles: `content_approver`, `system_admin`. Archives (does not hard-delete).

---

## Doctors

### GET /doctors
Auth. `?facility_id=&active=&search=`

### POST /doctors
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ facility_id, external_key, first_name, last_name, specialty, license_number, phone, email, active }`

### GET /doctors/{id}

### PUT /doctors/{id}
Auth. Roles: `system_admin`, `clinic_manager`.

### DELETE /doctors/{id}
Auth. Roles: `system_admin`, `clinic_manager`.

### POST /doctors/import
Auth. Role: `system_admin`. Multipart `file`.

---

## Patients

### GET /patients
Auth. `?facility_id=&search= (name or owner_name)&active=&page=`

### POST /patients
Auth.
**Body** `{ facility_id, external_key, name, species, breed, owner_name, owner_phone, owner_email, active }`

### GET /patients/{id}

### PUT /patients/{id}
Auth.

### DELETE /patients/{id}
Auth. Roles: `system_admin`, `clinic_manager`.

---

## Visits

### GET /visits
Auth. `?facility_id=&doctor_id=&patient_id=&status=&from=&to=&page=`

### POST /visits
Auth.
**Body** `{ facility_id, patient_id, doctor_id, service_order_id, visit_date, status }`

### GET /visits/{id}
Auth. Includes patient, doctor, review with images and response.

### PUT /visits/{id}
Auth.
**Body** `{ status, visit_date }`

---

## Reviews

### GET /reviews
Auth. `?facility_id=&doctor_id=&status=&rating=&page=`

### GET /reviews/dashboard
Auth. `?facility_id=&doctor_id=`
**Response** `{ total_reviews, avg_rating, by_rating: {1:n,...}, published: n, pending: n, hidden: n }`

### POST /reviews/visits/{visitId}/submit
**Public (no auth).** Rate limited.
**Body** `{ rating: 1-5, body: "optional", tags: [], submitted_by_name, images[] (multipart) }`
**Response 201** — VisitReview in `pending` status.
**Response 422** — Visit not completed or already reviewed.

### GET /reviews/{id}
Auth. Includes images, response, appeals, doctor, patient.

### POST /reviews/{id}/publish
Auth. Roles: `system_admin`, `clinic_manager`.

### POST /reviews/{id}/hide
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ reason }`

### POST /reviews/{id}/respond
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ body }`
**Response 201** — ReviewResponse.

### POST /reviews/{id}/appeal
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ reason }`
**Response 201** — ReviewAppeal.

### POST /reviews/appeals/{appealId}/resolve
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ resolution_note }`

---

## Merge Requests

### GET /merge-requests
Auth. Roles: `system_admin`, `clinic_manager`. `?status=pending|approved|rejected`

### POST /merge-requests
Auth. Roles: `system_admin`, `clinic_manager`.
**Body** `{ entity_type, source_id, target_id, conflict_data (json) }`

### POST /merge-requests/{id}/approve
Auth. Roles: `system_admin`, `clinic_manager`.

### POST /merge-requests/{id}/reject
Auth. Roles: `system_admin`, `clinic_manager`.

---

## Audit Logs

### GET /audit-logs
Auth. Roles: `system_admin`, `clinic_manager`.
`?user_id=&action=&entity_type=&entity_id=&from=&to=&page=`

### GET /audit-logs/export
Auth. Roles: `system_admin`, `clinic_manager`. Streams `text/csv`.

---

## Common Response Shapes

**Validation error (422)**
```json
{ "message": "The given data was invalid.", "errors": { "field": ["reason"] } }
```

**Unauthorized (401)**
```json
{ "message": "Unauthenticated." }
```

**Forbidden (403)**
```json
{ "message": "This action is unauthorized." }
```

**Not Found (404)**
```json
{ "message": "No query results for model [...]" }
```
