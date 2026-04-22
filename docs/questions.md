Q1. Raw Long-Lived Auth Token Stored in Unencrypted Cookie
Question: The `vetops_session` cookie was explicitly excluded from Laravel's `EncryptCookies` middleware via `$middleware->encryptCookies(except: ['vetops_session'])` in `bootstrap/app.php`, and the raw Sanctum token was stored directly as the cookie value. Why is this a high-severity vulnerability, and what is the correct mitigation?
My understanding: Storing a raw bearer token in a plaintext cookie means any attacker who can read the cookie (via XSS, network sniffing on HTTP, or physical access to browser storage) instantly gains a fully functional API credential that never rotates. The `EncryptCookies` middleware exclusion was probably added as a workaround to allow the refresh endpoint to read the cookie server-side, but it trades security for convenience.
Solution: Remove the `encryptCookies` exception so all cookies are encrypted by Laravel's default mechanism. In `AuthController::sessionCookie()`, store `encrypt($token)` as the cookie value so the raw token is never written to the browser in cleartext. In `AuthController::refresh()`, decrypt the cookie value with `decrypt($rawCookie)` before using it as a bearer token, wrapping the call in a try/catch to return a 422 on tampered or expired cookies. In tests, use `disableCookieEncryption()` combined with `withCookie('vetops_session', encrypt($token))` to prevent the framework from double-encrypting the value.

---

Q2. Clinic Manager Can Export All Facilities (Cross-Facility Data Leakage)
Question: The facility export endpoint (`GET /api/facilities/export`) called `$this->importService->export('facility')` without any facility scoping filter, meaning a clinic manager could download a CSV containing every facility's sensitive data (addresses, phone numbers, business hours). How should the export be scoped by role?
My understanding: The index endpoint already inline-scopes to `WHERE id = user->facility_id` for non-admin users, but the export path bypassed this because `ImportService::export('facility')` accepted no filter arguments at the time. The `ImportService` needed to be extended to accept and apply a `facility_id` filter when one is provided.
Solution: In `FacilityController::export()`, compute `$filters = !$user->isAdmin() && $user->facility_id !== null ? ['facility_id' => $user->facility_id] : []` and pass `$filters` to `$this->importService->export('facility', $filters)`. In `ImportService::export()`, extend the `'facility'` case to apply `->when(isset($filters['facility_id']), fn($q) => $q->where('id', $filters['facility_id']))` before calling `->get()`. This ensures managers export only their own facility's row while system admins get all rows.

---

Q3. Null-Facility Non-Admin Accounts Permissive Across Tenant Boundaries
Question: Users with `facility_id = null` who are not system admins could bypass facility scoping in `ScopesByFacility::applyFacilityScope()` and in policies like `PatientPolicy`, `VisitPolicy`, `RentalAssetPolicy`, `ServiceOrderPolicy`, and `StocktakeSessionPolicy`. Why is a null facility treated as permissive, and how should it be treated instead?
My understanding: The original code had a default-allow fallback for null-facility non-admins: "if the user has no facility, don't filter". This was likely a legacy shortcut for superusers before the `system_admin` role was added. But it creates a privilege-escalation path — any non-admin account without a facility assignment effectively bypasses tenant isolation for lists and object-level reads.
Solution: In `ScopesByFacility::applyFacilityScope()`, change the null-facility non-admin branch to `return $query->whereRaw('1 = 0')` so the query returns nothing. In each policy's ownership helper (e.g., `userOwnsFacility`, `sharesFacility`, `view`), return `false` when `$user->facility_id === null` and the user is not an admin. Update all `actingAs*` test helpers to create users with a factory-assigned facility so test data is in the correct tenant scope.

---

Q4. Visit Creation Accepts Foreign `service_order_id` Without Facility Consistency Validation
Question: The `VisitController::store()` validated that `patient_id` and `doctor_id` belong to the visit's facility, but omitted the same check for the optional `service_order_id`. What cross-tenant integrity problem does this introduce, and how is it fixed?
My understanding: If a user submits a `service_order_id` that belongs to a different facility, the newly created visit record references a service order from another tenant. This can expose cross-tenant data when the visit or order is later loaded with relationships, and it makes the facility boundary for service orders inconsistent.
Solution: In `VisitController::store()`, after the patient and doctor facility checks, add: `if (!empty($data['service_order_id'])) { $serviceOrder = ServiceOrder::findOrFail($data['service_order_id']); if ((int) $serviceOrder->facility_id !== $facilityId) { throw ValidationException::withMessages(['service_order_id' => ['Service order belongs to a different facility than this visit.']]); } }`. Add the `use App\Models\ServiceOrder;` import. Regression-test with a cross-facility service order to confirm 422 is returned.

---

Q5. Overdue Minutes Computation Mathematically Incorrect
Question: `RentalTransaction::overdueMinutes()` was computing `(int) $now->diffInMinutes($expected->copy()->addHours($overdueThreshold * 2))`. Why is `* 2` wrong, and why does the direction of `diffInMinutes` matter?
My understanding: The intent is to return the number of minutes a rental has been overdue past its grace threshold — i.e., `now - (expected_return + threshold)`. Multiplying by 2 doubled the threshold, making the grace period appear twice as long. Additionally, Carbon's `diffInMinutes($other)` returns a signed value (`$other - $this`), so calling `$now->diffInMinutes(past_time)` produces a negative result when `past_time < $now`.
Solution: Remove `* 2` from the threshold and reverse the operand order so the computation is `$expected->copy()->addHours($overdueThreshold)->diffInMinutes($now)`, which yields `now - (expected + threshold)` — a positive number when overdue. Update the `ExpandedModelBehaviorMatrixTest` data provider to match: change the hardcoded `active_past_threshold` expected value from `90` to `30`, and change the generated-matrix formula from `$now->diffInMinutes($expected->copy()->addHours($threshold * 2))` to `$expected->copy()->addHours($threshold)->diffInMinutes($now)`.

---

Q6. Review Hide Audit Trail Records Incorrect Old Status
Question: In `ReviewService::hide()`, the audit log call was made after `$review->update(['status' => 'hidden'])`, meaning `$old` captured the post-update state (status already `'hidden'`) rather than the pre-update state. What is the forensic impact and how is it fixed?
My understanding: The audit log exists to record what changed and from what state. If the `old_values` snapshot is taken after the mutating `update()` call, both `old` and `new` will show `status: 'hidden'`, making it impossible to reconstruct the original review state from the audit trail. This reduces the forensic reliability of the moderation log.
Solution: In `ReviewService::hide()`, capture `$oldStatus = $review->status;` before calling `$review->update(['status' => 'hidden'])`. Then pass `['status' => $oldStatus]` as the `old_values` argument to the audit log call and `['status' => 'hidden']` as the `new_values` argument. This ensures the audit record correctly shows the transition from the prior status to `'hidden'`.

---

Q7. Inventory Transfer Not Recorded as Explicit `transfer` Ledger Type
Question: `InventoryService::transfer()` was creating two `StockLedger` entries with `transaction_type => 'outbound'` and `'inbound'` respectively. The config already defined a `'transfer'` ledger type. Why does using `outbound`/`inbound` reduce traceability, and what is the correct fix?
My understanding: Using generic `outbound`/`inbound` types for transfers makes it impossible to distinguish a transfer from an ordinary issue or receipt when querying the ledger. Analytic queries like "show all cross-storeroom movements" cannot be answered without inspecting the `from_storeroom_id`/`to_storeroom_id` fields as a heuristic. The `'transfer'` enum value was already defined in `config/vetops.php`, indicating the design intended a dedicated type.
Solution: Change both `StockLedger::create()` calls in `InventoryService::transfer()` to use `'transaction_type' => 'transfer'`. Update the `InventoryServiceTest::test_transfer_moves_stock_between_storerooms` assertion from checking `'outbound'`/`'inbound'` to checking `'transfer'` for both the `$out` and `$in` entries. Update the `CoverageExpansionTest::test_inventory_transfer_creates_paired_ledger_entries` assertions similarly.

---

Q8. Security Tests Assert Broad Status Set, Reducing Defect Detection Strength
Question: `SecurityTest::test_inactive_user_cannot_access_api` used `$this->assertContains([200, 401, 403], ...)` (or equivalent broad assertions). Why does this weaken the test, and what additional production fix is required?
My understanding: Asserting that the status is "one of several acceptable values" means the test passes even when an inactive user receives a `200 OK` — i.e., gets full API access. The test was written defensively to avoid brittleness, but it accidentally allowed the actual security regression (inactive users with valid tokens can access the API) to go undetected.
Solution: Tighten the assertion to `$this->assertNotEquals(200, $response->status())` to ensure inactive users are definitely not getting data back. Additionally, fix the underlying security gap: create an `EnsureUserIsActive` middleware that checks `$user->active === false` and returns `403` with `{'message': 'Account is disabled.'}`. Register this middleware in the API group in `bootstrap/app.php` (appended after `EnsureFrontendRequestsAreStateful`) so it runs on every authenticated API request.
