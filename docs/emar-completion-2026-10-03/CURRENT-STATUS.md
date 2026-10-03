# eMAR implementation and verification status

Updated 4 October 2026, 07:38 Pacific/Auckland. This file takes precedence over the dated historical checkpoints in this folder. Reviewed work is published in [draft PR #16](https://github.com/Rory357/oblivionfindings/pull/16); this is not a production or clinical release.

## Scope and navigation

Claude's fourteen approved mockups and unfinished P01, P02 and P11 work have been recovered and carried into the isolated `codex/emar-completion-20261003` integration branch. Sixteen GPT-6.1 Sol Extra high chats handled page implementation and independent review, with at most three worker chats active alongside Main. Main alone reviews, integrates and pushes. All sixteen worker chats have finished their delegated work and are unpinned; release blockers remain assigned in this handoff.

The original long sidebar is consolidated into seven role-aware hubs: **Meds today; MAR & medicines; Orders & reviews; Stock & controlled drugs; Safety & oversight; Reports & audit; Settings**. Ordinary support workers see Meds today, with scoped person and task links. The additional capabilities remain available within the appropriate hubs and permissions.

The application remains one organisation across approved sites. Current staff eligibility, exact role permissions, person access and canonical record ownership govern every action. Legacy organisation columns do not establish a new tenant architecture.

## Implemented work

- Shared medication recording, person MAR/day/week charts, allergies and clinical readings, support agreements, independently checked orders, medication reviews and reconciliation.
- Stock and pharmacy foundations, controlled checks/register, follow-ups and handovers, medication errors and linked incidents, emergency access, downtime paper evidence, reporting and Settings.
- Connections to My Day, Tasks, staff competency, person profiles, site calendars, transport, health monitoring and incident workflows.
- Rory-aligned compact headers, connected counts and views, clearer status/action wording, phone cards, larger touch targets and contained chart scrolling. Initial review-dialog and Settings crashes were repaired.
- Export purpose and access checks, NZ calendar/date handling, retained history, canonical ownership checks and immutable evidence. Approved stock-pack infrastructure remains feature-gated.

The implemented list describes code coverage, not certification that every workflow has passed its release gates. Held actions below remain unavailable or incomplete.

## Verification evidence

| Check | Exact result and boundary |
| --- | --- |
| Full frontend tests | 65 files / 466 tests passed at `d90605140`; the only concurrent UI change was formatter-only `acfe39509`. Earlier 64-file run passed all 433 tests at `3ddda67fe`. |
| Final touch-control tests | All 58 cases across four affected UI suites passed at `029b7558f`, including drawer controls, phone register menus, shared entity interactions and history filters. The new test initially selected both desktop and mobile actions and used an unsupported query option; both test defects were corrected. |
| Full TypeScript and production build | Full TypeScript and Vite both passed at `029b7558f`; build completed in 6m17s. The rebuilt preview loads `app-DBMoLwNr.js`. |
| Initial consolidated backend | 1,911 cases: 1,518 passed / 393 failed at `92466adc2`. Complete console ledger covers 78 affected files; incomplete JUnit must not replace those totals. |
| Follow-up backend batches | First 133/143, second 215/217, third 166/170, fourth 142/146, fifth 198/212, sixth 450/475, seventh 392/480, eighth 240/285, ninth 54/76 passed. These overlap and must not be summed into a final pass total. |
| Original affected-file ledger | 71 of 78 files have a subsequent clean full-file run; seven retain failures in their latest run. Every originally affected file has been rerun. This bounded ledger contains 62 unresolved cases; broader CI failures are additional evidence, not included in this count. |
| Latest backend snapshot | Ninth batch at `0d1ff96c7`: 54 passed, 22 failed, 2,246 assertions, 405.40 seconds across four files. WorkerMedsTodayPayload and TaskProviderRowScope passed in full; MedicationGovernanceAuthorization retained six failures and ControlledDrugs retained sixteen. Earlier eighth batch: 240/285 passed, 4,637 assertions, 649.74 seconds across 20 files. |
| Syntax and source whitespace | All 406 changed PHP files passed syntax checks at `0d1ff96c7`; later application changes were UI only. Application/source whitespace checks passed at `53f888eb6`. Archived recovered patches retain their original provenance and formatting. |
| Final architecture safeguards | All 22 cases / 99 assertions across three files passed at `ed5b88f1a` in 7.12 seconds. Incident closure assertions now follow the shared service; the slot detector follows actual mutation receivers with the same three-writer allowlist and 15 added examples; medication UI/route assertions enforce the current exact capabilities. All three changed test files also passed syntax checks. These test-only changes leave production behavior unchanged. |
| New controlled reader regressions | All 6 privacy cases, 4 person/NZ-day boundary cases and 31 controlled-product cases passed in batch 8, including foreign-person concealment, DST boundaries, history limits and unchanged current stock/count evidence. |
| Security review | Independent review found no actionable issue in P01 preflight/batched re-offer reads, historical covert revocation and discrepancy exports within their reviewed boundaries. The separate additive controlled-reader privacy repair and numeric JSON fix are integrated and runtime-verified. Cached controlled replay still precedes current witness checking and remains a documented release blocker. |
| Paper export | Actual downloaded 34-page synthetic downtime PDF checked; person identity on all 24 person pages, controlled-medicine identity on all four controlled continuations, page numbers throughout and writing rows retained. |

Browser evidence uses `http://127.0.0.1:8765` and a separate synthetic database. Actual mobile scheduled-dose and PRN/effect-check journeys completed. Stock packs, Settings, person charts and history fit a 390px viewport; chart tables scroll within their own container. History person/date changes fetch the server and Back/Forward restore the selected NZ range. Its action menu supports keyboard dismissal/focus return. The final `029b7558f` build verifies the corrected history breadcrumb, event-specific medicine link and NZ timestamp. Controlled person/date changes, refresh and reload preserve current stock; Back restores the same read filters. Final phone measurements confirm all inspected drawer actions, record links, register medicine links and action controls are at least44px high. The drawer has no nested link/button elements; its body scrolls within the screen. Final phone and desktop screenshots are in `browser-verification.md`. No actual 200% browser-zoom proof has been captured.

The initial GitHub checks at published `12f4ce2d5` are **not green**: database bootstrap passed; foundation, eight feature shards and the desktop visual baseline failed, with other jobs still running when checked. Feature shards stop after their first failing batch, so this is not complete-suite coverage. All three observed architecture failures are corrected and locally verified above. The independent `ci-independent-triage-2026-10-04.md` records fourteen observed cases in ten other failure families, including that repaired incident assertion; the remaining thirteen are unresolved. Several have concrete conflicting baseline source contracts or fixtures, but baseline runtime behavior is unverified. The clinical event H&S row count remains unexplained. Twelve desktop screenshots differ from stored baselines. No snapshots have been accepted merely to suppress failures. Checks for the final pushed head require a fresh CI result; the earlier run is not evidence that the final head passed.

OneChartAdministrationSafety passed all 7 cases in the seventh batch. It did **not** pass the earlier second batch; only OneChartGovernanceWorkflow did then. The earlier P11 `can`-shadow theory was disproved: shared navigation lives under `auth.can.medications`; the `settingsCan` rename clarifies ownership, while separate fixture and repeated-query repairs address the verified failures.

## Automatic-review holds and unresolved release work

Automatic approval review required specific human confirmation for the following proposed actions. Blanket delegation while away did not satisfy those requests. Equivalent changes have not been substituted through another path.

1. New frontline stock-receive and expanded house-lead stock-update grants. Existing `stock.update` access remains; stock-pack flag remains off.
2. Unassigned-round dose-recording authority. Assignment gate remains; read-only task/calendar visibility does not imply recording authority.
3. Deleted/superseded order administration expansion and controlled receipt/pack writer adapters.
4. P06 after-commit stock-alert integration patch, preserved outside the integration branch.
5. P07 `277d20ba1b` controlled-dose linking/schema, waste-witness and PRN changes. Related failures must not be hidden or fixed by importing its migration indirectly.
6. P01 `d32897b78` emergency/offline expiry and forgotten-PIN recovery changes.
7. P10 historical paper-to-clinical posting. Immutable paper evidence and a truthful pending reason are retained; it does not become a posted dose.
8. P07 `1033a1f0d` combined controlled-source/test rewrite: rejected for insufficiently reviewed production scope and roughly 1,600 deleted test lines, including a lost date-filter assertion. It remains unapplied. The separately reviewed, approved and runtime-verified privacy alternative (`3e911ef28` plus `433e8df5a`) changes reader ownership and PIN scrubbing with six additive tests; it does not import the held writer package. Bounded test-only prerequisite/reader-contract updates preserve every original test method and strengthen date inclusion/exclusion checks.
9. P08b entered-in-error writer. Reader recognition of the marker does not enable the held transition.

The separately reviewed historical **revocation** repair only ends an existing covert authorisation, preserves the order and audits the action. It was approved and does not grant administration authority on a retired order.

Remaining release work includes the 62 cases in the original affected-file ledger and the wider CI findings. The P01 query/module-parity fixes and three reader-contract/count-response fixture corrections passed the ninth run. Controlled destruction reversal, replay-witness authority, lost metadata, stale count alerts and ordinary destruction/history contracts remain explicitly unresolved in the P07 case ledger. The site-specific brand projection is also absent on the new controlled page. These are a mixture of held changes, remaining implementation gaps and retained contract failures; they must not all be described as approval holds. No main-branch merge or deployment is justified by passing subsets.

See `latest-backend-results.md`, `latest-affected-file-results.json` and `latest-backend-failures.json` for the complete latest per-file and per-case evidence, and `p07-seventh-residual-dispositions.md` / `p07-reader-contract-followup.md` for the precise controlled-case boundaries. These dated results must not be represented as one complete final-suite run.

## Preservation and handoff

The primary checkout and Claude's original work are preserved. Integration uses its own dependency copy and synthetic database. Primary local dependencies were restored successfully from the unchanged lockfile (641 packages), without install scripts. No operational database migration, clinical write, destructive cleanup, force-push or production deployment has been performed.

See `browser-verification.md` for screenshots and actual observed journeys, `mockup-inventory.md` for approved design provenance, `session-registry.json` for chat ownership, and the dated independent reports for findings and their original review boundaries.


