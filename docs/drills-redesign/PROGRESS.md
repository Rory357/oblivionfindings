# Emergency Drills redesign (`/health-safety/drills`) — PROGRESS

Bring the **Emergency Drills** register to the H&S gold standard (twin of Incidents / Safeguarding /
Fleet Incidents / Events). NZ-only, web-only, modal-first. Backend already exists (controller, 3 tables,
models, observer) but the pages use the legacy `PageHero`/`Card`/raw-table pattern and several endpoints
the current `show.tsx` calls are **dead**.

Design drop: `C:\Users\steph\Downloads\hs-drills-design\design_handoff_emergency_drills\` (README + .dc.html).
Worktree: `.claude/worktrees/practical-banach-f1766f` (vendor copied + autoloader re-dumped → resolves to
worktree `app/`; Wayfinder TS copied; npm resolves upward to parent). ⚠️ PHP tests in worktree run against
the COPIED vendor (worktree app/) — safe. Merge to main + Chrome-verify when done.

## Key decisions (design + audit driven)
- **Compose existing kits** (no new primitives): `hs-hero-kit`, `register-row-kit`, `@/components/rostering`
  (TabStrip/ShiftContextMenu/EntityFilter), `workflow-ribbon`, `wizard/shell` + `wizard/primitives`,
  `ui/file-dropzone` (premium upload), `ui/laravel-pagination`. Mirror `events/index.tsx` +
  `event-detail-dialog.tsx` structurally.
- **Modal-first**: left-click row → `DrillDetailDialog` (WizardShell read-only, 5 sections); right-click →
  `ShiftContextMenu`; `?drill={id}` partial reload `only:['detail']`. Deep-link fallback `/drills/{id}`.
- **Wizards = Add-Client idiom** (WizardShell, 4 steps each): **Schedule** + **Complete**. Sub-actions
  (Add participant / Add finding / Resolve / Edit / Cancel / Upload evidence) = in-detail panes using the
  wizard primitives (premium look), like event-detail-dialog's ActivePane/PaneRenderer.
- **Premium document upload**: new `DrillAttachment` (clone FleetIncidentAttachment) + `AttachmentUploader`
  on the Findings/Evidence section. max 20 MB, public disk, kind/notes/alt_text (a11y), no is_sensitive.
- **Discrete lifecycle endpoints** (matches README + design + fixes dead show.tsx calls): `start`,
  `complete`, `cancel`, `findings/{finding}/resolve`. Keep `update` for edit/reschedule.
- **Outcome enum**: `passed | passed_actions | failed` (nullable until completed). Observer fires
  `drill_failure` HsEvent + Control Room signal on non-pass (`passed_actions`/`failed`). `passed` = no fire.
- **Schema additions** (2 migrations): `emergency_drills` += `is_unannounced` bool, `assembly_point` string?,
  `evacuation_scheme` string?; + `emergency_drill_attachments` table. (Lifecycle cols already exist.)
- **Workflow ribbon**: ADD a `drill` ('Drill & prepare', Siren) stage to `WorkflowStage`/STEPS (sanctioned by
  README + design; makes drills first-class). Drills page passes `current="drill"`. ⚠️ shared chrome — appears
  on all H&S pages (additive).
- **Compliance single source of truth**: new `DrillComplianceService` (canonical = `whereNotNull('completed_at')`
  + MAX, 6mo window, due_soon = 6–7mo band, zero-drill site ⇒ overdue). Reconcile dashboard
  `drill_compliance_pct`, `HsAnalyticsService::drillStatusBySite`, register hero/sites_overdue, site-profile
  badge to it. (Changes the dashboard % for 6–7mo sites → intended.)

## Backend steps  ✅ ALL DONE + VERIFIED (15 routes register, lint clean, both migrations applied)
- [x] B1 Migrations: add cols to emergency_drills; create emergency_drill_attachments
- [x] B2 Models: EmergencyDrill (+cols, +attachments()), new EmergencyDrillAttachment
- [x] B3 `DrillComplianceService` (statusBySite/statusForSite/compliancePct/summary/sitesOverdue/siteSummary)
- [x] B4 Observer: VERIFIED no change needed (isPassing: passed=pass; passed_actions/failed=fire)
- [x] B5 Controller rebuild: index payload (hero{live,attention,badges}/tabCounts/detail/can/rows), buildDrillDetail
      (+derived timeline +two-way HsEvent link), buildDrillRow, start/complete/cancel/resolveFinding,
      addFinding(finding_type fixed), store (wizard+wardens seed roll-call), upload/download/destroyAttachment,
      update (edit/reschedule), create()→redirect
- [x] B6 Routes: start/complete/cancel/findings/{finding}/resolve/attachments(+download,+destroy); show last
- [x] B7 Factory: drill_type→enum + scheduled/inProgress/completed states; Participant + Finding factories
- [x] B8 Calendar: DrillObligationProvider + aggregator + CalendarSources + DEFAULT_SOURCES + --src-drill (hue 45)
- [x] B9a HsAnalyticsService::drillStatusBySite → delegates to DrillComplianceService
- [x] B9b Dashboard drill_compliance_pct → DrillComplianceService::compliancePct() (removed dead EmergencyDrill import)
- [x] B9c-be SiteController::show → eager `drillsSummary` prop (DrillComplianceService::siteSummary)
- [ ] B9c-fe sites/show.tsx Drills tab (consumes drillsSummary) — in FRONTEND phase

## Frontend steps  ✅ ALL DONE (tsc 0 errors, eslint clean)
- [x] F1 drills/index.tsx full rebuild (hero+ribbon+badges / 6 tabs / 7-col table / row+hero ctx / pagination / detail)
- [x] F2 components/health-safety/drill-detail-dialog.tsx (6 sections incl. Evidence + panes + premium upload)
- [x] F3 drill-schedule-dialog.tsx (4-step WizardShell, TilePicker, warden chips)
- [x] F4 drill-complete-dialog.tsx (4-step WizardShell, outcome segmented)
- [x] F5 show.tsx → thin shell; create.tsx deleted (controller redirects)
- [x] F6 workflow-ribbon: added `drill` ('Drill & prepare', Siren) stage
- [x] F7 sites/show.tsx Drills tab (status/last/next/findings + register links)
- [x] shared.tsx (types + token maps + en-NZ formatters)

## Verify  ✅ ALL GREEN
- [x] V1 PHP: EmergencyDrillTest 16/16 (96 assert); HealthSafety dir 253/253; Sites/Calendar 36/36 — no regressions
- [x] V2 tsc 0 errors · eslint 0 errors (drills) · vite build exit 0 (3m23s) · migrations applied · pint clean (new files)

## 🐛 Latent bug found + fixed (gap analysis payoff)
**EmergencyDrillObserver never actually fired.** It called `HsEventService::recordEvent(['source_type'=>…, 'source_id'=>…])`
but recordEvent reads `$data['source']` (a MODEL) to derive source_type/source_id + idempotency key. The undefined
`source` key threw → swallowed by the observer's try/catch → the drill_failure HsEvent + Control Room signal were
**never created** for any failing drill. Fixed to pass `'source' => $drill`. Now verified by test.

## Status: COMPLETE — committed to branch `claude/practical-banach-f1766f`. NOT yet merged/deployed (awaiting user OK).
Next (user-gated): merge → origin/main → deploy webhook (~5-8min; 2 migrations run on deploy) → Chrome-verify on .com.
No new permissions (reuses hazards.view/create/manage). Migrations: 2026_06_20_010000 (+cols), 010001 (attachments table).

## Design spec quick-ref
- Tabs (6): All(primary,LayoutList) / Scheduled(info,CalendarClock) / Overdue(critical,AlarmClock) /
  In progress(warning,Loader) / Completed(success,CheckCircle2) / Findings open(warning,ClipboardList).
- Table cols (7): When (whenMain + DR-#### ref) · Drill (type dot + label + title) · Site (avatar+region) ·
  Status · People · Findings (N open) · Flags (Overdue/Running/Finding overdue/N open).
- Types: fire_evacuation(Flame) earthquake(Activity) lockdown(Lock) tsunami(Waves) chemical_spill(FlaskConical)
  medical_emergency(HeartPulse). Schedule wizard offers first 4 as TilePicker.
- Detail sections: overview / run / participants / findings / history.
- Compliance badges: Sites drilled 6mo % · Fire N overdue · Ngā Paerewa certified · FENZ scheme review due.
