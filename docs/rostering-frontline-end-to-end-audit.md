# Rostering & Frontline Workforce — End-to-End Audit and Implementation Plan

**Date:** 2026-06-10 · **Branch:** `main` (clean) · **Scope:** web/desktop only
**Surfaces:** My Day, Shifts, Job Board, Rostering, Calendar, Availability, Handovers, Shift Notes, Timesheets, Conflict Queue
**Reference patterns:** Add Client wizard (`resources/js/components/clients/add-client-dialog.tsx`) and the Rostering hero (`resources/js/pages/operations/rostering/index.tsx` → `PageHero`).

Method: live-repo inspection (no stale-doc trust). Five parallel code sweeps + direct verification of every load-bearing claim. Where a sweep's claim was falsified by direct inspection, the corrected fact is recorded here with evidence.

---

## 1. Reference Pattern — Add Client Workflow

**Files**
- `resources/js/components/clients/add-client-dialog.tsx` (2,577 lines — shell, stepper, 8 steps, review, success pane)
- Opened from `resources/js/pages/operations/clients/index.tsx`; also reused by `pages/sites/show.tsx`
- Server validation: `StoreClientRequest`; submit → `POST /operations/clients` (edit: `_method:put` to `/operations/clients/{id}`)
- Companion standard for single-step popups: `docs/POPUP_STYLE_GUIDE.md` (shell+body split, width tokens, tile pickers)

**Anatomy (the wizard contract)**
| Element | Implementation |
|---|---|
| Dialog shell | `DialogContent` `overflow-hidden p-0 [&>button]:hidden`, inline `style={{ maxWidth: 'min(94vw, 1080px)', width: 'min(94vw, 1080px)' }}` (`add-client-dialog.tsx:915-918`) |
| Body frame | `flex h-[min(92vh,860px)] min-h-0 overflow-hidden` (line 1064) |
| Stepper rail | `aside` `w-[248px] shrink-0 border-r border-sidebar-border bg-sidebar p-4` — icon tile + product title header, one button per step with 26px status circle (active = primary, complete = green ✓), label + blurb, **completeness meter** pinned at bottom (lines 1066-1144) |
| Header | `Step {n} of {total} · {label}` left + custom X close right (lines 1148-1161) |
| Progress bar | 3px strip under the header, width `=(step+1)/steps` (lines 1164-1169) |
| Step body | scrollable `px-6 py-6`; every step starts with `StepHead` (icon tile `rounded-xl bg-primary/10 p-2.5` + `h2 text-lg font-bold` + blurb); enters with `animate-in fade-in slide-in-from-right-2` |
| Form grouping | `grid gap-4 sm:grid-cols-2`, `SubHead` uppercase 11px dividers per group, `Field` wrapper = Label + red `*` + inline hint + `FieldErr` (AlertTriangle + 12px critical text) |
| Controls | `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker` (Send-Kudos tiles), `ConsentChip`, `PhotoField`, `Ring` (SVG completeness ring) — lines 470-853 |
| Footer | `border-t bg-muted/30 px-5 py-3.5`; ghost **Back** left (hidden on step 1); right: outline **Cancel** + primary **Continue →** ; review step swaps in secondary **Save & add another** + primary **Create {noun}** with `Loader2` while processing (lines 1179-1234) |
| Validation | per-step client validation mirroring the server request (`validateStep`, lines 859-890); on submit, every gating step re-validated and the wizard jumps to the first failing step; server errors mapped back to their step via `STEP_FOR_PREFIX` (lines 431-464) |
| Review step | `ReviewCard` per section with icon + Edit link jumping back to the step; `ReviewRow` key/value lines; status values use `StatusBadge` (lines 2272-2516) |
| Success pane | centred ✓ tile + `{name} added` + “Add another / Go to profile” (lines 2521-2576) |

**Reuse rule for other workflows:** single-resource ≤8-field forms follow `POPUP_STYLE_GUIDE.md` (one-step shell); anything sequential/multi-section follows the wizard contract above. The wizard primitives (`Field`, `SubHead`, `StepHead`, tile/chip pickers) are currently **private to this file** and have been copy-pasted elsewhere — see finding I-7.

## 2. Reference Pattern — Rostering Hero

**Files**
- Usage: `resources/js/pages/operations/rostering/index.tsx:2195-2400`
- Component: `resources/js/components/page/page-hero.tsx` (+ `page-hero-stats/badges/meta/actions/quick-actions/avatar-stack`)
- Contract documented for governance in `docs/GOVERNANCE_HERO_GUIDE.md`; this audit extends the same contract to workforce pages with `category="ops"`.

**Anatomy**
- `PageHero category="ops"` → gradient banner `rounded-2xl` with three decorative orbs; **note `--category-ops` aliases `--primary`** (`resources/css/app.css:200,316`), so ops pages and default-primary pages render identically today.
- Big circular icon (24/28px ring tile), eyebrow “live” line (pulse dot + `Live roster · refreshed just now`), greeting title (`Kia ora {name}, your week at a glance — {range}`), sentence-form description with live counts.
- `meta` row (icon + label trio), `badges` (tones default/success/warning/critical/info, optional hover-pin popovers), right column `actions` (primary = `bg-primary-foreground text-primary`, secondary = outline on dark) + 4 inline `stats`.
- `footer` strip inside the banner (`border-t border-primary-foreground/20`): week stepper (`‹ Wk · current · pick week · Wk ›`) left, `EntityFilter`/`SiteFilter` with `onDark` right.

**What should improve (and become the shared pattern)**
1. Conflict-queue action uses a `MoreHorizontal` icon button (`rostering/index.tsx:2320-2329`) — semantically opaque; sidebar uses `AlertTriangle` for the same destination.
2. “refreshed just now” is static copy, not a live timestamp (acceptable; keep copy honest elsewhere).
3. The hero’s week-stepper + filters footer is the de-facto manager-surface pattern — Handovers, Shift Notes and Shifts already imitate it; the imitations should sit on `PageHero` rather than forked shells (see I-1/I-2).

## 3. End-to-End Workflow Map (verified routes ⇄ UI)

**Support worker (frontline)** — home `/my-day` (`MyTasksController`, `routes/web.php:129`):
- **Start shift:** hero clock toggle → `POST /attendance/clock-in` (`AttendanceController@clockIn`). Break toggles → `/attendance/break/start|end`.
- **Read handover:** Digest panel “Handover” tab (incoming `ShiftHandover`) → confirm-read `PATCH /attendance/handover/{id}/acknowledge`. Next-shift briefing in Tomorrow panel renders the full incoming handover.
- **Today’s shift details:** hero (site, residents avatar stack, times) + What’s-Next rail (meds + tasks per active shift); roster links to `/my-roster` (worker week view, `RosterController`).
- **Meds:** `POST /my-day/medications/{id}/administer|refuse|snooze` → `EnhancedMarService` (eMAR-safe; the 2026-06-08 bypass findings are FIXED — see §5).
- **Notes/handover out:** “Write handover” quick action → sheet → `POST /attendance/handover` (same `ShiftHandover` model + `ShiftHandoverService` as the manager workspace — two entry points, one model; intentional).
- **Tasks/checklists:** rail toggle → `POST /my-tasks/shift-task/{id}/complete`; site checklists → run modal.
- **Timesheet:** “Today’s timesheet” → `TimesheetReviewDialog` (allocation + details) → `POST /my-tasks/timesheet/{id}/submit`; `ensure-today` creates the draft when missing. End-of-shift checklist gates clock-out on blockers (flashed `clock_out_blockers`).

**Manager (scheduler)** — Workforce sidebar section (`app-sidebar.tsx:936-994`) = exactly the ten audit surfaces:
- **Create/edit shifts:** `/operations/shifts` index → `CreateShiftDialog` (single-pane; `GET /operations/shifts/create` 302s into the index with prefill params — `ShiftController@create:807`). Lifecycle: assign/unassign/auto-fill/start/complete/cancel/reopen/duplicate/promote-to-series/publish/broadcast/replacement-request (28 routes, all wired).
- **Rostering board:** `/operations/rostering` — 10 tabs (`shifts` grid, `calendar`, `open`, `coverage`, `timeoff`, `availability`, `capacity`, `analytics`, `templates`, `recurring`; `index.tsx:2065-2130,2483-2760`). Publish flow: validate → review (`publish/Review.tsx`) → diff (`publish/Diff.tsx`) → publish/republish/unpublish (permission `rostering.publish`).
- **Calendar:** `?tab=calendar` renders `RosteringCalendarView` (= `pages/calendar/index.tsx`, FullCalendar) fed by `operations.rostering.calendar.events`; drag-create/update POST/PATCH `…/calendar/shifts`. `/scheduling` 301s here (`routes/portal.php:1`).
- **Availability:** `?tab=availability` (`AvailabilityPane` + `TimeOffPane`); writes via `operations.rostering.time_off.*` and `staff/{user}/availability`; `/operations/availability` 302s to the tab (`routes/operations.php:873`).
- **Open shifts / Job Board:** `/operations/job-board` scopes for-you/all/mine/replacements/approvals; worker **claim** → manager **approve** (`JobBoardController`); positions created from a shift (`POST /operations/shifts/{shift}/open-position`).
- **Conflicts:** `/operations/rostering/conflicts` (`RosteringController@conflicts`) + `ResolveConflictDialog`; linked from sidebar + rostering hero.
- **Handovers / Shift Notes review:** `/operations/handovers` (cards/list/board + 4-step wizard + acknowledge/submit), `/operations/shift-notes` (cards/list + 5-step wizard + flag/review/export).
- **Timesheets:** `/operations/timesheets` unified index (tabs incl. `?tab=submitted` approval queue; `/approvals` + `/create` 302 into it — `routes/operations.php:893-919`); approve/reject/return + bulk; payroll adjustments. Legacy `/timesheets/*` → `LegacyRouteRedirectController`. Separate `hr/time/timesheets` (`Hr\TimeTrackingController`) is the HR-department system for non-shift staff — **intentional product scope**, not frontline duplication (My Day links only to operations timesheets).

## 4. UX/UI Inconsistency Inventory (vs the two references)

Verified state per surface (hero + workflow chrome):

| Surface | Hero | `category="ops"` | Wizard/dialog pattern | Status badges | Notes |
|---|---|---|---|---|---|
| Rostering | `PageHero` ✅ (reference) | ✅ | panes + 12 single-step dialogs + Template wizard | mixed `Pill`/tones | conflict action icon = `MoreHorizontal` (I-9) |
| Conflicts | `PageHero` ✅ (`conflicts.tsx:704`) | ✅ | resolve dialog single-step | ✅ | — |
| Job Board | `PageHero` ✅ (`job-board-hero.tsx:124`) | ✅ | inline claim/approve | ✅ | (a sweep mis-reported this as custom; verified it wraps PageHero) |
| My Day | `PageHero` ✅ (`my-day-hero.tsx:416`) | n/a (avatar hero) | review dialog = 2 sub-tabs, no step header (I-8) | ✅ | first-glance: §5 |
| Handovers | `PageHero` via wrapper (`handovers-hero.tsx:239`) | ❌ (invisible today — ops≡primary) | 4-step wizard, rail `w-[260px] bg-muted/30`, 1px progress (I-5) | ✅ | — |
| Shift Notes | `PageHero` via wrapper (`shift-notes-hero.tsx:282`) | ❌ (invisible) | 5-step wizard, default dialog width (I-5) | ✅ | — |
| **Shifts** | **forked hand-rolled hero** (`shifts-hero.tsx:110-311`) | n/a | `CreateShiftDialog` single-pane (~560-line body) | ✅ | **static fake badges** (I-0) |
| **Timesheets** | **forked hand-rolled hero** (`timesheets-hero.tsx:202-309`) | n/a | create/view dialogs single-step | ✅ | **hardcoded `white`/`emerald-300`** (I-2) |
| Attendance | `PageHero` bare (`attendance/index.tsx:134`) — no icon/category/stats | ❌ | page forms | ✅ | under-specified hero (I-6) |
| Calendar / Availability | tabs inside Rostering hero | — | — | — | — |

**Findings (I-numbers feed §8 ranking):**
- **I-0 — Shifts hero shows fabricated status.** `shifts-hero.tsx:189-194` renders `Auto-schedule ready` and `Week published` pills **unconditionally** — “Week published” shows on unpublished weeks. Misleading operational state on a manager surface.
- **I-1 — Shifts hero is a forked shell.** Hand-rolled gradient/orbs/medallion/footer duplicating `PageHero` (different gradient: `linear-gradient(135deg …)` vs PageHero’s `to_bottom_right`). Only listed surface not on `PageHero`.
- **I-2 — Timesheets hero is a forked shell with token violations.** `timesheets-hero.tsx:206-307` hardcodes `text-white`, `bg-white/10`, `bg-emerald-300` etc. instead of `primary-foreground`/`status-*` tokens (violates `docs/DESIGN_TOKENS.md`); duplicates orbs/medallion/stat tiles.
- **I-3 — Create Shift dialog diverges from the wizard contract.** Single scrolling pane with 10+ fields incl. recurring config (`create-shift-dialog.tsx:660-1220`); `POPUP_STYLE_GUIDE.md` says >8 fields → wizard/page. Functional and well-tested; full wizardization is a broad rewrite (deferred — see plan).
- **I-4 — Wizard chrome drift.** Handover wizard: rail 260px `bg-muted/30`, 1px progress; Add Client: 248px `bg-sidebar`, 3px. Note wizard: no explicit width token (default dialog width) vs Add Client `min(94vw,1080px)` / Handover `min(96vw,1000px)`.
- **I-5 — (merged into I-4).**
- **I-6 — Attendance hero under-specified (FIXED, two stages).** Stage 1 added icon/category/stats. Stage 2 (2026-06-10, follow-up request) brought it to the full Rostering pattern: live eyebrow, `Kia ora {name}` greeting (switches to “{staff}’s attendance” when a manager filters another worker, with a warning “Viewing {name}” badge), sentence description with clock state + today’s hours + session count, meta row, truthful badges (on-the-clock since, eligible-shift count, timesheet-sync link), stats Today/Sessions/On-the-clock/Eligible. The redundant body `OpsStatCard` row (duplicating hero stats) was removed, and the page’s `toLocaleString()` US-format timestamps were swapped to the shared en-NZ/Pacific-Auckland `datetime.ts` helpers (the “5/23/2026, 4:28 AM” rows now read “Sat 23 May, 4:28 am”).
- **I-6b — Attendance had no manager nav path (FIXED).** `/attendance` appeared nowhere in `app-sidebar.tsx`; its only link was the frontline `StaffPageShell` “More” menu (`staff-page-shell.tsx:88`), so managers could reach the page (route permits `timesheets.viewAny|…|shifts.manageAny`, `routes/shifts.php:96-98`, and it has a manager staff-picker) only by URL. Fixed: Workforce sidebar entry between Timesheets and Conflict Queue (`Timer` icon), gate `timesheets.viewAny|viewAssigned|shifts.viewAssigned|shifts.manageAny` (a subset of the route gate), `/attendance` added to `WORKFORCE_ROUTE_PREFIXES` for active-state, sidebar test extended (`app-sidebar.test.ts`).
- **I-7 — Wizard primitives duplicated.** `Field`/`SubHead`/`SelectInput` copy-pasted into `components/rostering/template-dialogs.tsx:166-241`; handover wizard re-invents `FieldError`. No shared module.
- **I-8 — WITHDRAWN after direct inspection.** The My Day `TimesheetReviewDialog` (`pages/my-day/_dialogs.tsx:1316-1493`) already conforms to `POPUP_STYLE_GUIDE.md`: icon+title+description header, locked-context card, allocation-method tile picker, live sum-balance banner, per-**resident** tabs (not workflow steps), inline errors, `DialogFooter` outline-Cancel + primary Submit with `Loader2`. The audit sweep’s “Allocation/Details sub-tabs without step headers” claim was inaccurate — no change needed.
- **I-9 — Rostering hero conflict action icon.** `MoreHorizontal` for “Conflict queue” (`rostering/index.tsx:2320-2329`); sidebar uses `AlertTriangle`.
- **I-10 — Date formatting fragmentation.** `lib/datetime.ts` (en-NZ, Pacific/Auckland — canonical) vs legacy `lib/date-format.ts`, plus ad-hoc `toLocaleDateString('en-NZ', …)` in `handover-detail-dialog.tsx:135`, `handovers-hero.tsx:84`, `shift-notes-hero.tsx:98`, `note-wizard.tsx:97-98`.
- **I-11 — Terminology.** “Open position” (Job Board model) vs “open shift” (status) used interchangeably in copy (e.g. JobBoard notification copy “Open shift at …”); also `/emar/handovers` (clinical) shares the “Handovers” nav label with `/operations/handovers` under different sections (acceptable, but copy should stay qualified).
- **I-12 — `category="ops"` missing** on Handovers/Shift Notes/Attendance/timesheets-edit/suggestions/publish heroes. Zero visual impact today (ops≡primary) — prop hygiene so a future ops-token change doesn’t fork these pages.
- **Empty/loading/error states:** consistent across surfaces (skeleton-light, text empty states, Sonner toasts, inline `FieldError`-style validation). No instance of validation-in-toast found in the ten surfaces.
- **Destructive actions:** rostering dialogs (Mark ended early, Unassign→open, Cancel) use inline warning tone + reason fields rather than a uniform confirm pattern; `ConfirmDialog` exists (`components/confirm-dialog.tsx`). Acceptable (each collects a reason — richer than a bare confirm); noted as P2 copy/affordance polish only.

## 5. My Day First-Glance Readiness

Verified against the checklist (all wired; the 2026-06-08 audit `docs/my-day-audit-fix-plan.md` is **implemented** — its P0s are fixed with regression coverage: `MyDayMedicationActionTest`, `MyDayHandoverDigestTest`, `AttendanceClockOutBlockerTest`, `MyDayTimesheetAllocationTest`, `MyDayNotificationsDigestTest`, `ShiftClinicalControllerTest` — 66 tests/493 assertions green):

| Need | Present? | Where |
|---|---|---|
| Current shift | ✅ | hero (site, residents, times, live badge) |
| Next shift | ✅ | Tomorrow panel + next-shift briefing (incl. incoming handover) |
| Site/client context | ✅ | hero avatar stack + resident filter pills |
| Clock-in/attendance state | ✅ | hero clock/break toggles + “Not clocked in” state |
| Handover to read | ✅ | Digest “Handover” tab + unread badge; confirm-read acks |
| Key risks/alerts | ✅ | Digest “Needs you” (control-room alerts, incident follow-ups) + badge popovers |
| Meds/clinical obligations | ✅ | What’s-Next rail (overdue/due/upcoming, give/refuse/snooze → eMAR) |
| Tasks/checklists | ✅ | rail tasks + due/overdue site checklists |
| Shift notes required | ◐ | outgoing handover prompt exists; no explicit “shift note due” nudge (notes are manager-reviewed on `/operations/shift-notes`; workers write via shift flows) — gap, low severity |
| Timesheet state | ✅ | Today’s-timesheet button + draft/returned cards in Paperwork panel |
| Next-action guidance | ✅ | What’s-Next rail ordering + quick actions |
| End-of-shift requirements | ✅ | End-of-Shift checklist modal gating clock-out (blockers flashed inline) |

Remaining improvements: none implemented this round. The timesheet-dialog item (I-8) was withdrawn after direct inspection (dialog already conforms). A “shift note due” nudge in the end-of-shift checklist was considered and **deliberately not added**: the backend has no per-shift note requirement (clock-out blockers are meds/handover/incidents), so a UI nudge would invent policy — left as a product decision, not a code gap.

## 6. Automation Capability Review

**Inventory (all manual-first compliant):**
| Capability | Trigger | What it changes | Gate |
|---|---|---|---|
| Suggestion runs (`RosterSuggestionService`) | manager button (hero “Auto-schedule”, `RosteringController@autoSchedule`) | creates `RosterSuggestion` rows (status `suggested`) for **open shifts only** (`whereNull('user_id')`, service line ~207) | suggestions inert until accept/apply |
| Apply suggestion(s) (`RosterSuggestionApplier`) | manager accept → apply / bulk apply-accepted | `ShiftLifecycleService::assign()` | re-validates eligibility at apply time; rejects stale (>24h run expiry), already-assigned, conflicted; bulk preflights every row first |
| Auto-fill one shift (`ShiftController@autoFill`) | manager button on a shift | assigns top eligible candidate | permission `shifts.manageAny`; eligibility hard-stops |
| Candidate ranking (`@candidates`, eligibility preview) | manager UI | read-only ranked list | — |
| Broadcast needs-cover | manager button | notifies staff | no assignment |
| Job Board claim/approve | worker claims; manager approves | `claimed` → approve sets `filled` + assigns | approval permission `job_board.approve` |
| Recurring series (`ShiftSeriesController`) | manual form | generates draft/scheduled shifts | sampled eligibility check blocks creation; **never auto-publishes** |
| Publish/republish (`RosterPublishingService` + `RosterPublishValidator`) | manager review → confirm | sets `published_at`, snapshots versioned `RosterPeriod` | validator blocks (missing staff, eligibility hard-stops); post-publish edits set `publish_dirty_at` requiring re-review; approved-timesheet shifts locked (`PublishedShiftPayrollLockTest`) |
| Coverage gap detection | computed | ack/dismiss/clear only | — |

**Verdict:** nothing publishes/assigns/cancels silently. Bulk apply-accepted is the closest edge — acceptable (per-row preflight + prior explicit accepts).

**Gaps (corrected after direct inspection)**
- **Audit logging:** suggestion `accept`/`dismiss` DO persist actor attribution on the suggestion row (`accepted_by/accepted_at`, `dismissed_by/dismissed_at` — `RosterSuggestionService:224-248`), so the initial “not audit-logged” sweep finding overstated the gap. What was genuinely missing was **test coverage locking that attribution in** — added in `tests/Feature/Rostering/SuggestionAuditTrailTest.php` (4 tests/15 assertions: accept/dismiss attribution, re-accept clears dismissal, expired-run accept → 422 + stale). A shift-TimelineEvent for accept/dismiss was **deliberately not added**: those planning-stage decisions would surface on client care timelines (TimelineEvents are client-scoped), which is the wrong audience; the shift-mutating action (apply → assign) already writes `recordAssigned` with the actor. `RosterPeriodPublished` event exists; period rows carry `published_by/published_at` (an audit record), but no listener writes a timeline entry — acceptable for the same reason; deferred.
- **Data-quality dependencies** gating safe automation: staff records present, `HrStaffComplianceStatus` current (hard stops), `StaffAvailability` rows, approved `HrLeaveRequest`s, driver eligibility for driver roles, `SiteCoverageRequirement`s for coverage scoring.
- **Tests:** good on applier/publishing/payroll-lock; missing accept/dismiss audit assertions and a full suggest→accept→apply feature test.

**Keep manual (policy):** publishing, any assignment mutation, replacements, cancellations, timesheet state — all already manual; automation stays suggest/rank/draft/warn.

## 7. Dead/Unused Code Audit (with proof)

| Candidate | Evidence | Verdict |
|---|---|---|
| `/scheduling` | `routes/portal.php:1` `Route::redirect(...301)`; covered by `tests/e2e/operations-rostering-calendar-tab.spec.ts` | **IN USE** (compat redirect — keep) |
| `pages/calendar/index.tsx` | imported solely by rostering index (line 94) as the Calendar tab; not Inertia-rendered standalone | **IN USE** |
| `pages/my-roster/**` + `RosterController` | routed `routes/web.php:133-135`; linked from `staff-page-shell.tsx:78`, `active-shift-card.tsx:301`, `tomorrow-panel.tsx:88`, `previous-shift-card.tsx:88`, `pre-shift-briefing-card.tsx:189` | **IN USE** (worker week view) |
| `GET /operations/shifts/create` | redirects into index with prefill (`ShiftController@create:807`); no orphan page (`pages/operations/shifts/` = index/show/test only) | **IN USE** (compat) |
| `/timesheets/*` legacy + `/operations/timesheets/{approvals,create}` | `LegacyRouteRedirectController` + documented 302s (`routes/operations.php:893-919`) | **IN USE** (compat) |
| `hr/time/timesheets` UI | separate HR system (`Hr\TimeSheet`/`TimeEntry`) for non-shift staff; linked under HR nav | **IN USE** (intentional product scope) |
| `tests/Browser/Frontline/FrontlineStaffUxTest.php` | header: “Superseded by … Kept until **2026-08-01**” | **SUSPICIOUS — keep until 2026-08-01** (intentional grace period; today 2026-06-10) |
| `.env.backup` | not in `git ls-files` (untracked local file) | out of repo scope — leave |
| rostering barrel exports | every export in `components/rostering/index.ts` imported by rostering pages | **IN USE** |
| stale docs | `docs/my-day-audit-fix-plan.md` (fixes implemented — keep as record, header note added by this audit), older readiness plans describe pre-consolidation routes | informational only |

**Conclusion:** the prior consolidations (scheduling→rostering tab, templates→tab, approvals→tab, my-tasks→my-day) already removed their dead ends and left deliberate redirects. **No proven-dead code is currently removable** in these surfaces; the one candidate has an explicit keep-until date. Honest result: nothing to delete this pass.

## 8. Findings Ranked

**P0 — broken workflow / safety / pay / dead paths:** none found. All 28 shift routes, 6 my-day routes, 4 job-board routes, 6 handover routes, 6 shift-note routes, 28 rostering routes and timesheet flows are wired UI⇄backend with permissions aligned to sidebar gating. Prior My Day P0s are fixed and regression-tested.

**P1 — major inconsistency / misleading info:**
1. **I-0** Shifts hero fabricated “Week published”/“Auto-schedule ready” badges (misleading publish state).
2. **I-1** Shifts hero forked off PageHero (gradient/structure drift on a core surface).
3. **I-2** Timesheets hero forked + hardcoded colors (token violations, dark-mode risk).
4. **A-1 — downgraded after inspection:** suggestion accept/dismiss attribution already persisted on the row; real gap was missing test coverage (now added — see §6).
5. **I-3** Create Shift dialog vs wizard contract (>8 fields single pane) — *deferred-by-design this round; see plan*.

**P2 — polish / hygiene:**
I-4 wizard chrome drift (rail bg/width, progress height, note-wizard width token) · I-6 Attendance hero identity · I-7 shared wizard primitives · I-8 timesheet dialog section headers · I-9 conflict icon · I-10 date-helper consolidation (scoped to these surfaces) · I-11 open-position terminology in copy · I-12 `category="ops"` props · optional shift-note nudge in end-of-shift checklist.

---

## Phase 2 — Implementation Plan (small, safe, pass-by-pass)

Constraints honoured: no broad rewrites; UI consistency separated from backend changes; dead-code separated (n/a this round); business logic preserved; no mobile-specific work; manual-first untouched.

**Pass A — truthful status + hero standardization (UI only)**
- `shifts-hero.tsx`: rebuild on `PageHero category="ops"` (icon `CalendarDays`, same title/description/meta, real badges: open count / covered, **publish state only if derivable from props — otherwise drop the fake pills**, stats Total/Open/Today/On-now, actions Export + Create shift, footer = week stepper + search + status/staff/client/site filters as today). Keep all props/behaviour (`WeekPicker`, `MultiEntityFilter`) identical.
- `timesheets-hero.tsx`: rebuild on `PageHero category="ops"` (icon `FileText`; tokens replace `white`/`emerald`; keep summary copy, badges, 4 stat tiles via `stats`, hours progress in `children`/`footer`; keep `onCreateTimesheet` etc.).
- `attendance/index.tsx`: give the hero its identity — `category="ops"` + icon `Clock` + stats (today’s hours, open session) — content already in props.
- Add `category="ops"` to handovers/shift-notes heroes + timesheets/edit + rostering publish/suggestions pages (I-12), and swap the rostering hero conflict button icon to `AlertTriangle` (I-9).
- Files: the two hero components, `attendance/index.tsx`, `handovers-hero.tsx`, `shift-notes-hero.tsx`, `timesheets/edit.tsx`, `publish/Review.tsx`, `publish/Diff.tsx`, `suggestions/Show.tsx`, `rostering/index.tsx`.
- Checks: `npm run types`, `npm run build`, vitest for `page-hero-stats` + shifts a11y test, browser pass on `/operations/shifts`, `/operations/timesheets`, `/attendance`.

**Pass B — wizard alignment (UI only)**
- New `resources/js/components/wizard/primitives.tsx`: `Field`, `FieldErr`, `SubHead`, `StepHead`, `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `Ring`, plus `WizardShell` constants (rail width 248px, `bg-sidebar`, 3px progress) — extracted verbatim from Add Client semantics (Add Client itself untouched this round; dedupe later).
- `handover-wizard.tsx`: rail → 248px/`bg-sidebar`, progress → 3px, footer order = ghost Back / outline Cancel / primary Continue (already close), adopt shared `Field`/`FieldErr`.
- `note-wizard.tsx`: same chrome alignment + explicit width token `min(94vw, 1080px)`-class inline style.
- `components/rostering/template-dialogs.tsx`: replace duplicated `Field`/`SubHead`/`SelectInput` with shared imports.
- My Day `TimesheetReviewDialog`: no change — I-8 withdrawn (dialog already conforms; see §4).
- **Deferral (explicit):** converting `CreateShiftDialog` to a full stepper wizard is a broad rewrite of a heavily-tested core flow — out of scope; alignment limited to footer button order/width token if off-contract. Recorded as future work.
- Checks: `npm run types`, `npm run build`, vitest (`resources/js/test`), targeted Playwright/manual browser run of both wizards + template wizard.

**Pass C — backend: automation audit logging (outcome — revised after inspection)**
- Inspection showed `accept()/dismiss()` already persist `accepted_by/at` & `dismissed_by/at`; a client-timeline event would put roster-planning noise on care timelines (see §6 corrected gap). So: **no service change**; added `tests/Feature/Rostering/SuggestionAuditTrailTest.php` locking the attribution + expiry guard (4 tests green).

**Pass D — copy/terminology + date helpers (outcome)**
- Job Board copy: after reading `JobBoardController::formatPositionTitle` in context (worker-facing card titles: “Support shift for {name}”, “Open shift at {location}”), the “position” rename was **deliberately not applied** — “shift” is the natural worker-facing word, no surface mixes the two senses confusingly, and the rename would be churn. Resolution: glossary documented (§3 terminology + this note); “open position” stays the model/manager term, “shift” the worker term.
- Ad-hoc `toLocaleDateString` swapped for `lib/datetime.ts` `formatDate` (Pacific/Auckland-pinned) where the value is a **server timestamp**: `handover-detail-dialog.tsx` shift rows, `note-wizard.tsx` `shiftOptionLabel`. Hero **week-range labels** intentionally keep local-date math — they follow the rostering week-picker convention (`startOfWeek` is computed client-side) and pinning only the label would desync it from the grid.
- Checks: types/build + vitest (see Verification).

**Pass E — verification (required commands)**
`php artisan route:list --path=operations/rostering -v` · `--path=operations/job-board -v` · `--path=operations/shift-notes -v` · targeted PHPUnit (rostering suggestion/publish, handover, shift-note, my-day suites) · targeted vitest · `npm run types` · `npm run build` · `git diff --check` · browser pass over the listed URLs (clients Add Client reference, rostering + tabs, conflicts, job board, my-day, shifts, handovers, shift notes, timesheets, attendance).

**Not doing (explicit deferrals):** Create-Shift wizardization (I-3); Add Client refactor onto shared primitives; `date-format.ts` repo-wide retirement (only these surfaces swapped); deleting `FrontlineStaffUxTest.php` before 2026-08-01; any rostering automation behaviour change.

## URL prefix vs “Workforce” nav label — decision (2026-06-10)

Question raised: the nav group says **Workforce** but the URLs live under `/operations/*` (and `/attendance` has no prefix) — should the URLs be refactored to match?

**Decision: no — deliberately deferred.** Measured blast radius of an `/operations/*`→`/workforce/*` rename: **4,443** `'/operations/'` string references in `resources/js`, **750** `operations.*` route-name references across `app/`+`routes/`+`tests/`, **89** generated Wayfinder helper files under `resources/js/routes/operations/`, **27** Playwright spec references — plus notification emails and bookmarks already in the wild pointing at `/operations/…`. The label/URL split is a normal nav-grouping pattern (the sidebar test pins “groups shift, handover and time navigation under Workforce instead of Operations” as deliberate UX); URLs are infrastructure users don’t read, and every compat redirect added is permanent surface area. Same verdict for the smaller `/attendance`→`/operations/attendance` move (109 frontend references, mostly hardcoded POST action endpoints used by My Day clock flows + a route test asserting the literal path): possible via the existing `$legacyRouteRedirect` pattern, zero user-visible value. Revisit only if a real driver appears (e.g. URL-based permission scoping or a public API).

## Final consistency pass (2026-06-10, round 3)

**Timesheet dialogs → Add Client chrome (the “modal doesn’t follow Add Client style” finding):**
- `create-timesheet-dialog.tsx`: the in-modal **gradient hero header** (hardcoded `text-white`/`emerald` step chips) replaced with the wizard contract — neutral “Step X of 2 · {label}” header + custom X, 3px progress strip, shared `StepHead` (icon tile + title + blurb) above the body, footer rebuilt to ghost **Back** left / outline **Cancel** + primary **Continue** (step 1) or secondary **Save as draft** + primary **Submit for approval** (step 2), width via inline `min(94vw, 920px)` per `POPUP_STYLE_GUIDE.md`. All raw colours (amber banner, rose hover, emerald success pane) → status tokens. **No logic/fields/modes changed.**
- `view-timesheet-dialog.tsx`: gradient header → read-only detail header (primary icon tile + “Timesheet #n” + `TimesheetStatusBadge`), every raw colour (returned banner, notes box, payroll chip, five audit-trail dots, reject/return/approve buttons) → status tokens, the two raw `toLocaleString` calls → `formatDateTime`, local `fmtTime/fmtDate` → timezone-pinned. **Removed the permanently-disabled “Export PDF” stub button** (no backend; no-stub-actions rule). `window.prompt()` for reject/return reasons kept (functional) — flagged as polish deferral.
- Swept all other workforce dialog files for the same violations: rostering template/series dialogs and the eligibility override dialog use `text-white` only on **solid status-colour buttons** (the established idiom) — left as-is; meal-planner/rooms dialogs are out of scope.

**Attendance substance upgrade (the “no real value” finding):**
- `AttendanceController@index` (additive payload only): `weekHours` (viewed user, current week) and manager-only `onClockNow` — all open sessions across the staff-picker scope, each flagged `is_stale` when open 16h+ (likely missed clock-out).
- Page body: new **“On the clock now”** manager board (live pulse per row, since-time, shift #/location/end, stale badges, header count chip, click-through to that staff member’s sessions) and a 16h+ **missed-clock-out warning** on the worker’s own open-session card. Hero stat “Sessions” → “This week {h}” (sessions count stays in the meta row).
- New `tests/Feature/AttendanceIndexPayloadTest.php` (3 tests/9 assertions: manager board + stale flag, worker gets empty board, week-hours scoping). Live-verified: the board immediately surfaced 6 real stuck sessions in demo data.

## Final per-surface consistency matrix (post-implementation, 2026-06-10)

| Surface | Hero: PageHero `ops` | Greeting + live eyebrow | Badges/meta/stats | Week-stepper + filters footer | Wizard/dialog pattern | en-NZ datetime helpers |
|---|---|---|---|---|---|---|
| Rostering | ✅ (reference) | ✅ | ✅ | ✅ | Template wizard ✅ aligned; 12 single-step dialogs per popup guide | ✅ |
| Shifts | ✅ rebuilt | ✅ | ✅ truthful | ✅ | CreateShiftDialog single-pane (deferred I-3) | ✅ |
| Timesheets | ✅ rebuilt | ✅ | ✅ | — (week-nav deliberately absent: approval worklist must not week-hide; hero summary is “this week” by design) | create/view dialogs ✅ | ✅ |
| Job Board | ✅ | ✅ | ✅ | ✅ | inline claim/approve ✅ | ✅ |
| Handovers | ✅ (+category) | ✅ | ✅ | ✅ | 4-step wizard ✅ aligned | ✅ (detail dialog swapped) |
| Shift Notes | ✅ (+category) | ✅ | ✅ | ✅ | 5-step wizard ✅ aligned | ✅ (wizard labels swapped) |
| Conflict Queue | ✅ | ✅ | ✅ | ✅ | resolve dialog ✅ | ✅ |
| Attendance | ✅ full pattern | ✅ | ✅ | — (not week-scoped: session history + live clock) | clock card + table | ✅ (page-wide swap) |
| Calendar / Availability | tabs inside Rostering hero | — | — | shared with Rostering | shared dialogs | ✅ |
| My Day | PageHero avatar variant — **intentionally personal** (worker home: avatar stack, resident pills, quick actions) | shift-state badges instead of greeting line | ✅ | n/a (day-scoped) | popup-guide dialogs ✅ | ✅ |

Navigation: all ten surfaces (+ Attendance) live in the one **Workforce** sidebar group with permission gates that are subsets of their route gates.
