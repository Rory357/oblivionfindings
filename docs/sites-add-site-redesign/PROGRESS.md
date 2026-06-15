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
  Pint clean. Commit `<s2>`.
- [ ] **S3 — Dialog scaffold + existing steps.** add-site-dialog.tsx (9 steps) mounted on sites index;
  Basics/Location-shell/Spaces/Contacts/Equipment/Documents wired from _wizard.tsx + must-add fields.
- [ ] **S4 — Rostering & coverage step (new).** Copy-from + 4 presets + coverage cards (role mix,
  allow_overstaffing, service_context) + credentials chip-multi + shift-template rows.
- [ ] **S5 — Location & geofence step.** AddressAutocomplete (/sites/geocode/search) + GeofenceDrawMap
  radius/draw + 50–500m slider + breach type + access instructions. Circle-only at create.
- [ ] **S6 — Property & finance + Review & safety steps.** Finance step + review summary/risk/notes +
  Save & add another + success pane.
- [ ] **S7 — Full audit, tests, cutover.** E2E + visual parity + full gate + deprecate /sites/create +
  final summary.
