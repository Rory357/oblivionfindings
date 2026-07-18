# Control Room multi-role manual acceptance and usability audit — final closure

## Executive decision

**Release decision: accepted for the tested Control Room → Incident → Health & Safety governance journey.**

The original seven-role audit findings have all been remediated, independently reviewed, deployed to the development server, and repeated in actual desktop Chrome at 1440 × 900. The golden relay completed through final closure with seven distinct fixture accounts. Separate live records also passed alternate branches A–F. No required finding or acceptance row remains unresolved.

The live runtime code SHA for final acceptance was `20a9691811583b62cd371c1ab047d130c4cc3286`. That SHA includes the post-acceptance shift-history query repair found when the incoming operator accepted the handover. The repaired `/control-room/shifts` page was then reloaded in Chrome and showed the single incoming active shift without a server error.

## 1. Final tested baseline

| Item | Final evidence |
|---|---|
| Live URL | `https://oblivionfindings.com` |
| Server checkout | clean `main` at `/var/www/oblivionfindings` |
| Runtime SHA | `20a9691811583b62cd371c1ab047d130c4cc3286` |
| Browser | actual desktop Chrome through the Codex Chrome extension |
| Viewport | 1440 × 900 |
| Final acceptance date | 18 July 2026, Pacific/Auckland |
| Seeder | `IncidentHandoverE2ESeeder` |
| Marker | `INCIDENT-HANDOVER-E2E-2026-07-16` |
| Site / client / tenant | site `9401`, client `9401`, tenant `1` |
| Live fixture backup | two verified 131 MB SQL backups under `/var/backups/oblivionfindings-before-control-room-remediation-*` |
| Migration state | all remediation migrations through `2026_07_17_000200_backfill_same_tenant_hs_organization` are `Ran` |
| WorkSafe consistency | `inconsistent: 0` from `health-safety:worksafe-decision-counts --json` |
| Browser console | empty error/warning capture on the final incoming-shift page |
| HTTP health | home and login both returned `200` |
| Server log | zero `ERROR`, `CRITICAL`, `ALERT`, or `EMERGENCY` entries in `laravel-2026-07-18.log` |

The canonical checkout at `C:\Users\steph\Herd\oblivionfindings` remained untouched. All implementation, verification, evidence, and publication work used the isolated `codex/control-room-hs-remediation` worktree.

## 2. Golden journey records and database truth

| Record | Reference / ID | Final state | Final actor evidence |
|---|---|---|---|
| Control Room alert | `CR-2026-2189` / `2258` | Closed | resolved and closed by `incident-e2e-incoming@demo.test` |
| Incident | `INC-2026-0142` / `261` | Closed | reviewed and closed by `incident-e2e-reviewer@demo.test` |
| H&S event | `HS-2026-0081` / `137` | Closed | accepted and closed by `incident-e2e-owner@demo.test` |
| Investigation | `INV-2026-9008` / `8` | Completed | one recommendation fully dispositioned |
| Corrective action | `CA-2026-9011` / `11` | Closed | completed by action owner; independently verified and closed by verifier |
| Source Control Room task | task `17` | Transferred | reciprocal one-for-one link to action `11` |
| Control Room evidence | pack `7` | Complete | one retained operational evidence item |
| Corrective-action evidence | attachments `2` and `3` | Retained | two private PNG evidence files uploaded by the action owner |
| Outgoing shift | shift `8` | Completed / handover accepted | prepared by user `112`, accepted by user `118` |
| Incoming shift | shift `9` | Active | sole active shift, led by `incident-e2e-incoming@demo.test` |

Read-only database verification returned a cardinality of exactly `1/1/1/1/1` for the CR/INC/HS/INV/CA references. Alert, incident, and H&S site IDs were all `9401`; client IDs were all `9401`; site, client, event, investigation, and action tenant IDs were all `1`.

The explicit WorkSafe decision on `HS-2026-0081` was recorded at `2026-07-17T22:41:44Z` by `incident-e2e-owner@demo.test`, with source `manual` and a reason explaining why the event did not meet the HSWA notifiable-event threshold. The stored decision is false by explicit actor decision, not by an implicit default.

The corrective-action history proves the complete rework loop:

1. action owner started action `11`;
2. action owner completed it at `2026-07-17T23:22:59Z`;
3. independent verifier returned it at `23:23:47Z` with a signed-checklist request;
4. action owner amended notes, added the second retained file, and resubmitted at `23:25:52Z`;
5. verifier recorded evidence review and effectiveness at `23:26:35Z`;
6. verifier closed the action at `23:26:53Z`.

## 3. Seven-persona live acceptance

| Tester | Persona/account | Result | Live outcome | Primary screenshots |
|---:|---|---:|---|---|
| 1 | Experienced Control Room Operator — `incident-e2e-operator@demo.test` | Pass | Selected the intended client, claimed/acknowledged/triaged, recorded typed immediate controls, created the scoped task and evidence, escalated, and created the exact incident/H&S handover. | `tester-1-operator-before.png`, `tester-1-operator-after.png` |
| 2 | Incident Reviewer / Provider Manager — `incident-e2e-reviewer@demo.test` | Pass | Found the journey by natural language and references, reviewed linked evidence, completed manager review, saw direct closure requirements, and later closed the incident. | `tester-2-reviewer-before.png`, `tester-2-reviewer-after.png`, `tester-2-reviewer-incident-closed.png` |
| 3 | H&S Owner — `incident-e2e-owner@demo.test` | Pass | Found `Awaiting H&S acceptance`, accepted ownership, recorded explicit WorkSafe truth, completed the investigation and disposition, transferred task `17`, and closed H&S after action closure. | `tester-3-hs-owner-before.png`, `tester-3-hs-owner-after.png`, `tester-3-hs-owner-closed.png` |
| 4 | Corrective Action Owner / Site Manager — `incident-e2e-action-owner@demo.test` | Pass | Found the assigned action, saw recommendation/source task, started, uploaded evidence, submitted, saw the exact return reason, amended notes/evidence, and resubmitted. | `tester-4-action-owner-before.png`, `tester-4-action-owner-uploaded.png`, `tester-4-action-owner-resubmitted.png`, `tester-4-action-owner-after.png` |
| 5 | Independent H&S Verifier — `incident-e2e-verifier@demo.test` | Pass | Saw owner submission and retained files, returned once, saw old/new history after resubmission, confirmed evidence review/effectiveness, verified, and closed. | `tester-5-verifier-before.png`, `tester-5-verifier-returned.png`, `tester-5-verifier-after.png` |
| 6 | Incoming Control Room Operator / Closure Auditor — `incident-e2e-incoming@demo.test` | Pass | Reviewed a frozen four-alert required set and zero-item carry-forward summary, accepted handover, became shift `9` lead, proved alert closure was blocked until H&S and Incident closure, then resolved and closed the alert. | `tester-6-outgoing-handover-final-review.png`, `tester-6-incoming-handover-before.png`, `tester-6-incoming-handover-after.png`, `tester-6-alert-close-gate.png`, `tester-6-alert-closed-after-governance.png`, `tester-6-incoming-active-shift-after-hotfix.png` |
| 7 | Novice Support Worker — `incident-e2e-worker@demo.test` | Pass | Found the five-item closed journey, saw plain-language ownership and no privileged mutation CTA, opened read-only destinations, used browser Back without losing filters, closed with Escape, and regained focus on the invoking task row. | `tester-7-novice-filtered-tasks-before.png`, `tester-7-novice-read-only-destination.png`, `tester-7-novice-escape-focus-return.png` |

No administrator account substituted for a required role.

## 4. Acceptance criteria 1–17

| # | Required acceptance | Result | Direct evidence |
|---:|---|---:|---|
| 1 | One Control Room alert exists | Pass | exact reference count `1`, alert `2258` |
| 2 | One official incident exists | Pass | exact reference count `1`, incident `261` |
| 3 | One H&S event exists | Pass | exact reference count `1`, event `137` |
| 4 | CR, INC, and HS links agree | Pass | incident and H&S both point to alert `2258`; incident points to H&S `137` |
| 5 | Notes, task, evidence, client, site, and timing survive | Pass | immediate controls are visible on linked evidence; task `17`, pack `7`, site/client `9401`, and journey times remain retained |
| 6 | H&S explicitly accepts ownership | Pass | accepted by the H&S owner at `2026-07-17T22:41:23Z` |
| 7 | WorkSafe state is complete and consistent | Pass | explicit actor/time/reason/source false decision; global inconsistent count `0` |
| 8 | Investigation is complete | Pass | `INV-2026-9008` is completed |
| 9 | Every recommendation is dispositioned | Pass | the sole recommendation produced action `11` with explicit owner/due/source |
| 10 | Every corrective action is independently verified or closed | Pass | `CA-2026-9011` is closed; owner/completer differ from verifier |
| 11 | Incident review and follow-ups are complete | Pass | manager review is recorded and incident `261` is closed |
| 12 | Operational alert is resolved and closed | Pass | resolved at `23:46:12Z`, closed at `23:48:14Z` by incoming operator |
| 13 | H&S governance is closed independently | Pass | H&S closed at `23:46:51Z` by H&S owner after action closure |
| 14 | Universal Tasks has no duplicate active responsibility | Pass | query `INC-2026-0142`: active count `0`; terminal history count `5` (CA, INC, HS, CR, INV) |
| 15 | History preserves references and audit trail | Pass | five terminal task rows plus action audit transitions and reciprocal transfer refs retained |
| 16 | Every role sees only authorised information and controls | Pass | novice has read-only destinations/no mutation CTAs; tagged records use only site/client `9401`; cross-site tagged count `0` |
| 17 | No unexplained HTTP, modal, stale-state, or console failure | Pass | final Chrome console error/warning capture `[]`; live home/login `200`; current-day severe Laravel log count `0`; production browser gate had zero strict request/page/console exceptions |

## 5. Alternate workflow branches A–F

| Branch | Result | Live record and outcome | Screenshots |
|---|---:|---|---|
| A. Routine alert with no incident | Pass | `CR-E2E-HANDOVER-02` / `2257` closed with no incident or H&S record | `alternate-a-routine-before.png`, `alternate-a-routine-after.png` |
| B. False-positive sensor detection | Pass | `CR-2026-2190` / `2259` dismissed as a false positive, removed from operational pressure, audit retained | `alternate-b-false-positive-before.png`, `alternate-b-false-positive-after.png` |
| C. Resolved alert later requiring an incident | Pass | `CR-2026-2191` / `2260` reopened through the incident path to `INC-2026-0143` and `HS-2026-0082`; original evidence pack retained | `alternate-c-reopen-before.png`, `alternate-c-reopen-after.png`, `alternate-c-evidence-continuity-after.png` |
| D. Snooze, unsnooze, and escalation | Pass | `CR-2026-2192` / `2261` snoozed, returned to the queue, and escalated to level 4 with applicable SLA truth retained | `alternate-d-snooze-before.png`, `alternate-d-snoozed.png`, `alternate-d-unsnooze-escalation-after.png` |
| E. One-for-one task transfer | Pass | `CR-2026-2193` → `INC-2026-0144` → `HS-2026-0083` → `INV-2026-9009` → `CA-2026-9012`; source task `18` has exactly one reciprocal action | `alternate-e-task-transfer-before.png`, `alternate-e-one-for-one-handover-review.png`, `alternate-e-one-for-one-after.png` |
| F. Every closure blocker and recovery | Pass | `CR-2026-2194` / `2263`, `INC-2026-0145`, and `HS-2026-0084` each exposed direct blockers, retained typed text, and then closed in the required order | `alternate-f-closure-recovery-before.png`, `alternate-f-hs-blocked.png`, `alternate-f-incident-blocked.png`, `alternate-f-alert-blocked.png`, `alternate-f-closure-recovery-after.png` |

All alternate records are confined to site/client `9401`; the tagged cross-site count is `0`.

## 6. Findings D-01–D-19 final disposition

| ID | Finding area | Status | Final evidence |
|---|---|---:|---|
| D-01 | Verifier evidence visibility | Closed | Tester 5 saw completion notes, both retained files, return history, resubmission, and effectiveness controls. |
| D-02 | Explicit WorkSafe decision | Closed | Tester 3 recorded actor/time/reason/source truth; H&S gate uses that explicit state. |
| D-03 | Corrective-action task status integrity | Closed | completed-but-unverified remains an active responsibility; only final closure enters history. |
| D-04 | Required site-scoped ownership | Closed | action creation retained dedicated owner, due date, priority, and source. |
| D-05 | Bounded handover operability | Closed | frozen required set `4`, reviewed set `4`, priority set `[2258]`, carry-forward total `0`, accepted into shift `9`. |
| D-06 | Natural-language and journey search | Closed | reviewer/owner/novice found work by client, site, task title, and CR/INC/HS/INV/CA refs. |
| D-07 | Duplicate responsibility | Closed | task `17` is transferred and reciprocally linked to exactly one action; active task count is `0`. |
| D-08 | Operational evidence continuity | Closed | canonical Incident/H&S surfaces show typed immediate controls, task ownership, pack/item, and source links. |
| D-09 | Permission-aware task UX | Closed | novice sees owner/read-only guidance and no forbidden mutation CTA or bare authorization dead end. |
| D-10 | Closure semantics | Closed | Resolve and Close are distinct; direct H&S/Incident blockers prevented premature alert closure. |
| D-11 | Picker reliability | Closed | live selected IDs persisted for client, operator, H&S owner, action owner, and verifier. |
| D-12 | Return-for-rework visibility | Closed | owner and verifier both saw the exact signed-checklist return reason and old/new history. |
| D-13 | Date-only integrity | Closed | date-only storage/rendering tests and live investigation/action dates remain stable. |
| D-14 | Action-owner findability and scope | Closed | dedicated action owner found and completed assigned work; no fallback account was used. |
| D-15 | H&S acceptance findability | Closed | dashboard Attention surfaced the exact `Awaiting H&S acceptance` event and deep link. |
| D-16 | Escape focus restoration | Closed | one Escape closed the novice drawer and restored focus to the invoking `CA-2026-9011` row. |
| D-17 | Controlled Select state | Closed | 122-test frontend gate and production browser run emitted no controlled/uncontrolled warning. |
| D-18 | Human audit/readability copy | Closed | shared journey labels, responsibility wording, and terminology help rendered in all four modules. |
| D-19 | Navigation and menu consistency | Closed | browser Back preserved `bucket=done&q=INC-2026-0142`; Escape/Close returned to the same row; menus remained usable. |

## 7. Automated and release-gate evidence

| Gate | Exact final result |
|---|---|
| Backend matrix | `1,314` tests, `10,150` assertions, `1,907.19s` |
| Frontend matrix | `23` files, `122` tests, `42.87s`; no controlled/uncontrolled warning |
| Client production build | `4,970` modules in `3m 09s` |
| SSR production build | `1,622` modules in `43.12s` |
| Production browser specs | golden seven-persona relay plus alternate A–F: `2` tests passed in `2.7m` with strict console/page/request/focus/duplicate guards |
| Post-acceptance shift hotfix | `ControlRoomShiftControllerTest`: `8` tests, `72` assertions; Pint and PHP syntax clean |
| Deployment build after hotfix | `4,970` modules in `2m 54s`; deploy script completed successfully |
| Static gates | Wayfinder, TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, and `git diff --check` all passed |
| Independent review | final verdict `Ready to merge: Yes`; no Critical, Important, or Minor findings |

The incoming handover acceptance initially committed successfully but its redirect hit MySQL `Out of sort memory` while querying recent shifts with an invalid mixed status predicate. The repair changed history to the real terminal shift status, was proven red then green, pushed as `20a969181`, deployed, and rechecked in Chrome. The screenshot `tester-6-incoming-active-shift-after-hotfix.png` is the final live proof.

## 8. Evidence inventory

The committed evidence directory is:

`output/manual-audits/control-room-multi-role-remediation-2026-07-16/`

It contains 42 PNG screenshots plus the redacted fixture evidence text. The two tiny PNGs attached to the corrective action were stored through the application's private evidence model because the Chrome extension environment denied programmatic file-chooser access; both retained files, metadata, history, download controls, and independent verifier review were then exercised through the live UI.

## 9. Final closure statement

All seven personas, all 17 acceptance criteria, alternate branches A–F, and findings D-01–D-19 are directly evidenced as Pass or Closed. The final database and log checks agree with the UI. No required work remains for this audit scope.
