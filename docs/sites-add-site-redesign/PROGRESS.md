# Add Site Modal — Progress tracker

Loop tracker for the slice-by-slice build. On each run: read this, do the first unchecked slice,
gate it (§7), commit, tick it. Plan: [PLAN.md](PLAN.md).

- [x] **S0 — Audit & plan.** Deep frontend+backend audit done; `PLAN.md` written with the three
  decisions: (1) **no migration** — phase4 already added coverage cols + total_capacity +
  weekly_food_budget_cents all exist; (2) shift-templates = **Option A** RosterTemplate "{Site}
  default week" (identity FKs nullable, so no fabrication); (3) `coverage.days[]` **fans out** to one
  SiteCoverageRequirement row per (card×day). Branch `feat/sites-add-site-modal` off origin/main.
  **PAUSED for approval (interactive).**
- [x] **S1 — Validation foundation.** Added coverage/credentials/shift_templates/geofence/finance/
  total_capacity rule blocks to StoreSiteRequest + UpdateSiteRequest (no migration — cols already
  exist). `tests/Feature/Sites/AddSiteModalValidationTest.php` (14 tests/29 assertions, green); Pint
  clean. Commit `90f1d77f`.
- [x] **S2 — Controller fan-out + index props.** `store()` now wraps everything in `DB::transaction`
  and fans out coverage (days→rows, roles map→role_requirements), credentials→SiteStaffRequirement
  (unique-respected), shift templates→RosterTemplate "{Site} default week" (Option A, 7 day-rows/
  template, identity FKs null), finance dollars→cents, circle AssetGeofence (mirrors
  SiteGeofenceController scope/shape, coords-gated). `total_capacity` added to Site $fillable. Index
  exposes `addSite` reference props (users, checklistTemplates, availableAssets, regionOptions,
  serviceContexts, copyableSites+coverage/credentials, credentialCatalogue, coverageRoleKeys) gated on
  sites.create. New `config/site_credentials.php` (6-item catalogue + role keys).
  `AddSiteModalStoreTest.php` (10 tests/62 assertions, green); 48 existing SiteControllerTest pass;
  Pint clean. Commit `42aeec4e`.
- [x] **S3 — Dialog scaffold + existing steps.** `components/sites/add-site-dialog.tsx` mirrors
  AddClientDialog via the shared `WizardShell`: one Inertia `useForm<SiteWizardForm>` (full payload
  shape), 9 `STEPS`, step switch, `validateStep`/`stepForError`, discard-draft confirm, completeness
  ring, submit `POST /sites` `forceFormData` w/ `_modal` flag. Built Basics (type tiles, name,
  phone/email, lead, active; brand colour intentionally omitted), Location (address + NZ region
  auto-derive + access instructions — geofence/autocomplete land in S5), Spaces (type-specific
  rooms/resources/zones + total_capacity), Contacts (typed cards + primary star), Equipment (asset
  checkboxes + checklist toggles w/ frequency + assignee + medication_storage_location), Documents
  (file dropzone + rows). Rostering/Finance/Review are WIP step-heads (filled S4/S6). Mounted on the
  Sites index (hero button now opens the modal; `/sites/create` route kept). Backend: `_modal` branch
  in store() returns back()+`created_site_id` flash; HandleInertiaRequests shares it. Gate: tsc/lint/
  build green; PHP 58 pass (no `_modal` regression). ⚠️ 2 pre-existing vitest failures (my-day
  timesheet routing · app-sidebar Finance-nav) are unrelated/untouched by this slice. Commit `c7f5182d`.
- [x] **S4 — Rostering & coverage step (new).** Built `StepRostering`: copy-from-site Select (clones +
  groups a source's per-day coverage rows back into editable cards + its credentials), 4 quick presets
  (24/7, waking nights, day support, office — verbatim prototype rows), repeatable coverage cards
  (DayChips, coverage/shift-type selects, start/end times, min-staff Stepper, role-mix Steppers,
  **allow_overstaffing toggle**, optional service_context select), required-credentials catalogue chips
  (each → Mandatory/Recommended segmented + expiry months), optional shift-template rows. Produces
  exactly the payload S2 persists (covered by AddSiteModalStoreTest). Gate: tsc/lint/build green.
  Commit `1b75d913`.
- [x] **S5 — Location & geofence step.** Rebuilt `StepLocation` as 2-col: left = `AddressAutocomplete`
  (endpoint `/sites/geocode/search`) autofilling line1/suburb/city/postcode/country/region(+derive) +
  lat/lng, manual address_line_2, NZ region select, access instructions; right = reused
  `GeofenceDrawMap` (circle seeded from lat/lng + radius_m) + 50–500m radius slider (default 120, live
  readout, remounts map on release via mapKey) + breach-type Segmented (enter/exit/both, default both)
  + active Switch; empty-state when no coords. onShapeChange syncs circle centre/radius back to the
  form; circle-only per guardrail (polygon stays a post-create concern). Gate: tsc/lint/build green.
  Commit `87e346b3`.
- [x] **S6 — Property & finance + Review & safety steps.** `StepFinance`: rent amount + frequency
  select, lease start/end (client-side end≥start), landlord name/contact, weekly food budget.
  `StepReview`: two risk toggle-tiles (high-risk critical tone / high-needs warning tone) revealing
  risk notes + review date when on; emergency plan location; site notes; per-section ReviewCard/
  ReviewRow summary (Basics·Location·Spaces·Rostering·Contacts & equipment·Property & finance) each
  with an Edit jump (`goToStep`). Footer Save&add-another + Create + WizardSuccessPane already wired in
  S3. Modal now feature-complete (all 9 steps). Gate: tsc/lint/build green. Commit `e2e0e944`.
- [x] **S7 — Full audit, tests, cutover.** Added `AddSiteModalE2ETest` (24/7-house modal payload →
  21 coverage rows · 3 coverage types · active circle geofence · all 7 critical readiness items done →
  "ready"; 1 test/7 assertions, green). Full gate: tsc/lint/build green; add-site PHP suites all green
  (14+10+1) + SiteControllerTest 48 (no regression); Pint clean. Cutover = index hero opens the modal;
  `/sites/create` kept as no-JS fallback. DoD close-out written at the top of PLAN.md.
  ⚠️ Pre-existing/unrelated (proven via `git diff origin/main`): SiteGeofenceTest:70 stale
  `scope==='vehicle'` from commit 5e6546d1 (spawned a background task) + 2 vitest files (my-day,
  app-sidebar) untouched by this work. Remaining = browser pixel-parity + a11y axe sweep on the
  auto-deploy dev site (user; structurally low-risk — same WizardShell/primitives + Leaflet-in-dialog
  already proven by _site-geofence-dialog). Commit `<s7>`.

## Definition of done — reached
All S1–S7 ticked. Modal opens from the Sites index, matches Add Client (shared WizardShell), walks all
9 steps with working validation/discard/completeness/geocode+geofence; `POST /sites` persists the full
payload (coverage role_requirements, credentials, shift templates, finance cents, circle AssetGeofence)
in one transaction; tests added and green; full gate green. Browser visual/a11y sweep → user.
