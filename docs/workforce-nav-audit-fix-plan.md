# Workforce Nav — Connectivity Audit Fix Plan

**Date:** 2026-06-08
**Author:** Claude (audit) → for Codex (implementation) → Claude (audit afterwards)
**Status:** Ready to implement — all open decisions resolved (see P0 note and P2; access model chosen for a supported-living staff hierarchy: frontline support workers are excluded from cross-client surfaces, coordinators/`shifts.viewAny` get them).

---

## Context for Codex

This is an NZ supported-living CRM (Laravel + Inertia + React/TypeScript). Authorization
is enforced two ways and they **must agree**:

1. **Route middleware** — `->middleware('permission:foo.viewAny|bar.baz')` (pipe = OR), often
   paired with `role_scope:my-day` which *redirects* frontline staff to `/my-day` instead of 403ing.
2. **Controller guards** — `abort_unless($auth && $auth->canDo('foo.viewAny'), 403);` (belt-and-braces).
3. **Sidebar nav gate** — `resources/js/components/app-sidebar.tsx` decides whether to *show* the link,
   reading a `can` object built in `app/Http/Middleware/HandleInertiaRequests.php`.

The bug class this plan fixes: **the sidebar shows a link the backend doesn't actually gate the same way** —
either a missing gate (data exposure) or an asymmetric gate (see-link-then-403).

I audited all 8 Workforce nav items (Shifts, Job Board, Rostering, Availability, Handovers, Shift Notes,
Timesheets, Conflict Queue). **6 of 8 are correct and must not be touched.** This plan addresses the
3 issues found, in priority order.

### What is already correct — DO NOT CHANGE

| Item | Route → Controller → Page | Status |
|------|---------------------------|--------|
| Shifts | `/operations/shifts` → `ShiftController@index` → `operations/shifts/index` | ✅ gated `shifts.viewAny\|viewAssigned` + `role_scope:my-day` + controller guard |
| Rostering | `/operations/rostering` → `RosteringController@index` → `operations/rostering/index` | ✅ gated `rostering.viewAny` + `role_scope` + guard |
| Availability | `/operations/rostering?tab=availability` (same controller; `initialRosterTab()` reads `?tab=`) | ✅ |
| Timesheets | `/operations/timesheets` → `TimesheetController@index` → `operations/timesheets/index` | ✅ gated `timesheets.viewAny\|viewAssigned` + guard |
| Conflict Queue | `/operations/rostering/conflicts` → `RosteringController@conflicts` → `operations/rostering/conflicts` | ✅ inherits `rostering.viewAny` group + guard |
| Handovers (route) | `/operations/handovers` → `HandoverController@index` → `operations/handovers/Index` | ✅ route gated (nav slightly stricter — see P2, optional) |

No new permissions are introduced anywhere in this plan. Every permission key used
(`shifts.viewAny`, `job_board.claim`, etc.) already exists and is seeded. **No seeder or
permission migration is required.** (This matters because deploys skip seeders — but since
we add no new keys, the demo admin already has `shifts.viewAny` on every environment.)

---

## P0 — Shift Notes has NO authorization (data exposure) — REQUIRED

### Problem

`routes/operations.php` (~lines 726–733):

```php
// -------------------------------------------------------------------------
// Shift Notes (NEW)
// -------------------------------------------------------------------------

Route::get('/shift-notes', [ShiftNoteController::class, 'index'])->name('operations.shift_notes.index');
Route::get('/shift-notes/export', [ShiftNoteController::class, 'export'])->name('operations.shift_notes.export');
Route::patch('/shift-notes/{note}/flag', [ShiftNoteController::class, 'flag'])->name('operations.shift_notes.flag');
Route::patch('/shift-notes/{note}/review', [ShiftNoteController::class, 'markReviewed'])->name('operations.shift_notes.review');
```

**None of these routes have `permission:` middleware** — they inherit only the `auth`
middleware from the surrounding `operations` group. And every method in
`app/Http/Controllers/Operations/ShiftNoteController.php` guards with only:

```php
$auth = $request->user();
abort_unless($auth, 403);   // <-- authenticated only, NO permission check
```

Meanwhile the sidebar gates this link behind `shifts.viewAny`
(`app-sidebar.tsx`, the `Shift Notes` item: `if (can?.shifts?.viewAny)`).

**Impact:** Any authenticated user — including a frontline support worker who only has
`shifts.viewAssigned` — can navigate directly to `/operations/shift-notes` and **read or
CSV-export every client's shift notes across the whole organisation**, including notes flagged
`is_private`, for clients they are not assigned to. They can also flag/mark-reviewed any note.
Data is org-scoped (no cross-tenant leak — `flag`/`markReviewed` correctly scope the lookup by
`organization_id`), but within the org this is an over-exposure of sensitive care data. This is
the only Workforce surface with no permission enforcement.

### Fix

Gate all four routes on `shifts.viewAny` (matching the sidebar), add `role_scope:my-day` to the
read routes (mirrors the Shifts surface so frontline staff are redirected to `/my-day` rather than
403ing), and add controller guards for defense-in-depth (matching `ShiftController`/`TimesheetController`).

**1. `routes/operations.php`** — replace the four flat routes with:

```php
// -------------------------------------------------------------------------
// Shift Notes (NEW)
// -------------------------------------------------------------------------
// Manager/scheduler surface — frontline staff are redirected to /my-day by
// role_scope; everyone else needs shifts.viewAny (matches the sidebar gate).

Route::middleware(['role_scope:my-day', 'permission:shifts.viewAny'])->group(function () {
    Route::get('/shift-notes', [ShiftNoteController::class, 'index'])->name('operations.shift_notes.index');
    Route::get('/shift-notes/export', [ShiftNoteController::class, 'export'])->name('operations.shift_notes.export');
});

Route::middleware('permission:shifts.viewAny')->group(function () {
    Route::patch('/shift-notes/{note}/flag', [ShiftNoteController::class, 'flag'])->name('operations.shift_notes.flag');
    Route::patch('/shift-notes/{note}/review', [ShiftNoteController::class, 'markReviewed'])->name('operations.shift_notes.review');
});
```

> Note: `role_scope:my-day` is applied to the GET routes only (it issues a redirect, which is
> correct for navigations). The PATCH actions use the permission gate alone, matching how
> `ShiftController`'s write routes are gated (permission middleware, no `role_scope`).

**2. `app/Http/Controllers/Operations/ShiftNoteController.php`** — in **all four** methods
(`index`, `export`, `flag`, `markReviewed`), change the guard from:

```php
$auth = $request->user();
abort_unless($auth, 403);
```

to:

```php
$auth = $request->user();
abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);
```

> **Decided: all four methods use `shifts.viewAny`.** Rationale for a supported-living staff
> hierarchy: a frontline support worker (`shifts.viewAssigned`) writes notes about *their own*
> clients and does handovers via `/my-day` — they should not read, export, flag, or review the
> cross-client care notes of clients they aren't assigned to. The single `shifts.viewAny` gate
> scopes the whole surface (read + the review tools) to coordinators / team leaders / managers,
> which is the role that performs notes QA and sign-off. Do **not** split the write actions onto a
> different permission.

### Tests (P0)

Add `tests/Feature/Operations/ShiftNoteAuthorizationTest.php`. Mirror the user/role setup and
assertion style already proven in `tests/Feature/ShiftControllerTest.php` (see the test ending at
~line 997, where a `support_worker` hitting `/operations/shifts` does
`->assertRedirect(route('my-day'))`, and `$this->admin` gets a 200 with the Inertia component).

Cover:

- **Frontline staff (`support_worker`) is redirected** — `actingAs($supportWorker)->get('/operations/shift-notes')`
  → `assertRedirect(route('my-day'))`. Same for `/operations/shift-notes/export`.
- **Manager/admin with `shifts.viewAny` gets 200** — `actingAs($admin)->get('/operations/shift-notes')`
  → `assertOk()` + `assertInertia(fn ($p) => $p->component('operations/shift-notes/Index'))`.
- **PATCH flag/review are blocked for a user lacking `shifts.viewAny`** — assert `403` (or redirect
  if `role_scope` applies to the actor's role) for a non-privileged authenticated user, and succeed
  for the admin.

The existing Dusk test `tests/Browser/Operations/OperationsTest.php` ("operations shift notes page
loads") logs in as `admin@test.com` (who has `shifts.viewAny`) — it should continue to pass
unchanged.

---

## P1 — Job Board nav/route gate asymmetry (latent 403) — REQUIRED

### Problem

- **Sidebar** shows the Job Board link when `can?.job_board?.viewAny || can?.job_board?.claim`
  (`app-sidebar.tsx`, the `Job Board` item).
- **Route** (`routes/operations.php`, ~line 1021) admits
  `permission:job_board.viewAny|shifts.viewAny|shifts.viewAssigned`.

These sets are not the same. A user holding **only `job_board.claim`** (no `job_board.viewAny`,
no `shifts.view*`) would **see the link in the Workforce panel** (the panel-visibility gate
`hasWorkforce` also includes `job_board.claim`) but **get a 403** on click.

In the *seeded* roles this never happens — every role with `job_board.claim` also has
`job_board.viewAny` or `shifts.viewAssigned` (see `database/seeders/RbacSeeder.php` lines ~547,
~618, ~654-655) — so this is **latent**, not an active production bug. But a custom role with only
`job_board.claim` would trip it. Fix the asymmetry so the route admits everything the nav offers.

### Fix

Make the route and the nav use the **same** canonical access set:
`job_board.viewAny | job_board.claim | shifts.viewAny | shifts.viewAssigned`.

**1. `routes/operations.php`** (~line 1021) — add `job_board.claim` to the index route middleware:

```php
Route::get('/job-board', [JobBoardController::class, 'index'])
    ->middleware('permission:job_board.viewAny|job_board.claim|shifts.viewAny|shifts.viewAssigned')
    ->name('operations.job_board.index');
```

(Also add `job_board.claim` to the `/job-board/alerts/toggle` route middleware just below it, which
currently mirrors the index gate — keep the two consistent.)

**2. `resources/js/components/app-sidebar.tsx`** — broaden the Job Board nav gate to match the route
(so anyone the route admits also sees the link). Change:

```tsx
if (can?.job_board?.viewAny || can?.job_board?.claim)
```

to:

```tsx
if (
    can?.job_board?.viewAny ||
    can?.job_board?.claim ||
    can?.shifts?.viewAny ||
    can?.shifts?.viewAssigned
)
```

> The minimal required change is **#1** (the route), which removes the only see-link-then-403 path.
> #2 is the symmetric completion so the gates are identical in both directions. Do both.

### Tests (P1)

Extend the existing `tests/Feature/Operations/JobBoardControllerTest.php` (do not create a new file —
this one already exists and covers the controller):

- A user with **only `job_board.claim`** can now load `/operations/job-board` → `assertOk()`
  (previously 403). This is the regression guard for the latent bug.
- A user with **`shifts.viewAssigned`** can load it → `assertOk()`.
- A user with **none** of `{job_board.viewAny, job_board.claim, shifts.viewAny, shifts.viewAssigned}`
  is denied (403 or redirect, depending on `role_scope` for that route — confirm current behavior;
  the job-board route does **not** currently use `role_scope`, so expect 403).

---

## P2 — Handovers nav gate stricter than route (cosmetic) — OPTIONAL

### Problem (not a bug)

- **Sidebar** shows Handovers when `can?.handovers?.viewAny || can?.shifts?.viewAny`.
- **Route** (`routes/operations.php` ~line 739) admits
  `handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny`.

Here the **nav is stricter than the route** — the opposite direction from P1. This produces **no
broken link** (it can only *hide* the link from someone who could access the page). The only effect:
a user with `shifts.viewAssigned` (frontline) or `shifts.update`/`shifts.manageAny` but without
`handovers.viewAny`/`shifts.viewAny` can reach `/operations/handovers` directly but won't see the
nav link.

### Decision — RESOLVED: Option A (no change)

**Do frontline / shift-working staff want the Handovers link in the Workforce panel?** They do
participate in handovers — but their handover workflow already lives on `/my-day` (the attendance
handover flow). `/operations/handovers` in the Workforce panel is the coordinator's oversight view.
For a supported-living staff model the coherent choice is to leave the link scoped to
coordinators/managers and keep frontline handovers on `/my-day`.

**Decision: implement Option A (leave the Handovers nav gate as-is — no change).** This is not a
bug (the nav is stricter than the route, which can only ever *hide* a link, never 403 a visible
one). Option B below is documented only for completeness; **do not implement it.**

- **Option A (CHOSEN): leave as-is.** No change. Frontline staff use `/my-day` for handovers.
- **Option B (NOT chosen): broaden the nav gate to match the route** (so anyone the route admits sees
  the link). Change the Handovers nav gate in `app-sidebar.tsx` to:

  ```tsx
  if (
      can?.handovers?.viewAny ||
      can?.shifts?.viewAny ||
      can?.shifts?.viewAssigned ||
      can?.shifts?.update ||
      can?.shifts?.manageAny
  )
  ```

  (If Option B were chosen it would also need `shifts.viewAssigned` added to the `hasWorkforce`
  panel-visibility gate at `app-sidebar.tsx` ~lines 453-460 — but it is **not** chosen, so leave
  both untouched.)

---

## Non-goals / out of scope

- Do **not** change the 6 working items (Shifts, Rostering, Availability, Timesheets, Conflict Queue,
  Handovers route). They are correct.
- Do **not** introduce any new permission keys, seeders, or migrations.
- Do **not** add private-note (`is_private`) visibility filtering. Observation: `is_private` is
  currently *never* used for access control anywhere in the app (only as a display badge / filter,
  e.g. in `ClientDailyNoteResource` and the Shift Notes filter). Changing that is a separate product
  decision and is out of scope here — the P0 permission gate is the fix.
- Do **not** restructure the Workforce nav or its sub-panel.

## Implementation order

1. P0 (Shift Notes) — route group + 4 controller guards + feature test.
2. P1 (Job Board) — route middleware (index + alerts toggle) + nav gate + feature test.
3. P2 — **not implemented** (Option A / no change, by decision above).

## Verification (run after implementing)

```bash
# Non-parallel, change-scoped (do NOT use --parallel in this repo).
php artisan test tests/Feature/Operations/ShiftNoteAuthorizationTest.php   # new
php artisan test tests/Feature/Operations/JobBoardControllerTest.php       # extended
php artisan test tests/Feature/ShiftControllerTest.php                     # regression — Shifts gate unchanged

# Confirm routes resolve and carry the new middleware:
php artisan route:list --path=operations/shift-notes
php artisan route:list --path=operations/job-board
```

Then `npm run build` (or typecheck) to confirm the `app-sidebar.tsx` edits compile.

## Acceptance criteria

- [ ] `/operations/shift-notes` (index + export) requires `shifts.viewAny`; frontline staff are
      redirected to `/my-day`; a non-privileged non-frontline user gets 403.
- [ ] `/operations/shift-notes/{note}/flag` and `/review` require `shifts.viewAny` (route **and**
      controller guard).
- [ ] All four `ShiftNoteController` methods guard with `$auth && $auth->canDo('shifts.viewAny')`.
- [ ] A user with only `job_board.claim` can load `/operations/job-board` (no 403).
- [ ] Job Board route middleware and sidebar nav gate admit the same set
      (`job_board.viewAny | job_board.claim | shifts.viewAny | shifts.viewAssigned`).
- [ ] New feature tests pass; existing `ShiftControllerTest` + Dusk shift-notes test still pass.
- [ ] No new permissions/seeders/migrations introduced.
- [ ] The 6 working nav items are untouched.

---

## Codex implementation close-out — 2026-06-08

**Status:** Implemented locally on branch `codex/workforce-nav-audit-fix`.

### Implemented

- P0 Shift Notes authorization:
  - Added `role_scope:my-day` + `permission:shifts.viewAny` to Shift Notes index/export routes.
  - Added `permission:shifts.viewAny` to Shift Notes flag/review routes.
  - Added `$auth && $auth->canDo('shifts.viewAny')` guards to all four `ShiftNoteController` actions.
  - Added `tests/Feature/Operations/ShiftNoteAuthorizationTest.php` covering frontend redirect, manager access, non-frontline 403, write-route blocking, admin write success, and controller defense-in-depth with route permission middleware bypassed.
- P1 Job Board gate symmetry:
  - Added `job_board.claim` to the Job Board index and alerts-toggle route middleware.
  - Added `job_board.claim` to `JobBoardController::canViewJobBoard()`.
  - Broadened the Workforce Job Board sidebar gate to `job_board.viewAny | job_board.claim | shifts.viewAny | shifts.viewAssigned`.
  - Added `shifts.viewAssigned` to the Workforce parent-panel gate so a custom `shifts.viewAssigned`-only role can actually see the admitted Job Board item.
  - Extended `tests/Feature/Operations/JobBoardControllerTest.php` with a regression test for `job_board.claim`, `shifts.viewAssigned`, and no-access users.
- P2 Handovers:
  - Left unchanged per Option A.

### Verification run

```bash
php artisan test tests/Feature/Operations/ShiftNoteAuthorizationTest.php
php artisan test tests/Feature/Operations/JobBoardControllerTest.php
php artisan test tests/Feature/ShiftControllerTest.php
php artisan route:list --path=operations/shift-notes -v
php artisan route:list --path=operations/job-board -v
npm run build
```

All commands above completed successfully. Route-list verification showed Shift Notes GET routes now carry `role_scope:my-day` and `permission:shifts.viewAny`, Shift Notes PATCH routes carry `permission:shifts.viewAny`, and Job Board index/alerts-toggle now carry `permission:job_board.viewAny|job_board.claim|shifts.viewAny|shifts.viewAssigned`.

### Dusk note

The existing Dusk test `tests/Browser/Operations/OperationsTest.php --filter="operations shift notes page loads"` was attempted but did not reach the browser/app. Local Dusk setup is missing `vendor/laravel/dusk/bin/chromedriver-win.exe`, and `php artisan dusk:chrome-driver --detect` could not download it because PHP cURL failed TLS verification with `SSL certificate problem: self-signed certificate in certificate chain` against the Chrome for Testing metadata URL. This is an environment/tooling blocker, not an application assertion failure.
