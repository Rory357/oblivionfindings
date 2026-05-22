# Client Profile — Phase 2 + Phase 3 Handoff

**Status:** Shipped to `main` (commits `f9aae5d9`, `777cc45f`, `ffeb607c`).
**Plan reference:** [`docs/client-profile-rebuild-phase-1-plan.md`](client-profile-rebuild-phase-1-plan.md) — the original 17-item Phase 2 + Phase 3 list at the bottom of that file is now complete.

This doc is the "what shipped, where to find it, how to verify" handoff so a fresh session does not need to re-discover the work.

---

## Commits in this wave

| Commit | What | Notes |
|---|---|---|
| `f9aae5d9` | chore(clients): post-codex audit cleanup | Deleted orphaned `progress-note-entry.tsx`; fixed pre-existing React Compiler lint error in `sites/plan/_inspector.tsx:1212`. |
| `777cc45f` | feat(clients): complete Phase 2 + Phase 3 | All 17 items below + the 3 known minor gaps. 48 files changed, +4,843 / -416. |
| `ffeb607c` | test+chore(clients): Phase 2/3 tests + schema dump + lint quiet | 8 new feature tests, schema dump refresh, 8 targeted lint-disable comments on intentional custom panels. |

---

## Known minor gaps fixed (Wave 1)

| Gap | Where it landed |
|---|---|
| **A1** — Daily Notes filter chips (date range, category, Mine, Family-visible) | [tabs/daily-notes.tsx](../resources/js/pages/operations/clients/tabs/daily-notes.tsx) — added Category Select, Mine toggle, Family-visible toggle, From/To date pickers, active-filter counter, Clear filters button. |
| **A2** — 19 controllers migrated from inline `TimelineEvent::create()` to emitter | New [`TimelineEmitter::record()`](../app/Services/Timeline/TimelineEmitter.php) passthrough. All 19 call sites converted. Only emitter itself retains the raw call. **Bonus:** discovered + fixed the collision bug (project() was nuking controller-written events sharing source_type/source_id; now marks rows with `meta._projected = true` and only deletes its own). |
| **A3** — Shared `DailyNoteEntry` component extracted | [`resources/js/components/daily-note-entry.tsx`](../resources/js/components/daily-note-entry.tsx). Used by Daily Notes tab AND Communication Notes tab (with `showCommunicationContext` prop). The legacy `progress-note-entry.tsx` was already deleted in `f9aae5d9`. |

---

## Phase 2 (B1 – B9)

| # | Item | Files |
|---|---|---|
| B1 | Personal Details tab | [tabs/personal-details.tsx](../resources/js/pages/operations/clients/tabs/personal-details.tsx) — identity, contact, cultural, service context, story/strengths, support team, emergency contacts, next of kin |
| B2 | Family Tree / Important People tab | [tabs/family-tree.tsx](../resources/js/pages/operations/clients/tabs/family-tree.tsx) — primary / emergency / family / guardians / friends / portal-access sections, permission badges (Medical/Medications/Incidents/Updates) |
| B3 | Goals / PATH / Whole of Life tab | [tabs/goals-path.tsx](../resources/js/pages/operations/clients/tabs/goals-path.tsx) — active goals + story/strengths/interests + 6-pillar PATH framework grid |
| B4 | Documents expiry observer | Already in place from codex (`ClientDocument::toTimelineEvent` returns `document_expiring` event). Confirmed wired in `AppServiceProvider`. |
| B5 | Finance tab | [tabs/finance.tsx](../resources/js/pages/operations/clients/tabs/finance.tsx) — fund balances, recent transactions, ledger entries; purchase requests + discrepancies sections with "ships with dedicated Finance module" empty state. |
| B6 | Leave / Excursions tab + models | **Backend:** [`ClientLeaveRequest`](../app/Models/ClientLeaveRequest.php), [`ClientExcursionRequest`](../app/Models/ClientExcursionRequest.php), migration `2026_05_22_000003`, [`ClientLeaveExcursionController`](../app/Http/Controllers/Operations/ClientLeaveExcursionController.php), routes in `operations.php`. **Frontend:** [tabs/leave-excursions.tsx](../resources/js/pages/operations/clients/tabs/leave-excursions.tsx) with inline create dialogs. Both models implement `EmitsToTimeline`. |
| B7 | Accident Investigation workflow | Already on the existing `ClientIncident` schema (`investigation_status`, `investigation_assigned_to`, `investigation_started_at`, `investigation_completed_at`, `root_cause_category`, `root_cause_description`, `contributing_factors`, `corrective_actions`, `lessons_learned`). Surfaced via existing incidents UI. |
| B8 | ProgressNote deprecation + data migration | [`oblivion:migrate-progress-notes-to-client-notes`](../app/Console/Commands/MigrateProgressNotesToClientNotes.php) — idempotent (keyed on `attachments.legacy_progress_note_id`). Maps `note_type` to canonical `category`. Run with `--dry-run` first. |
| B9 | URL routing for tabs | [show.tsx](../resources/js/pages/operations/clients/show.tsx) — `handleTabChange` now pushes `?tab=…` to history; `popstate` listener keeps state synced with browser back/forward. |

---

## Phase 3 (C1 – C5)

| # | Item | Files |
|---|---|---|
| C1 | Audit / History tab | [tabs/audit-history.tsx](../resources/js/pages/operations/clients/tabs/audit-history.tsx) — last 200 entries, day-grouped, action + model filters. Backend serves `audit_history` page prop gated on `audit.viewClient` OR `clients.update`. |
| C2 | Cross-client manager review dashboard | **Backend:** [`ReviewQueueController`](../app/Http/Controllers/Operations/ReviewQueueController.php) at `/operations/review-queue`, gated to `progress_notes.review`. Permission-scoped to clients the user can access. **Frontend:** [`pages/operations/review-queue/index.tsx`](../resources/js/pages/operations/review-queue/index.tsx). **Sidebar:** entry added in `app-sidebar.tsx` (also gated to `progress_notes.review`). |
| C3 | Per-client risk thresholds (fluid intake + seizure escalation) | Migration `2026_05_22_000004` adds `fluid_intake_min_ml`, `fluid_intake_max_ml`, `seizure_duration_escalation_seconds` to `clients`. `ClientSeizureEntry::toTimelineEvent` now consults the per-client override before falling back to the 300s default. |
| C4 | Behaviour pattern dashboard (ABC) | [`BehaviourPatternsService`](../app/Services/Client/BehaviourPatternsService.php) aggregates clinical_observations + flagged ClientNotes (30-day window): top triggers, antecedents, responses, behaviour tags, daily activity series. [`BehaviourInsightsCard`](../resources/js/components/behaviour-insights-card.tsx) renders the data with a simple sparkline. Mounted at the top of the Observations tab. |
| C5 | Retention policy | [`oblivion:prune-retention`](../app/Console/Commands/PruneTimelineAndAuditLogs.php) — defaults: 2 yrs audit, 5 yrs timeline (configurable per env or via `--audit-years` / `--timeline-years` flags). Pinned timeline events are preserved. Scheduled weekly Sunday 03:30 NZ via `routes/console.php`. |

---

## Backend infrastructure changes

### Migrations
- `2026_05_22_000003_create_client_leave_and_excursion_tables.php` — `client_leave_requests`, `client_excursion_requests`
- `2026_05_22_000004_add_health_thresholds_to_clients.php` — three nullable threshold columns on `clients`

The schema dump (`database/schema/mysql-schema.sql`) has been refreshed so test DBs created from the dump get the new tables/columns. **The migration files remain on disk** — restored after an accidental `schema:dump --prune` to avoid disrupting environments that already ran them.

### Permissions (no new gates)
Everything reuses existing permissions:
- `clients.viewAny` / `clients.viewAssigned` / `clients.update` for the new tabs
- `progress_notes.review` for the review queue + sidebar entry + hero badge
- `observations.viewAny` for the behaviour patterns service
- `medications.view` / `medications.administer.record` for the health charts (was Phase 1)
- `audit.viewClient` for the audit history tab (with `clients.update` fallback)

### Observers
[`AppServiceProvider`](../app/Providers/AppServiceProvider.php) now observes `ClientLeaveRequest` and `ClientExcursionRequest` with the generic `ProjectsToTimelineObserver`. Phase 1 observers untouched.

### Routes
Added in [`routes/operations.php`](../routes/operations.php):
- `POST /clients/{client}/leave` + `PUT /clients/{client}/leave/{leave}` + `DELETE /clients/{client}/leave/{leave}`
- `POST /clients/{client}/excursions` + `PUT /clients/{client}/excursions/{excursion}` + `DELETE /clients/{client}/excursions/{excursion}`
- `GET /operations/review-queue`

### Console schedule
Added in [`routes/console.php`](../routes/console.php):
- `oblivion:prune-retention` weekly Sunday 03:30 NZ.

---

## TimelineEmitter behaviour change (read this if you touch the emitter)

`TimelineEmitter::project()` now writes `meta._projected = true` on every event it creates. The type-switch delete is **scoped to events with that marker**, so controller-written events that happen to share a `source_type` + `source_id` with an `EmitsToTimeline` model are no longer silently deleted when the model fires again.

If you write timeline events via `TimelineEmitter::record(array $payload)`, your events do NOT get the marker — they survive observer fires for the same source.

Existing Phase 1 test `it projects client notes through the canonical timeline emitter and retracts deleted notes` continues to pass — the marker change does not affect the canonical project/retract lifecycle.

---

## Test coverage

**Phase 1:** [`tests/Feature/Operations/ClientProfilePhaseOneTest.php`](../tests/Feature/Operations/ClientProfilePhaseOneTest.php) — 7 tests, 53 assertions. **All pass.**

**Phase 2 + 3:** [`tests/Feature/Operations/ClientProfilePhaseTwoThreeTest.php`](../tests/Feature/Operations/ClientProfilePhaseTwoThreeTest.php) — 8 tests, 73 assertions covering:
- Leave request store → timeline projection
- Leave status update with approver stamp
- Excursion creation → non-critical timeline event
- Per-client seizure threshold override (180s > per-client 120 yet < default 300 — verifies override is consulted)
- Manual-event preservation (verifies the `meta._projected` guard)
- Behaviour patterns aggregator
- Cross-client review queue + site filter
- ProgressNote → ClientNote migration idempotency

**Full Feature suite:** 132/132 pass after schema dump refresh.

---

## Verification

After cloning or pulling, on a fresh local checkout:

```bash
php artisan migrate                                  # new migrations are idempotent
php artisan test --filter=ClientProfilePhaseTwoThreeTest   # 8 pass
php artisan test --filter=ClientProfilePhaseOneTest       # 7 pass
npm run types                                        # clean
npm run lint                                         # 0 errors
npm run build                                        # clean
```

On the dev environment (`https://oblivionfindings.com`) after deploy:
1. Open `/operations/clients/{id}` for an active client.
2. Confirm the 20-tab structure is intact and the URL updates as you click tabs.
3. Click "Daily Note" in the hero → wizard opens. Add a flagged concern note. Verify it appears in the daily-notes tab AND in the timeline.
4. Click Personal Details / Family Tree / Goals & PATH / Finance / Leave & Excursions / Audit History / Behaviour Patterns sections — each should render real data (or a friendly empty state).
5. As a Manager with `progress_notes.review`, the sidebar shows "Review Queue" under Client Management. Open it; filter by site / age; click "Mark reviewed" inline.
6. Run `php artisan oblivion:prune-retention --dry-run` to confirm retention policy reports counts without deleting.
7. Run `php artisan oblivion:migrate-progress-notes-to-client-notes --dry-run` to confirm ProgressNote deprecation can run safely.

---

## Risks and follow-ups (out of scope for this wave)

1. **Purchase Requests + Financial Discrepancies models** — Finance tab surfaces empty arrays today with a "ships with dedicated Finance module" hint. When those models land, the controller's `client_finance.purchase_requests` and `discrepancies` arrays just need to be filled.
2. **PATH plan dedicated model** — the Goals/PATH tab consumes a generic `path_plan` page prop. There is no `PathPlan` model yet; the tab renders all-empty pillars until something supplies the data. When a model is added, plumb it into `ClientController::show` as `path_plan`.
3. **NextOfKin relationship taxonomy** — Family Tree categorises members by string-matching the `relationship` field. A formal taxonomy enum would tighten this.
4. **Audit retention granularity** — `oblivion:prune-retention` uses global defaults. Per-organisation retention is supported via `config/retention.php` but no UI exposes it today.
5. **Cross-client review queue scaling** — current limit is 200 most-recent flagged items. If volume grows, paginate.
6. **Documents tab and Risk Management tab** — still use the existing standalone pages from Phase 1. They are mounted in the 20-tab rail but the *visual presentation* is the older table. A future polish wave could harmonise them with the new tab patterns from Phase 2.

---

## Suggested next-session prompt

```
The 20-tab Client Profile is shipped (Phase 1 + 2 + 3). Read
docs/client-profile-phase-2-3-handoff.md.

Pick one of the follow-ups under "Risks and follow-ups" and ship it.
The shortest wins right now are:
1. Wire a real PathPlan model so the Goals & PATH tab renders the
   actual person-centred planning data instead of placeholder pillars.
2. Refresh the existing Documents tab to match the new Phase 2 tab
   patterns (Card-based, with empty-state + filters).
3. Add per-organisation retention overrides via a small settings UI.

Stop after one. Run npm run types + ClientProfilePhaseTwoThreeTest
before opening a PR.
```
