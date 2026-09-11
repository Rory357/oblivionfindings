# W14 native technical-check adapter

Status: backend and independent-worker checks passed; first desktop run exposed two projection/refresh defects. Corrections implemented and current-build desktop recheck passed; remaining dashboard correlation and complete W14/release acceptance remain open. W14/F06/B04/E13 and the whole release gate remain open.

## Canonical behaviour

Non-availability monitors on IT infrastructure now emit `monitor_failed` / `monitor_recovered` from the existing confirmed/effective state machine. They retain its confirmation counts, duration, thresholds, maintenance/dependency suppression and immutable observations. They do not emit offline/online or change recorded device availability. Other device domains retain their owning workflow.

`MonitoringAvailabilityEpisode` was generalized to `MonitoringIssueEpisode`; availability-v1 digests and payload names are unchanged. Technical-check episodes use a separate server-owned `monitors.condition_episode`, a condition-specific digest and correlation key, and exact monitor/kind/device/Site/observation provenance. Recovery must match that issue; it records verification evidence without closing IT or operational work. Existing source outboxes, per-destination outcomes, retry locks/audits, canonical ticket routing/links, sealed evidence and scoped operations history are reused.

Additive migrations30/31 store condition state and register the two source codes. No live routing rule is created or changed. Current matching Control Room rules remain the authoritative severity/routing assessment, with the existing operational fallback when no rule exists. These migrations remain unapplied to the working database.

## Actual verification

- Initial guarded Feature run12028 / token `it_7b40d3bbe72d4570`: terminal1; diagnostic trace records81 passed,1 failed,82 finished,695 assertions. The sole failure is MonitoringRecoveryPipelineTest245, which expected the old exception wording limiting recovery to device_online. The invalid failure signal was still rejected. Its expectation now includes native monitor recovery, and rejection cases also cover monitor_failed and Fleet recovery. Both14-check pre/postflights passed and the owned schema is absent. Standard Pest summary was not emitted into the redirected log; counts come from the preserved diagnostic events.
- Initial evidence-card UI run:2 passed/1 failed because the exact text selector omitted the adjacent timestamp. Corrected selector; recheck3 passed/2.71s, terminal0. Production label distinguishes technical-check failure from the separately recorded device health.
- Scoped UI ESLint passed, no diagnostics. Full TypeScript56420 terminal0. Build33464 terminal0/3m50s, app-DrukXuMY.js. No browser success is claimed yet.
- Expanded guarded Feature run59408 / token `it_472b37fa0f174df9`: terminal0,86 passed,712 assertions from the diagnostic events; all14 postflight checks passed and the schema is absent. This includes the new technical-check lifecycle plus existing availability, recovery, delivery and operations regressions.
- Standalone independent-worker run60648 / token `it_d8b65641aff3400b`: terminal0,1 passed/590 assertions; all14 postflight checks passed and its schema is absent. Real workers covered availability and technical-check urgent/direct cases, held claim/insertion/commit barriers, interruption, competing retries and two source outboxes sharing one issue.

## First desktop run and discovered defects

Owned run `a86d0861d5714fcd`, fingerprint `4982db1def1c6ff782058c77fbe69abef7dba7ceb49899e9ffd44493013eb5fc`, used this checkout and app-DrukXuMY.js at the existing desktop size (1063×856); no resizing. Normal CSRF, isolated sessions/database, array mail and sync queue are recorded in w14-condition-browser-identity.json. Owned tab17 was closed, user tab2 preserved. Cleanup39883 terminal0; independent postflight confirms schema and owned directory absent. Working database untouched.

- Ticket13: technical-check title, sealed v2 source evidence, correct canonical Device9 link, Active/Healthy device state, queue/team/owner/cover and direct IT routing all visible. The original report incorrectly projected infrastructure-outage wording despite correct stored technical text. Fixed the historical-diagnostic projection to select the safe condition summary, with new HTTP/Inertia assertions for TLS, HTTP and SNMP.
- Ticket14: recovery activity visible and work remains open. Ticket15: urgent case retains linked Control Room alert3, safe technical-check evidence and canonical device/alert links. Ticket16 at another Site returned private404.
- Device delivery15: keyboard review and explicit consent produced one real retry, sent/applied, attempts2/limit2, exactly one retry audit and completion audit, and routed open IT-000024. Read-only final records preserve this proof. The result dialog showed the recorded success, but the background history retained its failed row after closing. Fixed by deferring an authoritative current-URL GET until close, preserving search and scroll and restoring row/history focus. Changed live reviews, uncertain POST outcomes and stopped waits also invalidate the history; unsuccessful refresh retains an explicit recheck control. Totals are never guessed locally.
- Normal logout/login as restricted actor4 concealed source evidence and device identity and removed operations counts/history. Console warning/error query returned none. Screenshot was inspected inline; no persisted screenshot file is claimed.

Initial correction UI run85197:32 passed/2 failed; both failures were accumulated axios spy counts across parameterized tests, corrected by restoring mocks between cases. Final correction UI run:34 passed/3 files/4.38s, terminal0. Scoped ESLint6335 terminal0. PHP45230/token `it_52218ec4af5940cd` terminal0:45 passed/345 assertions, all14 postflight checks/schema absent. Full TypeScript85174 terminal0 with no diagnostics. Build98832 terminal0/4m1s, app-CUgUJHwk.js, manifest e3e6553f6071fe1b0ed608079d23254d158ce6628949b95b43957fe152bd5373. Current27-source/10-unchanged-design hashes captured in w14-condition-fixes-source-hashes.json.

## Current closure and resumption

Corrected-build desktop run is complete and cleaned. See w14-condition-fixes-browser-results.md for runtime identity, exact report/history/keyboard/retry/permission evidence and persisted reconciliation. Cleanup17231 terminal0, independent schema/root absence, all27 sources and10 protected-design hashes unchanged. No owned runtime or check remains live. Next implement the native Monitoring dashboard correlation gap in w14-source-capability-inventory.md, retaining canonical records and exact issue/Site/current-access proof.

Public push remains pending the previously requested approval for the public destination. Local checkpoint is being committed after01bc54540; preserve independent Governance work. Production AI/providers disabled; no working-database mutation.