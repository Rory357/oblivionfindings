# Surface Safe Work Procedures in HR — Claude **Code** brief

**This is a Claude Code task, not Claude Design.** It is the backend + wiring half of the procedures
redesign (`PROCEDURES_FIX_PROMPT.md`). Claude Design will deliver the read-only panel visuals; you build
the data + endpoints and drop the designed components into HR.

## Why
`SafeWorkProcedure` records carry `applicable_roles` and `related_training`, i.e. **"which staff/roles must
know this procedure."** Audit confirms procedures are **entirely absent from HR today** — zero references
in `resources/js/pages/hr/**`, `app/Http/Controllers/Hr/**`, or the HR models. So there is no way to see
which procedures a staff member is responsible for or has acknowledged. Add that, read-only, with an
acknowledgement record.

## Confirmed anchors (from audit)
- Staff profile: `resources/js/pages/hr/employees/show.tsx` (tabbed; ~13 tabs: overview, documents,
  performance, training, driver, vetting, compliance, leave, onboarding, supervision, cases, assets,
  goals) — controller `app/Http/Controllers/Hr/EmployeeProfileController.php` (`show()`).
- Self-service: `MyHrController` + `/hr/my/*` (has a training/compliance surface already).
- Compliance matrix precedent: `HrStaffComplianceStatus`, `ComplianceMatrixService`,
  `HrComplianceRequirement` — the right mental model for "required vs done per staff".
- Library + lifecycle: `app/Models/SafeWorkProcedure.php`,
  `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php`, routes in
  `routes/health-safety.php` (~109–132).

## Build (spec → confirm → implement)
1. **Model + migration** — `HrStaffProcedureAcknowledgement` (`hr_staff_procedure_acknowledgements`):
   `tenant_id`, `employee_profile_id` (FK → `hr_employee_profiles`, cascade), `safe_work_procedure_id`
   (FK → `safe_work_procedures`, cascade), `acknowledged_at`, `acknowledged_by` (FK → users, nullOnDelete),
   `expires_at` (nullable — periodic re-ack), `certification_type` (`verbal|documented|exam|training`),
   `notes`, timestamps, `unique(employee_profile_id, safe_work_procedure_id)`. Relations both ways
   (`SafeWorkProcedure hasMany …`). Respect existing HR tenant scoping (`ResolvesHrTenant`).
2. **Resolve "applicable" per staff** — match `SafeWorkProcedure.applicable_roles` (and optionally
   `applicable_sites`) against the staff member's role(s)/site, so the panel can show
   **Required → Acknowledged / Outstanding / Review-due**. Keep it a query/service first (no schema change
   beyond the ack table).
3. **Staff profile tab** — add a read-only **"Procedures"** tab/section to `employees/show.tsx`
   (between Compliance and Leave) using the component Claude Design delivers: required procedures, status,
   review date, ack date/by; manager action **Acknowledge** (and **Revoke**). Feed it from
   `EmployeeProfileController::show()`.
4. **Self-service `/hr/my/procedures`** — employee-scoped list of their required procedures (read the
   library, read-only), click → procedure detail, **one-click Acknowledge** (writes the ack row, toast).
   Surface an "outstanding procedure acknowledgements" count on the `/hr/my` overview "needs attention"
   rail.
5. **Controller + routes** — `ProcedureAcknowledgementController` (`acknowledge`, `bulkAcknowledge`,
   `revoke`); routes under `/hr/...` gated by HR permissions; employee self-ack gated to `user_id = me`.
6. **Onboarding hook (phase 2, confirm first)** — allow a procedure acknowledgement as an
   `HrOnboardingTask` type, auto-seeded from `applicable_roles` for a new starter.

## Also do (the non-design backend from PROCEDURES_FIX_PROMPT §6, if not already done by then)
- Dedicated `procedures.{view,create,manage,approve}` permissions in `RbacSeeder` + expose in the
  `HandleInertiaRequests` `can` map (today procedures borrow `hazards.*`, and `hazards.manage` isn't
  exposed to the frontend); migrate route middleware in `routes/health-safety.php`.
- New library transitions: `request-changes`, `archive`/`restore`, `record-review`; `index()` returns
  `tabCounts` + `hero` + `detail` + `can`.
- Add procedures to `HealthSafetyDemoSeeder`; add the analytics metric + H&S hub card datum.

## Done when
- A staff member's required Safe Work Procedures are visible (read-only) on their HR profile and on
  `/hr/my`, with acknowledge/revoke and review-due status, single-source-of-truth in the H&S library.
- No duplication of the procedure content into HR; HR only stores **acknowledgement** state.
- Tenant + `hr.*` gates respected; lint + types clean.
