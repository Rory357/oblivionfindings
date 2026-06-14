# eMAR Redesign — Page Plan: Audit Trail (`/emar/audit`)

## 0. Identity
- **Route:** `GET /emar/audit` → `emar.audit` (`AuditLogController@index`, perm `medications.audit.view`). **READ-ONLY** + CSV export (`emar.audit.export` → `MedicationAuditController@exportCsv`).
- **Inertia page:** `resources/js/pages/emar/AuditLog.tsx` (rewrite).
- **Goal:** read-only timeline → governance surface — brand hero, view `TabStrip` (Timeline / Table / Compliance gaps), category `TabStrip` (All / Doses / Controlled / Clinical / Stock / Errors), filter bar, and a **read-only traceability drawer** (no separate detail pages). **No write modals** — the "Resolve gap" / countersign endpoints don't exist (HANDOFF §4 "to be added"); gaps cross-link to the owning module.

## Key findings (verify-against-code)
- `index()` aggregates events from **6 sources** (ClientMedication start/cease, administrations, prescriber orders, reviews, destructions, order versions) → flat list + stats + clients. No site/staff filter, no per-event category/witness/flags.
- **Gaps (HANDOFF §2) I can honestly close:** G2 CD witness (add `ClientControlledDrugEntry` source w/ `witnessedBy`), G5 errors (add `MedicationError` source), G4 staff filter, G3 omission flag (`dose_missed` already emitted → flag it), §3b site filter + brand colour.
- **Gaps I must NOT fake (per [[feedback_hide_unbuilt_actions]] + HANDOFF open-Q#2):** G6 crypto hash / device / IP — **not stored** → drawer shows only real fields ("append-only record", recorded-at); G1 `performed_by` for start/cease is genuinely null → honest "Not attributed" chip. No new countersign/reason write endpoints.

## 1. Section + modal map (§1/§4)
| Block | Component | Source |
|---|---|---|
| Hero (live eyebrow, stats Total/This-week/This-month/Open-gaps, badges, day-stepper + search + site) | `PageHero` + `brandColour` | flat events + stats + colour |
| View tabs (timeline/table/gaps) | `TabStrip` | client-side |
| Category tabs (all/doses/controlled/clinical/stock/errors) | `TabStrip` | client-side facet on `category` |
| Filter bar (client/staff/range/source) | inline `Select`s | flat list |
| Timeline / Table / Gaps views | inline | `events[]` (+ `flags`) |
| Event detail drawer (read-only) | **BUILD** `medication-event-drawer.tsx` on `MedsWizardDialog` (single-step) | — (+ cross-links) |
| Export audit pack | `emar.audit.export` (existing CSV) | — |

## 2. Hero spec
Eyebrow live-ping `TAMPER-EVIDENT AUDIT TRAIL · live`; title "Every medication action across {site underlined / your services}"; description (window + N actions + open-gaps note); meta (90-day window · append-only · actor counts); badges (open MAR gaps / CD missing witness / overdue review — solid token pairs); stats **Total · This week · This month · Open gaps**; actions **Export audit pack** (CSV) + **Print MAR & CD register** (→`emar.reports`); footer day-stepper + search + site `EntityFilter`. Brand colour from `?site_id`.

## 3. Backend (§5)
| # | Gap | Action | Test |
|---|---|---|---|
| brand | parity | `?site_id` (events scoped by client.site_id) → `site_brand_colour` + `sites` + `active_site` | feature: brand colour |
| G2 | CD not in feed | add `ClientControlledDrugEntry` source (cd_given/cd_balance_check) w/ `witness` + `witness_required` | feature: CD events + witness flag |
| G5 | errors not in feed | add `MedicationError` source (medication_error) | feature: error events |
| G4 | no staff filter | `?staff` param (filter by performed_by) + `staff` list | — |
| enrich | no category/flags | post-process every event: `category`/`source`/`site_id`/`site_name`/`outcome`/`flags[]` (no_actor/missing_witness/omission/no_reason); `open_gaps` count | feature: flags present |
| payload | scale | flat list capped (default 90-day window), stats total/week/month/open_gaps | — |

## 4. Cross-module (§6)
- Drawer "Linked records" + gap CTAs **cross-link** to the owning module (CD missing-witness → `/emar/controlled`; review → `/emar/reviews`; error → `/emar/errors`; dose → `/emar/mar`) — no embedded write flow. `emar.audit.export` CSV retained (hero button). Per-record detail *pages* already replaced by those modules' own modals (Pages 7/9/12) — the drawer is the audit-side read view.

## 5. Retire → fold into drawer
- Inline "Show details" expanders → the read-only traceability drawer. No routes removed (export kept).

## 6. Execution checklist
- [ ] Backend: `index()` — add CD + error sources; enrich (category/source/site/witness/flags); site+staff filters; brand colour; open_gaps stat. Test.
- [ ] Frontend: `components/emar/medication-event-drawer.tsx` (read-only); `AuditLog.tsx` rebuild (hero + 2 TabStrips + filter bar + timeline/table/gaps + drawer).
- [ ] §9 gate; commit; tick PROGRESS.

## 7. Notes / deferrals (backlog)
- §3d: the only modal is a **read-only** drawer (single-step `MedsWizardDialog`) — no write workflow exists to put on it.
- **Honest non-invention:** G6 crypto-hash/device/IP **not shown** (not stored — would be fabricated); "Immutable · cryptographically sealed" softened to **"Append-only record"**; G1 start/cease actor stays "Not attributed". 
- **Deferred (real backend gaps, flagged):** G1 `created_by`/`ceased_by` columns on client_medications, G3 scheduled-vs-recorded omission *reconciliation job* (only existing `dose_missed` rows flagged, not synthesised blanks), G6 append-only projection table + row hash, G7 coded refuse/omit reasons, stock-received source (no queryable StockMovement model — AuditLogger only), PDF audit-pack, the countersign/record-reason **write** workflows (endpoints don't exist). Reasons: schema/infra/new-endpoints — out of scope for a read-only page. Core = brand governance surface + Timeline/Table/Gaps + CD+error sources + category/witness/flags + read-only drawer.
