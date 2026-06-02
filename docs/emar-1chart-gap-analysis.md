# eMAR Gap Analysis — OblivionFindings vs. 1CHART (Toniq)

**Date:** 2026-06-02
**Author:** Gap analysis for the OblivionFindings eMAR (NZ supported‑living CRM)
**Source material:** *1CHART Manual for Care Staff v1.34* (Toniq Limited) + <https://toniq.nz/products/1chart/>
**Goal:** Make the OblivionFindings eMAR comprehensive and complete for the NZ supported‑living sector by closing the gaps against 1CHART — **without re‑architecting** the existing (already strong) medication module. Every item below is **additive** and follows existing conventions.

---

## 1. Executive summary

OblivionFindings already has a **very comprehensive eMAR** and in several respects **exceeds** 1CHART (shift handovers with controlled‑drug counts, covert authorisation under the PPPR Act, destruction/wastage with dual witnesses, pharmacy re‑ordering, NZULM codes, break‑glass, CD loss reports, guided rounds, PRN‑effectiveness reviews, order versioning, correction approvals, competency & self‑admin assessments, and specialist administration for insulin/inhaler/topical).

After verifying the code directly, the **genuine gaps** against the 1CHART care‑staff workflow are a focused set of **10 additive features**. None require re‑architecture; all fit the existing model/controller/route/permission/React conventions.

**The 10 gaps (priority order):**

| # | Gap | 1CHART ref | Risk if unaddressed | Effort |
|---|-----|-----------|---------------------|--------|
| 1 | Standardised **"Reason Not Given"** codes (currently free text) | §5.4 | Audit/reporting inconsistency | S |
| 2 | Client **Attention Bar** / chart warnings (warfarin, paper script, "other") + prompt‑on‑open + **disable med‑admin alerts** toggle | §3.2, §4.3, Dashboard | Safety‑critical info not surfaced | M |
| 3 | **Warfarin / INR** monitoring (INR history, review‑date alerts) | §5.2 "Recording INRs" | High‑risk anticoagulant unmanaged | M |
| 4 | **Facility medication rules**: configurable countersigning (by name/route) + **observation‑on‑sign‑off** prompts (BSL/pulse/BP) | §5.2, §6.1 | Policy & monitoring not enforceable | M |
| 5 | **Witness e‑signature** (countersign re‑authentication at point of administration) | §5.2 Controlled Drugs | Witness recorded without authenticating | S–M |
| 6 | **Syringe driver** administration (multi‑drug, time commenced, checks) | §5.2 Syringe Drivers | Palliative workflow missing | M |
| 7 | **Medication order verification/approval gate** before administration | Chart colour states (blue/yellow→white) | Unverified orders administrable | M |
| 8 | **Chart review cadence + 7‑day alerting** + review schedule report | Dashboard, §10.1 | Reviews slip without prompts | S–M |
| 9 | **Reporting depth** + **Pharmac therapeutic group** classification + care‑level filter | §7 | Limited usage/compliance reporting | M |
| 10 | *(Backlog/optional)* Inter‑site chart transfer; nightly encrypted PDF backup; pill‑picture UI | §3.6, §9 | Lower relevance to supported living | varies |

> **S** ≈ ≤1 day, **M** ≈ 2–4 days for one engineer including tests.

---

## 2. Methodology

1. Read the full 1CHART **Care Staff Manual v1.34** (65 pp) and the public product page.
2. Mapped every 1CHART care‑staff feature to the OblivionFindings codebase via direct file reads (models, migrations, routes, services, enums, seeders) — not assumptions.
3. Classified each feature as **Exists / Partial / Missing** with code evidence.
4. Translated only the *true* gaps into additive PRs that match existing patterns.

**Key code anchors confirmed:**

- `app/Models/ClientMedication.php`, `app/Models/ClientMedicationAdministration.php`, `app/Models/MedicationRound.php`, `app/Models/MedicationReview.php`, `app/Models/MedicationPrnEffectiveness.php`, `app/Models/MedicationCovertAuthorisation.php`
- `database/migrations/2026_03_26_000001_create_comprehensive_emar_tables.php` (rounds, prescriber orders, reviews, self‑admin, competency, destructions, handovers, pharmacy orders, covert, PRN effectiveness; adds `covert`, `self_administered`, `barcode`, `nzulm_code`, `photo_path` to `client_medications`)
- `routes/emar.php` (full eMAR surface), `app/Services/EnhancedMarService.php` (administration orchestration), `app/Domain/Clinical/Enums/ObservationType.php`
- `config/medications.php` (only MAR timing windows today)
- `database/seeders/RbacSeeder.php` (medication permission keys) + `database/seeders/ClinicalPermissionsSeeder.php` (per‑module permission seeder pattern)

---

## 3. Parity matrix — 1CHART feature → OblivionFindings status

> **Legend:** ✅ Exists · 🟡 Partial · ❌ Missing

### 3.1 Access, dashboard, patients

| 1CHART feature | Status | Evidence / Notes |
|---|---|---|
| Account activation, login, password reset | ✅ | Laravel auth + existing user management |
| Security challenge‑response "electronic signature" on login | 🟡 | Auth exists; the *witness* e‑signature at administration is the real gap → **PR 5** |
| Dashboard alerts (review due, re‑ordered, stopped, PRN effectiveness, pharmacy messages) | ✅ | `MedicationDashboardAlert`, notifications, `SendMedicationAlerts` |
| Patient list / search / history / discharge / reactivate | ✅ | Client module |
| **Disable Med Admin Alerts** per patient | ❌ | No client flag → **PR 2** |
| **Transfer patient between facilities (carry chart, re‑verify allergies)** | ❌ | → **PR 10 (backlog)** |
| Allocate patient's doctor / GP | ✅ | `ClientMedicalProfile` (gp_name/practice/phone) |
| Care Level field (for report filtering) | 🟡 | `risk_level` exists; explicit care‑level filter → **PR 9** |

### 3.2 Patient profile & chart

| 1CHART feature | Status | Evidence / Notes |
|---|---|---|
| Allergies/intolerances/ADRs (suspected vs verified) | ✅ | `MedicationAllergy`, `ClientMedicalProfile.allergies` |
| **Attention bar / chart warnings + prompt‑on‑open** | ❌ | No client‑level med‑chart warning fields → **PR 2** |
| **Paper prescription flag** (un‑charted script) | ❌ | → **PR 2** |
| Medicine Chart (read‑only) + colour states | 🟡 | States are `active/paused/ceased` only; no pre‑admin **approval** state → **PR 7** |
| Stopped medication + removal (RN‑only, confirm pack received) | ✅ | `cease()` + `MedicationDestruction` + alerts |
| MAR, select day, last‑24h view, re‑order | ✅ | `EnhancedMarService`, `MedicationPharmacyOrder` |
| Print medicine chart (PDF) | ✅ | `EmarPdfController` (mar, CD register, round sheet) |

### 3.3 Administering medication

| 1CHART feature | Status | Evidence / Notes |
|---|---|---|
| 5+3 rights checks at administration | ✅ | `SafetyCheckPanel` + `MedicationSafetyService` |
| Regular / short‑course / non‑packed | ✅ | `ClientMedicationAdministration` |
| **Not Given with standardised reason codes** | 🟡 | `reason` is free text → **PR 1** |
| PRN + min‑interval/max‑per‑day enforcement | ✅ | `isPrnOverLimit()`, `MedicationSafetyService` |
| PRN effectiveness follow‑up | ✅ | `MedicationPrnEffectiveness` |
| Controlled drugs (two‑staff) | ✅ | `ClientControlledDrugEntry`, `medications.controlled.witness` |
| **Witness re‑authenticates (enters own credentials)** | ❌ | `EnhancedMarService:457` sets `witnessed_by` from request, no `Hash::check` → **PR 5** |
| **Configurable countersigning (name/route keyword)** | ❌ | `config/medications.php` has no such rules → **PR 4** |
| **Syringe drivers** | ❌ | No fields/section → **PR 6** |
| Dose ranges / variable (VAR) doses | ✅ | `dose_given`, `dose_amount` |
| Administering late medication | ✅ | `missed`/late state machine in `EnhancedMarService` |
| **Recording BSLs** | ✅ | `blood_glucose_level`, `insulin_units_given` on administration |
| **Recording Pulse (e.g. digoxin) at sign‑off** | ❌ | No pulse field/prompt → **PR 4** |
| **Recording INRs (warfarin)** | ❌ | No INR type/table → **PR 3** |
| Covert administration | ✅ *(exceeds 1CHART)* | `MedicationCovertAuthorisation` |

### 3.4 Settings, reports, admin

| 1CHART feature | Status | Evidence / Notes |
|---|---|---|
| Facility countersigning settings | ❌ | → **PR 4** |
| Individual & facility usage reports (PRN, short course, regular, syringe, BSL/pulse) | 🟡 | MAR/discrepancy CSV exist; per‑category usage reports → **PR 9** |
| **Chart Review report** (upcoming reviews) | ❌ | → **PR 8** |
| **Pharmac therapeutic groups** in reports | ❌ | NZULM code exists; therapeutic group missing → **PR 9** |
| User management & roles | ✅ | RBAC + roles |
| Medicine chart review cadence (1/12, 3/12) + 7‑day alert | 🟡 | `MedicationReview` + `review_date` exist; no recurring auto‑alert → **PR 8** |
| Manual + **nightly emailed encrypted PDF backup** | 🟡 | PDF export exists; scheduled encrypted email backup → **PR 10 (backlog)** |

### 3.5 Already built — **do not rebuild**

PRN effectiveness · covert authorisation · destruction/wastage (dual witness) · pharmacy re‑ordering · shift handover (with CD counts) · break‑glass / emergency access · CD loss reports · guided rounds · order versioning · correction approvals · competency assessments · self‑admin assessments · medication errors · drug‑interaction & allergy safety gates · audit logging · MAR/CD‑register/round‑sheet PDFs · NZULM codes · per‑med `photo_path` · offline support (`emar-offline.ts`).

---

## 4. Cross‑cutting conventions (apply to every PR)

These reflect existing repo patterns and project rules — Codex must follow them:

1. **Migrations** — date‑prefixed in `database/migrations/`; use `Schema::hasColumn` guards (as the comprehensive eMAR migration does) so re‑runs are safe; explicit short index names (MySQL 64‑char limit, e.g. `cma_*`); `nullOnDelete()`/`cascadeOnDelete()` as appropriate. **Backfill existing rows** in the same migration where a new state/flag changes behaviour (esp. PR 7).
2. **Models** — `App\Models\`; add `use AuditableChanges;` and `SoftDeletes` where the model is audit‑critical (matches `ClientMedication*`).
3. **No hard deletes** of clinical records — disable/supersede instead (mirror `MedicationReview`, INR "disable not delete" in PR 3).
4. **Permissions are *seeded*, not migrated** — add new keys to a `*PermissionsSeeder` (extend `ClinicalPermissionsSeeder` or add a `MedicationSettingsPermissionsSeeder`) using the `Permission::firstOrCreate` + role‑sync pattern. **Deploys skip seeders**, so each PR that adds a permission must (a) call out *"run `php artisan db:seed --class=…PermissionsSeeder --force` on deploy"* in its description, and (b) ensure code path **degrades gracefully** if the permission row isn't present yet. *(There is no super‑admin bypass in `canDo`.)*
5. **Inertia flash errors** — controllers returning `back()->with('error', …)` trigger Inertia's `onSuccess` (it lands in `flash.error`, not `props.errors`); gate success UI on `!flash.error` (see `reference_inertia_flash_error`).
6. **Frontend** — Inertia + React + TS in `resources/js/pages/emar/…` and components in `resources/js/components/medications/…`; controller bindings in `resources/js/actions/App/Http/Controllers/Emar/…`. **Full‑width layout** (no centred `max-w-[1400px]` cap). Reuse the **sites‑module pattern**: rounded card grid + dialog CRUD, and **Send‑Kudos‑style tile pickers** for type selection (reason codes, alert types, syringe contents).
7. **Ship working features, not stubs** — no "coming soon" toasts or buttons without a backend (`feedback_hide_unbuilt_actions`).
8. **NZ context** — terminology and regulation: NZULM codes, Pharmac therapeutic groups, Medsafe *Medicines Care Guides for Residential Aged Care*, HDC Code of Rights, PPPR Act (covert). Use NZ spelling ("authorise", "whānau").
9. **Tests** — each PR adds Feature tests under `tests/Feature/` (mirror `tests/Feature/HealthSafety/*` style) covering permission gating, happy path, and the key guard (e.g. witness ≠ administrator, can't administer unverified order).
10. **Routes** — extend `routes/emar.php`; keep CRUD under the existing `permission:medications.orders.manage` group unless a new permission is warranted.

---

## 5. PR‑by‑PR implementation plan

Each PR is independently shippable. Suggested order is top‑to‑bottom; dependencies are noted.

---

### PR 1 — Standardised "Reason Not Given" codes

**Objective:** Replace/augment the free‑text `reason` on administrations with the NZ‑standard structured reason set so reporting and audits are consistent.

**1CHART ref:** §5.4 *Definitions of Reasons for Not Given*.

**Why (NZ):** Medsafe Medicines Care Guides expect a defined reason taxonomy; consistent codes power the usage reports in PR 9 and ARC/auditor reviews.

**Backend**
- New PHP enum `app/Enums/Medication/NotGivenReason.php` (string‑backed) with cases + `label()` + `requiresDetail(): bool`:
  `absent, destroyed, doctors_instruction, fasting, transferred, refused, social_leave, hospitalised, medication_unavailable, vomit_or_nausea, self_administered, withheld, other`.
- Migration: add `reason_code` (nullable string) to `client_medication_administrations`, **after** `reason`; keep `reason` for free‑text detail (used when `other` / `requiresDetail`).
- `ClientMedicationAdministration`: add `reason_code` to `$fillable`; cast to the enum.
- Validate in `EnhancedMarService`/controller: when `status ∈ {refused, withheld, missed→not_given}`, `reason_code` required; when `other`, free‑text `reason` required.

**Frontend**
- `RecordAdministrationDialog`: a Send‑Kudos‑style tile/select of reasons; "Other" reveals a free‑text field. Show the human label on the MAR cell tooltip.

**Permissions:** none new.

**Tests:** reason required for not‑given statuses; `other` requires detail; label rendering.

**Acceptance criteria**
- Recording "Not Given" forces a structured reason; "Other" forces detail.
- Existing free‑text rows remain readable (no backfill required; `reason_code` null = legacy).

**Dependencies:** none. **Effort:** S.

---

### PR 2 — Client Attention Bar, chart warnings & "disable med‑admin alerts"

**Objective:** Surface safety‑critical, client‑level medication warnings on the chart/MAR, with an optional pop‑up when the chart is opened, plus a per‑client toggle to suppress med‑due alerts.

**1CHART ref:** §3.2 (Disable Med Admin Alerts), §4.3 (Attention bar: Warfarin, Paper Prescription, Other; "Prompt when viewing patient"), Dashboard (paper‑prescription alert).

**Why (NZ):** The attention bar is how 1CHART communicates "this resident is on warfarin / has a paper script / read this first" to every administering staff member — a core medicines‑safety communication channel.

**Backend**
- New table `client_medication_alerts`: `id, client_id, type (warfarin|paper_prescription|other), title, detail (text, nullable), prompt_on_open (bool, default false), enabled (bool, default true), created_by, resolved_by (nullable), resolved_at (nullable), timestamps`. Index `[client_id, enabled]`. `AuditableChanges` + `SoftDeletes`.
- New model `ClientMedicationAlert` (belongsTo client/creator).
- Add to `clients` (or a small `client_emar_settings` row — match conventions): `suppress_med_admin_alerts` (bool, default false), `med_alerts_suppressed_reason` (string, nullable), `med_alerts_suppressed_by`, `med_alerts_suppressed_at`.
- `EnhancedMarService` / med‑due alert engine (`SendMedicationAlerts`, `MedicationDashboardAlert`): skip clients where `suppress_med_admin_alerts = true`. Add a **paper‑prescription** banner line to the MAR payload when an enabled `paper_prescription` alert exists, and emit a dashboard alert.
- Controller actions on `EmarController` (under `medications.orders.manage`): `storeAlert`, `updateAlert`, `resolveAlert`, plus `toggleMedAlertSuppression`.
- Routes in `routes/emar.php`.

**Frontend**
- An **Attention Bar** component at the top of the client MAR/EMAR view rendering enabled alerts (warfarin pill, paper‑script chip, "other" warnings).
- A **prompt‑on‑open** modal (`prompt_on_open = true`) shown the first time the chart loads in a session — "OK to proceed".
- Manage‑alerts dialog (tile picker for type) and a "Suppress med‑admin alerts" switch with required reason.

**Permissions:** reuse `medications.view` (see) and `medications.orders.manage` (manage). Suppression toggle gated by `medications.orders.manage`.

**Tests:** alert CRUD + permission gating; prompt‑on‑open flag in payload; suppression removes client from med‑due alert set; paper‑prescription banner appears on MAR + dashboard.

**Acceptance criteria**
- Enabled alerts render on the chart; `prompt_on_open` pops once per chart open.
- Paper‑prescription alert shows on MAR and dashboard until resolved.
- Suppressing alerts hides med‑due alerts for that client and is fully audited.

**Dependencies:** none (PR 3 reuses the `warfarin` alert type). **Effort:** M.

---

### PR 3 — Warfarin / INR monitoring

**Objective:** Track INR results and warfarin review dates with history and alerting — the highest‑risk gap clinically.

**1CHART ref:** §5.2 *Recording INRs*; warfarin attention + medicine‑review alert.

**Why (NZ):** Warfarin is a top contributor to medication harm; ARC standards require INR monitoring and timely review. 1CHART treats INR specially (history, disable‑not‑delete, review alerting).

**Backend**
- New table `client_inr_records`: `id, client_id, client_medication_id (nullable), inr_value (decimal 3,1), target_range_low (decimal, nullable), target_range_high (decimal, nullable), dose_mg (decimal, nullable), tested_on (date), next_test_date (date, nullable), recorded_by, disabled_by (nullable), disabled_at (nullable), notes, timestamps`. **No hard delete** — `disabled_at` only (matches 1CHART: "INR records cannot be deleted, only disabled"). Index `[client_id, tested_on]`.
- New model `ClientInrRecord` (`AuditableChanges`; scope `active()` = `whereNull('disabled_at')`).
- Surface warfarin via PR 2's `warfarin` attention alert type (or a `is_warfarin` convenience flag derived from an enabled alert).
- Alerting: when a warfarin med's `review_date` (already on `ClientMedication`) is within 3 days or a `next_test_date` is due, emit a `MedicationDashboardAlert` (extend `SendMedicationAlerts`).
- `EmarController`: `storeInr`, `disableInr`, `inrHistory` (under `medications.orders.manage` to write; `medications.view` to read). Routes in `routes/emar.php`.

**Frontend**
- INR panel (history table, most‑recent first; disabled rows greyed) accessible from the Attention Bar warfarin chip and the client clinical/medical tab. Add‑INR dialog (value, date, target range, dose, next test).

**Permissions:** reuse `medications.view` / `medications.orders.manage`. (Optionally add `medications.inr.record` if finer control is wanted — if so, follow the seeder + deploy note in §4.4.)

**Tests:** INR create/disable (no delete); review‑due alert fires within window; permission gating.

**Acceptance criteria**
- INR results recorded with target range + dose; history shown newest‑first; entries can be disabled but not deleted.
- Warfarin residents flagged; review/next‑test alerts surface on the dashboard.

**Dependencies:** PR 2 (warfarin alert type) recommended. **Effort:** M.

---

### PR 4 — Facility medication rules: configurable countersigning + observation‑on‑sign‑off

**Objective:** Let a facility/site define rules so that medications matching a name or route keyword (a) **prompt for a countersignature** (beyond controlled drugs) and/or (b) **require a clinical observation at sign‑off** (BSL, pulse, BP). Generalises the existing hard‑coded insulin‑BSL behaviour.

**1CHART ref:** §6.1 (Setting Countersigning Requirements by Medicine Name / Route); §5.2 (Recording BSLs, Recording Pulse).

**Why (NZ):** Facilities set their own dual‑signing and monitoring policies (e.g. digoxin→pulse, insulin→BSL, all IV→countersign). This makes those policies data‑driven instead of code changes.

**Backend**
- New table `medication_admin_rules`: `id, site_id (nullable = global), match_type (medicine_name|route|nzulm_code), match_value (string), requires_countersign (bool, default false), required_observations (json: ["blood_glucose","pulse","blood_pressure"]), active (bool), created_by, timestamps`. Index `[site_id, active]`.
- New model `MedicationAdminRule`.
- New service `MedicationRuleService` with `rulesFor(ClientMedication $m): {countersign: bool, observations: string[]}` (case‑insensitive contains match on name/route/nzulm).
- Add observation columns to `client_medication_administrations`: `pulse_bpm` (int, nullable), `blood_pressure_systolic` (int, nullable), `blood_pressure_diastolic` (int, nullable). (BSL already exists as `blood_glucose_level`.)
- `EnhancedMarService` / `MedicationSafetyService`: before completing an administration, compute required observations + countersign from rules **and** the med's own `witness_required`/`controlled_drug`; block completion until satisfied. Persist readings on the administration **and** mirror into `ClinicalObservation` (so vitals reporting stays unified).
- Facility Settings controller + routes (new permission — see below). Settings UI page under `emar/settings` or the existing settings area.

**Frontend**
- Facility‑settings page: rules table + add/edit dialog (match type tile picker, keyword autocomplete against known med names/routes, toggles for countersign + which observations).
- `RecordAdministrationDialog`: when a rule applies, show the required observation field(s) at sign‑off (after countersign if both apply, matching 1CHART order) and require entry (allow `0` with a note, per 1CHART "type 0 if unable").

**Permissions:** add `medications.settings.manage` (Clinical Lead, Coordinator, Provider Manager, Admin). **Seed it** in `ClinicalPermissionsSeeder` and note the `--force` deploy step (§4.4).

**Tests:** rule matching (name/route/nzulm, case‑insensitive); countersign enforced when rule matches; observation prompt blocks completion until entered; reading persisted + mirrored to `ClinicalObservation`.

**Acceptance criteria**
- A facility can add "Warfarin → countersign", "Digoxin → pulse", "IV → countersign" without code changes.
- Matching meds enforce the rule at administration; readings are stored and appear in vitals.

**Dependencies:** pairs with PR 5 (the countersign step should use the e‑signature). **Effort:** M.

---

### PR 5 — Witness electronic‑signature (countersign re‑authentication)

**Objective:** Require the second checker to **authenticate** (enter their own password/PIN) at the point of administration, rather than merely selecting a witness — a true electronic signature.

**1CHART ref:** §5.2 Controlled Drugs ("the second checker enters their 1CHART username and password").

**Why (NZ):** A countersignature must prove the witness was present and is who they claim. Today `EnhancedMarService:457` records `witnessed_by` from request data with no credential check — an audit/compliance weakness.

**Backend**
- Migration: add to `client_medication_administrations`: `witnessed_at` (timestamp, nullable), `witness_method` (string, nullable: `password|pin`).
- `EnhancedMarService::record…` (and the controlled‑drug path): when a witness is required (controlled drug, `witness_required`, or PR 4 rule), require `witness_id` + `witness_credential`; verify with `Hash::check($credential, $witnessUser->password)`; enforce **witness ≠ administrator** and witness holds `medications.controlled.witness`. Set `witnessed_at = now()`, `witness_method`. Reject (Inertia `back()->with('error', …)`, per §4.5) if verification fails — do **not** record the administration.
- Optional: support a per‑user numeric **medication PIN** (add `med_pin` hashed column to `users`) for faster trolley‑side countersigning; password remains the fallback. (Keep optional to limit scope.)

**Frontend**
- Countersign step in `RecordAdministrationDialog`: witness picker + password/PIN field + clear "Verifying second checker" affordance; surfaces verification errors inline (gate on `!flash.error`).

**Permissions:** none new (uses existing `medications.controlled.witness`).

**Tests:** correct credential records `witnessed_at`; wrong credential rejects and nothing is persisted; witness == administrator rejected; witness without witness permission rejected.

**Acceptance criteria**
- No countersigned administration is recorded unless the witness authenticated.
- Witness identity, method, and timestamp are captured and audited.

**Dependencies:** strengthens PR 4 and PR 6. **Effort:** S–M.

---

### PR 6 — Syringe driver administration

**Objective:** Add the syringe‑driver workflow: combine one or more medications into a driver, record commencement time, witness, rate/duration, and routine checks.

**1CHART ref:** §5.2 Syringe Drivers.

**Why (NZ):** Continuous subcutaneous infusion via syringe driver is standard in palliative/end‑of‑life care common to supported living; absent today.

**Backend**
- New table `medication_syringe_drivers`: `id, client_id, site_id, status (running|completed|stopped), commenced_at, commenced_by, witnessed_by (nullable), witnessed_at (nullable), rate, rate_unit, duration_hours, contents (json: [{client_medication_id, name, dose, unit}]), site_of_insertion (string, nullable), notes, completed_at (nullable), completed_by (nullable), timestamps`. `AuditableChanges`.
- New table `medication_syringe_driver_checks`: `id, syringe_driver_id, checked_at, checked_by, infusion_running (bool), site_condition (string), volume_remaining (string, nullable), notes`. (Routine driver checks.)
- Models `MedicationSyringeDriver`, `MedicationSyringeDriverCheck`.
- `EmarController`: `storeSyringeDriver`, `addSyringeDriverCheck`, `completeSyringeDriver` (under `medications.administer.record`). Countersign on commencement uses **PR 5** when a content med is controlled/witness‑required. Routes in `routes/emar.php`.
- Add a `syringe_driver` value to med `form`/`route` handling so the MAR can group these into their own section.

**Frontend**
- New `emar/SyringeDrivers.tsx` (or a section on the MAR): start‑driver dialog (multi‑med picker for contents, rate, duration, insertion site, countersign), running drivers list with a "record check" action and "complete/stop".

**Permissions:** reuse `medications.administer.record` + `medications.controlled.witness`.

**Tests:** start driver (with/without controlled content); check log append; complete; countersign enforced for controlled contents.

**Acceptance criteria**
- A driver combining ≥1 med is started with time commenced + witness; checks are logged; completion recorded — all audited.

**Dependencies:** PR 5 (countersign). **Effort:** M.

---

### PR 7 — Medication order verification / approval gate

**Objective:** New or changed orders enter an **"awaiting verification"** state and are **not administrable** until an authorised role verifies them — mirroring 1CHART's "blue (pharmacy check) → yellow (prescriber approve) → white (administer)" colour flow, adapted to OblivionFindings' internal roles.

**1CHART ref:** §4.4 Medicine Chart colour key; §5 (cannot administer until white).

**Why (NZ):** A support worker should never administer an order that a nurse/clinical lead hasn't verified. Today `ClientMedication.state` is only `active/paused/ceased` — newly created orders are immediately administrable.

**Backend**
- Migration: add `approval_status` to `client_medications` (`draft|pending_verification|verified|rejected`, default `pending_verification`), `verified_by` (nullable), `verified_at` (nullable), `rejection_reason` (nullable). **Backfill** all existing `state='active'` rows to `approval_status='verified'` in the same migration (so nothing in production becomes un‑administrable).
- `ClientMedication`: add to `$fillable`/casts; extend `scopeActive()` to also require `approval_status='verified'`; add `scopeAwaitingVerification()`; helper `isAdministrable()`.
- `EnhancedMarService`: exclude non‑verified orders from the administrable/MAR‑due set; surface them with an "Awaiting verification" badge (amber) on the chart/MAR — visible but not signable.
- `EmarController`: `verifyMedication`, `rejectMedication` (new permission). Newly created orders default to `pending_verification` unless created by a verifier (then auto‑verify).
- Dashboard: "Medications awaiting verification" count/alert.

**Frontend**
- Amber "Awaiting verification" badge on chart/MAR rows; verify/reject actions on the medications page (verifier roles only); dashboard widget.

**Permissions:** add `medications.orders.verify` (Nurse/Team Lead, Clinical Lead, Coordinator, Provider Manager, Admin). Seed + deploy `--force` note (§4.4). **Degrade gracefully:** if the permission row is missing post‑deploy, fall back to `medications.orders.manage` so verification isn't fully blocked.

**Tests:** new order is `pending_verification` and not in MAR‑due; verify makes it administrable; reject removes it; backfill keeps existing active meds administrable; permission gating.

**Acceptance criteria**
- Un‑verified orders are visible but cannot be administered.
- Existing production orders remain administrable after migration (backfill verified).

**Dependencies:** none (independent of PR 2). **Effort:** M. *(Highest‑care item: test the backfill carefully.)*

---

### PR 8 — Chart review cadence + 7‑day alerting + review schedule report

**Objective:** Turn the existing `MedicationReview` into a *recurring* cadence (1‑monthly / 3‑monthly) that auto‑alerts 7 days before due, and surface per‑med `review_date` (e.g. warfarin) as alerts. Add a Chart Review schedule report.

**1CHART ref:** Dashboard ("Medicine chart due for review", "Medication requiring review"), §10.1 (Initial review period).

**Why (NZ):** Medicines Care Guides require periodic prescriber chart review; staff need timely prompts and a schedule view to plan prescriber visits.

**Backend**
- Add `chart_review_interval_months` (int, default 3) + `next_chart_review_date` (date, nullable) to `clients` (or `client_emar_settings`).
- New scheduled command `CheckMedicationReviews` (register in `routes/console.php`/scheduler alongside `SendMedicationAlerts`): create a `MedicationDashboardAlert`/notification **7 days before** `MedicationReview.scheduled_date`/`next_review_date` and `clients.next_chart_review_date`, and for any `ClientMedication.review_date` within 3 days or overdue.
- On completing a `MedicationReview`, compute the next `next_review_date` from the interval (1CHART "reconcile to next due").
- `EmarReportController`: add a **Chart Review schedule** report (clients ordered overdue‑first then soonest; no review date = "never reviewed"), CSV export.

**Frontend**
- Reviews page: show next‑due + overdue badges; "Chart Review schedule" report tab with date‑range filter and CSV export. Client medical tab: set chart‑review interval + initial review date.

**Permissions:** reuse `medications.view` / `reports.viewAny`.

**Tests:** alert created exactly 7 days before; overdue surfaces; completing a review rolls the next date by the interval; report ordering.

**Acceptance criteria**
- Dashboard shows review‑due alerts 7 days ahead; schedule report lists upcoming/overdue reviews with CSV export.

**Dependencies:** none. **Effort:** S–M.

---

### PR 9 — Reporting depth + Pharmac therapeutic groups + care‑level filter

**Objective:** Add the per‑category usage reports 1CHART provides, classify meds by Pharmac therapeutic group, and allow filtering reports by care level.

**1CHART ref:** §7 (Individual & facility reports; therapeutic groups in CSV; filter by care level).

**Why (NZ):** Auditors and clinical managers expect PRN/short‑course/regular/syringe/observation usage reports and therapeutic‑group rollups; care‑level filtering matches ARC reporting.

**Backend**
- Add `pharmac_therapeutic_group` (string, nullable) + `pharmac_subgroup` (string, nullable) to `client_medications`. Seed a starter NZULM→Pharmac‑group mapping (`MedicationTherapeuticGroupSeeder`) for the common meds already in `SystemMedicationsSeeder`; allow manual override on the med form.
- Add `care_level` (string/enum, nullable) to `clients` (e.g. `rest_home, hospital, dementia, psychogeriatric, supported_independent`) for filtering.
- Extend `MedicationReportingService` + `EmarReportController` with report types: **PRN usage**, **short‑course usage**, **regular usage**, **syringe‑driver usage** (PR 6), **BSL/Pulse/INR observation** report; all with date‑range (cap 3 months, per 1CHART), care‑level filter, and CSV export including therapeutic group.

**Frontend**
- Reports page: report‑type picker (tiles), date range, care‑level filter, results table + "Export CSV". Med form: therapeutic‑group field.

**Permissions:** reuse `medications.reports.export` / `reports.viewAny`.

**Tests:** each report returns expected rows for seeded data; care‑level filter narrows results; CSV includes therapeutic group; 3‑month range cap enforced.

**Acceptance criteria**
- All listed usage reports run, filter by care level, and export CSV with Pharmac therapeutic groups.

**Dependencies:** PR 6 (syringe usage report); PR 3 (INR in observation report). **Effort:** M.

---

### PR 10 — Backlog / optional (lower relevance to supported living)

Document and triage; implement only if prioritised:

1. **Inter‑site chart transfer** (§3.6) — move a client's active chart between sites/services in one step, carrying meds and resetting allergy verification to "suspected". *Supported living has less inter‑facility transfer than ARC; current discharge→re‑admit covers most cases.*
2. **Nightly encrypted PDF backup** (§9) — scheduled job emailing password‑protected MAR PDFs to up to 5 addresses. *OblivionFindings is cloud‑hosted with infra‑level backups; on‑demand PDF export already exists. Build only if a facility contractually requires offline encrypted copies.*
3. **Pill‑picture identification** (website) — 1CHART licenses Toniq Pill Pictures (1,800+ images). Per‑med `photo_path` already supports manual images; a licensed image library is out of scope. *Enhancement: improve the med form photo UX and show the image on the MAR row.*

**Effort:** varies; not scheduled.

---

## 6. Suggested delivery sequence

```
Wave 1 (quick safety/compliance wins):   PR 1 → PR 5 → PR 2
Wave 2 (clinical depth):                 PR 3 → PR 4 → PR 6
Wave 3 (governance & reporting):         PR 7 → PR 8 → PR 9
Backlog:                                 PR 10 (as prioritised)
```

Rationale: PR 1 and PR 5 are small, high‑value compliance fixes. PR 2 unlocks the warfarin alert type PR 3 reuses. PR 4+PR 5+PR 6 form the "administration safety" cluster. PR 7–9 are governance/reporting and benefit from the data the earlier PRs produce.

## 7. Definition of done (every PR)

- [ ] Migration is re‑run‑safe (`hasColumn` guards) and backfills where behaviour changes.
- [ ] Models use `AuditableChanges` (+ `SoftDeletes`/disable where clinical); no hard deletes of clinical records.
- [ ] New permissions added to a `*PermissionsSeeder` **and** the PR description states the `php artisan db:seed --class=…PermissionsSeeder --force` deploy step; code degrades gracefully if the row is absent.
- [ ] Inertia controllers gate success UI on `!flash.error`.
- [ ] React UI is full‑width, reuses sites‑module card/dialog + tile‑picker patterns, ships no stub actions.
- [ ] Feature tests cover permission gating, happy path, and the critical guard.
- [ ] NZ terminology/regulatory framing (NZULM, Pharmac, Medicines Care Guides, PPPR Act) respected.

---

*This plan closes the verified gaps against 1CHART while preserving OblivionFindings' existing (and in places superior) eMAR architecture. No re‑architecture is required — every item is an additive table/column, service rule, controller action, React surface, and seeded permission that follows current conventions.*
