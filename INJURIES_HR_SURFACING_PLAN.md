# Injuries in the HR module — Surfacing Plan (CLAUDE CODE)

**Owner: Claude Code (backend + integration), not Claude Design.** This is the companion to
`INJURIES_FIX_PROMPT.md`. It answers the question *"does this need a place in the HR module as well?"*

## Verdict: yes — and it has none today

`WorkplaceInjury` is keyed to **`user_id` (a staff member)** and carries ACC + return-to-work +
WorkSafe data. It is **staff/people data**. Audit confirms it currently has **zero** HR presence:

- `routes/hr.php` — **no** injury/RTW routes.
- `resources/js/pages/hr/**` — **no** page references `WorkplaceInjury`, injuries, or RTW
  (employee profile, HR analytics, wellbeing, leave, cases — none surface it).
- It lives only under Health & Safety nav (`app-sidebar.tsx` → "Injury & Recovery" → "Workplace
  Injuries" → `/health-safety/injuries`).

H&S remains the **system of record**. HR should surface the same records (single source of truth),
read-mostly, so a manager looking at a staff member sees their injury/RTW history without leaving HR.

## Scope to build

1. **Staff/employee profile — "Injuries & Return to Work" section** *(primary)*
   - File: `resources/js/pages/hr/employees/show.tsx` (add a section/tab).
   - Content: that staff member's `WorkplaceInjury` records (`WorkplaceInjury::forWorker($userId)`),
     each with severity/status/lost-time/ACC/WorkSafe, plus active RTW plan + latest capacity
     assessment. **Read-only**; click/right-click opens the shared `InjuryDetailDialog` in the
     `embedded`/`readOnly` mode that Claude Design is leaving as a seam (see `INJURIES_FIX_PROMPT.md` §7).
   - All mutation deep-links to `/health-safety/injuries` — do not fork create/close here.
   - Backend: extend the employee `show` controller to pass the worker's injuries (paginated/capped),
     gated on the viewer's permission.

2. **HR navigation entry**
   - Add a "Workplace Injuries" / "Injury & RTW" link in the HR nav group in `app-sidebar.tsx`
     (pointing at `/health-safety/injuries`, or an HR-scoped index if we want a people-first view),
     so HR users discover it. Decide: deep-link to H&S vs. a thin HR-scoped register reusing the
     same controller with an `hr` context flag.

3. **HR analytics — workforce injury KPIs**
   - File: `resources/js/pages/hr/analytics/index.tsx` (+ its controller/service).
   - Surface lost-time days, open ACC claims, LTIFR/TRIFR for the workforce, injuries by severity.
     Reuse `HsAnalyticsService` / `HsKpiService` rather than recomputing.

4. **Cross-links to adjacent HR workflows** *(flag / decide)*
   - **Leave & rostering availability:** an active RTW plan / modified duty constrains availability —
     consider a read-only "on modified duties until {date}" badge on the roster/leave views.
     `ModifiedDuty` already has `hours_per_day` + date range.
   - **HR cases / wellbeing:** a serious/psychological injury may warrant a linked HR case or wellbeing
     follow-up. Decide whether to offer a "create linked case" deep-link (no new data model).

## Constraints / guardrails
- H&S is the system of record; HR surfaces are **read-mostly** and deep-link out for any mutation.
- Reuse the shared `InjuryDetailDialog` + row/badge components (the seam Claude Design leaves) — do
  not build parallel injury UI in HR.
- Permission-gate the HR views (managers/HR roles); respect existing `hazards.view` / HR policies.
- NZ-only, web-only, semantic tokens — same house rules as the rest of the app.

## Suggested order
1. Confirm the `InjuryDetailDialog` `embedded`/`readOnly` seam landed (from the design pass).
2. Employee-profile section (controller data + read-only UI).
3. HR nav entry.
4. HR analytics KPIs (reuse H&S services).
5. Decide + (optionally) build the leave/roster availability badge and HR-case deep-link.
