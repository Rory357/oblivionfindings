# Training Hub — Gap Analysis & Build Status

Canonical page: `/hr/training/catalog` (also served at `/hr/training`). The standalone
dashboard + course-detail pages are consolidated into a single hub with three in-page
tabs (Dashboard · Catalog · Assignments), a golden hero, a course slide-over sheet, five
Add-Client-style wizards, context menus, toasts and `n` / `/` shortcuts.

Prototype: `Training Hub.dc.html`. Backend handover: `TRAINING_CATALOG_BACKEND_HANDOVER.md`.

## Backend (handover items) — DONE

- [x] **0. Compliance bridge mitigation** — create/edit wizard surfaces & persists
  `compliance_requirement_id` (Compliance step → "Linked H&S requirement"). Catalog
  completions now feed `StaffTrainingRecord` when a requirement is linked.
- [x] **1. Rich course columns (additive slice)** — `add_rich_columns_to_hr_courses`:
  learning_outcomes, prerequisites, requires_renewal, validity_period_months,
  renewal_reminder_months, requires_assessment, pass_mark_percentage, cpd_points,
  provider_reference, mandatory_for_roles, org_pays_provider, staff_can_claim. (Full
  source-of-truth *unification* / polymorphic backfill intentionally deferred — needs
  Chane sign-off; catalog stays canonical, bridge via requirement.)
- [x] **2. Course CRUD** — `updateCourse` (PUT) + `UpdateTrainingCourseRequest`;
  `toggleCourse` (PATCH, is_active) + `bulkArchiveCourses`.
- [x] **3. Session CRUD** — store / update / cancel (soft: `cancelled_at` + reason);
  `extend_hr_course_sessions` adds online_link, trainer_id, waitlist_enabled, notes,
  cancelled_at, cancellation_reason.
- [x] **4. Assignments model + endpoints** — `hr_course_assignments` + index (in hub
  payload) / store (audience expansion individuals|role|site|cohort) / `preview`
  (debounced headcount + conflicts) / remind / waive.
- [x] **5. Bulk** — `courses/bulk-archive`; assign-to-cohort reuses the Assign wizard.
- [x] **6. Search + sort** — catalog search widened to title+code+provider+category;
  sort title|completion|enrol|cost|expiring (client-side over the full tenant set).
- [x] **7. Export** — `GET /hr/training/export?type=catalog|assignments|enrolments` (CSV stream).
- [x] **8. Completion** — certificate file upload (private disk), POST `/training/record`
  (multi-person find-or-create + complete), CPD/validity-driven expiry, assignment auto-close.
  Existing PUT complete endpoint retained; `enroll` now accepts multiple `user_ids`.
- [x] **9. Fees** — `training` added to expense categories; `source_type`/`source_id` on
  `hr_expense_items`; Claim wizard → submitted `HrExpenseClaim` linked to the course.
- [x] **10. GL double-count rule** — `HrCourseEnrollmentObserver` suppresses the provider
  posting when `staff_can_claim && !org_pays_provider` or a non-rejected training expense
  item already references the enrollment.

## Frontend — DONE

- [x] Golden hero (clickable HeroStats → tabs, 4 quick actions, needs-you strip).
- [x] Tab strip with right-click (Open / Set default / Pin) persisted to `localStorage`.
- [x] Dashboard: KPI tiles, overdue/due-soon renewals table, completion-by-site, upcoming sessions.
- [x] Catalog: `/`-focus search, sort, cards/table toggle, multi-select + bulk bar, status
  pills, actionable fee, row/card context menu.
- [x] Assignments: status filter chips, person/course/source/due/status/score table, row context menu.
- [x] Course detail slide-over sheet (stats, sessions w/ cancel, recent enrolments).
- [x] Five wizards (Create/Edit Course · Session · Assign · Record · Claim) — left rail,
  completeness meter, progress bar, per-step validation, jump-to-step, Review, Save &
  add another, success pane, toast. Assign has live preview; Record has cert upload;
  Claim has repeatable items + live total.
- [x] Tokens only (no raw hex).

## Verification

- TypeScript: `tsc --noEmit` clean for training files (pre-existing `@/routes` Wayfinder
  noise is an environment artefact — those modules aren't generated in the worktree).
- PHP: `php -l` clean across all new/changed files.
- Tests: `tests/Feature/Hr/TrainingHubTest.php` covers render, course CRUD, sessions,
  assignments + preview + waive, record completion, fee claim + source link, export, RBAC.

## Deferred (needs Chane)

- Full source-of-truth unification (`StaffTrainingRecord` ↔ `HrCourse` polymorphic, data backfill).
- Competency frameworks + Staff induction surfacing (tabs vs cross-links).
