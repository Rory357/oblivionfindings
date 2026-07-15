# Control Room, Incidents, H&S and Universal Tasks completion audit

**Audit completed:** 2026-07-15

**Branch:** `codex/control-room-incident-hs-unification`

**Final product commit audited:** `0b54cce572` (`fix(tasks): keep reviewer handovers available`)

**Surface:** desktop web only, verified at 1440 × 1000

**Deployment boundary:** isolated local worktree only; not merged, pushed or deployed

## Outcome

The Control Room no longer behaves like a separate, older incident register. Its Desk is now an action-first command centre using the same purple hero, workflow ribbon, status language, journey references and detail grammar as H&S. A staff member can follow one understandable path from operational alert to official incident and accepted H&S governance while the three accountable records retain separate, truthful states. Universal `/tasks` is included as the application-wide work hub; Control Room `My queue` remains a specialist lens rather than a competing ticket system.

The fresh code, desktop UI and workflow re-audit found and fixed one P1 before closeout: Universal Tasks eager-loaded a non-existent `Shift::user` relationship. That could turn the shared Tasks badge and an incident-reviewer login into a 500 whenever shift work was present. Commit `0b54cce572` now uses the canonical `Shift::staff` relationship, a dedicated provider regression passes, and scenario 2 proves the reviewer can review and close the Incident while accepted H&S governance remains independently open.

The audit also closed the final evidence gap by adding a durable first-viewport Desk capture. The desktop smoke test writes `output/playwright/control-room-dashboard-first-viewport.png`; its focused rerun passed and the 1440 × 1000 visual inspection confirms that the hero, continuity summary, filters and priority worklist precede historical analytics. After those fixes, **no P0 or P1 remains open within the audited scope**.

## What a worker now experiences

1. A Control Room operator sees one ordered priority worklist and one `Continue response` action per alert.
2. Creating an incident from an alert reuses the alert and preserves its notes, tasks and evidence.
3. Saving a report as draft creates no false H&S or Control Room work; submission creates the real handover.
4. H&S receives the narrative, context, evidence, official references and explicit acceptance action.
5. The alert, incident and H&S event display separate but connected lifecycle states.
6. H&S acceptance and WorkSafe state are visible back in the Incident and Control Room workspaces.
7. Operational completion does not falsely claim that governance is complete.
8. Role, tenant, site and sensitivity rules remove inaccessible rows, pickers, links and mutations.
9. Shift handover is prepared by the outgoing lead and becomes active only after the named incoming lead accepts it.
10. `/tasks` shows each real Alert, Incident, H&S investigation and corrective-action responsibility once, with shared references and a canonical deep link.

## R1–R21 completion ledger

| ID | Status | Current evidence and audit judgement |
| --- | --- | --- |
| R1 — One understandable cross-module journey | Closed | `IncidentJourneyPresenterTest`, the three shared detail components, and desktop scenarios 1–5 show the same CR/INC/HS references and the three lifecycle states. The H&S captures show `Connected lifecycle`; the Tasks capture groups the same references. |
| R2 — Idempotent alert/incident/H&S linking | Closed | `IncidentJourneyServiceTest`, sensor and medication journey tests, and invariant JSON prove matching direct links and stable counts on retry. Scenario 1 preserves one source alert; scenario 5 proves similar incidents remain distinct. |
| R3 — Draft versus submit is truthful | Closed | `IncidentControllerTest` plus desktop scenario 2 prove draft creates neither H&S nor an alert, while submitting the same record creates one H&S event and no medium-severity alert. |
| R4 — H&S handover is usable | Closed | `IncidentJourneyPresenter` carries narrative, controls, evidence, tasks, source references, ownership and next action. Scenario 1 preserves alert note/task/evidence; the H&S event dialog exposes Handover, Investigation, Corrective actions, Risk, Timeline and Evidence. |
| R5 — Explicit H&S acceptance | Closed | The acceptance migration/model/service/controller, `HsHandoverAcceptanceTest`, and scenarios 1–4 record owner, accepting actor, time and notes. Acceptance is monotonic and permission/site scoped. |
| R6 — One WorkSafe source | Closed | `HsEvent` is authoritative; incident payloads read its state. `HsWorksafeConsistencyTest` and scenario 3 prove the incident and H&S values/reference agree through notification and acknowledgement. |
| R7 — Site and sensitivity isolation | Closed | Journey, nested-record, operational-surface, H&S site and Universal Tasks isolation suites cover list, filter, picker, parent-record and mutation boundaries. Cross-site writes are rejected and restricted safeguarding data is not merged into Safety handovers. |
| R8 — Honest alert lifecycle and SLA | Closed | `ControlRoomAlertLifecycleServiceTest`, lifecycle gate, terminal/snooze, dismissed-alert, breach-job and SLA truth suites cover allowed transitions, clock cycles and active exclusions. The desktop lifecycle suite exercises acknowledge, triage, resolve and close through real routes. |
| R9 — Explicit closure gates | Closed | Open/blocked operational tasks prevent alert resolution; incident follow-ups prevent incident close; H&S requires acceptance, WorkSafe completion, investigation, recommendation disposition, verified actions and closure summary. Reopen paths are audited rather than silently rewriting history. |
| R10 — Action-first dashboard | Closed | `ControlRoomDeskTest`, dashboard component tests, desktop DOM assertions and `control-room-dashboard-first-viewport.png` put the Now/Continuity summary, filters and priority worklist in the first viewport. Historical detail is behind `Open analytics`. |
| R11 — Control Room feels like H&S | Closed | Both modules use shared command-centre hero, ribbon, workspace strip, reference/status and detail primitives. Visual review confirms matching purple hierarchy, rounded surfaces, labelled lifecycle chips and action treatment without copying H&S domain controls into Control Room. |
| R12 — Active work is prioritised | Closed | `AlertPriorityServiceTest`, `AlertWorklistPresenterTest` and Desk tests prove active, SLA-breached/at-risk, desk-priority, severity, escalation and oldest-waiting order. The browser renders that ordered list with one primary CTA. |
| R13 — Safety handovers replace the duplicate register | Closed | `ControlRoomIncidentController` now returns canonical `journeys` only; legacy `incidents`, `clients`, raw medication and safeguarding aggregation are absent. The five lenses open the official Incident/H&S records. |
| R14 — Shift handover requires acceptance | Closed | `ControlRoomShiftHandoverAcceptanceTest` proves required review, structured snapshot, Prepared state, selected incoming-lead permission, atomic activation, stale-version protection and retry stability. |
| R15 — Official references everywhere | Closed | Presenter/component/browser assertions use generated `CR-YYYY-NNNN`, `INC-YYYY-NNNN` and `HS-YYYY-NNNN` references. The Tasks capture demonstrates reference search and linked display without fabricated raw-ID labels. |
| R16 — Honest performance and freshness | Closed | Desk live and analytics payloads are separated; analytics is permission-gated, lazy and 90-second cached. Polling asks only for live props, pauses when hidden, retains last data on error and exposes Updated/Refreshing/Stale. Cold middleware regression now avoids permission N+1 while retaining the `/tasks` badge. |
| R17 — Five incidents work end to end | Closed | `incident-handover.spec.ts` passed all five desktop journeys and wrote five current screenshots plus five database invariant reports. Each submitted scenario reaches H&S with expected links/state; governance scenario 3 closes under the full gate. |
| R18 — Existing data can be repaired | Closed with deployment action | `ReconcileIncidentJourneysTest` proves dry-run, deterministic apply, ambiguity preservation and zero-repair rerun. The read-only shared test-DB run scanned 85 records and reported 255 legacy/demo issues without mutation; deployment must review a target dry-run before authorised apply. |
| R19 — Desktop accessibility basics | Closed | Component tests cover text-plus-icon status, named controls and sort semantics. Desktop Axe scan found zero serious/critical WCAG 2A/AA/2.1A/AA violations; the combined browser run reported no console errors. |
| R20 — No mobile scope leakage | Closed | Playwright used only `chromium-desktop` at 1440 × 1000. No mobile, responsive or WebView project was run or claimed. |
| R21 — Universal Tasks is the work hub | Closed | All five source paths reach `TaskAggregator` providers with canonical scopes and links. Provider/feature/UI/browser tests prove reference search, active/history separation, distinct responsibilities, retry-safe transfer and no second task lifecycle. Control Room `My queue` explicitly links users back to Universal Tasks. |

## Original ten gaps re-audited

| Original gap | Result after implementation |
| --- | --- |
| 1. Permission, tenant, site and sensitivity isolation | Closed. Parent alert authorisation now precedes nested reads/writes, site-bound pickers are canonical, foreign tuples/signals/assets are rejected, and source-module permissions govern deep links. |
| 2. Old dashboard and weak first viewport | Closed. The Desk leads with operational state, continuity, filters and priority work. Large historical chart walls were removed from the live path. |
| 3. Conflicting metrics and lifecycle meaning | Closed. Active/terminal/snoozed definitions and SLA cycles are centralised; null response metrics remain unavailable instead of becoming a false zero. |
| 4. Hard-to-scan active work and mismatched sorting | Closed. One server-presented worklist uses the documented priority order and renders official reference, person/site, status/SLA, owner, journey state and next action. |
| 5. Control Room looked unrelated to H&S | Closed. Shared command-centre primitives, spacing, purple visual hierarchy, workflow ribbon and status/reference grammar now span Control Room, Incidents and H&S. |
| 6. Handover was one-way or ambiguous | Closed. H&S explicitly accepts ownership; acceptance is visible in all three modules, and governance-continuing states remain visible after operational resolution. |
| 7. Reporting and entry paths could duplicate or mislead | Closed. One reusable incident report flow has truthful Draft/Submit outcomes; alert, sensor and medication paths use the same transactional journey orchestration. |
| 8. WorkSafe had competing sources/counts | Closed. H&S owns WorkSafe state and Incident is a compatible view/projection, with consistency tests and a complete notifiable browser journey. |
| 9. Closure and shift handover were easy to bypass | Closed. Alert, incident and H&S closure each enforce their own accountable work; shift completion requires incoming acceptance. |
| 10. Coverage did not prove the real handover | Closed. Backend, component and production-built desktop browser tests cover all roles, routes and five database-backed journeys. |

## Role and handover checks

| Role | Desktop/browser result | Security and workflow result |
| --- | --- | --- |
| Control Room operator/coordinator | Scenarios 1, 4 and 5 use the Desk, alert workspace, lifecycle actions, incident handover and Universal Tasks. | Can perform operational actions and see H&S acceptance; cannot receive foreign site/client/asset/signal options or mutations. |
| Support worker | Scenario 2 saves and submits the canonical incident form, then sees H&S acceptance read-only. | Does not receive the full Control Room dashboard or H&S governance controls; normal work remains in My Day/Universal Tasks. |
| Incident reviewer | Scenario 2 reviews/closes the incident independently from H&S. | Incident follow-up and close permissions remain domain-specific and site scoped. |
| H&S owner | Scenarios 1–4 accept the handover and own governance. | Assignment options are H&S-manage plus same-site eligible users; a foreign-site or view-only owner is rejected. |
| H&S verifier | Scenario 3 independently verifies the corrective action and closes governance. | Separation-of-duties prevents the action completer from self-verifying. |

No inspected role was shown an advertised action that ended in a dead route or an expected 403. Cross-site absence/denial is proven in the current feature suites; the deterministic browser fixture intentionally uses one isolated site so it cannot accidentally depend on live/demo tenancy data.

## Five desktop acceptance journeys

| Scenario | Browser and database result | Durable evidence |
| --- | --- | --- |
| 1. Existing alert → accepted H&S | One alert/incident/H&S journey; retry safe; original note, task and evidence retained; acceptance actor/time recorded. | `01-alert-to-accepted-hs.png` and `.json` |
| 2. Support-worker draft → submit | Draft is quiet; the same incident submits and creates no medium-severity alert; H&S accepts it; the reviewer closes the Incident independently; H&S remains open/accepted and the worker sees that state read-only. | `02-support-worker-draft-submit.png` and `.json` |
| 3. Critical/notifiable governance | One automatic alert and H&S event; WorkSafe state/reference agree; investigation, disposition, corrective action, independent verification and close all succeed. | `03-notifiable-full-governance.png` and `.json` |
| 4. Sensor fall | Existing alert/evidence reused; one incident and H&S event; H&S accepts; operational alert resolves while governance remains open. | `04-sensor-fall.png` and `.json` |
| 5. Similar manual + medication correlation | Similar incidents keep distinct alerts; medication evidence enriches one journey; CR/INC/HS reference search shows the three separate accountable responsibilities in `/tasks`. | `05-similar-medication-correlation.png` and `.json` |

Combined command:

```powershell
npx playwright test tests/e2e/incident-handover.spec.ts tests/e2e/control-room-dashboard.spec.ts tests/e2e/control-room-smoke.spec.ts tests/e2e/control-room-alert-lifecycle.spec.ts --project=chromium-desktop --workers=1
```

Result: **37 passed in 5.7 minutes, exit 0** on `http://127.0.0.1:4196`. The runner and its PHP server closed normally; port 4196 had no listening process afterward.

## Verification record

| Gate | Result |
| --- | --- |
| Relevant backend suite including Control Room, H&S, Incidents, Tasks and reconciliation | 1,351 passed; 9,454 assertions; exit 0 |
| Final cold-load permission-query regression | 1 passed; 29 assertions; `/tasks` badge retained; exit 0 |
| Final Universal Tasks/reviewer regression pack | 6 passed; 53 assertions; invalid Shift relationship fixed; exit 0 |
| Frontend unit suite | 69 files; 298 tests passed |
| TypeScript | `npm run types` exit 0 |
| Scoped Prettier | All 81 branch frontend/E2E files passed |
| PHP formatting and whitespace | Scoped Pint and `git diff --check` passed |
| Client production build | 4,961 modules transformed; exit 0 |
| SSR production build | 1,613 modules transformed; exit 0 |
| Combined desktop browser acceptance | 37 passed in 5.7 minutes on port 4196; exit 0 |
| Dashboard screenshot/a11y focused rerun | 1 passed; zero serious/critical Axe findings; exit 0 |
| Reconciliation on shared test data | Dry-run only; 85 scanned, 255 issues reported, 0 repairs |

The repository-wide Prettier baseline still reports 1,323 pre-existing files outside this programme. No unrelated file was reformatted to conceal that baseline; every frontend/E2E file changed by this branch passes the scoped check.

One immediate repeat run on the heavily reused port 4187 ended 36/37 when Chromium reported `ERR_NO_BUFFER_SPACE` while fetching a production JavaScript chunk. The retained trace proves `/tasks?q=INC-2026-0091` returned HTTP 200 with the expected Incident, H&S and Control Room responsibilities before hydration failed. Scenario 5 then passed independently on a fresh port, and the authoritative combined rerun passed 37/37 on port 4196; this was local loopback resource exhaustion, not an application or workflow failure.

## Remaining boundaries and release actions

- **No P0/P1 remains in the audited desktop product scope.**
- The 255 reconciliation findings belong to the existing shared test/demo dataset, not a failed repair algorithm. Before deployment, run migrations, take a target backup, run the reconciliation dry-run, review ambiguities, and apply only with explicit operational approval.
- Mobile, responsive and WebView behaviour was not tested or changed by design.
- Live demo deployment/login was not needed for completion evidence because the production-built client and SSR bundles were exercised against deterministic local MySQL fixtures. This branch has not been pushed or deployed.

## Evidence files

- `output/playwright/control-room-dashboard-first-viewport.png`
- `output/playwright/incident-handover/01-alert-to-accepted-hs.png`
- `output/playwright/incident-handover/01-alert-to-accepted-hs.json`
- `output/playwright/incident-handover/02-support-worker-draft-submit.png`
- `output/playwright/incident-handover/02-support-worker-draft-submit.json`
- `output/playwright/incident-handover/03-notifiable-full-governance.png`
- `output/playwright/incident-handover/03-notifiable-full-governance.json`
- `output/playwright/incident-handover/04-sensor-fall.png`
- `output/playwright/incident-handover/04-sensor-fall.json`
- `output/playwright/incident-handover/05-similar-medication-correlation.png`
- `output/playwright/incident-handover/05-similar-medication-correlation.json`
