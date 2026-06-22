# Positions → Recruitment Automation — Audit & Design

Audit of the Positions ↔ Recruitment ↔ Employee model and the plan to automate
vacancy detection + auto-requisition. Phase 3 of the `/hr/people` redesign.

## Findings (file:line in the audit agent transcript; summary here)

1. **`HrPosition.current_headcount` is a STORED column that never auto-syncs.**
   `PositionService::syncHeadcount` / `syncAllHeadcounts` exist but have **zero
   callers**; there is **no observer** on `HrEmployeeProfile`. So headcount drifts
   permanently — it's only the create-time default (0). (`PositionService.php:31-53`,
   `AppServiceProvider.php` observer list.)
2. **Recruitment hires never set `position_id`.** `HrOffer` has no `position_id`;
   `convertToEmployee` omits it. Only the manual Add-Employee modal sets
   `HrEmployeeProfile.position_id`. So even with sync, recruitment hires wouldn't
   count against a position. (`RecruitmentService.php` profileAttributes; `HrOffer` fillable.)
3. **No requisition ↔ position link.** `HrJobRequisition` has no `position_id`;
   `position_role` is a free string. (The legacy `HrJobPosting` *does* have
   `position_id` — but the modern requisition is what recruiters create.)
4. **No scheduled vacancy/understaffing job** anywhere (`routes/console.php`).
5. **No JD template library.** JD prose (`summary`, `description`, `requirements`,
   `responsibilities`) lives inline on `HrJobRequisition`; `HrPosition` only has
   `description` + `requirements`. `HrInterviewKit` is a scorecard template, not a JD body.
6. Requisition store = **`hr.jobs.store`** (`RecruitmentJobController@store`, gated
   `hr.recruitment.manage`). Positions = `hr.employees.manage`. Both resolve tenant via
   `resolveHrTenantIdForUser` (single-tenant → 1).
7. Vacancies surface only in `positions-pane.tsx` (a `{n} open` badge); the position
   dialog never exposes `current_headcount`.

## Decisions (vacancy = budget − current − open requisition openings; locked)

1. **Link the models.** New nullable FKs (additive migration):
   `hr_job_requisitions.position_id` → hr_positions, and **`hr_offers.position_id`**
   (so a hire converted from an offer carries the position through). `position_role`
   stays as denormalised fallback.
2. **Keep headcount honest.** Add an `HrEmployeeProfile` **observer** that resyncs the
   affected position(s) `current_headcount` on create / update (incl. old→new
   position_id change, is_active change) / delete / restore. Single-row paths (intake,
   setActive, manual edit) stay correct in real time; **mass updates bypass model events**
   (bulk bar) so the daily job (Phase 3b) is the reconciling backstop.
3. **Convert fills the seat.** `convertToEmployee` sets `position_id` from
   `offer->position_id` when present (offer inherits it from the requisition's position).
4. **Vacancy accessors on `HrPosition`:** `open_requisition_openings` (sum of `openings`
   on linked non-closed requisitions), `actionable_vacancies` = max(0, budget − current −
   openReqOpenings), `is_understaffed` = actionable_vacancies > 0.
5. **JD parity.** Add `summary` + `responsibilities` to `hr_positions` (it already has
   description + requirements) so the position is the canonical JD; requisitions inherit.
   No separate JD library this phase (overkill; `HrInterviewKit` stays the scorecard lib).
6. **Auto-detect + surface (3b).** New `hr:check-vacancies` daily command → syncAllHeadcounts
   then flag understaffed; surface in positions-pane + People hero needs-attention chip.
7. **Auto-open requisition (3c).** New Position modal gets a JD step + a gap-detection
   "Open a requisition for the N vacancies" toggle (off by default) → posts to
   `hr.jobs.store` with `position_id` prefilled from the position. Mirrors the
   onboarding-toggle pattern.
8. **Close the loop (3d).** When a position reaches budget (headcount synced on hire),
   auto-close / prompt-close the linked requisition; emit events.
9. Permissions unchanged (`hr.employees.manage` for positions, `hr.recruitment.manage`
   for requisitions); the auto-open toggle requires both (the actor creating a position
   with the toggle on must also hold recruitment.manage — gate in the controller).

## Build order
- **3a (this iteration) — data foundation:** additive migration (requisition+offer
  `position_id`, position `summary`/`responsibilities`); model fillable/relations;
  `HrPosition` vacancy accessors; `HrEmployeeProfile` observer for headcount sync;
  `convertToEmployee` sets `position_id`. Tests.
- **3b — detection + alerts:** `hr:check-vacancies` scheduled command; understaffed
  surfacing (positions-pane + hero chip). Resync headcount after bulk ops.
- **3c — New Position modal:** JD step + auto-open-requisition toggle (prefill → jobs.store).
- **3d — loop-close:** auto-close/prompt requisition when filled; events.
