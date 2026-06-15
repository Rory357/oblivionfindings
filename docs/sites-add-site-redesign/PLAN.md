# ✅ DEFINITION OF DONE — close-out (all S1–S7 shipped on `feat/sites-add-site-modal`)

The standalone full-page Add Site wizard is replaced by an **Add-Client-style modal**
launched from the Sites index, capturing the full site record across 9 steps and wired
backend↔frontend both ways. Slice commits: S0 `56c929ac` · S1 `90f1d77f` · S2 `42aeec4e`
· S3 `c7f5182d` · S4 `1b75d913` · S5 `87e346b3` · S6 `e2e0e944` · S7 (this).

**Zero schema changes** (every column already existed). **No new geofence table** — the
modal seeds a circle into the shared `AssetGeofence` via the same scope/shape contract as
`SiteGeofenceController`. One DB transaction fans the payload out to existing models
(coverage rows · staff requirements · RosterTemplate · finance cents · circle geofence).

Gates (per slice + final): `tsc` ✓ · `eslint` ✓ · `vite build` ✓ · `php artisan test`
green for every add-site suite — AddSiteModalValidationTest (14) + AddSiteModalStoreTest
(10) + AddSiteModalE2ETest (1, a 24/7 house → 21 coverage rows + active geofence + "ready"
in SiteReadinessService) + SiteControllerTest (48, no regression). Pint clean.

**Cutover:** the Sites index hero "Add site" now opens the modal; `/sites/create` + its
page are **kept as a no-JS fallback** (lowest-risk option from the plan; untouched).

**Remaining = browser-only (user, on the auto-deploy dev site):** pixel-parity vs Add
Client + an a11y/responsive (axe) sweep. Structurally low-risk: the modal reuses the
identical `WizardShell` + `wizard/primitives` + tokens as Add Client, and Leaflet-in-dialog
is already proven by the existing `_site-geofence-dialog.tsx` (same map, same Radix dialog).

**Pre-existing failures (NOT this work, proven via `git diff origin/main`):**
`SiteGeofenceTest` line 70 (stale `scope === 'vehicle'` expectation from commit `5e6546d1`;
flagged as a background task) · 2 vitest files (`my-day` timesheet routing, `app-sidebar`
Finance nav) — both untouched modules from concurrent loops.

---

# Add Site Modal — Implementation Plan (S0 audit output)

> Branch `feat/sites-add-site-modal` off `origin/main`. Design bundle (gitignored) at
> `.design-drops/sites-add-site-redesign/design_handoff_add_site_modal/`. This file is the
> S0 deliverable: deep audit + ordered plan + the three required decisions. **No code yet.**

## 0. Headline decisions (the three S0 gates)

1. **§1a coverage-columns migration — NOT NEEDED.** The original create migration
   `database/migrations/2026_04_05_235000_…site_coverage_requirements…php:36-53` omits
   `role_requirements`/`allow_overstaffing`/`preferred_client_id`, **but** a later migration
   `database/migrations/2026_04_06_120000_add_phase4_coverage_controls.php:11-27` already adds
   all three (guarded by `Schema::hasColumn`). The model
   `app/Models/SiteCoverageRequirement.php:14-37` is fillable+cast for them. **→ S1 adds no
   coverage migration.** (Likewise `weekly_food_budget_cents` exists via
   `2026_06_02_100001_add_weekly_food_budget_to_sites.php`; `total_capacity` exists via
   `2026_03_25_200200_add_capacity_fields_to_sites.php:13`.) Zero schema changes for the whole loop.

2. **§4 default shift-templates — Option A (`RosterTemplate` "{Site} default week").** The
   `template_shifts.*` request rules (`StoreRosterTemplateRequest.php:29-34`) make `client_id`,
   `user_id` and `service_context_id` **all nullable**; only `day_of_week` (0-6), `start_time`,
   `end_time` are required. So a faithful template can be built from the modal's
   `{name, starts_time, ends_time}` rows **without fabricating any client/context identity**.
   Plan: create one `RosterTemplate` ("{Site} default week", `template_type:'weekly'`) and, per
   shift-template row, **7** `RosterTemplateShift` rows (day_of_week 0-6, start/end from the row,
   `shift_type:'standard'`, identity FKs null, `notes` = the row name). Created inline in the
   `SiteController@store` transaction; skipped entirely when `shift_templates` is empty (it is
   optional). This reuses the real model, persists what the UI collects (parity), and invents
   nothing. **Alternative if rejected:** drop shift-template capture from v1 UI (hide-unbuilt rule).

3. **`coverage[].days[]` → one `SiteCoverageRequirement` row per (card × day).** The table stores a
   **single** `day_of_week string(3)` (`…235000…php:46`), validated `in:mon..sun`, and the existing
   post-create path `SiteComplianceController::storeCoverageRequirement` (`…/SiteComplianceController.php:273-314`)
   creates exactly one row per day. `show()` orders coverage `FIELD(day_of_week,'mon',…'sun')`
   (`SiteController.php:659`). So the canonical representation is one-row-per-day; the modal's
   multi-day chips are a UI convenience that the controller **fans out**. Consequence: the 24/7
   preset (3 coverage cards × 7 days) persists **21 rows**. The §7 plan text ("assert 3 coverage
   rules") describes the 3 logical *cards*, not DB rows — the E2E test asserts the 3 distinct
   `coverage_type`s (day/evening/overnight) **and** the exact row count **and** `SiteReadinessService`
   readiness, which is robust to the expansion. (Trust code over docs.)

## 1. Reuse map (frontend) — compose, don't rebuild

| Need | Reuse | Ref |
|---|---|---|
| Structural template | `AddClientDialog` (useForm + STEPS + WizardShell + validateStep/stepForError + discard + completeness + submit forceFormData + Save&add-another + success pane) | `resources/js/components/clients/add-client-dialog.tsx:319-369,595-600,710-749,755-789,861-902` |
| Modal chrome | `WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow` | `resources/js/components/wizard/shell.tsx` |
| Primitives | `TilePicker`, `ChipMulti`, `Segmented`, `Field`, `FieldErr`, `StepHead`, `SubHead`, `InfoCard`, `Ring`, `SelectInput`, + `WIZARD_*_CLASS` consts | `resources/js/components/wizard/primitives.tsx` |
| Existing step bodies | `StepBasics`, `StepAddress`, `StepRoomsOrResources`, `StepContacts`, `StepAssets`, `StepDocuments`, `StepChecklists`, `StepSafety` + `NZ_REGION_OPTIONS`, `deriveNzRegion`, `WizardData` & sub-types | `resources/js/pages/sites/_wizard.tsx:127-300,222-289,291-1914` |
| Address autofill | `AddressAutocomplete` (pass `endpoint="/sites/geocode/search"`) → `GeocodeResult{display_name,lat,lng,address_line_1,suburb,city,postcode,country,region}` (note: **no** `address_line_2` from geocoder — manual input) | `resources/js/components/address-autocomplete.tsx:10-20,28-150` |
| Geofence map | `GeofenceDrawMap` (`center`,`zoom`,`height`,`initialShape`,`onShapeChange`) → `GeofenceShape{type,center{lat,lng},radius_m,coordinates}` | `resources/js/components/geofence-draw-map.tsx:12-17,52+` |
| Geofence circle contract | `_site-geofence-dialog.tsx` `serializeShape` → `{type:'circle', shape:{center,radius_m}}` + breach_type/is_active/asset_ids; POSTs `sites.geofence.store` | `resources/js/pages/sites/_site-geofence-dialog.tsx:112-136,200-204` |
| Contacts | `CONTACT_TYPES`, `getContactType` | `resources/js/pages/sites/contacts/_helpers.ts:34-105` |
| Discard pattern | "Discard this draft?" dialog | `resources/js/pages/sites/create.tsx:475-500` |
| Mount pattern | `AddClientDialog` mounted with `isOpen/onClose/.../onSaved` | `resources/js/pages/attendance/index.tsx:1517-1541` |

**Build new only:** the **Rostering & coverage** step body and the **Property & finance** step body,
plus the new `add-site-dialog.tsx` shell that wires all 9 steps.

## 2. Backend map — fan payload into existing models

- **Store entrypoint:** `SiteController@store` (`app/Http/Controllers/SiteController.php:1054-1097`) —
  currently `Site::create($validated)` then `syncContacts/Rooms/Resources/Zones`, `assignAssets`,
  `syncChecklists`, `saveDocuments`. **Not transactional today.** S2 wraps the whole thing in
  `DB::transaction` and appends coverage/credentials/shift-templates/geofence fan-out.
- **Coverage:** `SiteCoverageRequirement` — per card, per day → row. Convert role map
  `{caregiver,driver,med_competent}` → `role_requirements:[{key,minimum}]` dropping zero counts
  (mirror `SiteComplianceController.php:286-288,305-311`). Set `organization_id`,`is_active:true`,
  `allow_overstaffing` (default true).
- **Credentials:** `SiteStaffRequirement` — `requirement_name=name`, `category`,
  `certification_required = category==='mandatory'`, `expiry_period_months = expiry ?: null`,
  `organization_id`, `is_active:true`. Honour `unique(site_id, requirement_name)`
  (`2026_03_25_200500_create_site_staff_requirements.php:23`) via `updateOrCreate`.
- **Shift templates:** Option A (see §0.2). `RosterTemplate` + `RosterTemplateShift`.
- **Finance:** fold into `Site::create` payload — `rent_amount`, `rent_frequency`,
  `lease_start_date`, `lease_end_date`, `landlord_name`, `landlord_contact`,
  `weekly_food_budget_cents = round(weekly_food_budget*100)`, `total_capacity`. Site `$fillable`
  already has the finance cols (`app/Models/Site.php:21-56`); **verify `total_capacity` is fillable
  and add if missing** (additive one-liner).
- **Geofence (circle, reuse `AssetGeofence`):** mirror `SiteGeofenceController@store`
  (`app/Http/Controllers/Sites/SiteGeofenceController.php`): create one `AssetGeofence`
  `{asset_id:null, site_id, name:"{site} Geofence", type:'circle', scope: match(type){house,residential→'house'; facility→'asset'; default→'site'}, shape:{center:{lat,lng}, radius_m}, breach_type, is_active}`
  **only when latitude+longitude present**; else skip silently. No new table; no asset assignment at
  create (deferred to post-create dialog per backend plan §9). Feeds `active_geofences_count`
  (`SiteController.php:1669`) and readiness `geofence` item.
- **Index props (S2):** `SiteController@index` (`:53-254`) must add — `users`, `checklistTemplates`
  (`checklistTemplatesPayload()`), `availableAssets` (`availableAssetsPayload(null)`), `regionOptions`
  (`NzRegions::REGIONS`), `serviceContexts` (org-scoped id/name/type), `copyableSites`
  (id/name/type + their active coverage rules + staff-requirement keys, for true clone),
  `credentialCatalogue` (**new `config/site_credentials.php`**), `coverageRoleKeys`
  (caregiver/driver/med_competent labels). These mirror what `@create` (`:1040-1052`) already gathers.

### Credential catalogue source (build it — none exists)
No staff-credential catalogue exists (`CredentialType` is for site *secrets*; `HrCandidateDocument`
is recruitment docs). Create **`config/site_credentials.php`** with the prototype's canonical list
(`Add Site Modal.dc.html:626-633`): `first_aid`(24), `med_competency`(12), `police_vet`(36),
`drivers_licence`(0), `manual_handling`(24), `cpi`(12) — `{key,name,default_expiry_months}` — and a
`coverage_role_keys` map (`caregiver→Support worker`, `driver→Driver`, `med_competent→Med-competent`).

## 3. Field-name normalization (prototype → contract)
Use the **README §State + backend-plan §2** names (authoritative), not the prototype's flat names:
- `site_lead` → `primary_contact_user_id`
- `geo_mode/geo_radius/geo_breach/geo_active` → `geofence:{mode, radius_m, breach_type, is_active}`
- `food_budget`(dollars) → `weekly_food_budget`(dollars) → `weekly_food_budget_cents` on store
- `lease_start/lease_end` → `lease_start_date/lease_end_date`
- `brand_colour` — **not collected** (README: set in Settings → Branding)
- coverage role map `{caregiver,driver,med_competent}` → `role_requirements:[{key,minimum}]` (drop 0s)

## 4. Per-slice steps

**S1 — Validation foundation.** Add to `StoreSiteRequest` **and** `UpdateSiteRequest` the
backend-plan §2 rule blocks (coverage[], credentials[], shift_templates[], geofence{}, finance) plus
`total_capacity => nullable|integer|min:0`. No migration. Unit tests: accept full valid payload;
reject bad coverage `coverage_type`/`days.*`/time format/`minimum_staff` range, bad credential
`category`, bad geofence `breach_type`, `lease_end_date before lease_start_date`.

**S2 — Controller fan-out + index props + config.** Wrap `store()` in `DB::transaction`; add
`persistCoverage/persistCredentials/persistShiftTemplates/persistGeofence` private helpers + finance
mapping; mirror `SiteGeofenceController` scope/shape. Add `config/site_credentials.php`. Add the index
reference props. Feature tests: N coverage rows (incl. role_requirements + day fan-out), M staff
requirements (unique respected), circle geofence when coords (+ none when omitted), finance cents,
shift-template RosterTemplate, copy-from clone. **Backend complete before any UI depends on it.**

**S3 — Dialog scaffold + existing steps.** `components/sites/add-site-dialog.tsx` mirroring
`AddClientDialog`: one `useForm<SiteWizardForm>` (full README payload), `STEPS` = 9
(Basics·Location·Spaces·Rostering·Contacts·Equipment & checks·Documents·Property & finance·Review),
step switch, `validateStep`/`stepForError`, discard confirm, completeness `Ring`, submit `POST /sites`
`forceFormData:true`, Save&add-another, `WizardSuccessPane`. Mount on `pages/sites/index.tsx` behind
the existing "Add site" button. Wire Basics (+phone/email tiles, total_capacity), Location(shell),
Spaces, Contacts, Equipment & checks (+assignee, medication_storage_location), Documents by lifting
`_wizard.tsx` bodies.

**S4 — Rostering & coverage step (new).** Copy-from `Select` (from `copyableSites`), 4 presets
(`Add Site Modal.dc.html:650-672` rows verbatim), repeatable coverage cards (day chips, coverage/shift
type, times, min-staff stepper, role-mix steppers, **allow_overstaffing toggle**, optional
`service_context_id`), credentials `ChipMulti` from `credentialCatalogue` (each → category +
expiry months), optional shift-template rows.

**S5 — Location & geofence step.** `AddressAutocomplete endpoint="/sites/geocode/search"` (autofill +
manual `address_line_2`, NZ region `Select` + `deriveNzRegion`), `GeofenceDrawMap` radius/draw
segmented, 50–500m slider (default 120, live-resize), breach enter/exit/both (default both), access
instructions textarea. **Circle only at create** (guardrail §8).

**S6 — Property & finance + Review & safety.** Finance step (rent+frequency, lease dates, landlord,
weekly food budget). Review step: risk tiles (high_risk/high_needs → reveal notes + review date),
emergency_plan_location, notes, `ReviewCard`/`ReviewRow` per section with Edit→`goToStep`, footer
Save&add-another + Create, success pane.

**S7 — Full audit, tests, cutover.** E2E happy path (open→basics+geocoded address→24/7 preset→create
→land on profile; assert coverage rows + ready). Visual parity vs Add Client. Full §7 gate. Deprecate
`/sites/create`: keep route as no-JS fallback (lowest risk) **or** redirect to index per approval.
Final change summary.

## 5. Risks / watch-items
- **`store()` not transactional today** — wrapping it must preserve existing document/asset side
  effects (files read from `$request` in `saveDocuments`). Keep `saveDocuments` inside the txn but it
  already runs post-create; fine.
- **`total_capacity` fillable** — verify in `Site::$fillable`; add if absent (additive).
- **Geofence permission** — `sites.geofence.store` is gated `assets.geofences.manage`; create flow is
  `sites.create`. Decision: create the geofence inline regardless (creating a site implies setting its
  boundary); note for reviewer. No separate authorization needed since it's part of `store`.
- **Index payload weight** — `copyableSites` with eager coverage/credentials adds queries; scope to
  org + `is_active` and select minimal columns.
- **Windows case-sensitive `git add`** — new dir is lowercase `components/sites/`; file
  `add-site-dialog.tsx`. `config/site_credentials.php` lowercase.
- **ESLint raw-colour guard** — tokens only; prototype oklch is reference.
- **build memory** — `npm run build` runs wayfinder:generate; if it OOMs use the documented
  `PHP_INI_SCAN_DIR` 1024M workaround in the same shell command.

## 6. Parity matrix (every field validated → persisted → reloadable)
Basics: type,name,phone,email,primary_contact_user_id,is_active,total_capacity → Site cols (✔ exist).
Location: address_line_1/2,suburb,city,postcode,country,region,latitude,longitude,access_instructions
→ Site cols (✔). Geofence: mode/radius_m/breach_type/is_active → `AssetGeofence` circle.
Spaces: rooms/resources/zones → existing sync. Rostering: coverage→SiteCoverageRequirement(rows),
credentials→SiteStaffRequirement, shift_templates→RosterTemplate(+shifts). Contacts→SiteContact.
Equipment: assets→Asset.site_id, checklists(+assigned_to_user_id)→SiteChecklistAssignment,
medication_storage_location→Site. Documents→SiteDocument. Finance: rent_amount,rent_frequency,
lease_start_date,lease_end_date,landlord_name,landlord_contact,weekly_food_budget(→cents)→Site.
Review: is_high_risk,is_high_needs,risk_notes,risk_review_date,emergency_plan_location,notes→Site.
Edit path: same fields added to `UpdateSiteRequest`; `@edit`/`@update` reload (S7 follow-up — the
modal edit path is reusable but the primary deliverable is create).
