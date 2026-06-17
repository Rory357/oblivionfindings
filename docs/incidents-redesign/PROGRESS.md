# Incidents redesign — build tracker

> Control Room + Health & Safety integrated · modal-first · feature-complete.
> **NZ-only** (WorkSafe NZ, HSWA 2015, Ngā Paerewa NZS 8134:2021) · **web-only** (desktop).
> Spec: `.design-drops/incidents-redesign/` (HANDOFF.md, Incidents.dc.html, Control Room.dc.html).
> Companions: `INCIDENTS_LIFECYCLE_PLAN.md`, `INCIDENTS_NEAR_MISS_ADDENDUM.md`, `docs/INCIDENTS_GAP_ANALYSIS.md`.

Branch: `incidents-control-room-redesign`. Started 2026-06-17 via `/loop`.

---

## Decisions (locked unless the user overrides)

- **Hero layout:** `heroClusters='clusters'` (two labelled cards — the spec default). Not `strip`.
- **TabStrip placement:** standard light card directly below the hero (the drop's choice for 8-tab legibility), not inside the onDark footer band.
- **Near-miss tab URL:** `/incidents?tab=near_misses`. The new index reads a `tab` query param.
- **Workspace:** feature branch in the main repo (not a worktree — single loop, no parallel writers).
- **Migrations:** WRITTEN but **NOT run** without explicit human confirmation (spec §6/§8.1). ⚠️ GATE.
- **Control Room page:** NOT built (redesigned separately). Only its workflows + the confirm/dismiss + flag-incident modals (mountable later).

## Audit reconciliation (what already exists — do NOT rebuild)

- `ClientIncidentObserver` already: records `HsEvent` (idempotent) on create + bridges high/crit non-draft → `ControlRoomAlert` + links `HsEvent.control_room_alert_id` back.
- `HsEvent` **already has** `control_room_alert_id` + `HsEventService::linkControlRoomAlert()`. Gap D still needs the FK on **`client_incidents`** (direct, not via HsEvent).
- `HsEventService::recordEvent()/syncSeverity()`, `HsInvestigationService` (create→start→recordFindings→complete), `HsCorrectiveActionService::createStandalone()` (Option B "+ Raise corrective action") — **all built**.
- `ControlRoomAlert` statuses today: open/ack/triaging/resolved/closed (no confirm/dismiss). Link to incident only via `context` JSON `incident_id`.
- `ControlRoom\Signal`: `signal_type_code` (`fall_detected`), `payload` JSON (confidence/location), `alert_id`, `device_id`.
- `/health-safety/events` (HsEventController@index/show) + `/health-safety/corrective-actions` (HsEventController@correctiveActions) exist but are **unreachable from nav** (Gap G nav TODO).

---

## Build order (each step independently shippable + verifiable)

### ☑ Step 1 — Schema (Gaps C, D) ✅ migration RUN + verified
- [x] Migration `2026_06_17_120000_add_source_and_control_room_alert_to_client_incidents.php`: `source` string (`manual|control_room|sensor|automated`, default `manual`, indexed) + first-class `control_room_alert_id` FK (nullOnDelete).
- [x] Backfill: `source` default `manual` backfills existing rows automatically (non-nullable default).
- [x] `interactive` = derived model accessor (`source ∈ {manual, control_room}`), `$appends`-ed — NOT a column (design note: "derived from source").
- [x] Model: `source` + `control_room_alert_id` in `$fillable`; `controlRoomAlert()` BelongsTo.
- [x] *Verified:* applied **mine only** via `--path` (5 unrelated migrations left pending). migrate→columns present · rollback→clean · re-migrate→present. Local `client_incidents` empty (backfill trivially ok).

**MIGRATION POLICY (user, 2026-06-17): run autonomously against local dev DB — verify migrate+rollback+re-migrate; don't re-ask. Apply mine only via `--path` (DB has unrelated pending migrations). Prod gated on deploy.**

### ☑ Step 2 — Nav / IA (§4a)
- [x] Add "Investigate & Remediate" group to `app-sidebar.tsx` (Investigations → `/health-safety/events`, Corrective Actions → `/health-safety/corrective-actions`), after the incidents group.
- [x] Repoint Near Misses `:1170` `/incidents?type=near_miss` → `/incidents?tab=near_misses`; drop the title-flip (handled by new index).
- [x] *Verify:* `tsc --noEmit` clean (exit 0). Browser check (both registers reachable; near-miss lands on tab) deferred to Step 3 when the tab target exists.

### ☑ Step 3 — List page (`incidents/index.tsx`, Gap G) ✅
- [x] hs-hero-kit hero: "This period · last 30 days" (Reported Δ / Open / Investigating / Closed Δ) + "Needs attention" (Follow-ups due+overdue / Awaiting review / WorkSafe / Active CR alerts) clusters, tiles href→tabs. NOT compliance badges.
- [x] `TabStrip` 8 tabs with counts (tones per spec). Tab nav preserves filters.
- [x] Footer filter band (onDark): date-range `HeroSegmented` (week/30d/quarter/custom popover) + Site + Client `EntityFilter`s + Source segmented + search pill + Clear.
- [x] Right-click `ShiftContextMenu` rows (PRN `openRowCtx` idiom); row badges (alert-linked / investigation / WorkSafe / follow-up-due+count / attachments+count); source column. **Row-click → `/incidents/{id}` (interim — Step 4 swaps to detail modal).** Ctx items limited to working actions (View / Continue draft / View client `/care` / View CR alert / Submit) — Add-followup/Upload/Flag deferred to Step 4 modals (no stubs).
- [x] Follow-ups-due worklist tab (open only, owner/due/overdue/parent); near-miss insights strip (trend / ratio / by-potential bars — "what could have happened" proxy for hazards until Step 5 adds a hazard field).
- [x] Extended `IncidentController@index` payload (tabCounts, rows+rowsKind, hero, nearMissInsights, sites, source/site filters, legacy `?type=near_miss`→tab back-compat). `php -l` clean.
- [x] *Verified:* `tsc` clean (exit 0) + **18 feature tests pass / 234 assertions** (IncidentControllerTest index rewritten to new contract + new tab/source/site/followups/counts coverage; RefreshDatabase exercised the migration in a full migrate:fresh). Browser/live verify deferred to post-merge (.com), per project pattern.

### ◑ Step 4 — Detail modal (`IncidentDetailDialog`, Gap F) — 4a DONE, 4b next
**4a (DONE):** read-only modal over the list, NON-REGRESSING (show.tsx kept for editing).
- [x] `IncidentController::buildIncidentDetail()` + index `?incident=` → `detail` prop (Inertia partial `only:['detail']`); null when absent/unviewable.
- [x] `components/incidents/incident-detail-dialog.tsx` on `WizardShell` read-only chrome: rail Overview/Timeline/Photos/Follow-ups/Investigation(H&S)/Linked + Options bar (one-click Submit when draft + Complete-followup + "Open full page" + jumps client `/care` / CR alert / H&S event). Read-only: WorkSafe banner, what-happened, people, classification, timeline (from timestamps), attachments+download, followups+complete, HsEvent/investigation/corrective-actions, linked records.
- [x] Wired: row-click + ctx "View" → `openDetail` (modal over list, no nav); close drops `?incident=`.
- [x] *Verified:* `tsc` clean + **4 detail feature tests / 60 assertions** (shape, null-when-absent, null-when-unviewable).
**4b (in progress):** workflow sub-modals into the dialog. *(Until complete, "Open full page" → existing editable show.tsx, so nothing regresses.)*
- [x] **Lifecycle action panes** (review / close / reopen) — in-body pane pattern (`action` state swaps the dialog body; Options bar shows status-appropriate buttons). Inertia `useForm` → existing endpoints; guardrail `flash.error` keeps the pane open. tsc clean.
- [x] **Add-follow-up** inline form in the Follow-ups section (`assignable_staff` + `can.followupsManage`/`portalManage` added to detail payload) + **attachment management** in Photos: upload (draft-only multipart via `forceFormData`), remove (draft-only), portal share/unshare (portalManage). tsc clean + detail tests still green.
- [x] **Edit pane** (core fields: type / severity / occurred_at / description / immediate-action / witnesses + near-miss potential + WorkSafe-notifiable flag) for **drafts**, via PUT `/incidents/{id}`; `flash.error` keeps the pane open. tsc clean.
- [ ] **(deferred → Step 7)** slim `incidents/show.tsx` (2196 lines) → thin shell rendering `<IncidentDetailDialog>` + retire old body. **Why deferred:** the only show.tsx capabilities not in the dialog are injury-detail + **investigation/corrective-action editing**, which Step 7 (Option B) migrates to the H&S register — retiring show.tsx is cleanest once that lands. Until then, "Open full page" bridges (no regression).

**Step 4 = modal-first detail is FUNCTIONALLY COMPLETE** (view + lifecycle + follow-ups + attachments + core edit, all over the list). Remaining is the show.tsx refactor above, sequenced into Step 7.

### ◑ Step 5 — Report wizard (`WizardShell`, §3) — 5a DONE, 5b next
**5a (DONE):** modal-first report wizard over the list.
- [x] `components/incidents/incident-report-dialog.tsx` on `WizardShell` (rail + completeness `Ring` + review + `WizardSuccessPane`): 6 steps with a **near-miss branch** (incident: Type&people → What happened → Severity&actions → WorkSafe check → Follow-ups → Review; near-miss: blame-free Who&where → What happened → What-could-have-happened [potential `TilePicker`+hazard+control] → light dangerous-occurrence check → Follow-ups → Review). Dynamic follow-up rows.
- [x] `store()` extended: `source='manual'`, `hazard`→`metadata.hazard`, `followups[]` created in one submit, `stay`→`back()` + flashes `created_incident_id`. Success pane "Open incident" → `openDetail`.
- [x] Wired the hero **"+ Report"** launcher (An incident / A near miss) to open the wizard over the list; `reportClients` (scoped) + `reportStaff` added to the index payload.
- [x] *Verified:* `tsc` clean + **3 store feature tests** (source=manual + followups[] created; near-miss potential+hazard→metadata).
**5b (DONE):** legacy create flow retired.
- [x] `create()` now **redirects** to `/incidents?report={incident|near_miss}` (+ `report_client_id`/`report_shift_id` prefill); `?incident=` → `/incidents?incident=` (resume = edit via detail). Keeps /my-day + rostering deep links working. `IncidentTemplate` import dropped.
- [x] index() reads `report` + `report_*` → payload `report`/`reportPrefill`; `index.tsx` auto-opens the wizard from `report` with prefill. Ctx "Continue draft" → `openDetail` (edit pane).
- [x] **Deleted** `incidents/create.tsx` + `incidents/wizard/{step-describe,step-optional-detail,step-who-what}.tsx` (kept the shared `@/components/wizard-stepper` — sites pages use it). Deleted obsolete `tests/e2e/incident-create.spec.ts` (tested the retired 2-step page + a removed mobile view); updated the Dusk `IncidentIndexTest` create test to expect the redirect→wizard.
- [x] *Verified:* `tsc` clean + **5 create feature tests** (redirect incident/near-miss, client prefill, draft resume→detail, auth).

**Step 5 = report wizard COMPLETE** (modal-first, both branches, legacy retired, deep-links preserved). Only deferred bit: in-wizard photo capture (step ②) — attachments are addable from the detail dialog meanwhile.

### ◑ Step 6 — Cross-module workflows (Gaps A, B, D, E) — 6a DONE
> Build the WORKFLOWS, not the CR page (redesigned separately).
**6a (DONE) — Gap A flag-as-incident:**
- [x] `ControlRoomIncidentController@flagAsIncident` (+ route `control-room.incidents.flag`, perm `controlRoom.alerts.create`): operator quick-flag → creates `ClientIncident` (source=control_room, status=submitted) **and** `ControlRoomAlert` together, bidirectionally linked (incident.control_room_alert_id FK ↔ alert.context.incident_id). Wrapped in a DB transaction so the observer's afterCommit sees the FK. Critical→high on the incident, critical kept on the alert.
- [x] `ClientIncidentObserver` guards: for source in {control_room, sensor} → back-link the existing alert to the HsEvent + skip the severity-gated bridge (no duplicate alert); manual unchanged.
- [x] *Verified:* `php -l` + **3 flagAsIncident tests** (linked incident+alert, critical→high mapping, permission gate).
**6b (DONE) — Gap B sensor bridge:**
- [x] `app/Services/ControlRoom/SensorIncidentBridgeService` — `confirm()` → `ClientIncident` (source=sensor, interactive=false, status=submitted) carrying signal evidence (`metadata.sensor_evidence`: device/signal_type/payload/detected_at), bidirectionally linked; idempotent (returns existing if already linked); `fall_detected`→type `fall`, critical→high. `dismiss()` → alert `resolution_code=false_positive` + `context.dismissed_reason`, suppresses signals, no incident. (HsEvent auto-opens via the observer, same as Gap A.)
- [x] `ControlRoomAlert`: added `STATUS_CONFIRMED`/`STATUS_DISMISSED` (+ VALID_STATUSES, ALLOWED_TRANSITIONS, isTerminal). `status` is a plain string col → **no migration**.
- [x] `ControlRoomAlertController@confirm`/`@dismiss` (routes `control-room.alerts.confirm`/`.dismiss`, perm `controlRoom.alerts.manage`) calling the service; catch `InvalidArgumentException` → back-with-errors.
- [x] *Verified:* `php -l` + **4 tests** (confirm→linked sensor incident+evidence; dismiss→false-positive+signal suppressed+no incident; perm gate; can't-confirm-resolved).
**6c (DONE) — Gap D state-sync:**
- [x] First-class link populated on the bridge path: `ClientIncidentObserver` now sets `incident.control_room_alert_id` (updateQuietly) when it bridges a manual high/crit incident — so the FK is direct for ALL paths (flag/sensor/bridge), not only via HsEvent.
- [x] Incident→alert sync in `IncidentController@close` (`resolveLinkedAlertOnClose`): closing the incident resolves its linked Control Room alert (actionable + transition-guarded; failure never blocks the close; `resolution_code=incident_closed`). Put in the synchronous close() — testable (the observer's afterCommit doesn't fire under RefreshDatabase). Alert→incident coherence is read-live (the detail banner shows the live alert status), deliberately not force-mutating the incident (its own close guardrails).
- [x] *Verified:* `php -l` + **1 test** (closing an incident resolves the linked open alert).
**6d (NEXT) — modal components (last Step 6 piece):** build the flag-incident + sensor confirm/dismiss triage **components** (ready-to-mount; do NOT build the CR page) — flag → POST `control-room.incidents.flag`; triage → signal evidence + `control-room.alerts.confirm`/`.dismiss` (dismiss reason chips). tsc-verify.

### ☐ Step 7 — Corrective-actions migration (Option B, §6.6)
- [ ] Move incident inline remediation → `HsInvestigation` + `HsCorrectiveAction`; wire "+ Raise corrective action"; cross-surface read-only.
- [ ] Retire/migrate `corrective_actions` JSON + `root_cause_*`/`contributing_factors`/`lessons_learned` on `client_incidents`.

---

## Log
- 2026-06-17 — Mapped spec + codebase (component kit + cross-module backend). Branch + tracker created.
- 2026-06-17 — Step 2 (nav) shipped: "Investigate & Remediate" group (Investigations + Corrective Actions) + near-miss repoint. `tsc` clean.
- 2026-06-17 — Step 1 CODE written (migration + model, both `php -l` clean). Asked migration-run policy → user: **run autonomously (local)**.
- 2026-06-17 — Step 1 migration RUN + verified (migrate/rollback/re-migrate clean, applied mine only via `--path`). **Foundation (Steps 1+2) complete.**
- 2026-06-17 — Step 3 list page DONE: `IncidentController@index` payload rebuilt + `incidents/index.tsx` rebuilt on hs-hero-kit + TabStrip + EntityFilter + right-click rows + followups worklist + near-miss insights. tsc clean + 18 feature tests (234 assertions).
- 2026-06-17 — Step 4a DONE: read-only `IncidentDetailDialog` over the list (controller `detail` payload + 6-section WizardShell modal + row-click wiring), non-regressing (show.tsx kept). tsc clean + 4 detail tests (60 assertions).
- 2026-06-17 — Step 4b-i: lifecycle action panes (review/close/reopen) added to the dialog (in-body pane, existing endpoints). tsc clean.
- 2026-06-17 — Step 4b-ii: add-follow-up inline form (+ `assignable_staff`/`can` in detail payload) + attachment upload/remove/portal-toggle in the dialog. tsc clean + detail tests green.
- 2026-06-17 — Step 4b-iii: edit pane (core fields, drafts) added. **Step 4 modal-first detail functionally complete** (view+lifecycle+followups+attachments+edit). show.tsx retirement deferred → Step 7 (investigation migration).
- 2026-06-17 — Step 5a: report wizard `IncidentReportDialog` (WizardShell, 6 steps + near-miss branch + review + success) over the list, launched from "+ Report"; `store()` extended (source/hazard/followups[]). tsc + 3 store tests.
- 2026-06-17 — Step 5b: `/incidents/create` → redirect to `/incidents?report=` (auto-opens wizard) + prefill/resume; deleted create.tsx + wizard/* + obsolete e2e spec; Dusk test updated. **Step 5 report wizard COMPLETE.** tsc + 5 create tests.
- 2026-06-17 — Step 6a (Gap A): `flagAsIncident` (CR operator quick-flag → linked incident+alert) + observer guards (no double-alert for control_room/sensor sources). php -l + 3 tests.
- 2026-06-17 — Step 6b (Gap B): `SensorIncidentBridgeService` (confirm→sensor incident+evidence; dismiss→false-positive+suppress signals) + ControlRoomAlert confirmed/dismissed states + controller confirm/dismiss endpoints. php -l + 4 tests.
- 2026-06-17 — Step 6c (Gap D): first-class alert FK on bridge path + close→resolve-linked-alert state-sync. php -l + 1 test. **Step 6 cross-module BACKEND (A+B+D) complete.** Next 6d: flag/triage modal components.
