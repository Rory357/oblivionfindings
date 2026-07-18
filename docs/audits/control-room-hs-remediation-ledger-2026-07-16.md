# Control Room / Incident / H&S remediation completion ledger

## Final state

This ledger is the authoritative completion record for the findings in `control-room-multi-role-manual-ux-audit-2026-07-16.md`.

Final result: **complete and accepted**.

- all 19 findings are Closed;
- all seven live personas are Pass;
- all 17 golden acceptance criteria are Pass;
- live alternate branches A–F are Pass;
- local, production-browser, deployment, database, log, and screenshot evidence is recorded;
- no required remediation work remains.

Runtime acceptance SHA: `20a9691811583b62cd371c1ab047d130c4cc3286`.

## Completion rule

A finding is Closed only when its canonical implementation exists, the focused regression is green, the complete release gate is green, and the applicable live Chrome/database/log proof is recorded. Every row below meets that rule.

## Finding closure matrix

| ID | Tasks | Canonical implementation | Automated evidence | Live evidence | Status |
|---|---:|---|---|---|---:|
| D-01 | 8, 9 | Private retained evidence upload/download; completion notes; evidence-first verifier pane; prior return/resubmission history; explicit evidence review and effectiveness; separation of duties | Task 8: 9 backend tests/128 assertions and 23 frontend tests. Task 9: 67 backend tests/490 assertions and 25 frontend tests. Included in full release gate. | `CA-2026-9011` retained attachments `2` and `3`; verifier returned once, saw old/new submissions, verified, and closed. `tester-5-verifier-*` | Closed |
| D-02 | 2–4 | Nullable explicit WorkSafe decision schema; conservative migration/report; actor/time/reason/source API and audit; truthful closure gate; capability-gated record/notify/acknowledge workflow | Schema/report 5 tests/29 assertions; H&S/incident/source regression 155 tests/1,246 assertions; Task 4 backend 44 tests/433 assertions and 17 frontend tests | `HS-2026-0081` explicit false decision at `2026-07-17T22:41:44Z`; global inconsistent count `0`; branch F direct WorkSafe blocker/recovery | Closed |
| D-03 | 10 | Universal Tasks `displayState` separates module status from responsibility truth; only final closure enters history | 24 backend tests/223 assertions and 3 frontend tests; done-bucket regression in Task 19 | Golden query has active count `0` and terminal history count `5`; action did not enter history before closure | Closed |
| D-04 | 5–7 | Unique source task; required eligible site-scoped action owner, due date, priority, and source decision across service and HTTP boundaries | schema/reconciliation 9 tests/59 assertions; focused contract 69 tests/309 assertions; surrounding regression 204 tests/1,095 assertions; 21 frontend tests | dedicated action owner found `CA-2026-9011`, completed and resubmitted it; owner/due/source persisted | Closed |
| D-05 | 15, 16 | Uncapped seven-criterion handover scope; frozen required set and carry-forward summary; prepare/accept access recheck; audited stale recovery; deterministic current fixture shift | Task 15: 110 backend tests/718 assertions plus query-budget proof and 3 frontend tests. Task 16: 19 backend tests/238 assertions and 4 frontend tests | shift `8` froze/reviewed four required alerts, priority `[2258]`, carry-forward `0`; user `118` accepted; sole current shift `9` created | Closed |
| D-06 | 10 | SQL-filtered journey search includes client, site, nested task, responsibility owners, and CR/INC/HS/INV/CA refs without serialising private search terms | 24 backend tests/223 assertions and 3 frontend tests; beyond-cap search regression | reviewer, owner, and novice found the golden chain by known natural-language and reference inputs | Closed |
| D-07 | 5–7, 10 | Atomic reciprocal source-task/action transfer; uniqueness; accepted-owner/due/source requirements; exact retry idempotency; terminal reconciliation | 69-test/309-assertion transfer contract plus uniqueness, retry-after-closure, terminal-task, missing-due, and task-reconciliation regressions | task `17` status transferred, compatibility target `11`, reciprocal count `1`; action source task `17`; alternate E repeats one-for-one transfer | Closed |
| D-08 | 12, 13 | Typed first-class operator notes; required serious immediate controls; one `LinkedOperationalEvidencePresenter`; parent-scoped downloads; canonical/legacy journey resolution; truthful separation from official attachments/follow-ups | Task 12: 161 backend tests/1,404 assertions and 11 frontend tests. Task 13: 55 backend tests/864 assertions and 30 frontend tests | reviewer saw exact immediate controls, evidence pack/item, task title/owner, and source links from canonical Incident/H&S surfaces | Closed |
| D-09 | 11 | Shared Control Room access service; role-valid task destinations; list-only guidance; permission-drift recovery to validated `/tasks` return URL; hidden unauthorized mutations | 177 backend tests/1,246 assertions and 12 frontend tests; exact-record cap/privacy/authorization coverage | novice opened read-only journey destinations, saw no privileged CTA, and encountered no bare authorization dead end | Closed |
| D-10 | 14, 18 | One server-owned `JourneyGate` contract across CR/Incident/H&S; Resolve distinct from Close; locked mutation gates; canonical ownership validation; permission-aware direct requirements | Task 14: 112 backend tests/829 assertions plus focused payload regressions and 51 frontend tests. Task 18: 51 backend tests/541 assertions plus historical-link regression | golden alert Close listed direct `INC-2026-0142` and `HS-2026-0081` blockers; branch F proved all three blockers, preserved input, and recovered in order | Closed |
| D-11 | 17 | Controlled shared Select; exact mouse/keyboard option commit; persisted-ID display on reopen | affected frontend set: 5 files/43 tests with click, keyboard, competing-highlight, exact-ID, reopen, and warning assertions | exact client/operator/H&S owner/action owner/verifier identities persisted throughout live relay | Closed |
| D-12 | 9 | Return reason and audit history in action payload, owner card, and verifier pane | 67 backend tests/490 assertions and 25 frontend tests | owner and verifier saw the signed-checklist return reason; audit rows `14380` and `14382` prove return and amended resubmission | Closed |
| D-13 | 17 | `formatDateOnly` validates and renders `YYYY-MM-DD` without creating an instant; presenters retain stored calendar values | backend 73 tests/179 assertions plus timezone-adjacent frontend tests and production builds | golden and alternate E date-only investigation/action values persisted through completion | Closed |
| D-14 | 6, 7, 10 | Explicit responsibility origin; site-scoped owner notification and assignment; reciprocal handover audit | 69-test/309-assertion ownership/transfer contract plus active-HR, tenant, source-choice, audit, and notification coverage | dedicated action-owner account found assigned reference and completed the full owner workflow; no fallback account used | Closed |
| D-15 | 18 | H&S dashboard Attention starts with complete viewer/site-scoped `Awaiting H&S acceptance` worklist and exact event destination | 51 backend tests/541 assertions; dashboard contract in 7 frontend files/58 tests | H&S owner began from dashboard Attention and accepted exact event `137` | Closed |
| D-16 | 11, 18 | task drawer owns initial focus, restores invoking-row ref through Escape/Close, and preserves originating query | Back/Close/Escape/focus coverage in 7 frontend files/58 tests | one Escape closed novice drawer and restored focus to the exact invoking `CA-2026-9011` row | Closed |
| D-17 | 17 | empty Select values remain controlled and survive empty-to-persisted rerender | controlled-state regression; complete 23-file/122-test frontend gate has zero warning | live paired browser relay traversed selection surfaces with empty console warning capture | Closed |
| D-18 | 9, 18 | central human journey-label map; safe `Activity recorded` fallback; reusable terminology help for status/severity/priority/escalation/SLA/governance | label/fallback/help contracts in 7 frontend files/58 tests plus client/SSR builds | golden and alternate relays rendered plain-language responsibility, gate, SLA, and escalation copy across all modules | Closed |
| D-19 | 11, 18 | validated exact `return_to`; Back/Close/breadcrumb recovery; post-permission empty-group filtering; stable expanded/collapsed menus | exact return-path, alert Close, browser Back, empty-group, menu, and recovery tests in backend/frontend gates | Back preserved `/tasks?bucket=done&q=INC-2026-0142`; Escape restored the invoking row; menus remained usable | Closed |

Canonical finding row count: **19**.

## Seven-persona live acceptance matrix

| # | Persona | Required completion | Result | Live reference/evidence |
|---:|---|---|---:|---|
| 1 | Experienced Control Room Operator | create, claim, acknowledge, triage, controls, task, evidence, escalation, handover | Pass | `CR-2026-2189`; `tester-1-operator-before.png`, `tester-1-operator-after.png` |
| 2 | Incident Reviewer / Provider Manager | find, inspect linked evidence, review, prove/complete closure | Pass | `INC-2026-0142`; `tester-2-reviewer-before.png`, `tester-2-reviewer-after.png`, `tester-2-reviewer-incident-closed.png` |
| 3 | H&S Owner | accept, explicit WorkSafe decision, investigation, disposition, ownership transfer, closure | Pass | `HS-2026-0081`, `INV-2026-9008`; `tester-3-hs-owner-*` |
| 4 | Corrective Action Owner / Site Manager | find, start, evidence, submit, understand rework, amend, resubmit | Pass | `CA-2026-9011`; `tester-4-action-owner-*` |
| 5 | Independent H&S Verifier | inspect complete evidence/history, return once, verify, close | Pass | verifier user `120`; `tester-5-verifier-*` |
| 6 | Incoming Control Room Operator / Closure Auditor | inspect frozen handover, accept, become shift lead, prove gates, ordered closure | Pass | shifts `8` → `9`; `tester-6-*` |
| 7 | Novice Support Worker | find, comprehend, read-only access, no mutation, Back/filter/focus recovery | Pass | five terminal journey rows; `tester-7-*` |

## Golden journey acceptance matrix

| Criterion | Result | Evidence |
|---:|---:|---|
| 1 | Pass | exact Control Room alert cardinality `1` |
| 2 | Pass | exact official Incident cardinality `1` |
| 3 | Pass | exact H&S event cardinality `1` |
| 4 | Pass | incident/H&S both link alert `2258`; incident links H&S `137` |
| 5 | Pass | typed controls, task `17`, evidence pack `7`, site/client/time retained |
| 6 | Pass | H&S accepted by owner at `2026-07-17T22:41:23Z` |
| 7 | Pass | explicit WorkSafe actor/time/reason/source; inconsistent count `0` |
| 8 | Pass | investigation `8` completed |
| 9 | Pass | sole recommendation disposition links action `11` |
| 10 | Pass | action `11` independently verified and closed |
| 11 | Pass | manager review and incident closure recorded |
| 12 | Pass | alert resolved and closed by incoming operator |
| 13 | Pass | H&S independently closed by owner |
| 14 | Pass | Universal Tasks active count `0`, terminal journey count `5` |
| 15 | Pass | full references, reciprocal transfer, and action audit transitions retained |
| 16 | Pass | role-valid controls; tagged cross-site count `0` |
| 17 | Pass | no final console warnings/errors, current-day severe server logs, or unexplained navigation failures |

## Alternate workflow matrix

| Branch | Result | Live proof |
|---|---:|---|
| A | Pass | alert `2257` closed without Incident/H&S; `alternate-a-routine-*` |
| B | Pass | alert `2259` dismissed false-positive; `alternate-b-false-positive-*` |
| C | Pass | alert `2260` retained evidence and produced `INC-2026-0143` / `HS-2026-0082`; `alternate-c-*` |
| D | Pass | alert `2261` snooze/unsnooze/escalation to level 4; `alternate-d-*` |
| E | Pass | alert `2262`, `INC-2026-0144`, `HS-2026-0083`, `INV-2026-9009`, `CA-2026-9012`, source task `18`; `alternate-e-*` |
| F | Pass | alert `2263`, `INC-2026-0145`, `HS-2026-0084` blocker/recovery sequence; `alternate-f-*` |

## Local verification ledger

| Gate | Result |
|---|---|
| Complete backend matrix | `1,314` tests, `10,150` assertions, `1,907.19s` |
| Complete changed frontend matrix | `23` files, `122` tests, `42.87s` |
| Wayfinder and TypeScript | Pass |
| Targeted ESLint / Prettier | Pass |
| Pint / PHP syntax | Pass |
| Client build | `4,970` modules, `3m 09s` |
| SSR build | `1,622` modules, `43.12s` |
| Production-built Playwright pair | `2` tests, `2.7m`; strict console/page/request/focus/duplicate guards clean |
| Final independent review | `Ready to merge: Yes`; no Critical, Important, or Minor findings |
| Shift-history hotfix focused method | `1` test, `12` assertions |
| Shift-history controller file | `8` tests, `72` assertions |

## Deployment and migration ledger

| Step | Result |
|---|---|
| Pre-migration backup | two non-empty 131 MB SQL backups verified |
| Remediation push | remote `main` fast-forwarded through runtime SHA `20a969181` |
| Deployment | `bash scripts/deploy-server.sh` completed successfully |
| RBAC | `RbacSeeder --force` completed during remediation deployment |
| Migrations | WorkSafe decision, task link, note purpose, organization provenance, and same-tenant provenance migrations all `Ran` |
| WorkSafe report | `undecided: 79`, `explicit_not_notifiable: 2`, `notifiable_pending: 1`, `inconsistent: 0` |
| Post-hotfix build | `4,970` client modules in `2m 54s` |
| Final runtime page | incoming operator loaded `/control-room/shifts` and saw shift `9` |

The live handover transaction completed before a recent-shifts redirect exposed a MySQL filesort memory failure. `20a969181` removes the invalid mixed-status history predicate, the focused regression went red then green, the full controller file passed, the server was redeployed, and the page was rechecked in Chrome.

## Live database integrity ledger

| Check | Result |
|---|---|
| Golden cardinality | CR/INC/HS/INV/CA = `1/1/1/1/1` |
| Canonical links | alert `2258`; H&S `137`; investigation `8`; action `11`; all reciprocal FKs agree |
| Scope | site IDs `[9401,9401,9401]`; client IDs `[9401,9401,9401]`; tenant IDs `[1,1,1,1,1]` |
| WorkSafe | actor `incident-e2e-owner@demo.test`, decision at `22:41:44Z`, source `manual`, reason retained |
| Transfer | source task `17` transferred to action `11`; reciprocal count `1`; transfer actor/time retained |
| Separation of duties | owner/completer `incident-e2e-action-owner@demo.test`; verifier/closer `incident-e2e-verifier@demo.test` |
| Action evidence | two private PNG attachments retained with original names, types, sizes, uploader, and timestamps |
| Rework audit | first completion, verifier return, amended resubmission, independent verification, and closure all present |
| Closure actors | alert incoming operator; Incident reviewer; H&S owner; action verifier |
| Universal Tasks | active count `0`; terminal history rows `5`: CA/INC/HS/CR/INV |
| Handover | shift `8` prepared by outgoing operator and accepted by incoming operator; required/reviewed `4/4`, carry-forward `0`; shift `9` sole current shift |
| Alternate scope | all six tagged alerts use site/client `9401`; cross-site tagged count `0` |

## Log, console, and screenshot ledger

| Evidence | Result |
|---|---|
| Current-day Laravel severe levels | zero matches for `ERROR`, `CRITICAL`, `ALERT`, or `EMERGENCY` |
| Final Chrome console | `[]` for error/warning levels on incoming shift page |
| HTTP | home `200`, login `200` |
| Evidence files | 42 PNG screenshots plus one redacted text evidence file; every file is non-empty |
| Corrective-action upload note | two tiny PNG fixtures were inserted through the application's private evidence model after Chrome extension file-chooser automation was denied; the live owner/verifier UI then proved retention, history, metadata, read-only review, verification, and closure |

Evidence root:

`output/manual-audits/control-room-multi-role-remediation-2026-07-16/`

## Commit and release history

| Range | Purpose |
|---|---|
| `cf4f6fd6b` – `ed2eb469a` | approved design, no-omission implementation plan, and evidence ledger |
| `a2f7e1866` – `4ab769ac0` | explicit WorkSafe decision truth and UI workflow |
| `8873ddbb6` – `d5708ead7` | task/action ownership, transfer, private evidence, verification, and truthful task state |
| `1c10c5db3` – `91a34f088` | permission recovery, immediate controls, linked operational evidence, and unified gates |
| `ec2ddce9d` – `c33c2dbff` | bounded handover, stale recovery, stable forms/dates, worklist/navigation/accessibility |
| `55858b48f` – `c91dbc6bc` | deterministic seven-role/A–F browser proof, review fixes, and local release evidence |
| `fba04d95d` – `bf44da16e` | conservative legacy organization provenance and historical-site ownership validation |
| `effb0a9ec` | deployment runtime permission repair |
| `20a969181` | post-acceptance recent-shifts filesort repair |

## Final checkpoint

- authoritative audit decision: accepted;
- runtime SHA deployed and live-proven: `20a9691811583b62cd371c1ab047d130c4cc3286`;
- seven personas: 7 Pass;
- acceptance criteria: 17 Pass;
- alternate branches: 6 Pass;
- findings: 19 Closed;
- current severe server log count: 0;
- current Chrome console error/warning count: 0;
- required work remaining: 0.
