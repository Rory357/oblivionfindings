# Fleet Full Audit — Modal Consistency, Workflow, UX, and Production Gaps

**Audit date:** 13 July 2026 (Pacific/Auckland)
**Audited source:** `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\strange-bhaskara-ae2843`
**Branch / HEAD:** `claude/frosty-leavitt-f99798` / `de5d13af323e`
**Fleet implementation commit:** `c705d567` (`feat(fleet): finish hero rollout consistency pass`)
**Live surface:** `https://oblivionfindings.com` using the existing authenticated Chrome session
**Audit mode:** Read-only. No source fixes, form submissions, deployment, merge, push, or server changes were performed.

> **Desktop-only scope override (14 July 2026):** The user confirmed that Fleet is a desktop web application. Mobile and narrow-viewport findings are not acceptance blockers and must not trigger remediation unless the user explicitly reverses this instruction. Desktop browser acceptance remains required.

## Executive verdict

The Fleet hero rollout is substantially present and the eight protected core routes render as one recognisable family. The live Daily Check page now includes all four canonical compliance badges, the Work Orders overdue list agrees with its zero count, Mileage opens the existing keyboard-contained wizard, incident rows carry text labels as well as tones, and Report CSV links carry both `period=30d` and the expected `type`.

The module is not ready to call fully consistent or production-complete. The most important gaps are:

1. A confirmed permission-boundary defect places work-order creation inside the read middleware group. Geofence create/update/toggle/delete are also available to users satisfying the read side of an OR permission.
2. Thirteen workflow/detail dialog render sites still use legacy compact `DialogContent` instead of the Add Client-derived wizard family. They represent eleven distinct workflows.
3. Seven legacy transport/checklist/schedule dialogs have no `DialogDescription`; Checklist and Service Schedule reproduce the Radix accessibility warning live.
4. The Maintenance dashboard's Overdue work-order tile drops `?overdue=1`.
5. Three create/edit families still escape to full pages: Geofences, Outings, and Resident Transports.
6. Date and time handling is fragmented across 27 Fleet files, including US locale output and UTC-derived HTML date defaults that can be one calendar day wrong in New Zealand.
7. All 25 Fleet page files containing tables lack a deliberate mobile-card/desktop-table split. Chrome did not expose a viewport capability in this session, so narrow-browser proof remains deferred.
8. Daily Check dark mode renders every not-yet-checked vehicle as a large critical-red panel. It is readable and not colour-only, but the severity is disproportionate to a routine due task.

## Canonical modal contract

The reference is `resources/js/components/clients/add-client-dialog.tsx`, with the reusable Fleet-compatible shell in `resources/js/components/wizard/shell.tsx`.

A conforming workflow/detail modal has:

- a constrained large dialog (`min(94vw, 980px)` in `WizardShell`; Add Client uses 1080px);
- a 248px context/step rail on supported widths;
- visible title, description, step names and short explanatory copy;
- a `Step x of y` or section header, close control, progress treatment, scroll-contained body, and muted footer band;
- explicit labels, validation messages, review/confirmation where the action changes records, semantic design tokens, focus containment, Escape dismissal, and a success state where applicable;
- no forced navigation to a second creation page.

Confirmation dialogs are a separate, intentionally compact tier. They should use the shared `ConfirmDialog`/`AlertDialog` family with the same tokens, spacing, focus behaviour, descriptive text and button hierarchy; they should not be inflated into multi-step wizards.

## Complete modal inventory

There are **33 dialog render surfaces** in Fleet source.

### Add Client-family workflow/detail dialogs — 8

| Workflow | Source | Result |
|---|---|---|
| Book vehicle | `resources/js/pages/fleet-assets/bookings/book-vehicle-wizard.tsx` | Conforming `WizardShell`; live 980px, rail/header/footer/focus confirmed |
| Add/edit asset | `resources/js/pages/fleet-assets/assets/components/asset-wizard-dialog.tsx` | Conforming `WizardShell`; live confirmed |
| Shift handover | `resources/js/pages/fleet-assets/handovers/index.tsx` | Conforming `WizardShell`; live confirmed |
| Mileage claim | `resources/js/pages/fleet-assets/mileage/index.tsx` | Conforming `WizardShell`; live open, Tab containment and Escape dismissal confirmed |
| Work order | `resources/js/pages/fleet-assets/maintenance/work-orders/create-wizard.tsx` | Conforming `WizardShell`; live confirmed |
| Inspection | `resources/js/pages/fleet-assets/inspections/create-wizard.tsx` | Conforming `WizardShell`; live confirmed |
| Report Fleet incident | `resources/js/components/fleet/fleet-incident-report-dialog.tsx` | Conforming `WizardShell` after the mode popover; live confirmed |
| Fleet incident detail/actions | `resources/js/components/fleet/fleet-incident-dialog.tsx` | Conforming `WizardShell`; live confirmed, but still offers `Open full page` |

### Non-conforming workflow/detail dialog render sites — 13

These are the answer to “how many Fleet modals do not follow the new Add Client style” when confirmation dialogs are excluded: **13 render sites, covering 11 distinct workflows**.

| # | Workflow | Source | Accessibility note |
|---:|---|---|---|
| 1 | Resolve alert | `resources/js/pages/fleet-assets/alerts/index.tsx` | Has title/description; legacy compact shell |
| 2 | Upload asset document | `resources/js/pages/fleet-assets/assets/show.tsx` | Has title/description; legacy compact shell |
| 3 | Log fuel | `resources/js/pages/fleet-assets/fuel/index.tsx` | Has title/description; live 512px compact shell |
| 4 | Pair tracking device | `resources/js/pages/fleet-assets/devices/index.tsx` | Has title/description; live 512px compact shell |
| 5 | Device detail | `resources/js/pages/fleet-assets/devices/index.tsx` | Has title/description; legacy compact detail shell |
| 6 | Create checklist template | `resources/js/pages/fleet-assets/maintenance/checklists/index.tsx` | Missing `DialogDescription`; warning reproduced live |
| 7 | Create service schedule | `resources/js/pages/fleet-assets/maintenance/schedules/index.tsx` | Missing `DialogDescription`; warning reproduced live |
| 8 | Assign tracker to resident | `resources/js/pages/fleet-assets/resident-tracking/index.tsx` | Has title/description; live 768px compact shell |
| 9 | Pack medication for transit | `resources/js/pages/fleet-assets/transports/show.tsx` | Missing `DialogDescription` |
| 10 | Record transport administration | `resources/js/pages/fleet-assets/transports/show.tsx` | Missing `DialogDescription`; duplicated workflow |
| 11 | Record medication return | `resources/js/pages/fleet-assets/transports/show.tsx` | Missing `DialogDescription`; duplicated workflow |
| 12 | Record transport administration | `resources/js/pages/fleet-assets/transports/medications.tsx` | Missing `DialogDescription`; duplicate of #10 |
| 13 | Record medication return | `resources/js/pages/fleet-assets/transports/medications.tsx` | Missing `DialogDescription`; duplicate of #11 |

### Confirmation dialogs — 12

- Nine shared `ConfirmDialog` uses: alert bulk action; device consent revoke; device unpair; vehicle driver removal; trip close; trip delete; booking cancel; work-order cancel; outing cancel.
- Three direct `AlertDialog` uses: booking reject; geofence delete; return all outing residents.

Literal comparison with the full Add Client wizard means 25 of 33 dialogs are not full wizard shells. That is not the recommended remediation metric: the twelve confirmations should remain compact. The actionable workflow/detail non-compliance count is **13**.

## Findings

### P1 — permission and data-boundary risks

1. **Work-order creation bypasses the intended maintenance write group.** `POST /fleet-assets/maintenance/work-orders` is in the read group guarded by `fleet.viewAny|assets.viewAny`, while the following group is explicitly labelled “write (requires maintenance manage or fleet manage)”. The controller does not add an authorization check. A read-only Fleet/Assets user can therefore reach the create action if the OR-permission middleware behaves as named.
2. **Geofence mutation is coupled to read access.** Index, create, store, edit, update, toggle and delete all sit under `permission:fleet.viewAny|assets.geofences.manage`. The controller performs validation and audit logging but no policy/permission authorization, so `fleet.viewAny` is sufficient for destructive geofence actions.
3. **Mutation-boundary coverage is incomplete.** Handover participant/site checks are present and have a dedicated isolation test. Equivalent negative permission tests are not present for Geofences, Work Orders, Transport completion/pre-check, and other state-changing endpoints that share broad read groups. The plan must add tests before deciding whether each self-service action is intentionally broad.

### P1 — dialog accessibility and consistency

1. Thirteen workflow/detail dialog render sites remain on compact legacy shells.
2. Seven lack `DialogDescription`: five transport medication dialogs plus Checklist Template and Service Schedule.
3. Checklist Template and Service Schedule each emit `Warning: Missing Description or aria-describedby={undefined} for {DialogContent}` live.
4. Transport administration/return dialog code is duplicated between the transport detail page and medication-transit index, making future UI, validation and accessibility drift likely.
5. Fleet incident detail is correctly modal-first but includes an `Open full page` escape, contrary to the desired complete-in-modal interaction model.

### P1 — workflow correctness

1. **Maintenance dashboard overdue link:** the tile displays `hero.wo_overdue` but links to `/fleet-assets/maintenance/work-orders` instead of `/fleet-assets/maintenance/work-orders?overdue=1`. The Work Orders page's own Overdue tile is correct and, live, its zero count agrees with an empty filtered result.
2. **Device pairing copy/data mismatch:** the modal label says `Vehicle Asset`, but the backend intentionally returns every active asset. Live options include beds, hoists, clinical devices and vehicles. Because the page describes device-to-asset linking, the likely correction is to rename the field to `Asset`; if only vehicles are intended, both query and validation must enforce that instead.
3. **Full-page workflow escapes:** Geofence create/edit, Outing create and Transport create still route to separate pages. Existing modal-first routes for Assets, Bookings, Inspections, Work Orders, Mileage and Handovers already demonstrate the desired `?new=1` redirect pattern.

### P2 — date, locale and timezone consistency

1. Fleet contains 89 date/time construction or formatting occurrences across 27 page files instead of consistently using `resources/js/lib/datetime.ts`.
2. Booking calendar labels explicitly use `en-US`.
3. Fuel `logged_at`, Mileage claim `date`, booking wizard “today”, booking calendar date serialization and reimbursement filenames derive dates from `new Date().toISOString()`. In New Zealand morning hours, the UTC calendar day can be the prior local day.
4. Vehicle detail, Daily Check, Trips, Work Orders and other pages call locale formatting without explicit `en-NZ`/`Pacific/Auckland`, so output depends on the device locale/timezone.

### P2 — responsive and frontline scanability

1. Fleet contains 25 page files with tables and none has the deliberate mobile-card/desktop-table dual layout used elsewhere in the application.
2. Worker-facing high-priority pages include Daily/operational lists such as Alerts, Keys, Fuel, Mileage, Handovers, Inspections, Outings, Transports, medication transit and incidents. Manager/reporting tables can retain horizontal tables at larger widths, but still need an explicit narrow strategy.
3. The Chrome surface exposed no viewport/emulation capability, so no narrow live assertion was made. This is a deferred proof item, not a pass.

### P2 — visual semantics and theme behaviour

1. Daily Check uses `status-critical` background treatment for every vehicle merely not yet checked. In dark mode this becomes a wall of deep red. Use a neutral or warning due state; reserve critical for a failed check or an actual compliance hazard.
2. Incident severity maps `Minor` to the success token. Although every badge has text and therefore is not colour-only, success semantics are too positive for an incident severity. Prefer neutral/info for Minor, warning for Moderate, critical for Major/Critical.
3. Fleet has 41 hard-coded colour occurrences across seven files, concentrated in charts, booking status blocks, maps/geofences and maintenance analytics. Map/chart palettes are legitimate special cases, but status colours should come from a theme-aware semantic palette so dark mode and tenant accent changes remain coherent.

### P2 — family completeness

1. Of 51 Fleet page files with a document title, 26 use `HeroShell`, 23 use `FleetCompactHero`, and two use neither: `drivers/show.tsx` and `mobile/dashboard.tsx`.
2. `drivers/show.tsx` uses a generic `PageShell`, breaking the Fleet detail-page family.
3. `mobile/dashboard.tsx` uses a separate legacy purple mobile header. It may remain compact, but its tokens, status vocabulary and action hierarchy should be brought under the Fleet family.

### P2/P3 — performance and test depth

1. The 28 FleetAssets controllers contain 167 `get()` calls and only 16 pagination calls. Not all `get()` calls are defects, but multiple list controllers compute hero/export totals by loading up to 5,000 models into PHP.
2. Work Order and Incident form options load all users/assets. Device pairing loads every unpaired tracker and every active asset. These will not scale gracefully without bounded or searchable option endpoints.
3. There are ten direct rollout-focused FleetAssets test files (nine Feature plus one Unit), plus broader telemetry, browser and federation coverage. Several controllers/workflows have no dedicated negative permission, modal contract, timezone or responsive contract tests.

## Live verification matrix

| URL / interaction | Result | Console/runtime |
|---|---|---|
| `/fleet-assets` | Hero family coherent; compliance badges present | Clean on route load |
| `/fleet-assets/daily-check` | WOF, Rego, CoF and Alerts badges present; nine due vehicles | Clean on route load; dark due-card severity gap |
| `/fleet-assets/maintenance/work-orders?overdue=1` | Overdue count `0`; filtered list empty; count agrees | Clean |
| `/fleet-assets/mileage` → `New claim` | Existing 980px wizard opens; focus stays inside; Escape dismisses | Clean |
| `/fleet-assets/incidents` | Severity/status labels visible with tones; report and detail wizards open | Clean |
| `/fleet-assets/reports` | Trips CSV has `period=30d&type=trips`; Fuel CSV has `period=30d&type=fuel` | Clean |
| `/fleet-assets/vehicles` | Fleet hero and compliance strip coherent | Clean |
| `/fleet-assets/maintenance/dashboard` | Hero coherent; Overdue tile URL defect confirmed | Clean |
| Add Client on `/operations/clients` | Canonical 1080px reference captured: rail/header/footer/scroll/focus structure | Clean |
| Six `?new=1` Fleet wizard routes | Booking, Asset, Handover, Mileage, Work Order and Inspection all use 980px wizard family | Clean |
| Incident report/detail | Both reach `WizardShell`; mode selector is a compact popover | Clean |
| Fuel → `Log fuel` | 512px legacy compact dialog | Clean |
| Devices → `Pair device` | 512px legacy dialog; `Vehicle Asset` mismatch visible | Clean |
| Checklists → `Create template` | 512px legacy dialog | Missing-description warning |
| Schedules → `Create schedule` | 512px legacy dialog | Missing-description warning |
| Resident Tracking → `Assign tracker` | 768px legacy dialog | No new warning observed |

Theme coverage:

- Light: Add Client reference plus all protected core routes and modal interactions.
- Dark: `/fleet-assets`, `/fleet-assets/daily-check`, and `/fleet-assets/reports` visually inspected; no horizontal document overflow at the desktop viewport. Theme was restored to Light after inspection.
- Narrow viewport: deferred because the installed Chrome control surface exposed no viewport capability. Source inspection found the 25-table responsive gap.

## What remains unverified

- Data-dependent legacy dialogs that require an existing alert, asset document action, transport medication log or outing state were source-audited but not all opened live.
- No destructive permission exploit was attempted on the live site. Permission findings are source-confirmed and must be proven with local negative Feature tests before remediation is merged.
- No create/edit form was submitted, no CSV was downloaded, and no record was mutated.
- Responsive behaviour needs a later supported narrow-browser surface or a manual device check after implementation.

## Remediation source of truth

Implement from `docs/superpowers/plans/2026-07-13-fleet-full-audit-fix-plan.md`. Do not treat the earlier hero rollout ledger as a fix plan for these newly audited gaps.

## Post-remediation desktop acceptance — 14 July 2026

Fleet remediation is implemented, merged to `main`, pushed, deployed, and accepted as a desktop web-only application. Mobile/narrow-viewport findings above are retained as historical audit evidence only; they are not current acceptance requirements and no further mobile work should be attempted unless the user explicitly changes scope.

### Final state

- Worktree: `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\strange-bhaskara-ae2843`
- Branch: `claude/frosty-leavitt-f99798`
- Worktree HEAD / `origin/main` / deployed server: `3d0d7b862e8bb1da5647e7185bb657f47f4ba698`
- Source tree: clean; this audit and the remediation plan are the only untracked files and remain uncommitted.

### Remediated inventory

- Legacy workflow/detail dialog sites: `0`.
- Converted legacy sites: `13`, representing all `11` audited workflows.
- Current workflow/detail family: `21` WizardShell surfaces.
- Compact confirmation family: `12` total — `9` shared ConfirmDialogs and `3` direct accessible AlertDialogs.
- Missing-description console warnings after remediation: `0`.

### Automated evidence

- PHP Fleet matrix: `82 passed`, `686 assertions`, `377.44s`.
- Vitest final rerun: `18` files, `48` tests, all passed in `6.29s`.
- TypeScript passed.
- ESLint completed with `0 errors` and `3` existing Card-primitive warnings.
- PHP syntax passed for all `29` Fleet controllers and the Fleet route file (`30` files).
- Client build: `4,948` modules in `3m55s`; server build: `2m51s`; standalone SSR: `1,600` modules in `45.42s`.

### Desktop Chrome evidence

One Chrome tab verified the eight core protected URLs: `/fleet-assets`, `/fleet-assets/daily-check`, `/fleet-assets/maintenance/work-orders?overdue=1`, `/fleet-assets/mileage`, `/fleet-assets/incidents`, `/fleet-assets/reports`, `/fleet-assets/vehicles`, and `/fleet-assets/maintenance/dashboard`.

- Both Maintenance Overview and Work Orders retained `?overdue=1`; their `0` overdue counts matched the empty filtered result.
- Resolve Alert, Upload Asset Document, Log Fuel, Pair Device, Device Detail, Checklist Template, Service Schedule, and Assign Tracker WizardShell workflows opened successfully.
- Geofence create/edit, Outing create, and Resident Transport create legacy links deep-linked into their modal-first query-string workflows.
- Mileage proved Tab, Shift+Tab, visible focus, and Escape behaviour.
- Close Trip and Delete Trip compact confirmations opened with Cancel initially focused and were cancelled without submission.
- Light and Dark checks covered Fleet dashboard, Daily Checks, Reports, Checklist Template, and Service Schedule. The session ended on Light at the normal desktop viewport.
- The final console contained no warnings or errors.
- The browser-discovered vehicle timestamp defect was fixed red-green, deployed, and verified as `Last seen: Sat 2 May, 4:30 pm` with no raw ISO timestamp.

The live tracker assignment was accidentally removed by an immediately mutating `Unassign` action. Work stopped, the user authorized repair, and the assignment wizard restored tracker `867963069916998` to Amelia Wilson with active consent. The live page confirmed the assignment and showed one tracked resident online.

### Live deployment evidence

- `/var/www/oblivionfindings` is clean on `main` at exact SHA `3d0d7b862e8bb1da5647e7185bb657f47f4ba698`.
- Build manifest: `2026-07-13 21:30:30.709054157 +0000`, `3,239,017` bytes.
- Pending migrations: `0`; Fleet routes: `144`; queue worker PID `375990` is running.
- `/login` returns `200`; unauthenticated `/fleet-assets` returns `302` to `/login`.
- Local port `8001` is not listening.

### Deferred without fabricating live data

- The Pack Medication, Record Administration, and Record Return transport workflows could not be opened live because there are no transport-medication or resident-transport fixtures. Their shared canonical implementation is covered by focused component and ownership tests.
- Outing detail could not be opened because the live dataset has no outing records.
- No destructive form was submitted, and no synthetic live fixture was created solely for acceptance.
