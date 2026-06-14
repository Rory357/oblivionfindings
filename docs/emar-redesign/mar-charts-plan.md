# eMAR Redesign — Page Plan: MAR Charts (`/emar/mar`)

## 0. Identity
- **Route:** `GET /emar/mar` → `emar.mar` (`routes/emar.php:70`, `permission:medications.view`).
- **Inertia page:** `resources/js/pages/emar/MarCharts.tsx`.
- **Controller / method:** `App\Http\Controllers\Emar\EmarController@mar` (`:746`); builds `marData` via `MarScheduleService` + helpers.
- **Bundle handoff doc(s) read in full:** `Emar_Charts_Page/design_handoff_emar_mar_charts/README.md`, `eMAR MAR Charts - Handoff.dc.html` (rationale), `eMAR MAR Charts.dc.html` (prototype).
- **One-line goal:** turn `/emar/mar` into a single scannable **time-grid medication chart** (medications × dose-time columns) with a live brand-tinted hero, an attention bar, a consolidated clinical rail, and modal-driven workflows — reusing the `meds/today` administration pipeline and wizards.

## Key investigation findings (verify-against-code, §5)
- **Most "backend gaps" already exist.** Routes already wired: `emar.medications.store/verify/reject`, `emar.clients.inr.store` + `emar.inr.disable` (disable-not-delete ✅), `emar.clients.syringe_drivers.store` (+checks/complete), `emar.clients.attention_alerts.store` (+update/resolve), `emar.clients.alert_suppression`, `emar.clients.medication_settings`. Models exist: `ClientInrRecord`, `MedicationSyringeDriver`, `ClientMedicationAlert`, `ClientMedicationAdministration` (has `reason_code`, observation, `witnessed_by` columns). → **MAR is primarily a FRONTEND rebuild + wiring.**
- **Administration pipeline is shared.** `EnhancedMarService` is the single write path; `meds/today` records via it. MAR must record through the **same** wizard + pipeline — no second path.
- **Reuse contract (the pivot):** `RecordDoseWizard`/`PrnWizard` (`resources/js/pages/meds/today/components/`) consume `ScheduleRow` / `PrnMedication` + `witnesses: WitnessOption[]` + `notGivenReasons: NotGivenReasonOption[]` + `signedAs` (from `board_user`) — all defined in `resources/js/pages/meds/today/types.ts` and built by `Emar/WorkerMedsController@today`. **MAR will serve the same keys for the selected client**, group `schedule` into a grid, and reuse these wizards verbatim.

## 1. Section map (design → component → backend source)
| Design block | Component (reuse / NEW) | Backend source |
|---|---|---|
| Hero (gradient, avatar, meta, badges, stats, footer) | `PageHero variant=hero category=ops` **+ `brandColour`** (foundation done) | `selectedClient` + `marData.stats` + new `board_user`; brand colour from `selectedClient.site.brand_colour` |
| Attention bar | **NEW** `components/emar/mar/attention-bar.tsx` | `marData.attention_alerts[]` (+ `prompt_on_open`) |
| MAR time-grid | **NEW** `components/emar/mar/mar-grid.tsx` (groups `schedule` rows by med × time) | `schedule: ScheduleRow[]` (NEW key via `EnhancedMarService`/worker builder) |
| Dose cell record | **REUSE** `RecordDoseWizard` | `meds.today.record` / EnhancedMarService (existing) |
| Right-click quick-actions | **NEW** `components/emar/mar/dose-context-menu.tsx` (status quick-set → same record endpoint; CD routes to full wizard) | existing record endpoint |
| PRN card | **NEW** `components/emar/mar/prn-card.tsx` + **REUSE** `PrnWizard` | `prn_medications: PrnMedication[]` (NEW key) |
| Clinical rail (INR / syringe / workflow / context) | **NEW** `components/emar/mar/clinical-rail.tsx` | `marData.inr_records`, `marData.syringe_drivers`, `awaiting_verification`, `pendingCorrections`, `clientContext`, `alerts` (all existing) |
| Tabs (chart facets) | `TabStrip` + `RosterTabItem` (`@/components/rostering`) | derived counts (see §3) |

## 2. Hero spec (§3a)
- **Eyebrow:** live ping + `LIVE MEDICATION CHART · REFRESHED {now_label}` (when `is_today`); else `CalendarDays` + `MEDICATION CHART · {date_label}`.
- **Title:** resident full name; description `Medication administration record for {Weekday DD Month}` with the date span underlined (`border-b-2 border-primary-foreground/40`).
- **Avatar:** `PageHeroAvatar` with resident initials + stable hue.
- **Meta chips:** NHI · DOB (age) · GP · Site · Room · Care level (icons User/Calendar/HeartPulse/Home/Shield).
- **Badges (conditional, count>0):** `{overdue} overdue` (critical) · `{cd_due} controlled · witness` (warning) · `Warfarin · INR {value}` (info) · `Paper prescription on file` (default).
- **Stats tiles:** Recorded % · Due now · Overdue · PRN today (from `marData.stats`).
- **Actions:** `Start medication round` (solid) · `Add medication` (outline) · `PDF` (outline → `emar.pdf.mar` new tab).
- **Footer:** day stepper (`< prev` · `DayPickerChip` · `next >` · Back-to-today) + client `EntityFilter`/Select (pill) + search input (onDark).
- **Per-site brand colour (§3b):** `<PageHero brandColour={selectedClient?.site_brand_colour ?? null}>`. EmarController resolves the **active site = selected client's site** → `site.brand_colour` → page prop. Null → falls back to `category="ops"` token then `--primary`. Foundation (column/validation/settings control/prop) already shipped in `848ccebe`.

## 3. Tab spec (§3c — `TabStrip`)
| Tab | id | Tone | Filter / target | Count source |
|---|---|---|---|---|
| Schedule | `schedule` | primary | the time-grid (all scheduled) | `schedule.length` |
| Due / overdue | `due` | critical | grid filtered to due+overdue | `stats.due_now + stats.overdue` |
| PRN | `prn` | warning | PRN card | `prn_medications.length` |
| History | `history` | info | today's recorded administrations list | `marData.stats.given+refused+withheld` |

## 4. Modal map (§4 — reuse-first, Add-Client/`MedsWizardDialog` chrome)
| Workflow | Existing? | Chrome | Decision | Steps → endpoint |
|---|---|---|---|---|
| **Record administration** | `RecordDoseWizard` (meds/today) | conforms (MedsWizardDialog) | **REUSE** | 5-rights→outcome→sign → `meds.today.record` (EnhancedMarService) |
| **PRN give** | `PrnWizard` (meds/today) | conforms | **REUSE** | → `meds.today.prn` |
| Legacy `RecordAdministrationDialog` + `prn-sheet.tsx` | raw `Dialog`/`Sheet` | legacy | **RETIRE from MAR** (replaced by the two wizards above; leave file until no importer) | — |
| **Add medication** | `GET /emar/medications` page | page | **BUILD** modal on `MedsWizardDialog` | name/strength/route/form/freq/schedule/classification/Pharmac/instructions → `emar.medications.store` (`pending_verification`) |
| **Record INR** | `emar/clients/inr` index page | page | **BUILD** modal | value/date/range/dose/next-test/notes → `emar.clients.inr.store`; disable → `emar.inr.disable` |
| **Start syringe driver** | inline in `ClientMedicationTools` | raw | **BUILD** modal | contents/rate/duration/site/commenced/witness → `emar.clients.syringe_drivers.store` |
| **Manage attention alerts** | inline in `ClientMedicationTools` | raw | **BUILD** modal | type tiles/title/detail/prompt-on-open/suppress(reason) → `emar.clients.attention_alerts.store` + `emar.clients.alert_suppression` |
| **Verify order** | inline buttons | raw | **BUILD** modal | summary → `emar.medications.verify` / `reject` |
| **Record observation** | scattered | none | **BUILD** modal (single-pane) | type/value/time/notes → (reuse clinical obs endpoint; confirm) |
| **Chart warnings prompt** | none | none | **BUILD** (single-pane on shell) | acknowledge `prompt_on_open` alerts (session-local) |

- End state: zero raw Dialog/Sheet **workflow** modals reachable from MAR. One dose-record path (`RecordDoseWizard`), one PRN path (`PrnWizard`).

## 5. Backend gap list (§5 — verify each still exists first)
| # | Gap | Sev | Fix | Test |
|---|---|---|---|---|
| 1 | MAR payload lacks `meds/today` keys (`schedule`,`prn_medications`,`witnesses`,`not_given_reasons`,`board_user`) needed to reuse wizards | **H** | extract a shared payload builder from `WorkerMedsController@today` (or new `MedsBoardPayload` service); call it from `EmarController@mar` for `selectedClient` | feature: `/emar/mar?client_id=` returns `schedule`+`witnesses`+`board_user` |
| 2 | Selected client's `site.brand_colour` not passed | **M** | add `site_brand_colour` (or nested `site`) to `selectedClient` payload in `mar()` | feature: prop present |
| 3 | Witness re-authentication (`Hash::check`) on CD countersign | **M** | verify whether `EnhancedMarService::recordAdministration` already checks; if not, enforce in the record path | feature: CD record without valid witness creds rejected |
| 4 | Verification gate: unverified orders not administrable | **M** | confirm `isAdministrable()`/`approval_status` gating already enforced in record path; backfill if needed | feature: `pending_verification` order can't be recorded |
| 5 | Coded omission reason enforced server-side | **M** | confirm `not_given_reasons` + `reason_code` enforced in record path (likely already) | feature: outcome≠given requires `reason_code` |
- **Safety:** all admin writes via `EnhancedMarService` + idempotency (`client_request_uuid`); CD witness + running balance; competency gate; INR/destruction immutability. Verify each in code before adding — several already enforced by the shared pipeline.

## 6. Cross-module touchpoints (§6)
- **Deep link** `/emar/mar?client_id=&date=` (client profile "Open MAR chart", meds/today `mar_url`) — preserve querystring handling.
- **meds/today / My Day / rounds:** reuse the same `RecordDoseWizard`/`PrnWizard`/pipeline so rounds + records stay consistent; "Start medication round" links to `emar.rounds`.
- **Client profile eMAR dialog** (`resources/js/pages/clients/profile/emar-dialog.tsx`) — still resolves; reuse where it surfaces client meds.
- **Clinical:** INR card + Record INR modal; syringe driver card; observations mirror to clinical.
- **Verify:** click-test each: open chart from profile deep-link; record a dose; give a PRN; record INR; open Verify-order; open Manage-alerts.

## 7. Pages / routes to retire → redirect
- `GET /emar/medications` → **Add medication** modal. (Retire when Page 3 "Medications Database" is built — that page also owns `/emar/medications`. **Defer the redirect to Page 3**; MAR just adds the modal launcher.)
- `GET /emar/clients/{client}/inr` (`emar.clients.inr.index`) → **Record INR** modal; `Route::redirect` to `/emar/mar` once no importer. Keep `emar.pdf.mar` + downloads.
- Inline `ClientMedicationTools` dialogs → rail modals (component retired from MAR once rail covers them).

## 8. Execution checklist (ordered)
- [ ] **Backend**: shared `meds/today` payload builder reused by `EmarController@mar` (`schedule`,`prn_medications`,`witnesses`,`not_given_reasons`,`board_user`) + `site_brand_colour` on `selectedClient`. Verify gaps 3–5 already enforced; add where not. Feature tests.
- [ ] **Hero** rebuild (avatar, meta, badges, stats, footer) + `brandColour` wiring.
- [ ] **Attention bar** + chart-warnings prompt.
- [ ] **MAR time-grid** (group `schedule` by med × time; status cells; left-click → RecordDoseWizard; right-click context menu).
- [ ] **PRN card** + PrnWizard.
- [ ] **Clinical rail** (INR/syringe/workflow/context) + its modals (Record INR, Start syringe, Manage alerts, Verify order, Record observation).
- [ ] **Tabs** (`TabStrip`).
- [ ] Retire legacy dialogs from MAR; redirect INR index route.
- [ ] §9 gate: types, lint, pint, tests (+new), build, screenshot vs prototype, brand-colour change, modal-chrome audit, cross-module click-through.
- [ ] Commit `feat(emar): redesign MAR Charts` + tick PROGRESS.md.

## 9. Notes / decisions / deferrals
- **Architecture decision:** MAR reuses the `meds/today` data contract + wizards rather than extending the legacy `RecordAdministrationDialog` — satisfies §4 (one dose path) and §5 (one pipeline). The MAR grid is a *presentation* over the shared `ScheduleRow[]`.
- **Brand colour active-site rule for eMAR:** selected client's site (MAR is per-client). Other eMAR pages (site-filtered lists) will resolve from the site EntityFilter selection — recorded per page.
- **Deferred:** `/emar/medications` GET retirement handled by Page 3 (Medications Database) to avoid two pages racing the same route. Observation endpoint to confirm during build (may reuse `clinical.events.record`).
