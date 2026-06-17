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

### ☐ Step 4 — Detail modal (`IncidentDetailDialog`, Gap F)
- [ ] `WizardShell` read-only chrome; rail Overview/Timeline/Photos/Follow-ups/Investigation/Linked; Options bar.
- [ ] Row-click + "View" open over list. Keep `/incidents/{id}` thin deep-link shell. Retire `show.tsx`.

### ☐ Step 5 — Report wizard (`WizardShell`, §3)
- [ ] 6 steps + near-miss branch + photo capture + `WizardSuccessPane`; preserve `?incident=` resume + `?shift_id`/`?client_id` prefill. Retire `create.tsx` + `WizardStepper`.

### ☐ Step 6 — Cross-module workflows (Gaps A, B, D, E)
- [ ] `flagAsIncident`; `SensorIncidentBridgeService` (confirm→incident/HsEvent; dismiss→false-positive); alert confirm/dismiss states; alert↔incident state-sync observers.
- [ ] Confirm/dismiss + flag-incident modals (mountable into future Control Room).

### ☐ Step 7 — Corrective-actions migration (Option B, §6.6)
- [ ] Move incident inline remediation → `HsInvestigation` + `HsCorrectiveAction`; wire "+ Raise corrective action"; cross-surface read-only.
- [ ] Retire/migrate `corrective_actions` JSON + `root_cause_*`/`contributing_factors`/`lessons_learned` on `client_incidents`.

---

## Log
- 2026-06-17 — Mapped spec + codebase (component kit + cross-module backend). Branch + tracker created.
- 2026-06-17 — Step 2 (nav) shipped: "Investigate & Remediate" group (Investigations + Corrective Actions) + near-miss repoint. `tsc` clean.
- 2026-06-17 — Step 1 CODE written (migration + model, both `php -l` clean). Asked migration-run policy → user: **run autonomously (local)**.
- 2026-06-17 — Step 1 migration RUN + verified (migrate/rollback/re-migrate clean, applied mine only via `--path`). **Foundation (Steps 1+2) complete.**
- 2026-06-17 — Step 3 list page DONE: `IncidentController@index` payload rebuilt + `incidents/index.tsx` rebuilt on hs-hero-kit + TabStrip + EntityFilter + right-click rows + followups worklist + near-miss insights. tsc clean + 18 feature tests (234 assertions). Next: Step 4 detail modal.
