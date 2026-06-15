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
  timesheet routing · app-sidebar Finance-nav) are unrelated/untouched by this slice. Commit `<s3>`.
- [ ] **S4 — Rostering & coverage step (new).** Copy-from + 4 presets + coverage cards (role mix,
  allow_overstaffing, service_context) + credentials chip-multi + shift-template rows.
- [ ] **S5 — Location & geofence step.** AddressAutocomplete (/sites/geocode/search) + GeofenceDrawMap
  radius/draw + 50–500m slider + breach type + access instructions. Circle-only at create.
- [ ] **S6 — Property & finance + Review & safety steps.** Finance step + review summary/risk/notes +
  Save & add another + success pane.
- [ ] **S7 — Full audit, tests, cutover.** E2E + visual parity + full gate + deprecate /sites/create +
  final summary.
