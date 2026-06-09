# Roster Templates → Rostering tab (consolidation + gap analysis)

**Date:** 2026-06-09
**Goal:** Fold the standalone Roster Templates pages (`/operations/rostering/templates/*`) into a
**tab** inside the Rostering workspace (`/operations/rostering`), matching the Calendar/Availability
consolidation. **No separate pages — all create / edit / view / apply happen in pop-ups** styled to
match the "Add client" wizard (`resources/js/components/clients/add-client-dialog.tsx`).

---

## 1. Current state

| Piece | Location |
|---|---|
| Controller | `app/Http/Controllers/Operations/RosterTemplateController.php` (CRUD + `apply`) |
| Routes | `routes/operations.php:800-819` — `operations.rostering.templates.*` |
| Pages (to remove) | `resources/js/pages/operations/rostering/templates/{Index,Show,Create,Edit,TemplateForm}.tsx` |
| Model | `RosterTemplate` + `templateShifts` (`RosterTemplateShift`) |
| Permissions | `roster_templates.viewAny / create / update` (+ `delete` **missing**, see gaps) |
| Entry point | one button at the bottom of the Rostering page, gated on `canViewOperationsReports` |

The page renders as **4 full Inertia pages** (list, show, create, edit) reached by full navigations.
The Rostering workspace already uses a `TabStrip` + lazy-loaded panes (`shifts, calendar, open,
coverage, timeoff, availability, capacity, analytics`) — Templates is the one scheduler surface still
living outside it.

---

## 2. Gap analysis

### A. Architecture / navigation
1. **Not a tab.** Unlike Calendar & Availability (folded in as tabs), Templates is a separate page
   tree reached by a single bottom-of-page button.
2. **Discoverability mismatch (bug).** The only link is gated on `canViewOperationsReports`
   (`operations.reports.view || reports.viewAny`), but the *route/page* is gated on
   `roster_templates.viewAny|rostering.viewAny`. A user with template permission but no reports
   permission can open the page directly yet sees **no link** to it.
3. **Full-page create/edit/view** — every action is a navigation, losing roster context. The user
   asked for pop-ups only.

### B. Permissions (deploy-impacting)
4. **`roster_templates.delete` is never seeded.** `routes/operations.php:817` gates Delete on
   `permission:roster_templates.delete`, and `DuskDatabaseSeeder` grants it, but
   `OperationsPermissionsSeeder` only defines `viewAny/create/update`. Per
   `reference_deploy_seeders.md` (permissions are seeded, deploys skip seeders, no super-admin
   bypass in `canDo`) → **Delete 403s for everyone on the live server**, including admins, until the
   permission is seeded. Fix: add the permission to `OperationsPermissionsSeeder` (admin sync picks
   it up) and reseed on deploy.

### C. Apply flow
5. **Idempotency / dup redirect targets a page we are removing.** `RosterTemplateController::apply`
   redirects to `operations.rostering.templates.show` on the "already applied this hour" and
   `Cache::add` race paths. Once the show page is gone these 404. Re-point to the Rostering index
   (templates tab) with the same status message.
6. **Apply success already lands on the roster week** (`operations.rostering.index?week=…`) — good
   UX, keep it (you see the shifts you just created).

### D. UI / UX quality
7. **Plain stacked list**, no at-a-glance sense of a template's shape (which days, how many
   shifts, assigned vs open). Add a Mon–Sun "week strip" mini-visualisation per card.
8. **No search / filter** on the list (everything paginated 25/page server-side).
9. **Create/Edit form** is a long flat stack of shift-row cards with every field always visible —
   noisy. Re-home into a 2-step wizard (Details → Shift rows) matching the Add-client chrome
   (stepper rail, header, footer), with the polished shadcn `Select`/`Input` controls and grouped
   sub-sections per row.
10. **Inconsistent design tokens / chrome** — uses `PageShell` + `PageHero` rather than the
    rostering pane conventions (`MicroStats` header + `rounded-[14px] border bg-card` sections).

### E. Data model (noted, **out of scope** for this pass)
11. `template_type` cadence is captured (`weekly/fortnightly/monthly`) but **apply always treats the
    chosen date as a single Monday anchor** — fortnightly/monthly cadence is not actually expanded.
    Leave as-is; flag for a later pass.
12. No "duplicate template" / "last applied at" affordances. Out of scope.

---

## 3. Target design

### Tab
- Add `templates` to `RosterTab`, `ROSTER_TABS`, `tabItems` (icon `LayoutTemplate`, tone `violet`,
  badge = template count).
- Lazy-load on first switch via a partial reload `only: ['rosterTemplates']` — mirrors the
  Availability tab. Direct landing (`?tab=templates`) eager-loads server-side.
- Remove the redundant bottom "Roster templates" button.

### `TemplatesPane` (new `components/rostering/templates-pane.tsx`)
- `MicroStats` header: Templates · Active · Shift rows · Assigned-vs-open ratio.
- Toolbar: search-by-name + Active/All segmented filter + **New template** (primary, gated on
  `canManageTemplates`).
- Responsive card grid (`sm:grid-cols-2 xl:grid-cols-3`), each card: name, cadence + status badges,
  description, **Mon–Sun week strip**, row/creator/updated meta, actions **Apply · Edit · ⋯Delete**
  (gated). Empty state with CTA.

### Pop-ups (match Add-client look & feel)
- **`TemplateWizardDialog`** (create/edit) — full-height `Dialog` (`p-0`), stepper rail + header +
  top progress + scroll body + footer (Back / Cancel / Continue → Save). Steps: **Details**,
  **Shift rows**. Submits via Inertia `useForm` to `store`/`update`; validation errors land inline.
- **`TemplateDetailDialog`** (view + apply + delete) — standard `Dialog`; left = read-only template
  rows w/ week strip, right = "Apply to a week" panel (date, info, Apply) surfacing preflight
  **blocks** inline + a **warnings** `AlertDialog` confirm. Keeps the e2e test ids
  (`template-apply-card`, `week-start`, `template-apply-submit`, `template-apply-blocks`).

### Backend
- `RosteringController@index`: add `rosterTemplates` (lazy/`Inertia::optional`, eager-loads
  `templateShifts` + client/user/serviceContext + creator), and `canManageTemplates` /
  `canDeleteTemplates` booleans. Reuse existing `clients/staff/serviceContexts` props for the wizard.
- `RosterTemplateController`: drop `index/create/show/edit` + `formOptions`; keep
  `store/update/apply/destroy`; re-point redirects to `operations.rostering.index?tab=templates`.
- `routes/operations.php`: keep `store/update/apply/destroy`; turn GET `templates.index` into a
  redirect (`?tab=templates`) for old bookmarks; drop GET `create/show/edit`.
- `OperationsPermissionsSeeder`: add `roster_templates.delete`.

### Tests
- `TemplateApplyTest.php`: update the idempotency redirect assertion → rostering index `?tab=templates`.
- `template-apply-conflict.spec.ts`: navigate to `?tab=templates`, open the detail/apply modal,
  reuse the same test ids.
- Check `operations-rostering-a11y.spec.ts` + `tests/Browser/Operations/OperationsTest.php`.

---

## 4. Status — DONE (uncommitted, locally verified 2026-06-09)
- [x] Backend — `RosteringController@index` (lazy `rosterTemplates` + `canManageTemplates`/
      `canDeleteTemplates` + `buildRosterTemplates`); `RosterTemplateController` trimmed to
      `store/update/apply/destroy` with redirects re-pointed to `?tab=templates`; routes (GET index →
      302 redirect, create/show/edit removed); `roster_templates.delete` seeded.
- [x] Frontend — `components/rostering/templates-pane.tsx` + `template-dialogs.tsx`
      (`TemplateWizardDialog` create/edit, `TemplateDetailDialog` view/apply), barrel exports, wired
      into the Rostering page (tab + lazy load + dialogs), bottom button removed, old
      `pages/operations/rostering/templates/` deleted.
- [x] Tests — `TemplateApplyTest` idempotency redirect; e2e specs + Dusk tests re-pointed to the tab.
- [x] Verify — `tsc` clean for all touched files (only pre-existing `@/routes/*` Wayfinding stubs
      remain); 28 rostering vitest green; 4 `TemplateApplyTest` green; live worktree build smoke
      (`php -S :8766` + built assets): tab renders, card + week strip, detail/apply pop-up, apply
      preflight → inline block alert, create wizard (Details → Shift rows). No console errors.

**Deploy note:** new `roster_templates.delete` permission must be reseeded on the server
(`OperationsPermissionsSeeder` / `php artisan db:seed`) — deploys skip seeders, so Delete stays 403
for everyone until then. See `reference_deploy_seeders.md`.

## 5. Deferred (not done this pass)
- Fortnightly/monthly cadence isn't expanded on apply (always a single Monday anchor) — gap §E11.
- Wizard form dropdowns reuse the manager-only `clients/staff/serviceContexts` props; a user with
  `roster_templates.create` but not `shifts.manageAny` would see empty pickers (gap §A/edge case).
- No "duplicate template" affordance.
</content>
</invoke>
