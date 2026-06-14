# eMAR Redesign — Page Plan: Destructions (`/emar/destructions`)

## 0. Identity
- **Route:** `GET /emar/destructions` → `emar.destructions` (`routes/emar.php:133`).
- **Inertia page:** `resources/js/pages/emar/Destructions.tsx` (rewrite).
- **Controller:** `EmarController@destructions` (`:1784`) + `storeDestruction` (`:2640`) + `destroyDestruction` (`:3590`, hard-delete — RETIRE).
- **Model:** `MedicationDestruction` (no SoftDeletes yet; fillable already has site_id/photo_path/witness_2_id/authorised_by_*).
- **Goal:** turn the flat table into a 4-tab disposal register — brand hero, `TabStrip` (Awaiting / Log / Controlled / Reports), **reusing the shared `RecordDestructionDialog`** (Page 6), with an **immutable, voidable** register (MoD Regs 1977).

## Key findings (verify-against-code)
- **`storeDestruction` already decrements stock** (gap 1 done) when `client_medication_id` set; requires witness_1 (≠ destroyer), witness_2 + authorised_by for CD. Quantity is `decimal:2` (gap 2 = frontend float, my wizard does this).
- **`destroyDestruction` HARD-DELETES** (gap 3 — the compliance miss). `destructions.destroy` route has **no other references** → safe to retire.
- **Reuse:** `RecordDestructionDialog` exists in `_cd-dialogs.tsx` (Page 6, CD-only) → **GENERALIZE** it (derive `is_controlled_drug` from the picked med; witness_2/authorised conditional; add site select) so it serves both Page 6 (CD meds) and Page 7 (all meds). Page-6 behaviour preserved (its meds are all controlled).

## 1. Section map
| Block | Component | Source |
|---|---|---|
| Hero (Awaiting/Destroyed-30d/CD-30d/Last-reconciled stats, Record + Export) | `PageHero` + `brandColour` | flat list + counts + site colour |
| Tabs (Awaiting/Log/Controlled/Reports) | `TabStrip` | client-side counts |
| Log table / Controlled table / Reports cards | inline | `destructions[]` (+ voided) |
| Record destruction | **REUSE generalized `RecordDestructionDialog`** | `emar.destructions.store` |
| Void record | NEW small modal | `emar.destructions.void` (NEW) |

## 2. Hero spec
Eyebrow live-ping `CONTROLLED-DRUG REGISTER · live`; title "Medication disposal & destruction for {site underlined}"; stats **Awaiting · Destroyed 30d · CD destructions 30d · Last reconciled**; actions **Record destruction** (primary) + **Export register** (outline); footer site `EntityFilter`. Brand colour from `?site_id`.

## 3. Tab spec (`TabStrip`)
Awaiting (warning, Package) · Destruction log (primary, ClipboardCheck) · Controlled drugs (critical, Lock) · Reports & export (info, FileText). Counts client-side. (Awaiting = deferred workflow → empty-state for now.)

## 4. Modal map (§4)
| Workflow | Decision | Endpoint |
|---|---|---|
| Record destruction | **REUSE generalized `RecordDestructionDialog`** | `emar.destructions.store` |
| Void record | **BUILD** small reason modal | `emar.destructions.void` (NEW) |

## 5. Backend
| # | Gap | Action | Test |
|---|---|---|---|
| 3 | register hard-deletes | **migration** SoftDeletes (`deleted_at`) + `voided_at`/`void_reason`/`voided_by`; model `SoftDeletes` + `scopeVerified` (whereNull voided_at); **`voidDestruction()`** (sets voided_*); **retire DELETE destroy → POST `…/void`** | feature: void marks voided, NOT deleted |
| 10 | witness uniqueness | storeDestruction: witness_2 ≠ destroyer + witness_1 ≠ witness_2 | feature: self/dup witness rejected |
| 6 | site_id uncollected | storeDestruction: add `site_id` nullable validation | — |
| payload | page needs flat list + CD subset + med picker | `destructions()` flat list + `cdRegister` + `medications` (CdMedication shape: controlled_drug/client_name/stock) + sites + site_brand_colour | feature: payload + brand colour |
- **Immutability (§5):** records never hard-deleted; **void supersedes** (record stays visible, struck-through, reason shown); retained.
- **Defer:** awaiting stock-state workflow (gap 8), photo upload (gap 7), witness credential verify (gap 4), CD-register reconciliation entry on destruction (gap 5), CSV/PDF export wiring (gap 9 — link existing routes if present, else defer).

## 6. Cross-module
- Shares `RecordDestructionDialog` with Page 6 (Controlled Drugs Destructions tab). CD destructions also surface on the CD page. app-sidebar/eMAR "Destructions"? (verify) → `/emar/destructions`.

## 7. Retire → redirect
- `DELETE /emar/destructions/{destruction}` (`emar.destructions.destroy`, hard-delete) → **replaced by** `POST /emar/destructions/{destruction}/void` (`emar.destructions.void`). No other refs → safe.

## 8. Execution checklist
- [ ] Backend: migration (SoftDeletes + void cols); model (SoftDeletes + scopeVerified + fillable/casts); `voidDestruction()` + route swap; storeDestruction (site_id + witness uniqueness); `destructions()` flat payload + cdRegister + CdMedication-shaped meds + brand colour. Tests.
- [ ] Frontend: **generalize `RecordDestructionDialog`** (is_controlled_drug from med, conditional witness_2/authorised, site select). `Destructions.tsx` rewrite (hero + 4-tab + reuse dialog + void modal).
- [ ] §9 gate; commit; tick PROGRESS.

## 9. Notes / deferrals
- §3d HARD RULE: `MedsWizardDialog`. Reuse Page 1–6 patterns; the destruction wizard is the shared one.
- **Deferred → backlog:** awaiting-destruction workflow + stock flag (gap 8), photo-evidence upload (gap 7), witness password/credential verification (gap 4), CD-register reconciliation entry written on destruction (gap 5), CSV/PDF export buttons wired to existing report routes (gap 9), right-click context menus. Reasons: separable compliance enhancements / new stock state; core = immutable voidable register + 4-tab surface + shared record wizard + witness rules.
