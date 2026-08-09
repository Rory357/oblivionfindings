# HR Module — Audit & Fix LOOP Prompt (module-wide + cross-module seams)

> Paste this whole brief to **Fable 5**, in a fresh session, as many times as it takes.
> This is a **loop prompt**: each run does ONE slice (one surface or one seam) end-to-end —
> audit → fix → gates → ledger → stop. The ledger (`HR_AUDIT_FIX_PROGRESS.md`) is the memory
> between runs. Never start a second slice in the same run; a finished small slice beats a
> half-finished big one.

---

## 0. Read these first (do not skip — match them, don't reinvent)

1. **`HR_AUDIT_FIX_PROGRESS.md`** (repo root). If it exists, it tells you exactly where the loop
   is up to — pick the next `⬜` row (or the highest-severity `🔴` open finding) and go to §3.
   If it does NOT exist, this run is **Run 0**: create it from the seed tables in §5/§6 and do
   nothing else except the first slice.
2. **The HR kit** — `resources/js/components/hr/` (import from `@/components/hr`; note the
   barrel doesn't export everything — some heroes live in subfolders): `HrHero` (`hr-hero.tsx`,
   incl. `kiaOra()`); the specialised heroes — `people-hero.tsx`, `compensation-hero.tsx`,
   `documents-hero.tsx`, `assets-hero.tsx`, `leave-hero.tsx`, `leave-hub-hero.tsx`,
   `feed-hero.tsx`, `my-hr-hero.tsx`, plus subfoldered `calendar/calendar-hero.tsx`,
   `onboarding/onboarding-hero.tsx`, `performance/performance-hero.tsx`,
   `recruitment/recruitment-hero.tsx`, `time/time-hero.tsx`; `HrTabs` (`hr-tabs.tsx`, aliases
   the rostering `TabStrip`) + `useHrTab()` URL sync; and the hub tab strips
   (`CompensationTabs`, `ComplianceTabs`, `DocumentsTabs`, `LifecycleTabs`, `PerformanceTabs`,
   `LeaveTabs`, `CalendarTabs`, `TrainingTabs`, `PayrollTabs`, `ReportsTabs`, `SettingsTabs`).
3. **Gold standards** (the bar every fix is measured against):
   - **Page chrome / heroes:** `/health-safety` (`hs-hero-kit` — `HeroShell`, `HeroMedallion`,
     `HeroStatusPill`, clusters/tiles with tone discipline, summary strip, single quick-action
     footer) and its HR expression, the specialised `*Hero` components above.
   - **Create/edit flows:** the **Add Client wizard** idiom — stepper rail + completeness meter,
     scrollable body, per-step `validateStep`, server errors auto-jump to the failing step,
     footer actions, "Save & add another".
   - **List surfaces:** `/meds/today` + PRN (eMAR parity pattern) — server-side `tabCounts`,
     row flags, row context menu, detail-as-modal.
4. **`HR_REORG_PROGRESS.md`** — the S1–S10 reorg is ✅ shipped (hub consolidation, sidebar's 10
   groups, permissions frozen). Do not undo any reorg decision.
5. **`docs/hr-module-design.md`** + **`docs/hr-nz-statutory-notes.md`** — domain boundaries and
   NZ statutory grounding (Privacy Act, HSWA, Holidays Act → **Employment Leave Bill,
   hours-based**).
6. **The written redesign prompts** (§6 table) — several HR surfaces already have a full
   redesign spec. This loop does not compete with them; §6 says how to behave when your finding
   lands on one.

---

## 1. Mission

The HR reorg put every hub on `HrHero`/tabs and consolidated routes — structurally sound, but
the module has never had a **single consistency + workflow sweep**: heroes that are on the kit
but miss its features (no deep-linked tiles, permanently-neutral tones, no summary strip, no
escalation), hubs still on generic `PageHero` instead of a specialised hero (Vetting, Training),
hand-rolled filter bars, workflows whose statuses exist in the model but are unreachable from
the UI, `confirm()`s where Add-Client-idiom modals belong, and cross-module seams that are
half-wired (announcements→bell inbox, injuries→HR, expenses→GL, driver eligibility→rostering).
Sweep **every HR surface and every seam**, fix what a slice can carry, and leave the module
reading as **one product** — for the support worker on `/hr/my`, the coordinator approving
leave, and the back-office keeping the org audit-ready.

**Audit is not the deliverable — fixed code is.** Every run must ship at least one fix unless
the slice audits genuinely clean (then the ledger says so with evidence).

---

## 2. Non-negotiables (carry into every fix)

- **Web app only.** Desktop web; no phone frames or mobile-app chrome.
- **NZ-only.** `en-NZ`, NZD, KiwiSaver, Employment Leave Bill (hours-based leave). Don't "fix"
  currency/locale to GBP/USD or leave back to days.
- **Kit-first — extend, don't fork.** Missing primitive feature → extend it in
  `resources/js/components/hr/` (or the generic kit it re-exports) so every hub benefits. No
  hand-rolled chips, pills, tiles, tabs, or steppers in page files.
- **Tokens only.** Semantic tokens (`status-*`, `primary-foreground/*`); no raw hex/oklch.
- **Sources of truth (settled — do not relitigate):**
  - Attendance: **one `AttendanceService`** behind both `/hr/my/time` and `/my-day`. Never fork
    a second clock.
  - Leave: **`HrLeaveRequest`** is the source of truth; `StaffTimeOff` is drift — reconcile
    toward `HrLeaveRequest`, never write new features against `StaffTimeOff`.
  - Alerts (lone-worker, wandering, etc.): **Control Room owns triage** — deep-link to it;
    never build parallel acknowledge/resolve inside HR.
  - Injuries: `WorkplaceInjury` lives under **H&S**; HR gets read-only surfacing per
    `INJURIES_HR_SURFACING_PLAN.md`.
  - Approvals: every approve/decline flow routes through **`ApprovalWorkflowService`** /
    `HrApprovalChain` and surfaces in `/hr/approvals` — no bespoke approval paths.
- **No schema invention.** Column/table might not exist → `Schema::hasColumn`/`hasTable` guard,
  degrade gracefully. Migrations only when a §7 finding explicitly needs one, and say so in the
  ledger.
- **Permissions are frozen** from the reorg — reuse existing abilities/policies
  (`app/Domain/Hr/Policies/`); don't add new permission names without flagging it as a decision.
- **Auditability.** Anything that mutates HR data keeps/gains the `AuditableChanges` trait path
  so it lands in `/hr/settings/audit-log`.
- **DO NOT run `npm run format`** (massive diffs). Hand-format to sibling style.

---

## 3. The loop — what one run looks like

1. **Pick** the next slice from the ledger: top-to-bottom through §5 surfaces, then §6 seams —
   unless a `🔴` finding is open, which always jumps the queue.
2. **Audit** the slice against every rubric in §4 that applies. Write findings into the ledger
   as you go: `🔴` broken workflow / dead end / data-integrity risk · `🟠` inconsistency that
   misleads (wrong hero pattern, unreachable status, duplicate affordance) · `🟡` polish
   (copy, spacing, empty state, a11y niceties).
3. **Check §6 first**: if a finding is inside the scope of a written redesign prompt, log it as
   `↗ deferred to <PROMPT>.md` and do NOT half-implement that redesign. Fix it here only when
   it's (a) a `🔴`, or (b) a small standardisation fix (< ~1 file-cluster) that the redesign
   would keep anyway.
4. **Fix** everything else you found in this slice. Prefer many small honest fixes over one
   speculative rebuild.
5. **Verify** — run the gates (§9). Screenshot light+dark, `sm`→`xl`, for any visual change.
6. **Ledger** — update the slice row (`⬜`→`✅` or `🔶 partial`), append findings with
   severity + status (`fixed <commit-ish>` / `deferred ↗` / `open`), note any new decision
   needed from Chane under **"Decisions needed"**.
7. **Commit** with a `hr-audit:` prefixed message. Stop. Do not start the next slice.

---

## 4. Audit rubrics

### 4A. Hero consistency (every hub landing page)

- [ ] Uses a **specialised `*Hero`** (or `HrHero` composed to the same anatomy) — a hub still on
  generic `PageHero` (known: Vetting, Training) is a `🟠` finding: build its specialised hero in
  the kit, mirroring `CompensationHero`'s composition.
- [ ] Anatomy reads as family: identity row (medallion · status pill · h1 · subtitle) → KPI
  tiles/clusters → (where the domain has one) summary strip → **one** canonical quick-action
  row. No second quick-action card in the body duplicating the footer.
- [ ] **Every KPI tile deep-links** to the filtered view that explains it; no dead tiles.
- [ ] **Tone discipline:** tiles escalate (`warning`/`critical`) when their number demands it —
  permanently-neutral tiles that can represent overdue/expired states are a `🟠`. Escalation
  lives IN the hero (attention strip / tile tones), not a hand-rolled banner below it.
- [ ] Counts come **server-side** from the controller; no client-side derived KPIs that lie
  (delete fake derived stats rather than surface them).
- [ ] `kiaOra()` greeting only where the pattern already uses it (`MyHrHero`); don't scatter it.
- [ ] A11y: timestamp/refresh `aria-live="polite"`, tone never colour-only (count + noun in
  text), `tabular-nums`, `motion-reduce` guards, focus-visible rings from the kit.

### 4B. UI standardisation (every page)

- [ ] Tabs: `HrTabs`/hub tab strip with `useHrTab()` URL sync + **server-side `tabCounts`** —
  pills-pretending-to-be-tabs are a `🟠`.
- [ ] Tables: shadcn `DataTable` + `RowContextMenu` for row actions; row flags for states;
  detail-as-modal (or drawer for long-lived workspaces) rather than full-page detours for
  quick reads.
- [ ] Create/edit: **Add-Client wizard idiom** for anything multi-step; single-step forms use
  the standard dialog. `confirm()` anywhere is a `🟠` → replace with `AlertDialog`.
- [ ] Filter bars: match the dominant sibling pattern; if you touch two hand-rolled ones in one
  slice, extract the shared `HrFilterBar` into the kit and note it in the ledger.
- [ ] Empty states: designed empty state (icon · one-liner · primary CTA), never a bare table.
- [ ] Loading: skeletons/`isRefreshing` idiom on partial reloads; every reload `only:` list
  includes any prop you add; components tolerate `undefined` mid-reload.
- [ ] Copy: sentence case, en-NZ spelling, dates via `en-NZ` formatting, no raw enum values in
  the UI.

### 4C. Workflow integrity (every domain with a status field)

- [ ] **Every model status is reachable from the UI** — grep the model's status enum/constants,
  then prove each transition has a button/menu/endpoint. Model-supports-it-but-controller-never-
  sets-it (the Drills disease) is a `🔴`. Known suspect: **onboarding task lifecycle endpoints
  missing (handover-only)** — see §6 before rebuilding.
- [ ] Every transition endpoint: policy check → service call → notification (if someone waits on
  it) → audit log → redirect/back with flash.
- [ ] Approvals route through `ApprovalWorkflowService` and appear in `/hr/approvals` (leave,
  expenses, offers, comp reviews, amendments). A flow with its own bespoke approve path is `🔴`.
- [ ] No dead ends: every list row answers "what can I do next?" (context menu), every detail
  answers "what happens now?" (status + next action), every submit answers "where did it go?"
  (toast + inbox/approvals link).
- [ ] Notifications exist for the waiting party (check `app/Domain/Hr/Notifications/`) and
  deep-link back to the exact record.

### 4D. Cross-module seams (§6 list) — for each seam

- [ ] **Data flows end-to-end**: trigger the upstream action, prove the downstream surface
  reflects it without manual refresh voodoo.
- [ ] **One owner**: the same fact isn't editable in two modules (e.g. vehicles/keys must not be
  duplicated between `/hr/assets` and Fleet — HR federates, Fleet owns).
- [ ] **Deep links both ways** where humans cross (HR record ↔ other-module record).
- [ ] **Counts agree**: a KPI shown on both sides comes from the same query/service, not two
  drifting implementations.
- [ ] Failure mode is graceful: other module's table/feature disabled → guard, hide, or
  empty-state; never a 500.

---

## 5. Surface inventory — ledger seed (audit top to bottom)

> Columns for the ledger: `Surface · Route · Page file · Status(⬜/🔶/✅) · Findings`.
> Page files live under `resources/js/pages/hr/…`; routes in `routes/hr.php` (`hr.*` names).

| # | Surface | Route(s) |
|---|---------|----------|
| 1 | My HR hub (all 16 self-service sub-pages: index, calendar, leave, expenses, training, benefits, policies, profile, directory, reviews, goals, surveys, time, one, shoutouts, documents) | `/hr/my/*` |
| 2 | People / employee profiles (+ bulk, rehire) | `/hr/people` |
| 3 | Recruitment (jobs, candidates, offers, kits) | `/hr/recruitment` |
| 4 | Onboarding | `/hr/onboarding` |
| 5 | Offboarding + exit interviews | `/hr/offboarding`, `/hr/exit-interviews` |
| 6 | Calendar hub + time-off calendar | `/hr/calendar`, `/hr/calendar/time-off` |
| 7 | Leave (balances, holidays, reports) | `/hr/leave/*` |
| 8 | Time | `/hr/time` |
| 9 | Compensation (bands, bonuses, reviews, benefits, expenses, history) | `/hr/compensation/*` |
| 10 | Payroll + payslips | `/hr/payroll/*` |
| 11 | Compliance (overview, matrix, calendar) + Vetting + Drivers | `/hr/compliance/*`, `/hr/vetting`, `/hr/drivers` |
| 12 | Documents (documents, templates, policies, attestations) | `/hr/documents/*` |
| 13 | Performance (reviews, PIPs, supervision, competencies, skills) | `/hr/performance/*` |
| 14 | Goals + Development | `/hr/goals`, `/hr/goals/development` |
| 15 | Training + catalog | `/hr/training/*` |
| 16 | Feed | `/hr/feed` |
| 17 | Announcements | `/hr/announcements` |
| 18 | Feedback + surveys | `/hr/feedback` |
| 19 | Wellbeing | `/hr/wellbeing` |
| 20 | Cases | `/hr/cases` |
| 21 | Assets | `/hr/assets` |
| 22 | Approvals inbox | `/hr/approvals` |
| 23 | Signatures | `/hr/signatures` |
| 24 | Analytics, reports, headcount, succession, import-export | `/hr/analytics`, `/hr/reports`, `/hr/headcount`, `/hr/succession`, `/hr/import-export` |
| 25 | Settings + audit log | `/hr/settings/*` |

---

## 6. Cross-module seams — ledger seed + how to treat the written prompts

### Seams (audit each with rubric 4D)

| # | Seam | Wiring to prove |
|---|------|-----------------|
| S1 | Attendance ↔ My Day | `/hr/my/time` and `/my-day` clocks both drive `AttendanceService`; one engine, one state |
| S2 | HR Assets ↔ Fleet | Federation, not duplication: vehicles/keys owned by Fleet, surfaced read-only in `/hr/assets` |
| S3 | Driver eligibility → Fleet/Rostering | `HrDriverEligibility` actually gates assignment; expiry flows to warnings on the other side |
| S4 | Injuries (H&S) → HR | Read-only surfacing per `INJURIES_HR_SURFACING_PLAN.md`; no HR-side editing of `WorkplaceInjury` |
| S5 | H&S incidents ↔ HR cases | `is_hr_confidential` linking works and leaks nothing to non-HR viewers |
| S6 | Compliance matrix → Rostering | `ComplianceMatrixService`/`LiveComplianceValidator` blocks non-compliant shift assignment; matrix edits propagate |
| S7 | Training → Compliance/Vetting | Course completion updates `hr_staff_compliance_status`; cert expiries round-trip |
| S8 | Expenses → Finance GL | Approved claim → `PostExpenseJournalJob` posts; failure path visible, not silent |
| S9 | Payroll ↔ Time/Leave | Approved timesheets + leave (hours-based) feed payslips; period locks respected |
| S10 | Announcements → header bell inbox | `AnnouncementAudienceResolver` + `AnnouncementInboxBridge` deliver end-to-end; read-state syncs |
| S11 | Feed ↔ My HR shoutouts | Reactions/comments (backend exists, used on `/hr/my/shoutouts`) exposed consistently on the feed wall |
| S12 | Recruitment → Onboarding | Offer accepted auto-creates checklist; new hire visible without re-keying |
| S13 | Performance ↔ Governance | Two-model split (`hr_performance_reviews` live vs unrouted governance `performance_reviews`) — surface the state honestly; flag consolidation as a Decision, don't merge unilaterally |
| S14 | Approvals spine | All approvable HR things visible in `/hr/approvals/pending` with working act-from-inbox |
| S15 | Wellbeing lone-worker → Control Room | Flags deep-link to Control Room; no parallel triage in HR |
| S16 | Procedures (H&S) → HR | Surfacing per `PROCEDURES_HR_SURFACING_PROMPT.md` |

### Written redesign prompts — the rule

`HR_MY`, `HR_PEOPLE`, `HR_FEED`, `LEAVE`, `HR_TIME`, `HR_CALENDAR`, `COMPENSATION_HUB`,
`HR_COMPLIANCE`, `HR_DOCUMENTS`, `HR_TRAINING`, `GOALS`, `ANNOUNCEMENTS`, `WELLBEING`,
`ONBOARDING`, `PERFORMANCE`, `HR_ASSETS`, `RECRUITMENT` — each has a
`*_REDESIGN_PROMPT.md` at repo root.

- A finding **covered by** one of those specs → ledger it `↗ deferred`, don't duplicate or
  contradict the spec.
- A finding that's a **small standardisation fix** consistent with the spec (token swap,
  deep-link a tile, `confirm()`→`AlertDialog`, missing `tabCounts`) → fix now.
- A `🔴` **workflow break** → fix the break now in the narrowest way the spec would keep.
- If this audit **contradicts** a written spec (codebase moved on) → note it under "Decisions
  needed"; Chane arbitrates.

---

## 7. Known findings to confirm first (pre-seeded — verify, then fix or defer)

1. `🟠` **Vetting + Training hubs on generic `PageHero`** while 13 sibling hubs have
   specialised heroes → build `VettingHero`/`TrainingHero` in the kit (Training: check its
   redesign prompt first, §6 rule).
2. `🔴` **Onboarding task lifecycle endpoints missing** — checklist is handover-only; tasks
   can't be progressed from the UI (defer full rebuild to `ONBOARDING_REDESIGN_PROMPT.md`,
   but a task can at minimum be completable).
3. `🟠` **Feed wall lacks reactions/comments** that the backend already has and
   `/hr/my/shoutouts` already renders (S11).
4. `🟠` **`StaffTimeOff` drift** — find any surface still reading/writing it and repoint to
   `HrLeaveRequest` (S9 depends on this).
5. `🟠` **Wellbeing duty-of-care loop open** — `flaggedStaff` is read-only with no follow-up
   action path; fix the narrow loop (acknowledge → note → link out), full suite stays with
   `WELLBEING_REDESIGN_PROMPT.md`.
6. `🟡` **Filter bars hand-rolled per page** — extract `HrFilterBar` opportunistically (4B).
7. `🟠` **Performance two-model split** (S13) — audit which surfaces read which model; make
   `/hr/performance` internally consistent; consolidation = Decision needed.
8. `🟡` **Hero KPI deep-link coverage** — sweep for dead tiles module-wide (4A).

---

## 8. Who this module serves (walk each slice through these; EGL + Ngā Paerewa lens)

1. **Support worker on their phone-sized attention budget (but desktop web)** — `/hr/my` is
   their whole HR world: clock in, request leave in hours, claim an expense, see their
   training/vetting expiries before they block a shift. Every self-service flow ≤ 2 clicks to
   start, always answers "what happens now?".
2. **Coordinator / rostering manager** — approvals inbox is the cockpit: leave, timesheets,
   expenses, amendments — act-from-inbox, and compliance-matrix red flags explain *why* someone
   can't take a shift (S3/S6) before rostering finds out the hard way.
3. **HR / people manager** — hubs read as one product; a case, a review, a goal, an onboarding
   all use the same wizard/detail/status grammar; nothing confidential (cases, wellbeing flags)
   leaks into shared surfaces (S5).
4. **Back-office / compliance & payroll** — the audit log catches every mutation; expenses hit
   the GL (S8); leave is hours-based per the Employment Leave Bill; vetting/matrix evidence is
   one click from any red chip.
5. **The person receiving support (indirect)** — every seam that gates staff (vetting, driver
   eligibility, training, compliance matrix) exists so the right person walks in the door;
   broken gating (S3/S6/S7) is a safeguarding issue, triaged `🔴`, not a data nicety.

---

## 9. Gates — every run, before the ledger update

1. `php artisan wayfinder:generate` — if routes changed.
2. `npm run types` — 0 errors.
3. `npm run lint` — 0 NEW errors in touched files.
4. `npm run build` — exit 0.
5. `npm run test` — baseline is 5 pre-existing vitest fails (app-sidebar + my-day; unrelated).
   No NEW failures.
6. `vendor/bin/pest tests/Feature/Hr tests/Unit/Hr` — baseline 1–2 environmental fails
   (RecruitmentJobPostingSync, date-env ShiftPayroll). No NEW failures.
7. Visual change → light + dark screenshots at `sm` and `xl`, dropped alongside the ledger
   entry (or `.design-drops/hr-audit/` if you follow that loop).

---

## 10. Definition of done (the whole loop, not one run)

- [ ] Every §5 surface row `✅` with findings fixed or explicitly `↗ deferred` to its prompt.
- [ ] Every §6 seam row `✅` proven end-to-end (rubric 4D), or `🔴`-blocked with a named
  Decision for Chane.
- [ ] Zero hubs on generic `PageHero`; every hero passes 4A; heroes screenshot-diff as one
  family.
- [ ] Zero `confirm()`s, zero pills-as-tabs, zero hand-rolled hero elements in HR page files.
- [ ] Every status transition in every HR model reachable from the UI (or ledgered as
  intentionally admin-only, with the policy that enforces it).
- [ ] All approvable flows visible + actionable in `/hr/approvals`.
- [ ] "Decisions needed" section in the ledger is the ONLY place open questions live — nothing
  buried in commit messages.
- [ ] Gates green on the final run; ledger's top section rewritten as a 10-line executive
  summary of what changed.

---

## 11. Guardrails — do NOT

- Do NOT run more than one slice per run, and do NOT leave a slice half-fixed without a `🔶` +
  honest ledger note.
- Do NOT half-implement a written redesign prompt "while you're in there" — §6 rule only.
- Do NOT undo S1–S10 reorg decisions, rename routes, or invent permissions.
- Do NOT fork a second attendance clock, leave model, or alert-triage surface (§2 sources of
  truth).
- Do NOT add packages, run `npm run format`, or reformat untouched files.
- Do NOT let an audit finding become a rebuild: smallest honest fix, defer the rest.
- Do NOT mark a seam `✅` from code-reading alone — prove it with a test, a tinker session, or
  a walked-through screenshot trail.
