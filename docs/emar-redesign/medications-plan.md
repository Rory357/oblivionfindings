# eMAR Redesign — Page Plan: Medications Database (`/emar/medications`)

## 0. Identity
- **Route:** `GET /emar/medications` → `emar.medications` (`routes/emar.php:83`).
- **Inertia page:** `resources/js/pages/emar/Medications.tsx` (current 1715 lines — rewrite) + NEW `resources/js/pages/emar/_dialogs.tsx`.
- **Controller:** `EmarController@medications` (`:1222`). CRUD: `storeMedication` (:2784), `updateMedication` (:2831), `discontinueMedication` (:3001), `importMedications` (:3387), `verifyMedication`/`rejectMedication` (:2875/:2889).
- **Bundle:** `Medications_Page/.../README.md` + `Design-Notes.html` + `Medications.dc.html`.
- **Goal:** turn the flat directory into an ops-grade medication register — meds/today-style brand hero, Rostering `TabStrip` facets, a filterable directory table, and an **all-modal** create/edit/detail/discontinue/import/interactions flow on `MedsWizardDialog`.

## Key findings (verify-against-code)
- **No master formulary** — "medications" = per-client `ClientMedication` rows. Page stays a real register (NOT retired into a modal).
- **All CRUD endpoints exist.** `discontinue` reason is `nullable` → make **required** (undocumented cessation is poor practice). Import is fire-and-forget (preview deferred).
- **One Add-medication path:** the existing 2-step `AddMedicationDialog` in `mar-governance-dialogs.tsx` → **promote to the design's 4-step shared modal** in `_dialogs.tsx`; MAR governance reuses it (preset clientId). No second create path.
- Current payload is **server-side paginated (50)**; the design filters **client-side** (tabs + search + client + sort, live counts) → switch to a flat list of current meds.
- Reuse Page 1–2 patterns: `PageHero brandColour`, `MedsWizardDialog` + `wizard/primitives`, `MedsBoardPayloadService` for witnesses.

## 1. Section map
| Design block | Component | Backend source |
|---|---|---|
| Hero (stats Active/PRN/Controlled/To-verify, Add/Import) | `PageHero category=ops` + `brandColour` | `medications` flat list + resolved site colour |
| Tabs (All/Active/PRN/Controlled/High-risk/Awaiting) | `TabStrip` + `RosterTabItem` | client-side counts |
| Directory toolbar (search/client/sort/result/Interactions) | inline | client-side |
| Directory table | NEW `components/emar/medications/med-directory.tsx` | flat `medications[]` |
| Modals | NEW `_dialogs.tsx` (on `MedsWizardDialog`) | existing endpoints |

## 2. Hero spec
Eyebrow live-ping `MEDICATION REGISTER · synced …`; title "Kia ora {first} — the medication register for {site underlined}"; description "{n} medications across {clients} clients. {awaiting} awaiting verification and {lowStock} low on stock"; meta chips clients · last-import; stats **Active · PRN · Controlled · To verify** (live); actions **Add medication** (primary) + **Import** (outline). **Brand colour (§3b):** resolve from active site — selected client's site, or `?site_id` filter, else null → category default.

## 3. Tab spec (`TabStrip`, client-side facets)
| Tab | id | Tone | Filter |
|---|---|---|---|
| All | all | primary | everything |
| Active | active | success | `state==='active'` |
| PRN | prn | primary | `is_prn` |
| Controlled | controlled | critical | `controlled_drug` |
| High-risk | high_risk | warning | `high_risk` |
| Awaiting | awaiting | warning | `approval_status==='pending_verification'` |

## 4. Modal map (§4 — MedsWizardDialog only)
| Workflow | Existing? | Decision | Endpoint |
|---|---|---|---|
| Add medication (4-step) | 2-step in `mar-governance-dialogs.tsx` | **PROMOTE to shared 4-step** `_dialogs.tsx`; MAR reuses (preset clientId) | `emar.medications.store` |
| Edit medication | raw `Dialog` in page | **BUILD** on shell | `emar.medications.update` |
| Medication detail (read-only) | inline drill-in | **BUILD** single-pane | (read) + verify/discontinue/edit launchers |
| Discontinue (required reason) | `window.prompt()` ⚠ | **BUILD** confirm modal | `emar.medications.discontinue` |
| Import CSV | raw `Dialog` | **MIGRATE** to shell | `emar.medications.import` |
| Verify / Reject order | inline buttons / `window.prompt()` | **BUILD** (reject = reason modal) | `emar.medications.verify` / `reject` |
| Drug interactions (reference) | inline manager | **BUILD** single-pane list | (read `interactionMap`) |

- End state: zero raw Dialog/Sheet workflow modals reachable from the page; both `window.prompt()` calls replaced.

## 5. Backend gaps
| # | Gap | Action | Test |
|---|---|---|---|
| 1 | payload paginated; design filters client-side | `medications()` → flat list of `current()` meds w/ stock+interaction+client; add `site_brand_colour` + `witnesses` | feature: page returns flat `medications` + brand colour |
| 2 | discontinue reason `nullable` | make **required** (string, max 500) | feature: discontinue without reason rejected |
| 3 | (defer) import preview/summary | keep simple; note backlog | — |
- Safety already enforced by `EnhancedMarService` for administration; this page is order-management (verify gate already exists via `approval_status`).

## 6. Cross-module
- **MAR Charts "Add medication"** (`mar-governance-dialogs.tsx`) → reuse the shared 4-step modal (preset clientId). Verify MAR still adds meds.
- **app-sidebar** "Medications" → `/emar/medications` (unchanged).
- Pharmac group surfaced in MAR's `buildMarData` — keep the field in the create/edit modals.

## 7. Retire → redirect
- `resources/js/pages/medications/enhanced-mar.tsx` (orphaned, non-tokenised) + `resources/js/pages/medications/dashboard.tsx` (redirects to /emar) → delete; drop redirect routes `/medications/enhanced-mar/{client}` + `/medications/dashboard` in `routes/medications.php` (keep `/clients/{id}/mar`→`/emar/mar`). Repoint `pages/medications/index.tsx:140` stale link. **Verify each exists first.**

## 8. Execution checklist
- [ ] Backend: `medications()` flat payload + brand colour + witnesses; discontinue reason required. Tests.
- [ ] `_dialogs.tsx`: shared 4-step Add (allergy/interaction safety step), Edit, Detail, Discontinue, Import, Reject, Interactions — all on `MedsWizardDialog`.
- [ ] `mar-governance-dialogs.tsx`: reuse the shared Add modal (preset clientId).
- [ ] `Medications.tsx` rewrite: hero+brandColour, TabStrip, directory (toolbar+table), wire modals.
- [ ] Retire orphaned medications pages + routes; repoint stale link.
- [ ] §9 gate: types/lint/pint/tests/build; commit; tick PROGRESS.

## 9. Notes / deferrals
- §3d HARD RULE: `MedsWizardDialog`, not raw shadcn `Dialog` (handoff predates the standard). Reuse Page-1/2 patterns.
- **Deferred → backlog:** import row-preview/validation step (keep fire-and-forget import w/ toast), full live drug-interaction engine in the wizard Safety step (show client allergies + simple name-match + active-meds note; reference Interactions modal lists `interactionMap`), client-context cards (folded into the detail modal / hero). Reasons: secondary; core = register + all-modal CRUD.
