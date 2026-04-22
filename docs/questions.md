# Q1. Workstation Identity for Per-Workstation Rate Limiting

**Question:** The prompt specifies "max 10 login attempts per 10 minutes per workstation," but does not define how a workstation is identified on the local LAN. Devices behind a shared router or switch share the same public IP. Should the rate limiter key on IP address, browser fingerprint, a device UUID, or something else?

**My understanding:** On a clinic LAN, multiple exam-room terminals share the same NAT IP. Keying the throttle purely on IP would mean that a brute-force attempt from one terminal locks out all other terminals at the same site simultaneously — defeating the "per workstation" intent. A stable client-side identifier is needed.

**Solution:** Generate a UUID v4 device identifier in the browser on first load, persist it in `localStorage` under `vetops.device_id`, and include it as an `X-Device-ID` request header on every login and captcha-status call. On the server, derive `$throttleKey = X-Device-ID ?? $request->ip()` and key both the `LoginAttempt` table (`throttle_key` column) and the Laravel named rate-limiter (`RateLimiter::for('login', ...)`) on that value. Non-browser clients (e.g. CLI scripts) fall back to IP, which matches the spirit of the requirement.

---

# Q2. Unauthenticated Tablet Review Submission Scope

**Question:** The prompt says staff hand a tablet to the pet owner after a visit to submit a review, but pet owners have no portal accounts. How is the review scoped to the correct visit? What prevents a tablet user from submitting fake reviews for visits they were not part of, or browsing other owners' records?

**My understanding:** Because pet owners are unauthenticated, the only viable scoping mechanism is the visit identifier itself. Staff must open the review flow for a specific visit before handing the tablet over, giving the owner a URL or QR code that embeds the visit ID. The endpoint must be designed so that possessing the visit ID is the only credential required, and no other patient or visit data must be reachable from that endpoint.

**Solution:** Expose `POST /api/reviews/visits/{visit}/submit` as a fully public, unauthenticated endpoint. The visit ID in the URL acts as the access token; the endpoint returns only what is needed for the submission form (visit date, clinic name) and accepts only the review payload (rating, tags, text, up to 5 images). Apply a per-IP rate limit (`throttle:10,60`) to prevent bulk fake submissions. No PII from other visits is reachable, and each submission is immutable once created.

---

# Q3. Inventory Reservation Strategy: Stock Exhaustion Between Order Creation and Close

**Question:** The prompt offers two reservation strategies per service order — "lock inventory at order creation" vs. "deduct when the order is closed." Under the deduct-at-close strategy, what should happen if the on-hand quantity drops below the required amount between when the order was created and when it is eventually closed?

**My understanding:** With deduct-at-close, the system does not hold the stock, so a concurrent issue or transfer can consume the same units another order is expecting. If the close is allowed to proceed regardless, the ledger goes negative and the available-to-promise calculation is violated. The system needs an explicit policy for this conflict.

**Solution:** At order-close time, re-validate available-to-promise before posting the ledger deduction. If ATP is insufficient, reject the close with a `422` error message naming the item and the shortfall quantity, requiring the clinician to either adjust the quantity or switch to a manual adjustment entry. The `deduct_at_close` strategy is documented as "optimistic" — it assumes stock will be available and fails explicitly if it is not, rather than silently going negative.

---

# Q4. Content Targeting Logic: Conjunction or Disjunction Across Dimensions

**Question:** The prompt states content can be targeted "by facility, department, role, and tags." When multiple targeting dimensions are set simultaneously — for example, `facility = Clinic A` and `role = inventory_clerk` — does a staff member need to match ALL dimensions (AND) or ANY dimension (OR) to see the item?

**My understanding:** AND semantics make targeting restrictive and precise (only inventory clerks at Clinic A see it), while OR semantics make it additive (any inventory clerk anywhere, or anyone at Clinic A). These produce very different visibility results. The prompt does not disambiguate. For a clinical communications tool, AND semantics are safer by default — accidental over-sharing of sensitive announcements is worse than under-sharing.

**Solution:** Implement AND semantics across dimensions: a content item is visible to a user only if the user satisfies every non-empty targeting dimension simultaneously (facility match AND role match AND department match AND tag match). Store targeting as a JSON object on the `content_items` row. An empty or null dimension means "no restriction on that axis." Add a preview button in the authoring UI that shows which roles and facilities will see the item given current targeting settings, so editors can verify intent before submitting for approval.

---

# Q5. Overdue Asset Transition: Background Job or On-Demand Check

**Question:** The prompt states "assets auto-transition to 'overdue' after 2 hours past scheduled return." It is unclear whether this transition is driven by a scheduled background job that updates records in the database, or whether "overdue" is a derived/computed state evaluated on-demand each time the asset or transaction is read.

**My understanding:** A background job approach updates rows in place, making the status queryable directly in SQL (useful for dashboards and alerts), but requires a reliable scheduler and risks stale data if the job is delayed. A computed-on-read approach is always accurate but means the `status` column in the database does not reflect overdue state, complicating queries and audit logs.

**Solution:** Use a hybrid: store `status` on `rental_transactions` as a persisted column, and run a scheduled artisan command (`rental:mark-overdue`) every 15 minutes via the Laravel scheduler to transition `active` transactions whose `expected_return_at + 2 hours < now()` to `overdue`. The `RentalTransaction` model also exposes `isOverdue()` and `overdueMinutes()` computed helpers for real-time display in the UI so the countdown is always accurate regardless of when the background job last ran.

---

# Q6. Near-Duplicate Announcement Detection: Threshold and User-Facing Flow

**Question:** The prompt specifies SimHash/MinHash for detecting near-duplicate announcements, but does not define the similarity threshold at which content is flagged, nor the user-facing behavior — should a near-duplicate block the save, show a warning the editor can dismiss, or route to a manager for comparison?

**My understanding:** Too strict a threshold produces false positives (flagging legitimately similar but intentionally distinct announcements). Too loose a threshold lets true duplicates through. The appropriate UX also matters: a hard block is frustrating if the editor is intentionally creating a closely related piece; a silent warning might be ignored; a required confirmation strikes a balance.

**Solution:** Set the SimHash Hamming-distance threshold at 10 bits (out of 64), which empirically flags documents sharing roughly 85%+ of their content fingerprint. When a new draft's fingerprint is within threshold of an existing published or in-review item, return a `409` response with the duplicate candidate's title and ID. The editor UI renders a side-by-side diff modal and requires the editor to either confirm creation (proceeds with a `force: true` flag) or discard. Confirmed near-duplicates are stored with a `duplicate_of` reference for audit purposes.

---

# Q7. Entity Merge Scope: Can Managers Merge Across Facilities?

**Question:** The prompt states "merges require manager confirmation and record conflict resolution rules." It does not specify whether a clinic manager can merge entities that belong to different facilities — for example, a doctor record at Clinic A with a suspected duplicate at Clinic B — or whether cross-facility merges are admin-only.

**My understanding:** Clinic managers are scoped to their own facility in every other part of the system. Allowing a manager at Clinic A to alter or absorb records at Clinic B violates the facility isolation guarantee. Cross-facility deduplication is a data-governance action that could have legal and billing implications, so it should require elevated privilege.

**Solution:** Scope merge-request creation and approval to the `system_admin` role for cross-facility cases. A `clinic_manager` may only create and approve merge requests where both the `source_id` and `target_id` entities belong to their own facility. The `MergeRequest` model stores `facility_id`; the `MergeRequestPolicy` enforces that non-admin users' facility must match that field. The dedup candidates endpoint (`GET /api/dedup/candidates`) aborts with `403` for null-facility non-admins rather than silently returning nothing.

---

# Q8. PII Masking Scope: Which Roles See Unmasked Owner Phone Numbers?

**Question:** The prompt states owner phone numbers are masked to `(555) ***-1234` in "non-admin views," but the RBAC table defines six roles including `clinic_manager` who has significant data access. Is the clinic manager a "non-admin" for PII purposes, or do managers need unmasked phone numbers to contact pet owners about their animals?

**My understanding:** Operationally, clinic managers routinely need to call pet owners to reschedule appointments, discuss treatment plans, or follow up after a visit. Masking the number for managers would require them to route every call through a system admin, which is impractical. However, giving all six roles access to raw PII defeats the purpose of masking. The line should be drawn at roles that have a legitimate operational need for the contact detail.

**Solution:** Allow unmasked phone number access to `system_admin` and `clinic_manager` only. All other roles (`inventory_clerk`, `technician_doctor`, `content_editor`, `content_approver`) receive the masked format. Implement this in `Patient::getMaskedPhoneAttribute()` and expose it via a separate `phone_masked` field in API responses; controllers call `$user->can('viewUnmaskedPii', $patient)` before deciding which field to include. Gate `viewUnmaskedPii` in `AuthServiceProvider` to the two privileged roles.
