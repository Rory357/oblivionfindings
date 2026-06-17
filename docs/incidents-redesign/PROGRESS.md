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

### ☐ Step 1 — Schema (Gaps C, D) ⚠️ migration write-only until human-confirmed
- [ ] Migration: `source` enum (`manual|control_room|sensor|automated`, default `manual`) + `interactive` bool on `client_incidents`.
- [ ] Migration: first-class `control_room_alert_id` FK (nullOnDelete) on `client_incidents`.
- [ ] Backfill existing rows `source='manual'`.
- [ ] Model: add to `$fillable` + casts + `controlRoomAlert()` relation.
- [ ] *Verify:* migrate + rollback clean; existing incidents unaffected. **(needs human OK to run)**

### ☑ Step 2 — Nav / IA (§4a)
- [x] Add "Investigate & Remediate" group to `app-sidebar.tsx` (Investigations → `/health-safety/events`, Corrective Actions → `/health-safety/corrective-actions`), after the incidents group.
- [x] Repoint Near Misses `:1170` `/incidents?type=near_miss` → `/incidents?tab=near_misses`; drop the title-flip (handled by new index).
- [x] *Verify:* `tsc --noEmit` clean (exit 0). Browser check (both registers reachable; near-miss lands on tab) deferred to Step 3 when the tab target exists.

### ☐ Step 3 — List page (`incidents/index.tsx`, Gap G)
- [ ] hs-hero-kit hero (incident clusters "This period" + "Needs attention", NOT compliance badges) + footer filter band.
- [ ] `TabStrip` 8 tabs: All · Open · Under investigation · Follow-ups due · WorkSafe-notifiable · Near misses · Awaiting review · Closed.
- [ ] `EntityFilter`s (Site, Client) + Sources lens + search pill.
- [ ] Right-click `ShiftContextMenu` rows (copy PRN `openRowCtx`); row badges; row-click → detail modal.
- [ ] Follow-ups-due worklist tab; near-miss insights strip.
- [ ] Extend `IncidentController@index` payload (per-tab aggregates, follow-ups roll-up, near-miss insights, source).
- [ ] *Verify:* tabs filter; right-click opens; counts match.

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
