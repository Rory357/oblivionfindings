# Plan — Consolidate shift editing on the CreateShiftDialog popup; retire the standalone `/edit` page

**Status:** Ready to implement · **Base:** `main` @ `751a1028` · **Intended owner:** Codex

> Hand-off note: this plan was written by the previous (Claude) session. All of that
> session's rostering work (Site/Staff grid toggle, eligibility-aware reassign popup,
> inline resolve-overlap + request-replacement popups) is already merged to `main`
> (`751a1028`) and deployed/verified on https://oblivionfindings.com. This plan is the
> agreed next step ("option B"): make the **edit** experience inline too and delete the
> old full-page edit form.

---

## 1. Goal

Remove the last full-page jump in the rostering experience. Today, editing a shift from
the roster grid (and from the shift detail page) navigates to a separate full-page form at
`/operations/shifts/{id}/edit`. The newer `CreateShiftDialog` popup already supports
editing. Make that popup the **single** edit surface everywhere, then delete the old page.

This is intentional because the app is **not in production yet** — fix it once, end up with
one edit surface and zero `/edit` page.

---

## 2. Current state (verified against `main` @ 751a1028)

### The popup to standardise on (KEEP)
- `resources/js/pages/operations/shifts/components/create-shift-dialog.tsx`
  - Edit mode via the `initialShift` prop → `isEdit = !!initialShift` (~line 181).
  - In edit mode it does `form.put(updateShift.url(initialShift.id), …)` (~line 413) →
    **`PUT /operations/shifts/{shift}`** (`operations.shifts.update`, routes/operations.php:679 → `ShiftController@update` at ShiftController.php:1086). This same endpoint already powers both the page form and the popup.
  - Already used for **create + edit** on the standalone shifts page
    `resources/js/pages/operations/shifts/index.tsx` (it renders two `CreateShiftDialog`s;
    the edit one is passed `initialShift` built from a `ShiftRow`). **Use that mapping as the
    reference** for the shape below.
  - The dialog also needs reference-data props: `clients`, `staff`, `sites`,
    `serviceContexts`, `defaultServiceContextId` (plus optional create defaults).
  - `initialShift` / `EditableShift` shape (fields the dialog reads): `id`, `client {id}`,
    `service_context_id`, `staff {id}`, `starts_at`, `ends_at`, `status`, `shift_type`,
    `location`, `is_sleepover`, `is_on_call`, `expected_break_minutes`, `coverage_roles[]`,
    `tasks[]`, `notes`.

### The old page to delete (at the very end, after §5)
- Page: `resources/js/pages/operations/shifts/edit.tsx`
- Route: **`GET /operations/shifts/{shift}/edit`** (routes/operations.php:676) →
  `ShiftController@edit` (ShiftController.php:1046), name `operations.shifts.edit`.
- The page shows a prominent **eligibility banner** ("Current assignee is no longer eligible"
  + reasons: overlap, 12h daily max, min-rest, compliance). **This is the parity gate — see §5.**

### Everything that currently points at `/edit` (must be repointed before deletion)
1. **Rostering grid context menu** — `resources/js/components/rostering/week-grid-pane.tsx`
   - `const editHref = `/operations/shifts/${shift.id}/edit`` (~line 201)
   - `window.location.href = editHref` for **"Edit shift"** (open status, ~line 275)
   - `navAction(…editHref)` for **"Edit shift"** (scheduled/default, ~line 389) and
     **"Edit draft"** (draft, ~line 422)
2. **Shift detail page** — `resources/js/pages/operations/shifts/show.tsx:916`
   `<Link href={`/operations/shifts/${shift.id}/edit`}>` (the header "Edit" button).
3. **Backend post-duplicate redirect** — `ShiftController@duplicate` (ShiftController.php:939)
   returns `route('operations.shifts.edit', $copy)` (~line 1041) so "Duplicate as draft"
   lands the user on the edit form to finish the new draft.

---

## 3. Implementation — phase by phase

> Pattern to copy: this is exactly how the previous session added the **reassign** and
> **request-replacement** popups — a small on-demand fetch + a dialog opened from a grid
> callback. Reuse `resources/js/components/rostering/reassign-dialog.tsx` and the
> `onRequestReplacement` wiring in `pages/operations/rostering/index.tsx` as templates.

### Phase 1 — Open the popup from the rostering grid (kills the reported jump)
The grid's `props.shifts` (`ShiftLite`) is lighter than what `CreateShiftDialog` needs, so
fetch the editable payload on demand.

1. **Backend:** add `GET /operations/shifts/{shift}/editable` → JSON in the
   `initialShift`/`EditableShift` shape (reuse the data `ShiftController@edit` already loads:
   client, service context, staff, times, type, break, sleepover/on-call, coverage_roles,
   tasks, notes). Name it `operations.shifts.editable`; middleware `permission:shifts.update`.
2. **WeekGridPane** (`week-grid-pane.tsx`): add prop `onEditShift?: (shift: GridShift) => void`.
   In `buildShiftActions`, replace the three `editHref` navigations ("Edit shift" / "Edit
   draft") with `callbacks.onEditShift?.(shift)` (fall back to the nav if the callback is not
   supplied). Thread `onEditShift` through the component destructure **and** the
   `buildShiftActions(...)` call inside `onShiftCtx` — identical to how `onRequestReplacement`
   was threaded.
3. **Rostering page** (`pages/operations/rostering/index.tsx`):
   - State: `editShift: EditableShift | null` (+ a small loading flag).
   - `onEditShift={(s) => fetch('/operations/shifts/'+s.id+'/editable') → setEditShift(json)}`
     (same fetch shape as the reassign popup's candidate fetch — `Accept: application/json`,
     `credentials: 'same-origin'`).
   - Render `<CreateShiftDialog open={!!editShift} initialShift={editShift} onClose={() =>
     setEditShift(null)} … />`.
   - **Reference-data props:** `CreateShiftDialog` needs `clients`, `staff`, `sites`,
     `serviceContexts`, `defaultServiceContextId`. The rostering controller already returns
     `clients/staff/sites`; **add `serviceContexts` + `defaultServiceContextId` to
     `RosteringController@index`** if absent (cheap — mirror what the shifts index controller
     passes).

### Phase 2 — Repoint the other two entry points
4. **Shift detail page** (`shifts/show.tsx`): replace the `<Link href=".../edit">` "Edit"
   button with an in-page `CreateShiftDialog` (build `initialShift` from the page's shift
   props — the show page already has the shift and already imports the eligibility
   components, which helps with §5). Remove the navigation.
5. **Duplicate redirect** (`ShiftController@duplicate`, ~line 1041): stop redirecting to
   `operations.shifts.edit`. Redirect back to the originating page (use the existing
   `return_to` pattern) with a success flash. **Behaviour change — call it out.** If you want
   to preserve "land in the editor", pass the new draft id back and have the originating page
   auto-open the edit popup for it.

### Phase 3 — Remove the old page (only after §5 passes)
6. Delete `resources/js/pages/operations/shifts/edit.tsx`.
7. Remove route `GET /operations/shifts/{shift}/edit` (routes/operations.php:676) and
   `ShiftController@edit` (ShiftController.php:1046).
8. Remove the now-unused `editHref` constant in `week-grid-pane.tsx`.
9. Repo-wide sweep for stragglers and clean them:
   `rg "operations\.shifts\.edit|shifts/.*\}/edit"` across `app/`, `resources/`, `routes/`,
   `tests/` (breadcrumbs, notifications, feature tests, the standalone shifts page's own
   "edit" links if any still navigate).

---

## 4. Tests
- `resources/js/components/rostering/rostering-redesign-followups.test.tsx`: assert
  "Edit shift" invokes `onEditShift` (not a navigation), mirroring the existing
  reassign/request-replacement tests.
- Add/confirm a `CreateShiftDialog` edit test (pre-fills from `initialShift`; PUT on submit).
- `shifts/show.tsx`: the Edit button opens the dialog (no nav).
- Backend feature test for `GET /shifts/{shift}/editable` (200 + correct shape; 403 without
  `shifts.update`). Update or remove any test that exercises the old `/edit` route/page.
- Commands: `npx tsc --noEmit` (ignore the pre-existing `@/routes/*` module-not-found noise —
  those stubs aren't generated in this checkout), `npx eslint <touched files>`,
  `npx vitest run resources/js/components/rostering/rostering-redesign-followups.test.tsx`,
  and the relevant `php artisan test` filter.

---

## 5. Parity gate — DO NOT delete the page until this is satisfied
The old `/edit` page surfaces a **"Current assignee is no longer eligible"** banner with
detailed reasons (overlap, daily-max, min-rest, compliance). `CreateShiftDialog` does **not**
appear to render eligibility warnings today (no eligibility handling was found in the
component). Before removing the page:
- Confirm `ShiftController@update` runs the same eligibility evaluation and returns
  warnings/blocks (it should — it shares `ShiftStaffEligibilityService`).
- Surface them in the popup: on a warning/block response, show the reasons and require an
  override acknowledgement. Reuse `@/components/eligibility/eligibility-status-badge` and
  `@/components/eligibility/override-confirmation-dialog` (the shift detail page already uses
  these), mirroring the **reassign popup's** warning → reason override flow
  (`reassign-dialog.tsx`). Otherwise editing a staff member into an overlap would silently
  drop the safety heads-up the old page gave.

---

## 6. Verification (live, after deploy)
- Rostering grid → **Edit shift** → popup opens in place; URL stays `/operations/rostering`.
- Shift detail → **Edit** → popup (no navigation).
- **Duplicate as draft** → sensible landing (no `/edit`).
- Editing a staff member into an overlapping slot shows the eligibility warning **in the popup**.
- `php artisan route:list | grep shifts` → no `operations.shifts.edit`; nothing 500s; the
  standalone shifts page and rostering page still load.

---

## 7. Risks / notes
- **`CreateShiftDialog` needs full shift data + reference-data props.** The rostering payload
  is light; the `/editable` endpoint + passing `serviceContexts`/`defaultServiceContextId` is
  the bulk of the work.
- **Behaviour change:** "Duplicate as draft" no longer drops you into the editor (unless you
  wire the auto-open).
- **Eligibility parity is the hard gate (§5)** — treat as blocking.
- **Concurrent work:** a `codex/WorkforceLeftNav` branch is in flight (cut from `main` @
  751a1028) touching `week-grid-pane.tsx`, `pages/operations/rostering/index.tsx`,
  `app/Http/Controllers/ShiftController.php`, `app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php`,
  `app/Services/ShiftTimelineService.php`, and adding an `UnassignMakeOpenDialog`. Rebase on
  the latest `main` and expect conflicts in those files — resolve carefully.

---

## 8. Deploy (this repo's flow)
- Push to `main`. The server (SSH `oblivion@oblivionfindings.com`, app at
  `/var/www/oblivionfindings`) auto-pulls and now also auto-runs `npm ci && npm run build`.
  PHP changes go live on pull (OPcache validate_timestamps on).
- If you build manually: `NODE_OPTIONS=--max-old-space-size=8192 npm run build` (default heap
  OOMs) then `php artisan optimize:clear`. If a build crashes mid-run, `rm -rf
  node_modules/.vite node_modules/.cache` first (a crashed build corrupts the Vite cache and
  leaves `public/build` without a manifest → frontend down until the next good build).
- Verify on https://oblivionfindings.com (login `admin@demo.test`).
