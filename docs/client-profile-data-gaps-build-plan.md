# Client Profile — Data-Gap Build Plan

The client-profile redesign (`28a6229d` → `169625d0`) is design-faithful everywhere the
data exists. This plan covers the places the design prototype shows **data the system
doesn't capture yet** — so each one gets built properly in its owning module instead of
being faked on the profile. Once a module ships its part, the profile surface binds to it
(the design components are already in place and waiting).

> **Implementing agent — read this first.** This file is the complete spec; you don't need
> the conversation that produced it. The redesign architecture and the workflow→endpoint
> map are in `docs/client-profile-redesign-plan.md`. Repo gotchas you'll hit are in
> **§0 below — read it before writing code or running tests.** This is a NZ supported-living
> CRM: use NZ terminology/currency. Keep the existing real data + components; do not
> replace working features to chase the mock (the prototype is hardcoded sample data).

Conventions for every item below:

- **Timezone:** datetime inputs are worker wall-clock. Parse in `app.worker_timezone`
  (Pacific/Auckland) → store UTC → convert back at display. (Pattern already used by
  `ClientTransportBookingController`; see Cross-cutting §8 for the backfill of older
  endpoints.)
- **Permissions:** reuse existing module permissions (`clients.update`,
  `medications.administer.record`, `respite.*`, `care_plans.*`). Deploys do **not** run
  seeders — any item that truly needs a new permission must say so loudly and ship a
  `*PermissionsSeeder` note for the server runbook.
- **Profile wiring:** every item ends with the exact profile surface it unlocks
  (hero vital / tab card), so "done" is visible on `/operations/clients/{id}`.
- **Tests:** each item gets a feature test in the pattern of
  `tests/Feature/ClientProfileRedesignTest.php` (RbacSeeder + admin + Client factory).

---

## 0 · Repo gotchas (read before coding)

These are project-specific landmines an implementing agent will not infer from the code.

**PHP / artisan / tests**
- Run PHP via the Herd binary directly: `C:\Users\steph\.config\herd\bin\php84\php.exe`
  (`php84\php.exe artisan ...`). **Do not** use `php.bat` — Node-spawned it mangles args.
  artisan errors print to **stdout**, not stderr.
- **Never** run `php artisan test --parallel` here — per-worker test DBs aren't migrated,
  so you get 3000+ false "table doesn't exist" failures. Run non-parallel and
  change-scoped (`php84\php.exe artisan test tests/Feature/XxxTest.php`).
- Run migrations after adding them: `php84\php.exe artisan migrate --force`.
- Feature-test setup pattern (copy from `ClientProfileRedesignTest`): `RefreshDatabase`,
  `$this->seed(RbacSeeder::class)`, admin = `User::factory()->create(['role'=>'admin',
  'approved_at'=>now()])` then attach the `admin` Role, `Client::factory()`.

**Frontend**
- Verify every change: `npx tsc --noEmit`, `npx eslint <changed files>`, `npm run build`,
  and `npx vitest run <relevant test>` (not the whole suite unless needed).
- `resources/js/pages/operations/clients/show.tsx` must stay **< 500 KB** — there's a
  vitest guard (`client-profile-source-size.test.ts`). It's near the cap; **extract new
  tab bodies to `resources/js/pages/operations/clients/tabs/*.tsx`** rather than inlining
  (see `tabs/mar.tsx`, `tabs/incidents-tab.tsx` for the pattern).
- ESLint enforces design tokens + Card/Button usage. Bespoke styled-native surfaces
  (filter chips, stat tiles) need an inline `// eslint-disable-next-line no-restricted-syntax`
  with a reason — see existing tabs.

**Timezone (this is §8's whole job — get the pattern right)**
- `config('app.timezone') === 'UTC'`, `config('app.worker_timezone') === 'Pacific/Auckland'`.
- A datetime-local input is worker wall-clock. **Store:**
  `CarbonImmutable::parse($value, config('app.worker_timezone','Pacific/Auckland'))->utc()`.
  Eloquent `'datetime'` cast + the frontend formatting in NZ then round-trips it.
  Reference impl + test: `ClientTransportBookingController` and
  `test_transport_booking_stores_scheduled_at_as_worker_timezone_utc`.

**Inertia**
- `back()->with('error', …)` fires Inertia's `onSuccess` with `flash.error` (NOT
  `props.errors`) — gate success UI on `!flash.error`.
- An endpoint hit by both Inertia and plain axios must content-negotiate
  (`$request->header('X-Inertia') ? back() : response()->json(...)`) or axios
  PUT/DELETE follows the 302 to a GET-only route → 405. See `ClientFamilyChatController`.

**Deploy / permissions**
- Deploys **skip seeders**, and permissions are seeded (not migrated). So a new
  permission-gated feature 403s on the server until its `*PermissionsSeeder --force` is
  run. **Reuse existing permissions** for everything in this plan; if any item genuinely
  needs a new one, ship the seeder and add a one-line server-runbook note.

**Profile binding**
- The design surfaces already exist (`resources/js/components/clients/profile/` +
  inline sections in `show.tsx`). When a module ships its data, bind the prop and delete
  the fallback empty-state copy (e.g. "No target set", "Nothing rostered").

**After implementation:** hand back for a design-fidelity + correctness audit (the
established Claude-designs → Codex-implements → audit loop) — keep the real data/components,
audit chrome vs. the design prototype in `.design-drops/client-profile-redesign/`.

---

## 1 · Sites & Locations — client room assignment

**Design shows:** hero chip `Tūī House · Room 3`, Personal Details → Service → Room,
design data `room: 'Room 3 · West Wing'`.

**Current state:** `site_house_rooms` already exists (personal assets validate
`room_id => exists:site_house_rooms`). Clients have `site_id` but **no room link**.

**Build:**
- Migration: `clients.room_id` → FK `site_house_rooms` nullable, `nullOnDelete`.
- `Client::room()` BelongsTo; include `room` (`id`, `name`, `wing/area` if present) in
  `ClientController@show` client payload and in `UpdateClientRequest`
  (`room_id => nullable|integer|exists:site_house_rooms,id` — validate the room belongs
  to the chosen site).
- Add-client wizard + Complete-profile wizard (`client-edit-dialog`): room select under
  Site (options filtered by site; reuse the rooms list the personal-assets tab already
  receives via `asset_locations`).
- Site profile (Sites module): show room occupancy (which client occupies which room) —
  additive card on the site page.

**Profile unlock:** hero chip becomes `{site} · {room}`; Personal Details Service card
gains a real ROOM row.

**Size:** S (1 migration, 1 request rule, 2 wizard selects, payloads).

---

## 2 · Catering / Meal Planner — per-client meal intake log

**Design shows:** hero vital `Meals 3/3 · On track today`; Food & Meal tab mealtime
support; design data `meals: '3/3'`.

**Current state:** meal-planner module covers menus/products/dietary tags + per-client
tags & dislikes. **No record of what a client actually ate.**

**Build:**
- Migration `client_meal_logs`: `client_id` FK, `meal_type`
  (`breakfast|lunch|dinner|snack`), `status` (`eaten|partial|refused|declined`),
  `occurred_at` (UTC, worker-tz parsed), `portion_note` nullable, `notes` nullable,
  `recorded_by` FK users, `organization_id`, timestamps. Index `(client_id, occurred_at)`.
- `ClientMealLogController` (Operations): `store` / `update` / `destroy`, permission
  `clients.update|medications.administer.record` (frontline parity with health charts).
  Routes under `/operations/clients/{client}/meal-logs`.
- Frontline capture: add a "Log meal" quick action to the Food & Meal tab and to
  `/my-day` shift view (same pattern as health charts).
- `ClientController@show`: today's meal logs join the `health_monitoring`-style payload
  (`meal_logs: today + last 7 days`).

**Profile unlock:** hero vital `Meals {eaten}/{expected today}` (expected = 3 mains by
default, configurable later); Food & Meal tab gains a "Today" strip + 7-day history.

**Size:** M (table + CRUD + 2 capture surfaces + vital).

---

## 3 · Health & Clinical — sleep chart (hours series)

**Design shows:** hero vital `Sleep 6.4h · Below 7h target`; Health Monitoring sleep
sparkline over 7 nights.

**Current state:** `ObservationType::Sleep` exists but its `data` is `{quality,
interruptions}` — **no hours**, no dedicated chart entries like bowel/fluid/seizure.

**Build (follow the existing chart pattern exactly):**
- Migration `client_sleep_entries`: `client_id`, `slept_at` (date — the night),
  `hours_slept` decimal(4,1), `quality` (`good|fair|poor`) nullable, `interruptions`
  smallint nullable, `settled_by`/`woke_at` time strings nullable, `notes`,
  `recorded_by`, `organization_id`.
- `Clinical\ClientSleepChartController` mirroring `ClientBowelChartController`
  (store/update/destroy, permission `medications.administer.record|clients.update`),
  routes `/operations/clients/{client}/health/sleep`.
- `health_monitoring.sleep` joins the show payload (limit 60).
- HealthMonitoringTab: add the Sleep pane next to bowel/fluid/seizure (sparkline of
  `hours_slept`, 7-night average, target line from a new optional
  `clients.sleep_target_hours` column — or hardcode 7h initially).
- `record_obs` wizard: the existing Sleep picker option routes to the new endpoint
  (one `when:` field group in `flows.tsx`).

**Profile unlock:** hero vital `Sleep {avg}h` with below-target trend; Health Monitoring
sleep chart per the design.

**Size:** M (clone of an existing pattern).

---

## 4 · Respite — nights allocation

**Design shows:** Respite tab stat strip `Allocation 28 nights/year · Used 9 · Booked 2 ·
Remaining 17` + the allocation progress bar.

**Current state:** bookings/requests exist; **no allocation model**, so used/remaining
can't be computed against anything.

**Build:**
- Migration `client_respite_allocations`: `client_id`, `period_start` date,
  `period_end` date, `nights_allocated` smallint, `funding_source` nullable,
  `notes`, `organization_id`. One active row per client per period.
- CRUD on the Respite workspace (coordinator-facing; permission `respite.manage` —
  exists). Client profile only **reads**.
- Computation service: `used = sum(nights of completed/approved bookings in period)`,
  `booked = nights of future approved`, `remaining = allocated − used − booked`.
- `ClientController@show` respite payload gains `allocation {allocated, used, booked,
  remaining, period_label}`.

**Profile unlock:** the design's MiniStat strip + progress bar on the Respite tab
(component grammar already in place from the redesign).

**Size:** M.

---

## 5 · eMAR — medication stock on the profile MAR tab

**Design shows:** Medical → Stock section (`Quetiapine 24 doses · low → Reorder`) and
controlled-drug balance.

**Current state:** **mostly exists** — `RecordAdministrationDialog` already types
`stock { on_hand, unit }` and recordDose accepts `cd_balance`. Verify the source model
(likely a medication stock/balance table in the eMAR domain) before building anything.

**Build:**
- (Verify first) If stock lives in eMAR: expose `stock_on_hand` + `reorder_threshold`
  per medication in the `medical.medications` payload of `ClientController@show`.
- If absent: `client_medication_stock` (medication FK, on_hand, unit, reorder_threshold,
  last_received_at) + a "Receive stock" action on the MAR page (permission
  `medications.orders.manage`).
- MAR tab (`tabs/mar.tsx`): add the design's Stock list rows + low-stock `Reorder`
  badge once the field arrives in props.

**Size:** S–M depending on what verification finds.

---

## 6 · Care Plans — structured domains & strategies

**Design shows:** Care & Support Plan as **domain cards** (Health & wellbeing, Daily
living, Community participation, Communication & choice) each holding strategies with
an owner (`'Meds prompted…', 'Key worker'`) and a status chip.

**Current state:** plans hold `content` JSON (about_me etc.) + **goals**; no
domain/strategy structure.

**Build (JSON-schema approach — no new tables, versioning stays intact):**
- Standardise `care_plans.content.domains` schema:
  `[{ key, label, status: on_track|active|review, strategies: [{ text, owner }] }]`.
- Care plan Create/Edit (`operations/care-plans`): a Domains builder section
  (add domain → add strategies w/ owner select). Validation in `CarePlanController`
  (`content.domains.*.label required`, strategies max lengths).
- Review flow copies domains with the version (already copies `content`).
- Profile Care & Support Plan tab: render the design's 2-col domain cards from
  `content.domains` when present (fall back to today's goals view when absent — both
  shown, nothing lost).
- Portal: family dashboard may surface domain summaries later (out of scope here).

**Size:** M–L (builder UI is the bulk).

---

## 7 · Workforce — available-workers list for the Workers tab editor

**Design shows:** two-column Assigned ⇄ Available editor with search, directly on the
Workers tab.

**Current state:** the profile payload has only assigned `support_workers`; the full
staff list lives on the assignments edit page.

**Build:**
- `ClientController@show`: add `assignable_workers` (id, name, email) **lazily** via
  `Inertia::optional`, loaded when the Workers tab opens (same pattern as `transport`).
  Gate on `clients.assignments.update`.
- Workers tab: upgrade the current read-only design surface to the full two-column
  editor (`tab-workers.jsx` contract — AssignedRow w/ remove, AvailableRow w/ add,
  search) submitting to the existing `PUT /operations/clients/{client}/assignments`
  (`user_ids[]`). Key-worker select submits via `quick-update` (`key_worker_id`).

**Profile unlock:** in-tab assign/remove without leaving the profile; the
`assign_workers` design flow becomes fully native.

**Size:** S–M (no new tables; one lazy prop + one tab body).

---

## 8 · Cross-cutting — datetime-local +12h audit (pre-existing endpoints)

The live-dev audit proved manual datetime inputs shift +12h anywhere a controller
stores the naive string (app tz UTC). Transport bookings are fixed (`30507d9f`);
the same fix (parse in `app.worker_timezone` → `->utc()`) is needed in:

- `ClientIncidentController@store` — `occurred_at`
- `Clinical\ClientClinicalController@store/@storeEvent` (+ global
  `HealthClinicalController`) — `recorded_at` / `occurred_at`
- `ClientCalendarController@storeAppointment/@updateAppointment` — `starts_at`/`ends_at`
- Health charts (`bowel/fluid/seizure` + new `sleep`, `meal_logs`) — `occurred_at`
- `ClientDailyNoteController@store` — `occurred_at`, `follow_up_due_at`

Do this as one sweep with a shared helper (e.g. `App\Support\WorkerClock::toUtc()`)
plus regression tests per endpoint asserting the 10:30→10:30 round-trip
(copy `test_transport_booking_stores_scheduled_at_as_worker_timezone_utc`).

**Size:** M (mechanical but must be tested endpoint-by-endpoint).

---

## 9 · Remaining cosmetic-only fidelity items (no data needed)

Safe to do any time; the pattern components exist:

| Tab | Design delta |
|---|---|
| Rhythms & Routines | two-column layout (daily rhythm timeline left, support blocks right, hover-edit) |
| Medical | section chips (Overview / Conditions / Medications / Administrations / Stock / Contacts) |
| Documents | MiniStat strip + category chips above the existing manager |
| Photos | album-style cards + consent footnote |
| Personal Inventory | MiniStat strip + category chips above the asset grid |
| Respite | MiniStat strip now; allocation bar arrives with §4 |
| Portal / Communication / Family Notes | design stat cards above existing lists |
| Assessments | suppress the legacy in-tab "New Assessment" button (wizard is primary) |

---

## Suggested build order

1. **§8 timezone sweep** — correctness, touches everything else's inputs. ✅ do first
2. **§1 room** + **§7 available workers** — small, instantly visible in hero/tabs
3. **§3 sleep chart** + **§2 meal log** — completes the hero vitals strip as designed
4. **§4 respite allocation** — completes the Respite tab
5. **§5 eMAR stock** (verify-first) — completes MAR/Medical
6. **§6 care-plan domains** — biggest UI lift, do last
7. **§9 cosmetic sweep** — fold into whichever PR touches each tab

Each item is independently shippable. After each ships, bind the waiting profile
surface and delete the corresponding fallback copy ("No target set", "Nothing
rostered"-style empties). Permission seeding details for the implemented care-plan
route gates are captured in the closeout below.

---

## Implementation closeout — Codex 2026-06-11

**Status:** implemented end to end on branch `codex/client-profile-data-gaps`.

**Implemented scope**
- §1 Room and site placement: `clients.room_id`, room/site validation, room occupancy
  sync, add/edit client room selection, profile hero/details room display.
- §2 Food & Meal: meal-log table/model/controller, profile Food & Meal tab log form,
  today strip, 7-day history/summary, and My Day meal logging.
- §3 Sleep: sleep-entry table/model/controller, sleep target field, profile health
  sleep chart/metrics/history, and My Day sleep observation routing.
- §4 Respite allocation: allocation table/model/controller/service, respite workspace
  CRUD, and client profile allocation stats/progress.
- §5 eMAR stock: existing medication stock is exposed in the profile medication payload
  and rendered on the MAR tab with low-stock/reorder context.
- §6 Care plan domains: validated `content.domains` schema, create/edit builder,
  version/review copy through existing content flow, and profile/show domain cards.
- §7 Workforce editor: assignable worker payload, in-tab assign/remove editor, and
  key-worker quick update.
- §8 Worker-time parsing: shared `App\Support\WorkerClock` helper and sweep over
  incidents, calendar appointments, daily notes, legacy/current clinical events and
  observations, bowel/fluid/seizure charts, meal logs, and sleep entries.
- §9 Cosmetic/data fidelity: legacy assessment create buttons suppressed, profile show
  split into smaller tab modules, food/respite/workers/care-plan surfaces bound to real
  data, and MAR/personal-details polish completed for the specified gaps.

**Verification run**
- `php artisan migrate --force` — passed, no pending migrations.
- `php artisan test tests/Feature/ClientProfileDataGapsBuildTest.php` — passed
  (`8 passed`, `130 assertions`).
- `php -l` over all modified and new PHP files — passed.
- `npm run types` — passed.
- `npx eslint` over touched profile/My Day/care-plan UI files — passed.
- `npx vitest run resources/js/test/client-profile-source-size.test.ts resources/js/test/client-profile-phase-one-ui.test.tsx`
  — passed (`2` files, `3` tests).
- `npm run build` — passed.

**Deploy/runbook note**
- `php artisan migrate --force` is required for the new client room, meal log, sleep,
  and respite allocation schema.
- Deploys skip seeders in this project. This build adds the missing seeded
  `care_plans.viewAny`, `care_plans.create`, `care_plans.update`, and
  `care_plans.delete` permissions to `RbacSeeder` because the existing care-plan
  routes already gate on `care_plans.*`. Production needs the RBAC seeder run, or
  equivalent permission rows inserted, before auditing care-plan create/edit routes.

**Claude audit boundaries**
- Audit the real profile, My Day, care-plan, respite, and MAR surfaces rather than the
  older static design snippets.
- Treat `.design-drops/` as pre-existing untracked workspace material; it was not part
  of this implementation.
- Family portal domain summaries remain intentionally out of scope, as stated in §6.
