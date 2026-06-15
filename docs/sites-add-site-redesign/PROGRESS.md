# Add Site Modal — Progress tracker

Loop tracker for the slice-by-slice build. On each run: read this, do the first unchecked slice,
gate it (§7), commit, tick it. Plan: [PLAN.md](PLAN.md).

- [x] **S0 — Audit & plan.** Deep frontend+backend audit done; `PLAN.md` written with the three
  decisions: (1) **no migration** — phase4 already added coverage cols + total_capacity +
  weekly_food_budget_cents all exist; (2) shift-templates = **Option A** RosterTemplate "{Site}
  default week" (identity FKs nullable, so no fabrication); (3) `coverage.days[]` **fans out** to one
  SiteCoverageRequirement row per (card×day). Branch `feat/sites-add-site-modal` off origin/main.
  **PAUSED for approval (interactive).**
- [ ] **S1 — Validation foundation.** New rules in StoreSiteRequest + UpdateSiteRequest
  (coverage/credentials/shift_templates/geofence/finance/total_capacity). No migration. Unit tests.
- [ ] **S2 — Controller fan-out + index props.** Transactional store fan-out (coverage rows,
  credentials, shift templates, finance cents, circle AssetGeofence) + index reference props +
  config/site_credentials.php. Feature tests.
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
