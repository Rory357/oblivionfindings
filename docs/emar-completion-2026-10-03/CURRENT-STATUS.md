# eMAR implementation and verification status

## Pack stock and historical recovery — current release pass

The [pack and paper release ledger](pack-paper-release-2026-10-05.md) records the integrated stock, transport and historical-dose recovery contracts and their current acceptance boundary. Both implementations are present. Complete-file local acceptance now passes 155 pack/transport cases and 173 recovery cases; the additional cross-module acceptance batch passes 108 cases / 1,301 assertions and the final provider-connection file passes 11 / 184. Fresh candidate CI is still required. Full frontend acceptance passed 547 files / 3,661 tests, with additional final contract, navigation and desktop sizing checks recorded in that ledger. Oversized header controls are corrected across all 15 affected eMAR/Meds today headers, paper review dates use the shared readable NZ format, and stock now uses one compact paginated Rory table instead of repeated expanded person cards. The final stock build and desktop checks are recorded in the linked ledger. Earlier blanket implementation holds below are historical checkpoints, not a description of the current code. Production remains unmigrated and disabled by default for stock packs until release verification is complete.

## Whole-module audit repairs — 5 October 2026

The [whole-module repair ledger](whole-module-repairs-2026-10-05.md) supersedes prior asset and test counts. All eleven audit findings have repairs: PRN safety timing, linked controlled evidence, export and notification privacy, shift read authority, NZ round dates, person/house/day entry context, hero wrapping, Settings breadcrumbs and labelled modal fields. Additional repairs cover consistent NZ export clocks, stock-unit initialization and recoverable page-load failures.

The full frontend suite passed **541 files / 3,648 tests** and the final overlapping run passed **5 files / 41 tests**. Full TypeScript, scoped lint/format, final build and desktop checks at 1280×900 and 1440×900 passed; the verified asset is `app-BKfQQVwh.js`. The latest disjoint backend union passed **16 complete files / 445 tests / 6,767 assertions**, including clinical safety, privacy, exports, current site authority and entry-point context. The linked ledger retains the initial fixture failures and subsequent verified repairs without summing overlapping runs. Current work is **desktop web only**; earlier phone evidence below is historical and is not an ongoing workstream.

Draft PR #16 remains unmerged and undeployed. Pack-stock rollout, historical given/controlled/PRN/witnessed recovery and complete release acceptance remain held. The page-load recovery screen is verified; the intermittent dynamic-import failure's root cause remains unconfirmed. The separately requested Workforce chat is independent and outside this branch.

## Desktop eMAR modal controls — 5 October 2026

The user explicitly requires **desktop web only**. The [modal-controls audit](modal-controls-audit-2026-10-05.md)
records the 208-call source inventory and repairs for distorted switches and
checkboxes, dark switch contrast, form and wizard widths, review-card layout,
footer text, and unsaved on-call changes. The full frontend suite passed
**537 files / 3,633 tests**; the final settings/shell rerun passed 30 overlapping
tests. TypeScript, changed-source lint and formatting passed. Desktop browser
coverage and the final asset are recorded in the audit. Draft PR #16 remains
unmerged and undeployed; existing clinical release gates remain open.

## On-call card and cross-module modal fields — 5 October 2026

The [cross-module sizing follow-up](cross-module-modal-sizing-2026-10-05.md)
records the house-profile-style on-call card and compact date/time presentation
inherited through shared dialog and sheet shells. Fleet, transport, assets,
maintenance and location forms now inherit the same compact picker proportions.
The full frontend suite passed **537 files / 3,632 tests**; TypeScript, scoped
lint and formatting passed. Browser checks covered desktop and 320px transport
fields, exact-minute entry, cancellation/draft protection, asset date fields and
the on-call card's fit and destination. Full evidence and final build details
are in the follow-up. Existing clinical release gates remain open; these are UI
corrections in draft PR #16, not a deployment.

## Modal sizing follow-up — 5 October 2026

The [modal sizing audit](modal-sizing-audit-2026-10-05.md) records the latest UI
follow-up. All 72 eMAR date/time picker usages use the compact shared controls;
modal shells, confirmations and discard prompts consistently use frontline
sizing. Phone clock/dropdown overflow, footer wrapping and the oversized house
selector footer are corrected. The final build passed in 3m47s, the complete
frontend suite passed **536 files / 3,628 tests**, and TypeScript, scoped lint and
formatting passed. The browser verified `app-BSfYpu4V.js` at desktop, 720px and
320px CSS widths, including exact-minute entry, picker dismissal and house
switching. Draft PR #16 remains unmerged and undeployed; the clinical release
gates below remain open.

## Current repair pass — 5 October 2026

The [linked-workflow re-audit](linked-workflow-re-audit-2026-10-05.md) supersedes the prior final-asset, secondary-tab and CI claims below. Guided rounds prevent stale-chart next-dose recording and keep exact offline doses pending until acknowledged. Rory secondary-tab dimensions were corrected and measured. Medication error dialogs now protect unsaved work, explicitly discarded reports reopen empty, and phone discard controls measure 44px. Roster day grouping uses New Zealand dates; shift reports again support custom periods. Nonclinical users can open the general report hub without gaining medication data. The latest full frontend suite passed **536 files / 3,624 tests**; TypeScript, scoped lint and the 3m54s build passed. The browser verified `app-Czgn9PcE.js` and the oversight report-discard/reopen journey. Earlier phone draft recovery and custom report-date evidence remains recorded. Whole-file backend results and independent disposable-schema cleanup are in the current ledger.

Draft PR #16 remains unmerged and undeployed. Prior candidate `871482aa` was non-green: both Governance browser sizes passed, but backend shards, generic desktop, IT/security and twelve legacy screenshot comparisons failed. The follow-up fixes need a fresh full run. Consent readiness (10/163), tracking (20/451), IT setup and incident draft recovery (16/236), and protected incident-history fixture replay (6/358) now have whole-file passing results. Exact cleanup evidence is in the current ledger. The explicit clinical recovery and deployment gates remain open; this checkpoint is not a production-readiness certification.

The final gate review confirms that ordinary scheduled paper refused/withheld recovery is supported after exact evidence, giver confirmation and current authority checks. Historical given/controlled/PRN/required-second-person paper posting remains held, and offline timestamps cannot revive an expired emergency grant. Older blanket historical paper-posting holds below are superseded by this narrower current boundary.

## Current repair pass — 4 October 2026

The **Round privacy and final browser re-audit** section of the [completion re-audit](completion-re-audit-2026-10-04.md) now supersedes the CI and final-asset claims below. Round previews use exact canonical dose identities and viewer-scoped totals, and an earlier inaccessible round no longer hides later actionable work. The phone notification panel fits the viewport. Ceased medicines with no physical stock cannot acquire stock through a new controlled count. The latest complete remote run at `7cac7fb3398a6b6a487d7640e5d719e6d2f3ed2b` is non-green; corrected fixtures and browser checks require a new complete run. Draft PR #16 remains unmerged and undeployed.

The final bounded verification union is **230 distinct backend cases / 3,182 assertions across 17 files**, plus **70 focused frontend cases**. Changed frontend formatting/lint, full TypeScript and the final 4m05s build passed. The browser confirmed `app-C9NzB-qn.js`, exact round medicines at desktop/phone widths, modal background isolation and recovery. All 25 backend failures observed in the preceding remote head have later whole-file passing evidence. Revised full browser and final-head CI results remain outstanding; these bounded results do not certify production readiness.

The next two paragraphs preserve the preceding resumed checkpoint; use the newer ledger for current results.

The user resumed the safe-stop checkpoint. The **Resumed completion pass** section of the [completion re-audit](completion-re-audit-2026-10-04.md) is the current evidence ledger. Weekly count timing, current follow-ups in person/handover consumers, the default rebuilt person record, phone targets and bounded refused/withheld paper recovery are implemented. Privacy, paper/readiness, handover and corrected weekly concurrency checks passed. Browser testing completed fictional scheduled and PRN recording, an owned effect check, all three doses and completion of a guided round, and the My Day → person → MAR journey. The final build fixes readable phone medicine cards, 44px phone toolbar controls and the NZ birthday age mismatch. Fresh complete CI and the explicit clinical release gates remain outstanding; this is a draft candidate, not a production-readiness certification.

The follow-up CI audit repaired two eMAR test prerequisites/response expectations and one Finance approved-site fixture without weakening production access or clinical assertions. Their full files passed **7/127**, **8/36** and **6/98** tests/assertions respectively. Calendar badge and past-roster-date contrast are also corrected and checked in the actual preview. The final frontend asset is `app-C_KJp27P.js`. The first resumed remote run passed database bootstrap but exposed additional Finance update, H&S closure, HR/IT command, device-consent fixture and desktop snapshot failures. The current re-audit lists each unresolved family. Complete CI remains non-green; no main merge or deployment has occurred.

## Previous pushed checkpoint — preserved history

The paragraphs below preserve the previous pushed checkpoint; their weekly/import limitations and test totals must not be used as the current disposition.

The [completion repair and re-audit report](completion-re-audit-2026-10-04.md) is the current source of implementation and verification status after the critical audit of `7318603c0`. Work remains in [draft PR #16](https://github.com/Rory357/oblivionfindings/pull/16), not a production or clinical release. Main completed frontend changes; bounded backend repairs and independent review were delegated to GPT-6.1 Sol Extra high.

This pass corrects measured Rory header/tab geometry, the phone header, modal recovery and confirmation, person/house/day navigation, follow-up queue parity, report/alert destinations, destruction annotation semantics, controlled-dose provenance, current replay authority, ordinary disposal, metadata and count policy. The re-audit found and corrected further historical-count incident linkage, legacy creator provenance, filtered-stock recovery and order search, and shared follow-up link issues.

The complete frontend rerun passed **524 files / 3,579 tests**; five later direct-link regressions also passed. Full application-source lint passed. The controlled latest-result union passed **158 distinct cases**. The final full RBAC/Stock/readiness plus targeted deep-link batch passed **61/61 cases / 1,103 assertions**. Medication orders passed **38 tests / 794 assertions**, and the follow-up/worker/reporting/Tasks latest-result union passed **92 distinct cases**. Exact batches, type/build evidence, browser coverage and remaining gaps are recorded in the linked report. Overlapping test runs must not be added together.

The older 62-case affected-file ledger and 14-of-15 failed CI checkpoint below are **historical**, not the new candidate's current failure total. Many named controlled, follow-up, reporting, PIN and fixture cases have subsequently passed. Fresh complete CI and cross-module clinical acceptance remain required. Weekly count timing still lacks a reviewed anchor; canonical-only consumers still need deliberate preparation/import of legacy follow-ups. No operational import, main merge or deployment has occurred.

The historical approval holds below record the scope of earlier rejected packages. The current pass does not import those broad packages or expand clinical/role grants. Separately reviewed, narrowly scoped integrity repairs have been implemented and tested; the old statement that controlled linking, void semantics and replay checks remain wholly unimplemented is superseded by the current report. Preserve the historical records as provenance, not as current disposition.

## Historical checkpoint before the critical-audit repairs

The remainder records the earlier hero/navigation correction pass and its then-current evidence. Its unresolved-case statements are superseded only where the current report provides newer results.

## Scope and navigation

Claude's fourteen approved mockups and unfinished P01, P02 and P11 work have been recovered and carried into the isolated `codex/emar-completion-20261003` integration branch. Sixteen GPT-6.1 Sol Extra high chats handled page implementation and independent review, with at most three worker chats active alongside Main. Main alone reviews, integrates and pushes. All sixteen worker chats have finished their delegated work and are unpinned; release blockers remain assigned in this handoff.

The original long sidebar is consolidated into seven role-aware hubs: **Meds today; MAR & medicines; Orders & reviews; Stock & controlled drugs; Safety & oversight; Reports & audit; Settings**. Ordinary support workers see Meds today, with scoped person and task links. The additional capabilities remain available within the appropriate hubs and permissions.

The application remains one organisation across approved sites. Current staff eligibility, exact role permissions, person access and canonical record ownership govern every action. Legacy organisation columns do not establish a new tenant architecture.

## Implemented work

- Shared medication recording, person MAR/day/week charts, allergies and clinical readings, support agreements, independently checked orders, medication reviews and reconciliation.
- Stock and pharmacy foundations, controlled checks/register, follow-ups and handovers, medication errors and linked incidents, emergency access, downtime paper evidence, reporting and Settings.
- Connections to My Day, Tasks, staff competency, person profiles, site calendars, transport, health monitoring and incident workflows.
- Compact headers and connected views on the reachable legacy eMAR pages, clearer status/action wording, phone cards, larger touch targets and contained chart scrolling. Initial review-dialog and Settings crashes were repaired. The later correction pass replaces white list-tab containers with header scopes and corrects the shared record/Settings sub-tab geometry. See its exact acceptance boundary below.
- Export purpose and access checks, NZ calendar/date handling, retained history, canonical ownership checks and immutable evidence. Approved stock-pack infrastructure remains feature-gated.

The implemented list describes code coverage, not certification that every workflow has passed its release gates. Held actions below remain unavailable or incomplete.

## Hero and navigation re-audit — 4 October 2026

**The initial re-audit failed Rory's hero and tab rules.** The earlier blanket description of its headers as Rory-aligned was withdrawn. With the user's specific read-only approval, the seven live entry pages were inspected: six retained legacy heroes and white card tab strips; Settings used older sub-tab geometry. At that checkpoint, canonical Stock still selected the legacy renderer, MAR was configuration-dependent, and several new headers omitted required anatomy. Reviews → To check also had a reproduced selector defect, with the same mismatch for Reconciliation. The subsequent fixes below supersede those findings for the integration draft, not the deployment.

The [focused re-audit](hero-tabs-reaudit-2026-10-04/README.md) records nine findings, the active route/variant inventory and earlier evidence. That audit was documentation-only. The subsequent [implementation and verification report](hero-tabs-modal-fixes-2026-10-04.md) supersedes its application status: Main migrated the reachable legacy headers and tabs, corrected meter/search anatomy, replaced copied modal shells, added draft/error guards, split complex forms and improved phone actions. Sol repaired order selectors, PRN house details, truthful import/generation feedback and controlled-register house branding. These fixes are in the integration draft; the previously inspected production pages have not been deployed from it. Permissions, feature flags and existing release holds remain unchanged. No production client records or screenshots were exported.

## Verification evidence

| Check                                                   | Exact result and boundary                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Header, navigation and modal correction pass, 4 October | 62 files / 431 selected frontend tests and 82 bounded backend tests / 1,577 assertions passed. Full TypeScript and changed-file lint passed. Final build: 4m3s; browser confirmed `app-B8ZEru95.js`, with no hot-file override. Desktop and 390px phone journeys and exact limitations are recorded in [the implementation report](hero-tabs-modal-fixes-2026-10-04.md). These are not a rerun of the full clinical release suite.                            |
| Full frontend tests                                     | 65 files / 466 tests passed at `d90605140`; the only concurrent UI change was formatter-only `acfe39509`. Earlier 64-file run passed all 433 tests at `3ddda67fe`.                                                                                                                                                                                                                                                                                            |
| Final touch-control tests                               | All 58 cases across four affected UI suites passed at `029b7558f`, including drawer controls, phone register menus, shared entity interactions and history filters. The new test initially selected both desktop and mobile actions and used an unsupported query option; both test defects were corrected.                                                                                                                                                   |
| Full TypeScript and production build                    | Full TypeScript and Vite both passed at `029b7558f`; build completed in 6m17s. The rebuilt preview loads `app-DBMoLwNr.js`.                                                                                                                                                                                                                                                                                                                                   |
| Initial consolidated backend                            | 1,911 cases: 1,518 passed / 393 failed at `92466adc2`. Complete console ledger covers 78 affected files; incomplete JUnit must not replace those totals.                                                                                                                                                                                                                                                                                                      |
| Follow-up backend batches                               | First 133/143, second 215/217, third 166/170, fourth 142/146, fifth 198/212, sixth 450/475, seventh 392/480, eighth 240/285, ninth 54/76 passed. These overlap and must not be summed into a final pass total.                                                                                                                                                                                                                                                |
| Original affected-file ledger                           | 71 of 78 files have a subsequent clean full-file run; seven retain failures in their latest run. Every originally affected file has been rerun. This bounded ledger contains 62 unresolved cases; broader CI failures are additional evidence, not included in this count.                                                                                                                                                                                    |
| Latest backend snapshot                                 | Ninth batch at `0d1ff96c7`: 54 passed, 22 failed, 2,246 assertions, 405.40 seconds across four files. WorkerMedsTodayPayload and TaskProviderRowScope passed in full; MedicationGovernanceAuthorization retained six failures and ControlledDrugs retained sixteen. Earlier eighth batch: 240/285 passed, 4,637 assertions, 649.74 seconds across 20 files.                                                                                                   |
| Syntax and source whitespace                            | All 406 changed PHP files passed syntax checks at `0d1ff96c7`; later application changes were UI only. Application/source whitespace checks passed at `53f888eb6`. Archived recovered patches retain their original provenance and formatting.                                                                                                                                                                                                                |
| Final architecture safeguards                           | All 22 cases / 99 assertions across three files passed at `ed5b88f1a` in 7.12 seconds. Incident closure assertions now follow the shared service; the slot detector follows actual mutation receivers with the same three-writer allowlist and 15 added examples; medication UI/route assertions enforce the current exact capabilities. All three changed test files also passed syntax checks. These test-only changes leave production behavior unchanged. |
| New controlled reader regressions                       | All 6 privacy cases, 4 person/NZ-day boundary cases and 31 controlled-product cases passed in batch 8, including foreign-person concealment, DST boundaries, history limits and unchanged current stock/count evidence.                                                                                                                                                                                                                                       |
| Security review                                         | Independent review found no actionable issue in P01 preflight/batched re-offer reads, historical covert revocation and discrepancy exports within their reviewed boundaries. The separate additive controlled-reader privacy repair and numeric JSON fix are integrated and runtime-verified. Cached controlled replay still precedes current witness checking and remains a documented release blocker.                                                      |
| Paper export                                            | Actual downloaded 34-page synthetic downtime PDF checked; person identity on all 24 person pages, controlled-medicine identity on all four controlled continuations, page numbers throughout and writing rows retained.                                                                                                                                                                                                                                       |

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

Remaining release work includes the dated 62-case affected-file ledger and wider CI findings. The P01 query/module-parity fixes and three reader-contract/count-response fixture corrections passed the ninth run. Controlled destruction reversal, replay-witness authority, lost metadata, stale count alerts and ordinary destruction/history contracts remain explicitly unresolved in the P07 case ledger. The later UI pass fixes the controlled house-brand projection; its previously failing branding case and twelve reader/privacy cases pass, but the complete legacy file was not rerun here, so the dated ledger is retained. These are a mixture of held changes, remaining implementation gaps and retained contract failures; they must not all be described as approval holds. No main-branch merge or deployment is justified by passing subsets.

See `latest-backend-results.md`, `latest-affected-file-results.json` and `latest-backend-failures.json` for the complete latest per-file and per-case evidence, and `p07-seventh-residual-dispositions.md` / `p07-reader-contract-followup.md` for the precise controlled-case boundaries. These dated results must not be represented as one complete final-suite run.

## Preservation and handoff

The primary checkout and Claude's original work are preserved. Integration uses its own dependency copy and synthetic database. Primary local dependencies were restored successfully from the unchanged lockfile (641 packages), without install scripts. No operational database migration, clinical write, destructive cleanup, force-push or production deployment has been performed.

See `browser-verification.md` for screenshots and actual observed journeys, `mockup-inventory.md` for approved design provenance, `session-registry.json` for chat ownership, and the dated independent reports for findings and their original review boundaries.
