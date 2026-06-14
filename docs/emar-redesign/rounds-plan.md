# eMAR Redesign — Page Plan: Medication Rounds (`/emar/rounds`)

## 0. Identity
- **Route:** `GET /emar/rounds` → `emar.rounds` (`routes/emar.php:112`).
- **Inertia page:** `resources/js/pages/emar/Rounds.tsx` (current 516 lines — rewrite).
- **Controller:** `EmarController@rounds` (`:1453`) + `GuidedRoundController@show/administer/complete` + template/generate/start/complete/assign actions.
- **Bundle read:** `Emar_Medication_Rounds_Page/.../README.md` + `Rounds Redesign - Handoff.dc.html` + prototype.
- **Goal:** rebuild `/emar/rounds` into a `/meds/today`-style hero + a usable round chart (Board/Chart/Templates/Activity via `TabStrip`) with a **guided-round modal** (reusing the existing `GuidedRoundController@administer/complete` pipeline) and template/generate wizards on `MedsWizardDialog`; retire the standalone guided page.

## Key findings (verify-against-code)
- **Write path reused, not rebuilt:** `GuidedRoundController@administer` (`:84`) → `EnhancedMarService.recordAdministration` (one pipeline; CD witness + vitals enforcement already runs there — Page 1's "controlled dose requires witness" test proves it). Offline idempotency via `HandlesMedicationSync`. **Keep `administer`/`complete`; retire only `@show`'s page render.**
- **`GenerateRoundsModal` already exists** on `MedsWizardDialog` (`resources/js/pages/emar/components/generate-rounds-modal.tsx`) but is unused → **REUSE/wire it.**
- `GuidedRoundService` (items/progress/summarise) builds the dose list for a round — reuse for the modal payload.
- Models `MedicationRound` / `MedicationRoundTemplate` are complete (counts, `updateCounts()`, `appliesToDay`, `applicableMedicationCountForDate`).

## 1. Section map
| Design block | Component | Backend source |
|---|---|---|
| Hero (day stepper, stats, Generate/New-template) | `PageHero category=ops` + `brandColour` | `rounds` + `MedsBoardPayloadService` (witnesses/reasons/board_user) + resolved site colour |
| Tabs Board/Chart/Templates/Activity | `TabStrip` + `RosterTabItem` | derived counts |
| Board (cards/list) | NEW `components/emar/rounds/round-board.tsx` | `rounds[]` (assignee, counts, status) |
| Chart (resident × round matrix) | NEW `round-matrix.tsx` (simple) | `rounds[]` + per-round items |
| Templates table | NEW `round-templates-table.tsx` | `templates[]` |
| Activity feed | inline | derived from rounds' administrations/timeline (simple) |
| Guided-round modal | NEW `guided-round-dialog.tsx` on `MedsWizardDialog` | `guidedRound` prop (round+items+progress) → posts `administer`/`complete` |
| Template wizard | NEW `round-template-dialog.tsx` on `MedsWizardDialog` | `emar.rounds.templates.store/update` |
| Generate modal | **REUSE** `generate-rounds-modal.tsx` | `emar.rounds.generate` |

## 2. Hero spec
Eyebrow live-ping (today) / calendar (other day); title "Kia ora {first}, today's rounds — {date underlined}"; description "{total} doses across {sites} sites · {given} given, {due} to give"; badges sites·residents / competency; stats Rounds done/total · Given/total · Due · Flags; actions Generate rounds + New template; footer day stepper + Site/Resident `EntityFilter` (onDark). **Brand colour (§3b):** resolve from active **site filter** (`?site_id=`) → `site->brand_colour`; null → category default (rounds are site-scoped, not per-client).

## 3. Tab spec (`TabStrip`)
Board (primary, `CalendarCheck`) · Chart (info, `Grid3x3`) · Templates (violet, `LayoutList`) · Activity (success, `Activity`). Counts: rounds.length / residents / templates.length / activity.length.

## 4. Modal map (§4)
| Workflow | Existing? | Decision | Endpoint |
|---|---|---|---|
| Guided round | `meds/round/guided.tsx` (page, raw Dialog) | **BUILD modal** on `MedsWizardDialog`; retire page | `…/guided/items/{med}` + `…/guided/complete` |
| Template new/edit | inline raw `Dialog` in Rounds.tsx | **BUILD** wizard on `MedsWizardDialog` | `emar.rounds.templates.store/update` |
| Generate rounds | `generate-rounds-modal.tsx` (MedsWizardDialog) | **REUSE** | `emar.rounds.generate` |
| Audit & timeline | none | DEFER (backlog) | — |
| Context menu | `ShiftContextMenu` exists | DEFER (right-click → open guided, safe) | — |

## 5. Backend gaps (verified)
| # | Gap | Action |
|---|---|---|
| G1 (P1) | `days_of_week` validated `min:0\|max:6` but generate uses ISO 1–7 → **Sunday rejected** | FIX: validation `min:1\|max:7` in store/update; UI sends ISO 1–7; `appliesToDay` already ISO-compatible. **Feature test.** |
| G4 (P1) | witness/vitals not enforced | **Already enforced** in `EnhancedMarService` (shared); new modal *sends* them. Note only. |
| G2/G3 (P1) | complete-with-pending guard + auto-miss job | **DEFER (backlog)** — miss-tracking infra, separable from the UI redesign; G3 needs a scheduled command. |
| payload | new page needs guided items, brand colour, witnesses, reasons | extend `rounds()`: `?guided={id}` → `guidedRound`={round,items,progress}; `?site_id` → `site_brand_colour`; reuse `MedsBoardPayloadService` for `witnesses`/`not_given_reasons`/`board_user`. |

## 6. Cross-module
- `/meds/today` shows active/upcoming rounds (`GuidedRoundService->progress`) linking to the guided modal — **repoint `RoundInfo.url`** + `active-shift-card.tsx` deep links from `meds.round.show` to `/emar/rounds?guided={id}` (or make `@show` redirect there). Verify `/my-day` round links.
- Recording in the modal flows through the same pipeline → counts update on board/today.

## 7. Retire → redirect
- `GET /emar/rounds/{round}/guided` (`meds.round.show`) page → modal. Make `@show` **redirect to `/emar/rounds?guided={id}`** (preserves deep links); keep `administer`/`complete` POSTs. Delete `resources/js/pages/meds/round/guided.tsx` once no importer.

## 8. Execution checklist
- [ ] Backend: G1 fix + test; `rounds()` payload (guidedRound / site_brand_colour / witnesses+reasons+board_user); `@show` → redirect.
- [ ] Frontend: Rounds.tsx rewrite (hero+brandColour, TabStrip, board cards/list, chart matrix, templates table, activity feed) + new components.
- [ ] Guided-round modal on `MedsWizardDialog` (6-Rs gate, Given/Refused/Held, witness/vitals, auto-advance, summary) → reuse administer/complete.
- [ ] Template wizard + reuse GenerateRoundsModal.
- [ ] Repoint cross-module deep links; retire guided page.
- [ ] §9 gate: types/lint/pint/tests(+G1)/build; commit; tick PROGRESS.

## 9. Notes / deferrals
- Use `MedsWizardDialog` (loop §3d HARD RULE) NOT the handoff's `WizardShell`. Reuse Page-1 patterns (brandColour, MedsBoardPayloadService, mar-governance-dialogs template).
- **Deferred → backlog:** round **timeline** (horizontal donut axis — most complex, least essential), **audit-&-timeline** dialog, **context menu** (right-click opens guided), **G2/G3** miss-tracking infra, G5–G10. Reasons: secondary lenses / separable infra; core = hero + board + guided modal + templates + generate.
