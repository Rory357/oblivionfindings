# eMAR implementation and verification status

Updated 4 October 2026, 05:20 Pacific/Auckland. This file takes precedence over the dated historical checkpoints in this folder. Work remains in progress; this is not a production or clinical release.

## Scope and navigation

Claude's fourteen approved mockups and unfinished P01, P02 and P11 work have been recovered and carried into the isolated `codex/emar-completion-20261003` integration branch. Sixteen GPT-6.1 Sol Extra high chats handled page implementation and independent review, with at most three worker chats active alongside Main. Main alone reviews, integrates and pushes.

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
| Full frontend tests | 60 suites / 403 tests passed at `8c00c1eee`. |
| Full TypeScript and production build | Passed at `a4eb818e2`; retained clinical-history UI improvements are being completed and require a new build. |
| Initial consolidated backend | 1,911 cases: 1,518 passed / 393 failed at `92466adc2`. Complete console ledger covers 78 affected files; incomplete JUnit must not replace those totals. |
| Follow-up backend batches | First 133/143, second 215/217, third 166/170, fourth 142/146, fifth 198/212, sixth 450/475 passed. These overlap and must not be summed into a final pass total. |
| Original affected-file ledger | 51 of 78 files have a subsequent clean full-file run; 11 retain failures in their latest run; 16 await a follow-up run, including held-authority paths. |
| Latest backend snapshot | Sixth batch at `e734a69b6`: 450 passed, 25 failed, 5,983 assertions. Remaining cases are assigned and are not silently skipped. |
| Security review | Focused ownership, role, privacy and replay review found no actionable issue in historical covert revocation `53f8fe03c`, discrepancy exports `4157f7290` and retained log filters `7cbb808bc`. Runtime evidence remains separate. |
| Paper export | Actual downloaded 34-page synthetic downtime PDF checked; person identity on all 24 person pages, controlled-medicine identity on all four controlled continuations, page numbers throughout and writing rows retained. |

Browser evidence uses `http://127.0.0.1:8765` and a separate synthetic database. Actual mobile scheduled-dose and PRN/effect-check journeys completed. Stock, Settings and person charts fit a 390px viewport; chart tables scroll within their own container. The medication history screen still needs its final compact header/date-filter repair and browser check. No actual 200% browser-zoom proof has been captured.

OneChartAdministrationSafety did **not** pass the second batch: only OneChartGovernanceWorkflow did. The sixth batch still has one controlled witness fixture failure. Likewise, the earlier P11 `can`-shadow theory was disproved: shared navigation lives under `auth.can.medications`; the `settingsCan` rename clarifies ownership, while separate fixture and repeated-query repairs address the verified failures.

## Automatic-review holds and unresolved release work

Automatic approval review required specific human confirmation for the following proposed actions. Blanket delegation while away did not satisfy those requests. Equivalent changes have not been substituted through another path.

1. New frontline stock-receive and expanded house-lead stock-update grants. Existing `stock.update` access remains; stock-pack flag remains off.
2. Unassigned-round dose-recording authority. Assignment gate remains; read-only task/calendar visibility does not imply recording authority.
3. Deleted/superseded order administration expansion and controlled receipt/pack writer adapters.
4. P06 after-commit stock-alert integration patch, preserved outside the integration branch.
5. P07 `277d20ba1b` controlled-dose linking/schema, waste-witness and PRN changes. Related failures must not be hidden or fixed by importing its migration indirectly.
6. P01 `d32897b78` emergency/offline expiry and forgotten-PIN recovery changes.
7. P10 historical paper-to-clinical posting. Immutable paper evidence and a truthful pending reason are retained; it does not become a posted dose.
8. P07 `1033a1f0d` combined controlled-source/test rewrite: rejected for insufficiently reviewed production scope and roughly 1,600 deleted test lines, including a lost date-filter assertion. It remains unapplied; a separate additive privacy regression candidate is under independent assessment.
9. P08b entered-in-error writer. Reader recognition of the marker does not enable the held transition.

The separately reviewed historical **revocation** repair only ends an existing covert authorisation, preserves the order and audits the action. It was approved and does not grant administration authority on a retired order.

Remaining release work includes the assigned backend failures, final history UI verification, current whole-change syntax/diff checks, and reconciliation of the full failure ledger. Controlled destruction reversal and replay-witness findings remain under investigation and must have explicit final dispositions. No main-branch merge or deployment is authorised by a passing subset alone.

## Preservation and handoff

The primary checkout and Claude's original work are preserved. Integration uses its own dependency copy and synthetic database. Primary local dependencies were restored successfully from the unchanged lockfile (641 packages), without install scripts. No operational database migration, clinical write, destructive cleanup, force-push or production deployment has been performed.

See `browser-verification.md` for screenshots and actual observed journeys, `mockup-inventory.md` for approved design provenance, `session-registry.json` for chat ownership, and the dated independent reports for findings and their original review boundaries.
