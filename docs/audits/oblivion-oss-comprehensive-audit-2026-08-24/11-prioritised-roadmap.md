# 11 — Prioritised audit-completion roadmap

> This is an evidence-closure roadmap. The register retains 12 historical identities: 8 current provisional claims, two historical already-fixed records (`MED-RBAC-01` and the bounded manual-entry clause of `MED-CD-ATOMICITY-01`), and two historical remediated records (`MED-CD-SCOPE-01` and `SAFE-ALERT-DEDUP-IDENTITY-01`). Priority labels on the 8 active claims remain provisional until their independent and runtime gates are satisfied.

Architecture constraint: One operating organisation across multiple Sites; Site access, exact action permissions, ownership, consent and privacy are the boundaries.

## Stop-gaps while evidence is incomplete

- Do not present the audit as comprehensive or complete; keep every static, browser, benchmark, ease and finding credit boundary visible.
- Operationally reconcile safety-critical medication and safeguarding records through existing governed processes; this does not assert that a defect has occurred.
- Review sensitive governance/privacy exports and webhook destinations under their exact action authority; never treat broad Site visibility as action permission.
- Preserve the no-copy boundary; verify a current issue before any narrow native remediation and keep application, audit, publication, and completion evidence separate.

## Dependency waves

| Wave | Target window | Evidence owner | Scope | Effort | Exit test | Principal risk |
|---|---|---|---|---|---|---|
| A — source denominators and ownership | days 0–30 | audit orchestrator + independent source reviewers | framework route denominator; 711 page-root classification; route/page→FEATURE-ID; 782 models; 75 policies; 735 service entries; critical async subset; canonical module/submodule ledger | XL | Gates 1–4 and 14–18 have exact denominators and independently reconciled rows | collapsing locators into semantic ownership or inheriting family credit |
| B — safe attributable task/browser evidence | days 0–30 in parallel after access | browser/task agents under root control | current-build identity, non-production/read-only safety, representative roles/Sites/fixtures, 300 H scripts, four viewports, material states, screenshots, DOM/a11y and independent 4/5 review | XL | Gates 6–13, 22–24 have exact numerators/denominators; 0 unexplained browser claims | live-data mutation, unknown build attribution, or invented ease scores |
| C — target-specific benchmark closure | days 0–60 | clean A→B→C→D benchmark chains | formally triage 95 unique prompt repos / 98 occurrences; 1–3 target candidates; target-specific neutral requirements; exact mappings or exhaustive final no-match | XL | Gates 5 and 19 are 340/340 and 95/95; every edge independently adjudicated | family inheritance, observer-only credit, incomplete NCM search |
| D — eight journeys and technical boundaries | days 0–60 | journey + architecture reviewers | eight exact journeys; canonical entity ownership; duplicates; events/outboxes; integrations; finance; Site/RBAC/privacy/safety; tests/performance/operability | XL | Gates 7, 17 and technical P2/P5/P6/P7 cells reconciled with source and execution evidence | treating source links as runtime behaviour |
| E — final findings and independent Pass 8 | days 60–90 | fresh reviewers with no prior ownership | full finding schema, native proposals, interim safeguards, acceptance/validation contracts, cross-module sequencing, visual resample, all agent reconciliation | XL | Gates 20–21 and 25–26 complete; every module has P1–P8; no live agent | confirmation bias and premature completion claim |

## Highest-risk provisional review order

1. Safeguarding intake and durable projection (`SAFE-INTAKE-CANONICAL-SCOPE-01`; `SAFE-PROJECTION-DURABILITY-01`).
2. Webhook destination enforcement (`SET-API-WEBHOOK-DESTINATION-01`).
3. Privacy report domain capability and H&S Site scope (`PRIV-*`, `HS-*`).
4. Governance confidentiality and quorum snapshot (`GOV-*`).

This order is risk-led, not a final remediation sequence. Each item must first pass the exact independent review and validation gate in `findings.json`.

## Per-claim evidence-closure queue

| Provisional ID | Feature/module | Proposed evidence owner | Effort | Interim safeguard | Exit test |
|---|---|---|---|---|---|
| `GOV-EXECUTIVE-VISIBILITY-01` | `CAP-GOV-MEETING-AGENDA-MINUTES-ATTENDANCE` / Governance | independent source reviewer + task-specific runtime/browser reviewer | NOT_ESTIMATED | Do not use the provisional claim as proof of disclosure; restrict operational review of sensitive meetings to explicitly authorised audiences pending verification. | Independent policy review and negative direct-ID, calendar, committee, executive-session, picker, and attachment tests. |
| `GOV-BOARD-PACK-VISIBILITY-01` | `CAP-GOV-BOARD-PACK-DISTRIBUTION` / Governance | independent source reviewer + task-specific runtime/browser reviewer | NOT_ESTIMATED | Keep board-pack distribution explicitly recipient-controlled and avoid assuming broad governance visibility is equivalent to receipt. | Independent policy review and recipient/non-recipient negative tests including executive packs and supplementary attachments. |
| `GOV-RESOLUTION-QUORUM-01` | `CAP-GOV-RESOLUTION-VOTE-QUORUM` / Governance | independent source reviewer + task-specific runtime/browser reviewer | NOT_ESTIMATED | Require governance owners to verify quorum and conflicts against the authoritative meeting record until the runtime contract is proven. | Independent design review plus sequential and concurrent eligibility, conflict, quorum, vote-close, replay, and immutable-evidence tests. |
| `HS-REGISTER-SITE-SCOPE-01` | `CAP-HS-FIRST-AID-REGISTER` / Health & Safety | independent source reviewer + task-specific runtime/browser reviewer | NOT_ESTIMATED | Treat Site selection as a filter, not an authorisation grant; operational reviewers should verify Site access separately from action authority. | Independent per-controller review and representative foreign-Site list, picker, direct-ID, export, and write denial tests. |
| `PRIV-REPORT-DOMAIN-RBAC-01` | `CAP-PRIV-COMPLIANCE-REPORT-EXPORT` / Privacy | independent source reviewer + task-specific runtime/browser reviewer | NOT_ESTIMATED | Do not infer export authority from dashboard or request visibility; review sensitive exports under the exact domain capability. | Independent field-flow review and a per-report/per-export capability denial matrix. |
| `SAFE-INTAKE-CANONICAL-SCOPE-01` | `CAP-SAFE-CONCERN-INTAKE-TRIAGE` / Safeguarding | independent source reviewer + task-specific runtime/browser reviewer | NOT_ESTIMATED | Operationally verify the Site/person/incident chain for sensitive intake records pending independent proof. | Confirm reporter product policy and run adversarial foreign-Site, person, incident, update, and projection tests. |
| `SAFE-PROJECTION-DURABILITY-01` | `CAP-SAFE-TERMINAL-PROJECTION` / Safeguarding | independent source reviewer + task-specific runtime/browser reviewer | NOT_ESTIMATED | Reconcile committed safeguarding concerns against H&S and Control Room projections until durable recovery is proven. | Inject H&S and Control Room projection failures, verify durable retry/reconciliation ownership, and assert idempotent recovery. |
| `SET-API-WEBHOOK-DESTINATION-01` | `CAP-INT-OUTBOUND-WEBHOOK-CONNECTION` / Integrations | independent source reviewer + task-specific runtime/browser reviewer | NOT_ESTIMATED | Administrators should use only approved public webhook destinations pending independent destination-policy verification. | Independent security review plus authorized loopback, private, reserved, metadata, redirect, DNS-rebinding, and egress-control tests. |

No application owner, delivery effort or remediation design is assigned to the 8 active provisional claims. Those fields remain deliberately unresolved until a claim becomes independently actionable; RUN-162 and RUN-173 completed native remediations and RUN-166's already-fixed bounded atomicity adjudication are retained separately below and do not transfer.

## Historical adjudications removed from the active queue

`MED-RBAC-01` is retained as a historical P1 issue identity but is not a current provisional or final finding. RUN-159/R reproduced the historical broad `medications.orders.manage` condition at `a0493442…`, verified exact controlled/stock capability separation on current `4f57ad4…`, passed 73 bounded tests / 1,481 assertions, and received exact-artifact GO after cleanup disclosure correction. No application change was required.

`MED-CD-SCOPE-01` is retained as a historical remediated P1 issue identity but is not a current provisional or final finding. RUN-162 reproduces five current related scope defects, integrates and publishes the seven-path native fix at `0b1920d…`, passes 5 focused tests / 48 assertions on advanced main, and records zero disposable-schema/process/listener residue. RUN-162R independently returns GO and authorizes RUN-163 reporting. This outcome grants no credit to the separate atomicity adjudication or residual balance-check, destruction, sibling-writer, forced-deadlock-retry, and stress scope, and no module, Pass, benchmark, application-browser/ease, release, feature-completion, or audit-completion credit.

`MED-CD-ATOMICITY-01` is retained as a historical already-fixed P1 issue identity for the bounded manual `POST /emar/controlled/entries` register/stock clause only. RUN-165 establishes the historical/current source delta at `cf0090e…`; RUN-166 passes separately reported 3 claim-specific test functions / 146 assertions / 3 synchronized two-process race subscenarios and records exact schema/process/listener/barrier cleanup; RUN-166R independently returns GO and authorizes RUN-167 reporting. No application source or product test changed and no remediation was required. `storeBalanceCheck`, destruction relationship checks, delivery/adjustment/loss and sibling writers, forced transient-deadlock retry, stress/repeated schedules, benchmark, representative browser/ease, module, Pass, release, final-finding, feature-completion, and audit-completion remain unadjudicated or zero-credit.

`SAFE-ALERT-DEDUP-IDENTITY-01` is retained as a historical remediated P1 issue identity but is not a current provisional or final finding. RUN-173 reproduces four distinct-concern failures at the e488 baseline, integrates the exact two-path native fix to local merge `705db2dc…`, and records one unique post-merge 5-test / 60-assertion execution. Concern ID now precedes client/asset/null fallback inside the unchanged 30-minute window; same-client, personless, and cross-Site concerns remain distinct, a five-minute same-concern retry remains idempotent, observer custody remains concern-owned, and the accepted 31-minute lifecycle is unchanged. The isolated 5/60 replay, supporting 28/73 bridge run, adjacent 3/5 H&S run, and six pre-bridge terminal fixture failures are not aggregated. RUN-173R independently returns GO and authorizes RUN-174 reporting. `origin/main` remains `c39b076…`; application and audit publication, timeless retry, unused escalation semantics, terminal fixture debt, broader safeguarding, benchmark, representative browser/ease, module, Pass, release, final-finding, feature-completion, and audit-completion credit remain false.

## Required inputs and decisions

- Safe attributable current-source environment/build identity or an authoritative deployed commit/tree marker.
- Manual sign-in or approved credential entry by the user; no credential invention, bypass or storage inspection.
- Representative role, approved-Site and synthetic/non-sensitive fixture definitions, plus explicit read-only/pre-submit boundaries and cleanup authority for any later mutation-capable lane.
- A separately authorised runtime/test/database gate if execution becomes necessary; static source does not grant it.
- Specialist decisions for legal, clinical and security assertions after the audit separates official source, inference and decision boundaries.
