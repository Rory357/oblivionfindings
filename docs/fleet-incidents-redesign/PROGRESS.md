# Fleet & Asset Incidents redesign — build PROGRESS tracker

> Loop checkpoint doc. Branch `feat/fleet-incidents-redesign`. Design drop: `.design-drops/fleet-incidents-redesign/` (`Fleet Incidents.dc.html` + `HANDOFF.md`). Canonical specs: `FLEET_INCIDENTS_LIFECYCLE_PLAN.md` (data/lifecycle/cross-module) + `docs/FLEET_INCIDENTS_GAP_ANALYSIS.md` (A–H checklist SSOT). Near-twin of the shipped Incidents (`docs/incidents-redesign/PROGRESS.md`) + Safeguarding (`docs/safeguarding-redesign/PROGRESS.md`) redesigns — reuse those patterns 1:1.

**Region:** NZ only (Waka Kotahi, Land Transport Act 1998 **s22** 24h Police/TCR, NZ Police, ACC, WorkSafe/HSWA, Ngā Paerewa). **Platform:** web-only. **Scope:** H&S incident slice now; register/telematics pieces = `PREP-LATER` (UI+schema now, wired later).

## Decisions log
- **2026-06-18** — Build autonomously in `/loop` (user dropped the design drop + started the loop). Feature branch in the main repo (NOT a worktree) so backend tests exercise the edited code + migrations run against local DB. Migration policy: run local autonomously.
- **2026-06-18 — Severity vocab (Gap F4):** KEEP `FleetIncident`'s `minor/moderate/major/critical` (established vocab + data + UI + validation); map correctly at every cross-module boundary (critical/major→high, moderate→medium, minor→low). Chose "map in UI/service" over a destructive migrate. **Fixes the latent observer bug** where `severity in ['high','critical']` never matched `major`, so major incidents never raised a Control Room alert.
- **2026-06-18 — Attachments:** dedicated `FleetIncidentAttachment` table mirroring `SafeguardingAttachment` (matches the H&S family; reuses shared `file-dropzone.tsx`).
- **2026-06-18 — Follow-ups:** mirror the `IncidentFollowup` pattern (confirm reuse-vs-new after reading it in Step 1).

## Steps
- [ ] **Step 1 — Backend schema + model + observer fix** (B1–B6). Grouped migrations expanding `fleet_incidents` (people-aboard+injuries, third-party, witnesses, scene/conditions, damage/recovery/VOR, Police+WorkSafe+ACC, insurance/cost, investigation/assignment, register/licence snapshots `PREP-LATER`); `FleetIncidentAttachment`; fleet follow-ups; direct FleetIncident↔ClientIncident FK; model fillable/casts/relations/constants/accessors (s22 countdown, isPoliceReportDue, WorkSafe classifier hook); fix observer severity mapping.
- [ ] **Step 2 — Controller + routes + services**. Expand store/update validation for the full capture set; new endpoints (updateStatus, addFollowup/completeFollowup, uploadAttachment/deleteAttachment, logPoliceReport/TCR, logClaim, markOffRoad/backInService, close w/ gate, export); index tab-scoping + new stats (Police due, VOR, claims, injury/ACC) + filters; show() full modal payload; permissions.
- [ ] **Step 3 — List page** (`index.tsx`). Rebuild on `hs-hero-kit` (fleet clusters: This period + Needs attention), `TabStrip` (All·Open·Under investigation·Police report due·Injury/ACC·Insurance & claims·Off-road (VOR)·Near misses·Closed), footer band (HeroSegmented + Site/Vehicle/Driver `EntityFilter` `onDark` + type/severity + search + CSV), `ShiftContextMenu` right-click rows + badges, row→detail modal.
- [ ] **Step 4 — Detail modal** (`FleetIncidentDialog`). `WizardShell` read-only chrome, 10 rail sections (Overview w/ stage tracker + s22 countdown · Vehicle/asset · People · Scene & conditions · Damage & recovery · Police & regulatory · Insurance & cost · Photos & documents · Investigation & follow-ups · Linked records), Options bar. Thin `show.tsx` deep-link fallback.
- [ ] **Step 5 — Report wizard** (modal, 3 branches). `WizardShell` vehicle 6-step / asset 4-step / near-miss 4-step; photo capture; Police step auto-surfaces s22 + WorkSafe; review + `WizardSuccessPane`. Launcher (3 choices). Retire `create.tsx`→redirect.
- [ ] **Step 6 — Cross-module + workflow modals + telematics storyboard**. Update-status/follow-up/upload/log-police/log-claim/mark-off-road dialogs; linked-records both ways (client incident shows originating fleet incident); telematics crash-detect→confirm/dismiss→draft storyboard (`PREP-LATER`).
- [ ] **Step 7 — Tests + polish + retire + ship**. Feature tests (schema, endpoints, gate, cascade, observer fix, classifier); tsc/eslint/build; merge→deploy→Chrome-verify on .com.

## Build log
- (pending)
