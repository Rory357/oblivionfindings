# P10 audit — emergency access and downtime, as they are today

Read-only audit of `origin/main` `4f7f37245` (1 October 2026), made before the questions were sent to Main. **[V]** marks a line I read myself; **[A]** marks the audit agent’s report, spot-checked in the same area but not re-read line by line. Paths are relative to the repository root.

Aliases: BGC `app/Http/Controllers/BreakGlassController.php` · EAC `app/Http/Controllers/EmergencyAccessController.php` · MSDS `app/Services/Medication/MedicationScopeDecisionService.php` · CP `app/Policies/ClientPolicy.php` · RQD/RVD/ACC `resources/js/pages/emergency/{_request-dialog,_review-dialog,access}.tsx`

## 1. Routes and gates
- [V] Page GET `/emar/emergency-access` — `permission:medications.breakglass` (routes/emar.php:356-358).
- [V] Request POST `/clients/{client}/break-glass` — `medications.breakglass` (routes/clients.php:204-206), plus policy `breakGlass` (CP:127-131).
- [V] Revoke DELETE and extend POST — `breakglass|audit.view` (emar.php:361-368). Review POST — `audit.view` only (emar.php:371-373). Flag dismiss — `audit.view` (380-382).
- [V] Policy PUT `/emar/break-glass-policy` has no route middleware; admin/provider_manager check inside the controller (emar.php:376-377).
- ⇒ NF-12 confirmed: coordinators, auditors and clinical leads hold the review permission but can't open the only page with the review dialog.

## 2. Who holds what (RbacSeeder)
- [V] `medications.breakglass`: admin (all non-restricted, 627-631; not in the restricted list 30-39) and provider_manager (666). No other role; the only migration touching it is the April canonicalisation (rename, no grants).
- [V] `medications.audit.view`: provider_manager 666, coordinator 723, auditor 867, clinical_lead 995 (+ admin).
- [V] team_lead (890) and support_worker (777) have no break-glass.
- [V] Consequence: CP `viewMedications` lets audit.view holders see every MAR in their Sites (CP:75-89), so the seeded break-glass holders never *need* a grant to view — today a grant only matters for recording and orders.

## 3. Request (BGC:31-85)
- [V] Reason required when the policy says so; `minutes` 5..policy max; `co_signed_by` must differ from the requester and be an approved user with breakglass or audit.view and Site access (37-56).
- [V] Both acknowledgements are `nullable|boolean` (58-59) — but the authority check requires both true (MSDS:1022-1031). A grant without them opens the MAR (CP:122-129 checks only live-ness) but can't record.
- [V] Co-signer = an id picked from a list; no confirmation by that person (46-56, 73).
- [V] No check for an existing live grant → duplicates allowed.
- [V] RQD hardcodes `DURATIONS = [30, 60, 120, 240]` (54) — ignores the policy.
- [V] Authority copy with no backing check: "Self-authorised (RN+)" (129, 350), "I am a Registered Nurse or above…" (422), "incident report within 48 hours" (474).
- [V] Notification: `notifyCrud(... 'created', 'break-glass access' ...)` (79-82).

## 4. Extend, revoke, expiry
- [V] Extend (87-114): live only; +policy extend minutes, hard cap created_at + max; overwrites `expires_at`; no reason, no event row, no notification. The grantee can extend their own (canManage 24-29).
- [A] Revoke (203-224): sets revoked_by, soft-deletes; no reason.
- [V] No expiry job: console.php only schedules `breakglass:daily-report` (236-238). Expiry = `expires_at > now()` at read time (MSDS:935-936; CP:127).
- [A] Mid-task expiry → generic 403 "You do not have a current assignment for this medication action." The MAR gets a `breakGlassAccess` prop nobody reads (no hit in pages/emar for it [V]).
- [V] Policy edits apply retroactively: the authority check reads the *current* policy max and reason rule (MSDS:1034-1053).

## 5. Review (BGC:116-147)
- [V] No reviewer ≠ grantee (or ≠ co-signer) check.
- [V] `forceFill` overwrites any earlier review, including notes and the incident link.
- [A] No check the grant has ended (UI hides Review on live grants only). "Awaiting review" leaves out revoked grants (EAC:170-186).

## 6. What a grant unlocks (MSDS)
- [V] `resolveClientAuthority` (819-855): covering shift first, then `activeBreakGlass` (923-944): breakglass permission, Site access, grant for this user + this client, created ≤ action time ≤ expiry, expiry > now, canonical check.
- [V] Rounds never: `resolveSiteAuthority` refuses (888-892, comment "must never widen into authority over a whole round").
- [A] Used by dose, PRN, PRN effect, My Day meds, client medical, API, guided-round item, order/prescription writes (if the user also holds order permissions), refusal follow-up, error, audit-event flag. Controlled still needs `controlled.record`.
- [V] ClientMarController:20-29 redirects a breakglass holder without MAR access to `emar.emergency_access?request_client=` — the only contextual entry today.

## 7. Notifications
- [V] `AppEventNotification::via` = `['database']` only (19-22).
- [V] No routing rule for `break_glass_access.created` (config/notification_routing.php only has `breakglass.daily_report`, 74) → default MANAGER_ROLES: admin, provider_manager, coordinator, **hr, finance**, auditor (NotificationService:25-32), org-wide.
- [V] Daily report (SendDailyBreakGlassReport:17-51): `now()->subDay()->startOfDay()` with app tz UTC (config/app.php:99) → wrong NZ day; times printed UTC; links to `/medications/audit`.
- [A] Extend, review and expiry notify nobody; Control Room bridges for break-glass are dead code.

## 8. Audit trail
- [V] Zero AuditLogger calls in BGC and EAC. P09's `Emar/AuditLogController` has no break-glass source (grep: 0).
- [V] Only trail: the grant row + `break_glass_access_events` (`viewed_mar` EC:1326 with 5-min dedupe; `recorded_dose`/order uses via `recordBreakGlassUse`, e.g. ClientMedicalController:776, MedicationsApiController:1136, EC:4247).
- [A] Dose records don't carry the grant id (only order discontinue does).

## 9. Downtime
- [V] PDFs: MAR chart and CD register need `client_id` (EmarPdfController:28-29, 103-104); Reports.tsx:505, 1310, 1317, 1324 link without it → fail. Dates from `Carbon::now()` (UTC) (34-35, 109-110).
- [V] Round sheet lists `$round->administrations` — already-recorded doses; an unstarted round prints "No medications assigned to this round." (round-sheet.blade.php:54-69). Useless as a blank paper sheet.
- [V] Offline banner: "You're offline. We'll send anything you save when you're back." (offline-status-banner.tsx:95-97) — but Record Dose refuses offline (record-dose-wizard.tsx:308), as do emar-dialog, shift-medication-card, CD and stock dialogs (navigator.onLine checks).
- [V] Service worker clears only old cache versions on activate (sw.js:94-106) — nothing on logout. [A] Every navigation network-first with cache fallback and no stale marker.
- [V] Offline dose time: `administered_at` if sent, else `captured_offline_at` when `queued_offline` (HandlesMedicationSync:72-83).
- [V] No entered-late / paper column on administrations (migrations grep: none). [A] Past times allowed inside a covering (even completed) shift or live grant; outside the dose window a free-text reason; future > 1 min rejected.
- [A] CD manual entry: no settable `recorded_at`; witness time = now or captured_offline_at; `on_hand_before` must match current stock → paper backlog is order-sensitive.

## 10. Paper reconciliation
- Nothing exists (no downtime mode, no paper flag, no reconciliation list).

## Plan anchors
- Revised plan §6 (254-259): contextual request from blocked states (breakglass holders only); nobody else told about break-glass; print pack in Hub 6 · Print & exports with a Meds today link when offline/stale; paper reconciliation needs scope approval.
- Table 231: keep `?request_client=`; add review access for audit.view. Row 341 states: expired mid-task; revoked; justified / not justified; misuse flag.
- P11 v5 AUDIT §6: build with P10 — durations only up to the longest grant; second-person confirmation (optional/required); review due within a set time with overdue follow-up; reviewer ≠ the person who used it.
- P08a only links to emergency access (README:13) — no view designed there.
- Relief/agency access = D2 (Verification-and-open-decisions.md:166), not P10.
