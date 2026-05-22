# Client Profile Rebuild — Gap Analysis & Phase 1 Plan

> **Purpose:** Single-source implementation brief for the Client Profile rebuild. Hand this file to the implementer (human or agent). Each PR section is self-contained enough to execute independently.

---

## Context

Oblivion Findings is a NZ Supported Living CRM (Laravel 13 / Inertia / React / TypeScript). The current Client Profile (`resources/js/pages/operations/clients/show.tsx`, ~10,600 LOC) already has 22 tabs and most core entities. But:

- The frontline workflow for **Daily Notes** is hidden inside a generic `progress_notes` tab, with no hero-level capture path. Staff drill three levels to record a shift observation.
- Two parallel note models exist (`ClientNote` and `ProgressNote`). `ProgressNote` writes TimelineEvents inline; `ClientNote` uses an observer — a divergence that will double-write once both are wired.
- Several SharePoint-reference modules are entirely missing: **Bowel / Fluid / Seizure charts, Rhythms & Routines, proper Communication Notes, Family Tree, Actions & Reviews dashboard, Accident Investigation, PATH / Whole-of-Life**.
- Tab labelling and grouping don't reflect a clean supported-living mental model (Personal Details hidden in an edit dialog; Appointments buried in Calendar; Finance on a separate page).
- `TimelineEvent` infrastructure exists but is sparsely wired — most modules don't emit events, so the Timeline tab is anaemic.

This plan delivers, in Phase 1, the hero quick-capture workflow, rebuilt Daily Notes, broadened Communication Notes, three Health Monitoring charts, the new Rhythms & Routines tab, and a per-client Actions & Reviews aggregator — all on top of a unified TimelineEvent emitter so future modules feed the timeline without re-plumbing.

**User decisions captured upstream:**
- Daily-note model: **standardise on `ClientNote`** (richer schema; deprecate `ProgressNote` in Phase 2).
- Tab structure: **restructure to the 20-tab spec**, mapping existing content into it.
- Phase 1 scope: **comprehensive** — hero capture + Daily Notes + Timeline emitter + Communication Notes + Health charts + Actions & Reviews + Rhythms & Routines.
- Review routing: **right-rail review queue in Daily Notes + hero badge counter** (scoped to one client; cross-client dashboard deferred).

---

## Current-state audit (summary)

**Frontend:** [resources/js/pages/operations/clients/show.tsx](../resources/js/pages/operations/clients/show.tsx) at ~10,600 LOC contains all 22 tabs inline. Hero uses `<PageHero avatar=…>` with actions: Call, Visits, Edit. Tabs grouped in [resources/js/pages/operations/clients/tabs/_groups.ts:22](../resources/js/pages/operations/clients/tabs/_groups.ts). Local React state drives tab selection (no URL routing).

**Backend:** `Client` model with 30+ relationships ([app/Models/Client.php](../app/Models/Client.php)). 29 `Client*` sub-models. `TimelineEvent`, `TimelineEventComment`, `TimelineEventReaction`, `AuditLog` already exist. `AuditableChanges` trait is on 20+ models. `ClientPolicy` with `viewAny`/`viewAssigned`/`view`/`viewMedications` (5-tier).

**Patterns to reuse:**
- PageHero + avatar: [page-hero.tsx:29-149](../resources/js/components/page/page-hero.tsx)
- Tile picker (Send-Kudos style): [sites/contacts/_dialogs.tsx:61-107](../resources/js/pages/sites/contacts/_dialogs.tsx)
- Card-grid + dialog CRUD: [sites/show.tsx:3792-3920](../resources/js/pages/sites/show.tsx)
- Activity timeline: [components/dashboard/activity-timeline.tsx:60-237](../resources/js/components/dashboard/activity-timeline.tsx)
- Outline-on-gradient button class: `border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground`

**Critical finding — duplicate note systems:**
- `ClientNote` (table `client_notes`) — full schema: `shift_id, type, subject, goal, body, mood_rating, flagged, reviewed_at, visibility, attachments[]`. **Has `ClientNoteObserver` that upserts TimelineEvent.**
- `ProgressNote` (table `progress_notes`) — separate, has `note_type` / `emotions[]` / `care_plan_goal_id`. **Writes TimelineEvent inline at [ProgressNoteController.php:128](../app/Http/Controllers/Operations/ProgressNoteController.php) — divergent path.**

---

## Gap analysis table (20-tab spec)

| # | Spec tab | SharePoint ref | Current Oblivion state | Gap | Recommended action | Priority |
|---|---|---|---|---|---|---|
| 1 | Overview | Landing page | `profile` tab (rich) | Already strong; needs hero badge for flagged-review count | Wire badge; small | P1 |
| 2 | Personal Details | Person Details list | In edit dialog only ([client-edit-dialog.tsx](../resources/js/components/client-edit-dialog.tsx)) | No dedicated tab | Surface as read-only tab; edit-in-place | P2 |
| 3 | Daily Notes | Daily Notes list | `progress_notes` tab (basic list); duplicate model | Hero capture missing; no filters/queue/draft | Rebuild as canonical; hero wizard + quick note | **P1** |
| 4 | Timeline | (none) | `timeline` tab exists but sparse | Only ClientNote and a few sources emit events | Centralise emitter; wire more sources | **P1** |
| 5 | Communication Notes | Communication Notes list | `family_notes` tab (portal-flavoured only) | No general comms log (providers/agencies/schools) | Rebuild as `communication` type on ClientNote | **P1** |
| 6 | Care & Support Plan | Care & Support Plan | `care_plans` tab + `/operations/care-plans/` | Already strong | Relabel/regroup only | P2 |
| 7 | Rhythms & Routines | Rhythms and Routines | Missing | Entire feature absent | New model + tab | **P1** |
| 8 | Goals / PATH / Whole of Life | Whole of Life Showcase / PATH | Partial (goals inside care plan); no PATH module | No life-story surface, no PATH integration | Phase 2 tab; lift `life_story`/`strengths_abilities` columns into UI | P2 |
| 9 | Health Monitoring (bowel / fluid / seizure / appts / docs) | Bowel / Fluid / Seizure charts | Medical/MAR strong; no bowel/fluid/seizure | Three missing chart modules | Three new models + sub-tabs with Recharts | **P1** |
| 10 | Behaviour / ABC | ABC Observation Form | `observations` tab (uses [ClientObservationsTab](../resources/js/components/clinical/client-observations-tab.tsx)) | Already exists; lacks pattern dashboard | Relabel; pattern dashboard in Phase 2 | P2 |
| 11 | Incidents & Accidents | Incident / Accident | `incidents.tsx` standalone; no accident-investigation workflow | Accident investigation flow missing | Relabel into tab; investigation in Phase 2 | P2 |
| 12 | Risk Management | Risk Management Plan | `risks.tsx` standalone | Surface as tab; already linked from hero | Promote to tab in new structure | P2 |
| 13 | Appointments | Appointment Record | Buried in `calendar` tab | No dedicated list view | Pull list view out; calendar stays | P2 |
| 14 | Finance | Ledger / Discrepancies / Purchase Requests | `client-funds` external; no profile tab | Three missing: discrepancies, purchase requests | Phase 2 tab with sub-sections | P2 |
| 15 | Leave & Excursions | Leave / Excursion Requests | `visit-requests.tsx` (visitor visits only) | Leave & excursions absent | New models + tab | P2 |
| 16 | Personal Inventory | Personal Inventory | `personal_assets` tab | Already strong | Relabel only | P2 |
| 17 | Documents | Client Documents | `documents.tsx` (rich) | Missing expiry/review timeline events | Add observer; surface expiring docs in Actions tab | P2 |
| 18 | Family Tree / Important People | Family Tree | `NextOfKin` model + `portal-users.tsx` | No visual tree, no relationship-type taxonomy | Phase 2 visual tree | P2 |
| 19 | Actions & Reviews | (cross-cutting) | Missing | No aggregated "what needs attention" view | New aggregator tab | **P1** |
| 20 | Audit / History | Recycle Bin / Archive | `AuditLog` model exists; no UI | Read-only; not surfaced | Phase 3 tab | P3 |

**P1 = Phase 1 (this plan), P2 = Phase 2, P3 = Phase 3.**

---

## Recommended 20-tab structure

Tab keys (left) are the new internal identifiers; in **Phase 1** we relabel-and-regroup but keep old keys aliased where already in use, so existing in-flight links/tests don't break.

Five groups, 20 tabs:

| Group | Tabs |
|---|---|
| **Snapshot** | `overview`, `personal_details` |
| **Daily care** | `daily_notes`, `timeline`, `communication_notes`, `rhythms_routines` |
| **Plans & goals** | `care_plan`, `goals_path`, `behaviour_abc` |
| **Health & safety** | `health_monitoring` (sub: bowel / fluid / seizure / appointments / docs), `incidents_accidents`, `risk_management` |
| **Day-to-day operations** | `appointments`, `leave_excursions`, `personal_inventory`, `finance`, `documents` |
| **Relationships & governance** | `family_tree`, `actions_reviews`, `audit_history` |

Existing logistical tabs (transport, respite, MAR, meal_prefs, calendar, consents, consent-requests, service_agreements, portal, assignments, photos) fold into the above (e.g. transport → `appointments` rail, MAR → `health_monitoring` sub-tab, photos → `documents` sub-section, consents/portal → `relationships`). No data is deleted; only navigation is reorganised.

---

## Data model strategy

### Reuse (no schema change beyond additive)
- **`ClientNote`** becomes the canonical home for Daily Notes (`type='daily_note'`), Communication Notes (`type='communication'`), and quick capture (`type='quick'`). Add columns: `category`, `behaviour_tags` (json), `concerns_flags` (json), `follow_up_action`, `follow_up_due_at`, `follow_up_completed_at`, `appears_on_timeline` (bool, default true), `is_draft` (bool, default false), `contact_person`, `contact_relationship`, `contact_method` (for communication notes).
- **`TimelineEvent`** stays as-is. All emitters go through a new `TimelineEmitter` service.
- **`AuditLog`** stays as-is; new modules opt in via `AuditableChanges` trait.

### New tables (Phase 1)
- `client_bowel_entries` — `client_id, occurred_at, bristol_type (1-7), volume, notes, recorded_by, organization_id`. Soft deletes.
- `client_fluid_entries` — `client_id, occurred_at, direction ('in'|'out'), fluid_type, volume_ml, notes, recorded_by, organization_id`. Soft deletes.
- `client_seizure_entries` — `client_id, occurred_at, duration_seconds, seizure_type, trigger, response_taken, recovery_notes, escalated, follow_up_action, recorded_by, organization_id`. Soft deletes.
- `client_routines` — `client_id, time_block ('morning'|'day'|'evening'|'overnight'|'preferences'|'triggers'|'calming'|'avoid'|'what_works'), body (text), display_order, updated_by, organization_id`. One row per block. Soft deletes.

### Deferred (Phase 2)
- Leave / Excursion request models, Accident Investigation, PATH plan, Family relationship taxonomy, Purchase Request, Financial Discrepancy. Existing `Invoice`/`ClientLedgerEntry` cover basic finance; Phase 2 extends.

---

## Workflow design

### Daily Note (hero "Daily Note" button → multi-step wizard)
1. Staff clicks **Daily Note** in hero `actions` slot.
2. **Step 1 — Pick category** (tile grid, modelled on `ContactTypePicker`): Activity, Mood/Behaviour, Health, Communication, Concern, Goal progress, Routine, Other. Active tile is purple-tinted.
3. **Step 2 — Form** (category-aware): subject, body, mood slider (auto-shown for Mood/Concern), behaviour tag chips (Mood/Concern), concern flag (Concern), follow-up action + due date (collapsible), goal link (Goal progress), shift selector (auto-fills active shift), attachments dropzone.
4. **Step 3 — Review & submit**: read-only preview card showing how the note will appear in the tab. Two toggles: "Visible to family" (sets `visibility=portal`), "Show on timeline" (sets `appears_on_timeline`). Buttons: `Save as Draft`, `Submit Note`.
5. On submit:
   - `ClientNote` row created.
   - `ClientNoteObserver` calls `TimelineEmitter::project($note)` which upserts a `TimelineEvent` (unless `is_draft=true` or `appears_on_timeline=false`).
   - `AuditableChanges` writes an `audit_logs` row.
   - If `is_flagged=true`, the right-rail review queue counter increments; hero badge counter increments (only visible to users with `progress_notes.review`).
   - Inertia partial reload updates `client_daily_notes` and `daily_notes_summary` props; toast "Daily note added".

### Quick Note (hero "Quick Note" button → tiny dialog)
- One-line subject (optional), 3-row textarea, category chip row (default Other), "Flag for review" toggle, primary `Add note` button. No multi-step. Submits to same controller as `type='quick'`. Same emitter path.

### Communication Note
- Triggered from hero "Communication" sub-button OR via Communication Notes tab "+ New".
- Same wizard skeleton, but Step 2 swaps fields: contact person + relationship + method (phone/email/text/meeting/in-person), summary, follow-up required + owner, related documents.
- Persists as `ClientNote(type='communication')`. Timeline event `type='communication'`.

### Timeline aggregation
- All emitters call `TimelineEmitter::project(EmitsToTimeline $source)`. Source models implement `toTimelineEvent(): array`.
- Phase 1 wires: `ClientNote`, `ClientIncident`, `ClientBowelEntry`, `ClientFluidEntry`, `ClientSeizureEntry`, `Appointment`, `ClientAssessment` reviews, document expiry.
- Removes the inline `TimelineEvent::create` in `ProgressNoteController:128` to prevent double-writes once `ProgressNote` is also wired (deferred to Phase 2 deprecation).

### Manager review
- Flagged + non-reviewed notes land in the right-rail "Review queue" card on the Daily Notes tab.
- Hero shows `Flagged: 3` badge (only when user has `progress_notes.review` and count > 0).
- Clicking the badge or the right-rail card opens the tab filtered to `?flagged=1&reviewed=0`.
- "Mark reviewed" inline button on each note → sets `reviewed_at`, `reviewed_by`; queue updates.

### Actions & Reviews tab (aggregator, no new table)
Lists, per client, with status colour-coded:
- Open follow-ups (from `ClientNote.follow_up_action where follow_up_completed_at IS NULL`)
- Overdue follow-ups (same + `follow_up_due_at < now`)
- Flagged notes pending review
- Documents expiring within 30 days (from `ClientDocument.expires_at`)
- Risks due for review (from `ClientRisk.next_review_at`)
- Care plan reviews due (from `CarePlan.next_review_at`)
- Assessments due (from `ClientAssessment.next_due_at`)
- Pending consent requests
- Pending visit requests

Backend: a single `ClientActionsAggregator` service that returns a typed list. UI is read-only with deep-link buttons.

---

## Implementation plan — PR sequence

Each PR is independently shippable; later PRs depend on earlier ones for shared infrastructure only.

### PR 1 — Timeline emitter foundation (no UX change)
- New `app/Contracts/Timeline/EmitsToTimeline.php` (interface: `toTimelineEvent(): array`).
- New `app/Services/Timeline/TimelineEmitter.php` (`project($source, ?$forcedType)`, `retract($source)`). Uses `updateOrCreate` keyed on `(type, source_type, source_id)`.
- `ClientNote implements EmitsToTimeline`. Existing `ClientNoteObserver` refactored to call the emitter (behaviour-preserving).
- `ProgressNoteController` inline `TimelineEvent::create` replaced with emitter call (or removed if observer also added — pick one path; aim for observer).
- **Ship gate:** existing timeline rows look identical; tests pass.

### PR 2 — `client_notes` schema + new controller + policies
- Migration: add `category`, `behaviour_tags`, `concerns_flags`, `follow_up_action`, `follow_up_due_at`, `follow_up_completed_at`, `appears_on_timeline`, `is_draft`, `contact_person`, `contact_relationship`, `contact_method` to `client_notes`. Composite index `(client_id, is_flagged, reviewed_at)`.
- Extend `ClientNote` fillable/casts; add `scopeDailyNotes`, `scopeCommunication`, `scopeReviewQueue`, `scopeForUser`.
- New `app/Http/Controllers/Operations/ClientDailyNoteController.php` (index/store/update/destroy/flag/review/markReviewed).
- New `app/Http/Resources/ClientDailyNoteResource.php`.
- Extend `app/Policies/ClientNotePolicy.php` with `flag`/`review`/`viewFlaggedQueue`.
- Routes in `routes/clients.php`: `Route::resource('clients.daily-notes', ...)` + `POST /flag`, `POST /review`.
- Seed `progress_notes.review` permission for Manager role.
- **Ship gate:** backend-only; endpoints respond correctly; observer still emits.

### PR 3 — Hero quick-capture dialogs
- `resources/js/pages/operations/clients/dialogs/_note-category-picker.tsx` (clone of `ContactTypePicker`).
- `resources/js/pages/operations/clients/dialogs/quick-note-dialog.tsx`.
- `resources/js/pages/operations/clients/dialogs/daily-note-wizard.tsx` (3-step).
- `resources/js/pages/operations/clients/hooks/use-daily-note-form.ts`.
- Two new `<Button>` in `show.tsx` hero `actions`; dialog mount + open state.
- **Ship gate:** users can capture notes; they land in `client_notes` and surface in the existing Timeline tab.

### PR 4 — Daily Notes tab rebuild
- New `resources/js/pages/operations/clients/tabs/daily-notes.tsx` (filters bar, day-grouped list, right-rail review queue + stats + drafts).
- Replace inline `progress_notes` block in `show.tsx` with `<DailyNotesTab .../>` mount.
- Rename `progress-note-entry.tsx` → `daily-note-entry.tsx` (re-export shim for back-compat); extend props for `category`/`behaviour_tags`/`concerns_flags`/`follow_up_action`.
- `ClientController::show` passes `client_daily_notes` and `daily_notes_summary`.
- Hero badge counter (reviewer-only) when `summary.flagged_open > 0`.
- **Ship gate:** tab loads; existing `progress_notes` data renders correctly; new submissions render correctly.

### PR 5 — 20-tab restructure (relabel + regroup; no behaviour change)
- Update `tabs/_groups.ts` to the 5 new groups + 20 keys.
- Add label-only renames for: `progress_notes` → "Daily Notes" (key stays for back-compat), `family_notes` → "Communication Notes" (data filtered to portal-visible).
- Introduce new placeholder tabs for `personal_details`, `rhythms_routines`, `health_monitoring`, `actions_reviews`, `goals_path`, `family_tree`, `audit_history` — each renders a minimal "Coming soon" card so the structure ships safely.
- **Ship gate:** all old tabs still resolve; new placeholders visible; no data loss.

### PR 6 — Communication Notes tab
- New `tabs/communication-notes.tsx` reusing `daily-note-entry.tsx`. Filters by `type='communication'`.
- Wizard variant in `daily-note-wizard.tsx` switches Step 2 to communication fields when category = "Communication" (or via a dedicated `mode='communication'` prop).
- Hero gets a third button "Communication" (or single "Add" button that opens a category picker first — decide in code review; default to separate button for visibility).
- Migration data step: identify existing `family_notes` rows; flag them with `type='communication'` if not already.
- **Ship gate:** family notes still display in the relabelled tab; new comms notes work end-to-end.

### PR 7 — Health Monitoring tab shell + Bowel chart
- New migration: `client_bowel_entries`.
- New `app/Models/ClientBowelEntry.php` (implements `EmitsToTimeline`).
- Observer + emitter wiring.
- New `app/Http/Controllers/Clinical/ClientBowelChartController.php` (index/store/update/destroy).
- New `tabs/health-monitoring/index.tsx` with sub-tab navigation (Bowel / Fluid / Seizure / Appointments / Docs).
- New `tabs/health-monitoring/bowel.tsx` with Bristol-stool entry form, recent-entries list, 14/30-day trend chart (Recharts).
- **Ship gate:** bowel entries persist, render, emit timeline events.

### PR 8 — Fluid chart
- Migration + model + observer + controller for `client_fluid_entries`.
- `tabs/health-monitoring/fluid.tsx` with intake/output bars (Recharts), daily totals, escalation banner if 24h intake < threshold (configurable per client; Phase 2 for per-client thresholds).
- **Ship gate:** parity with PR 7.

### PR 9 — Seizure chart
- Migration + model + observer + controller for `client_seizure_entries`.
- `tabs/health-monitoring/seizure.tsx` with frequency chart, severity heatmap, last-event card. Auto-escalation flag if duration > 5 min (status_critical timeline event).
- **Ship gate:** parity with PR 7.

### PR 10 — Rhythms & Routines tab
- Migration + model for `client_routines` (one row per block).
- `app/Http/Controllers/Operations/ClientRoutineController.php` (upsertBlock + reorder).
- `tabs/rhythms-routines.tsx` with 8 collapsible cards (Morning, Day, Evening, Overnight, Preferences, Triggers, Calming, What Works, Avoid). Each card has inline edit + version-stamp.
- Routine updates emit a TimelineEvent (`type='routine_updated'`).
- **Ship gate:** routines persist; rendered in stable order; edits audit-logged.

### PR 11 — Actions & Reviews tab
- New `app/Services/Client/ActionsAggregator.php` returning a typed list (`type`, `severity`, `due_at`, `summary`, `deep_link`).
- `app/Http/Controllers/Operations/ClientActionsController.php` (index returns aggregator output).
- `tabs/actions-reviews.tsx` with grouped sections (Overdue, Due this week, Upcoming, Flagged), severity dots, deep-link buttons.
- Hero badge stat: "X open actions".
- **Ship gate:** aggregator returns correct counts; deep links navigate.

### PR 12 — Polish, telemetry, and shared affordances
- Keyboard shortcuts: `n` from anywhere in profile → Quick Note; `Shift+N` → Daily Note wizard; `g d` → Daily Notes tab.
- Loading skeletons for all new tabs.
- Empty-state illustrations for new tabs.
- Telemetry hooks: which categories most-used; time to submit; reviewer queue clearance rate.
- Re-test the hero badge across all role permutations.
- Per-tab file extraction continues — pull more of the inline `show.tsx` tab bodies into their own files to shrink the 10,600 LOC monolith.
- **Ship gate:** UX polish only.

---

## Critical files

**Most affected (Phase 1):**
- [resources/js/pages/operations/clients/show.tsx](../resources/js/pages/operations/clients/show.tsx) — hero actions, tab mounts, page props
- [resources/js/pages/operations/clients/tabs/_groups.ts](../resources/js/pages/operations/clients/tabs/_groups.ts) — group/label restructure
- [app/Models/ClientNote.php](../app/Models/ClientNote.php) — extend fillable, add `EmitsToTimeline`
- [app/Observers/ClientNoteObserver.php](../app/Observers/ClientNoteObserver.php) — route through emitter
- [app/Http/Controllers/ClientController.php](../app/Http/Controllers/ClientController.php) — pass new page props
- [routes/clients.php](../routes/clients.php) — register new resource routes

**New files (representative paths):**
- `app/Contracts/Timeline/EmitsToTimeline.php`
- `app/Services/Timeline/TimelineEmitter.php`
- `app/Services/Client/ActionsAggregator.php`
- `app/Http/Controllers/Operations/ClientDailyNoteController.php`
- `app/Http/Controllers/Operations/ClientRoutineController.php`
- `app/Http/Controllers/Operations/ClientActionsController.php`
- `app/Http/Controllers/Clinical/ClientBowelChartController.php` (+ Fluid, Seizure equivalents)
- `app/Http/Resources/ClientDailyNoteResource.php`
- `app/Models/ClientBowelEntry.php` (+ Fluid, Seizure, Routine equivalents)
- `database/migrations/*_add_daily_note_fields_to_client_notes_table.php`
- `database/migrations/*_create_client_bowel_entries_table.php` (+ Fluid, Seizure, Routine)
- `resources/js/pages/operations/clients/dialogs/quick-note-dialog.tsx`
- `resources/js/pages/operations/clients/dialogs/daily-note-wizard.tsx`
- `resources/js/pages/operations/clients/dialogs/_note-category-picker.tsx`
- `resources/js/pages/operations/clients/tabs/daily-notes.tsx`
- `resources/js/pages/operations/clients/tabs/communication-notes.tsx`
- `resources/js/pages/operations/clients/tabs/rhythms-routines.tsx`
- `resources/js/pages/operations/clients/tabs/actions-reviews.tsx`
- `resources/js/pages/operations/clients/tabs/health-monitoring/{index,bowel,fluid,seizure}.tsx`

---

## Permissions

Reuse existing: `clients.viewAny`, `clients.viewAssigned`, `clients.update`, `progress_notes.viewAny`, `progress_notes.create`, `progress_notes.update`, `progress_notes.delete`, `timeline.create`, `timeline.pin`, `medications.*`.

**One new gate:** `progress_notes.review` (Manager role). Seeded in `RolePermissionSeeder` in PR 2.

For new health charts: reuse `medications.view` for view, `medications.administer.record` for write — already scoped to clinical staff. (Alternative: create `health_charts.view`/`write` gates if a separate boundary is preferred; default to medication gates for least churn.)

For Rhythms & Routines: reuse `clients.update`.

For Actions & Reviews: read-only based on per-source permissions (the aggregator filters items the user can't see).

---

## Verification

End-to-end checks after each PR. Phase 1 final acceptance:

1. **Database** — `php artisan migrate` clean. `tinker → ClientNote::first()->category` returns `null` (additive only). All new tables exist with correct indexes.
2. **Hero capture** — open `/operations/clients/1`:
   - "Daily Note" and "Quick Note" buttons render in hero `actions`.
   - Quick Note: submit → toast → row in `client_notes` (`type=quick, category=other`) → row in `timeline_events` (`source_type=App\Models\ClientNote`) → row in `audit_logs`.
   - Daily Note wizard: 3 steps → submit → same projection → tab list refreshes.
   - Submit as Draft: `is_draft=1`; **no** timeline row; appears in right-rail "My drafts".
3. **Daily Notes tab** — filters compose (`?flagged=1&mine=1`); day-grouped; right-rail counts match. Review queue shows flagged notes; "Mark reviewed" updates `reviewed_at`.
4. **Communication Notes tab** — relabelled from `family_notes`; existing rows visible; new comms notes via wizard work.
5. **Health Monitoring** — bowel/fluid/seizure: create entry → row in respective table → row in `timeline_events` → chart updates. Seizure > 5 min auto-escalates (critical timeline event).
6. **Rhythms & Routines** — edit each block; persist; appears on Timeline as `type=routine_updated`.
7. **Actions & Reviews** — aggregator returns expected list (seed test data: 1 overdue follow-up, 1 expiring doc, 1 flagged note). Deep-links navigate correctly.
8. **Permissions** — log in as support worker without `progress_notes.review` → hero badge hidden, queue endpoint 403s. Log in as Manager → badge visible, queue accessible.
9. **No double-writes** — verify exactly one `timeline_events` row per source after the inline `ProgressNoteController` write is removed.
10. **Cascade** — delete a ClientNote via tinker → observer fires `TimelineEmitter::retract` → matching `timeline_events` row deleted.
11. **Browser smoke** — use Claude Preview MCP (or local Herd dev server `https://oblivionfindings.test`) to load profile page, click Daily Note button, complete wizard, verify the new note appears in tab. Screenshot for the PR description.
12. **Cypress/Playwright** — add at minimum: happy-path Quick Note submission, Daily Note wizard step navigation. (Confirm test framework in `package.json` before writing.)

---

## Risks

1. **Tab key rename breaks deep-links / bookmarks.** Today tab is local React state, not URL — so no live deep-links exist. Risk is future-tense: as URL routing is added (Phase 2), the relabelled tabs must keep old keys aliased. **Mitigation:** keep `progress_notes` and `family_notes` as internal keys in Phase 1 (label change only). Introduce URL routing + redirect map in Phase 2.
2. **ProgressNote vs ClientNote dual-write.** During Phase 1 `ProgressNote` still exists and is written to by the legacy controller. New writes go to `ClientNote`. Data divergence risk if both surfaces are used concurrently. **Mitigation:** deprecate the legacy `progress_notes` POST endpoint in PR 3 (return 410 Gone or redirect to new endpoint); data-migrate `ProgressNote` rows into `ClientNote` in Phase 2.
3. **Double timeline rows.** If both `ProgressNoteController` (inline write) and a future `ProgressNoteObserver` write to TimelineEvent, the unique index `(type, source_type, source_id)` will throw. **Mitigation:** PR 1 removes the inline write before any observer is added.
4. **Drafts leaking to family portal.** A `is_draft=1` note with `visibility=portal` could leak. **Mitigation:** enforce `visibility=internal` when `is_draft=true` in `ClientDailyNoteController@store`; model-level mutator as belt-and-braces.
5. **`show.tsx` extraction risk.** Pulling tab bodies out into separate files churns closures over `pageProps`/`client`/`can`. **Mitigation:** make extracted tabs dumb (props-only, no `usePage()` inside) — trivially testable, no behaviour shift.
6. **`progress_notes.review` permission not assigned.** A new gate with no role assignment makes the review queue invisible. **Mitigation:** seed in PR 2 with explicit deploy note (`php artisan db:seed --class=RolePermissionSeeder`).
7. **Family-portal still reads `progress_notes`.** Confirm before PR 6 lands. If yes, leave the old path alone; dual-write keeps it fed until Phase 2 deprecation.
8. **TimelineEvent volume.** Wiring 7+ new emitters in Phase 1 increases write load. **Mitigation:** emitter uses `updateOrCreate`; for high-frequency sources (medication administrations would be) the emitter has a `skip` short-circuit. Phase 1 sources are low-volume.
9. **Recharts bundle size.** If Recharts isn't already in the bundle, three new chart tabs add weight. **Mitigation:** confirm `package.json` first; lazy-load chart components with `React.lazy`.
10. **Audit-log growth.** `AuditableChanges` on new models grows `audit_logs` linearly. **Mitigation:** confirm retention policy; out of scope to add one in this plan but flag for Phase 2 review.
11. **Test data for Actions & Reviews aggregator.** The aggregator needs realistic seeded data to be visually convincing. **Mitigation:** extend `ClientSeeder` / `DemoSeeder` with overdue/expiring fixtures.

---

## Phase 2 and 3 (out of scope but tracked)

**Phase 2:** Personal Details tab, Goals/PATH tab + life-story surfaces, Family Tree visual, Accident Investigation workflow, Finance ledger tab + Discrepancies/Purchase Requests, Leave/Excursion request models + tab, Documents expiry observer, ProgressNote deprecation + data migration, URL routing for tabs.

**Phase 3:** Audit/History tab UI, cross-client manager review dashboard, per-client risk thresholds (fluid intake escalation), behaviour-pattern dashboard (ABC), retention policies for `audit_logs` and `timeline_events`, PATH plan templates, mobile-app-side adjustments.

---

## Implementer's quick-start

1. **Read** [docs/hero-unification-v3-handoff.md](hero-unification-v3-handoff.md) so the hero conventions (`PageHero`, outline-on-gradient class, no `category=` props) are second nature.
2. **Open** [resources/js/pages/operations/clients/show.tsx](../resources/js/pages/operations/clients/show.tsx) and locate: the hero block (lines ~880-940), the `progress_notes` tab body (lines ~4687+), the page-props extraction.
3. **Open** [resources/js/pages/sites/show.tsx](../resources/js/pages/sites/show.tsx) and [resources/js/pages/sites/contacts/_dialogs.tsx](../resources/js/pages/sites/contacts/_dialogs.tsx) — these are the visual reference for tile picker + card-grid + dialog CRUD.
4. **Start with PR 1.** Do not skip — every later PR depends on the emitter foundation. Ship and merge each PR before starting the next.
5. **Deploy workflow** (per repo convention): merge to `main` → wait ~5 min for deploy → hard-refresh browser at `https://oblivionfindings.com` to verify on the dev environment. Local Herd dev is `https://oblivionfindings.test`.
