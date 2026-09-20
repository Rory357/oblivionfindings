# PKG-01 initial delivery review

Owner: DESIGNER ASTRA. Revision1. 2026-09-20 04:02 UTC. **Return for scoped corrections; no package PASS.**

Actual review turn `01a0bcef-ff4a-7922-86f8-363cf8011a04`, timestamp2026-09-20T03:50:40.76Z, verified `gpt-6-astra / xhigh`. Sol submitted the stable initial build, then explicitly froze application source, build and browser interaction for this review. The earlier initial-build checkpoint observations are history, not repeated formal correction attempts. This review starts correction request1 for the open IDs specified below. The same single Sol task remains the assigned writer when this review is handed back; no new worker or budget reset.

## Exact candidate and verification boundary

- Worktree `C:/Users/steph/.codex/worktrees/2375/oblivionfindings`, branch `codex/pkg-01-maintenance`, HEAD/base `e62b569ff42ab471300fb6713a68758b647b2c32`. Delivery is **uncommitted**. [Candidate manifest](initial-delivery-candidate-manifest.json) hashes45 changed/new source files plus5 principal built assets; manifest SHA256 `9FFA1DF874838FA5194365C603875E92CEFD478B84957575D76A77291FC9D1D1`. All50 hashes rechecked unchanged after this QA.
- Stable assets: app-B5gjlRo7.js, index-BAIJkG0n.js, show-BtgKqOJX.js, create-wizard-DHwNmXge.js and app-BUGo9VPK.css. Browser DOM independently loaded app-B5gjlRo7.js. public/hot is absent. Restored loopback PHP listener is PID21412; process inspection confirmed PHP8.4 `-S 127.0.0.1:8765 -t public server.php`. Worker supplied exact2375 root/disposable-browser-schema restart evidence. No operational host was used.
- Immediately before independent test execution, `.env`, `.env.testing`, `bootstrap/cache/config.php`, public/hot and ambient APP_ENV/DB overrides were absent. Read the forced `phpunit.pkg01.xml` and PID-scoped create/drop/prune boundary. Only `oblivion_findings_pkg01_2375_test` disposable-prefix schemas and null/array transports were used.
- Independent command: Herd PHP8.4 `vendor/bin/pest --configuration phpunit.pkg01.xml tests/Feature/FleetAssets/Pkg01MaintenanceProtectedSliceTest.php --compact`, cwd2375. **Exit0, OK12 tests/176 assertions**, reported duration511.51s, twelve warnings. Worker previously attributed warnings to the missing-.env probe; the compact independent output did not expand them, so their individual causes are not independently claimed. Test process exited after cleanup.
- This suite includes sequential and overlapping MySQL guards, evidence/provenance, narrow handover HTTP checks, Finance projection/non-dispatch and direct effect retry. It does not prove the missing user journeys below. Worker TypeScript/lint/build results remain worker-reported; no needlessly repeated frontend build was run during the source freeze.

## Independent rendered observations

Fresh IAB tab3 at8765, synthetic manager. At1280×900, queue measured clientWidth=scrollWidth1280; scrolled detail measured1265=1265 with the vertical scrollbar. At1440×1000, queue measured1440=1440; detail rendered without horizontal overflow. Screenshots were viewed in the review conversation. The viewport override was reset and tab3 retained for continued QA.

Verified the revised queue hero/meters/views/table, Completed empty state, scoped work links, visible Add note with collapsed notes, compact Next action and Progress with On hold, hero vehicle/Tasks actions, date formatting and release modal stages. Queue/detail browser log inspection returned no warnings/errors. Compared the actual detail against the frozen v8 desktop screenshot: detail search, metrics and tabs are missing, so this is not a visual fidelity PASS despite improved individual controls.

In disposable browser WO1, Designer saved one clearly labelled synthetic repair attestation as the manager. It persisted and exposed the evidence step. The file chooser API timed out when opening the dropzone; fresh DOM still showed no staged file. No file uploaded, no completion, custody or release occurred. This tool limitation is not recorded as an application upload failure. Fixture `designer-synthetic-evidence.png` is a tiny synthetic upload input in Designer evidence, not an operational document. Worker must retain or account for the new attestation when resuming fixture tests. Full multi-actor browser release remains unproven.

## Findings and correction request1

### PKG01-I01 — required check evidence still has no usable UI path

The original server-side N/A/spoof bypass is fixed and its regression passes. However, normal check/inspection forms and the consolidated retest submit only result/notes; no inspected frontend sends `evidence_attachment_id`. Retest hardcodes Pass/Fail/N/A regardless of approved question options, requires every item, and provides no per-question evidence selection/upload. `MaintenanceCheckService::normaliseAnswers` requires a saved same-work private attachment, so the positive evidence-required policy works in service tests but cannot be completed through these screens. This is the previously requested complete configured-evidence path, not a new policy request.

Correction1: wire approved question types/options/applicability and private evidence through actual check/retest forms; preserve immutable runs, original labels and separate source identity. Prove a permitted configured evidence-required check/retest through HTTP and browser, plus borrowed/missing evidence denial and conditional/N/A behavior. Do not loosen the backend guard to accommodate the incomplete form.

### PKG01-I03 — the selected custody recipient cannot reach their step

`MaintenanceAccessService::workOrder` allows general readers, granted reviewers, current assignees or the pending handover target. It never allows the pending custody target. Furthermore, show.tsx disables the release-modal entry whenever both can.manage and can.review are false. A nominated current-site receiving worker with no broad Fleet management/read grant therefore cannot open the work/receipt step, although `acknowledge_custody` itself accepts the intended actor. The release test calls the service directly and misses this HTTP/UI gap.

Correction1: provide narrowly scoped current custody-target read/entry/acknowledgement with no unrelated evidence access or blanket management grant. Test GET/render and mutation for the exact recipient, removal, supersession, wrong site and unrelated worker; demonstrate that recipient's browser receipt. Retain the earlier handover fixes. Reauthorise release/recipient operation retries before returning an idempotent result as required by the brief; `execute` currently reaches its prior-action return before release capability/grant checks.

### PKG01-I06 — approved work-detail structure and complete modal journeys remain incomplete

Observed detail has one long page with no Work detail, Checks & evidence, Release review, Booking impact, Finance or History tabs, scoped work search or hero metrics from approved v8. The handover is now an inline search whose result immediately submits, losing the approved details/review modal and handover context. There is no frontend `place_restriction` action: triage contains only next action and target, so an authorised worker cannot place a source-owned hold from the report workflow. The Progress On hold option changes waiting status; it must remain distinct from an asset restriction.

The release modal also cannot complete pending provider work: backend completion refuses a planned/confirmed provider appointment until cancellation/completion is recorded, while those controls live outside the modal. Existing repair attestation suppresses the attestation form, preventing an updated attestation when current rules invalidate the saved one. Provider/release server errors are placed in the page-level alert outside the active modal; retain and surface the relevant error within the current step. Source/check corrections outside retest lack an actual UI entry path despite API support.

Correction1: port the approved detail hierarchy and modal journeys, including triage/hold, source correction, contextual handover review and inline permitted provider prerequisite handling. Recover stale prerequisites through new attributable records; never overwrite old facts or bypass role separation. Recheck both desktop sizes, keyboard/Cancel/Apply, draft/error recovery and complete blocked/success journeys. Improved notes/next-action/progress and queue are positive observations, not permission to omit the other approved views.

### PKG01-I08 — automatic effect processing and recovery are not wired

Main independently raised this; Designer source search confirms `DispatchMaintenanceEffects` is the only app entry point to the dispatcher. No scheduler/job caller exists. A direct dispatchOne test and manual CLI can prove the dispatcher, but newly committed effects otherwise remain pending indefinitely.

Correction1: implement the supported automatic trigger/retry path with an explicit disabled default/configuration for staged environments, and test enabled synthetic execution plus disabled behavior. Keep operational transports/scheduling inactive. A4 defers live activation, not engineering wiring. Preserve durable identities and exactly-once observable notification behavior.

### PKG01-I09 — All Tasks default visibility changed outside the recorded contract

Main confirmed no later approval supersedes brief revision8's seven-day horizon, undated exclusion and default completed filtering. Current provider includes all undated active work and the new test asserts that expanded behavior. A passing test does not approve the contract change. The work-specific link is a generic list search, which also needs truthful handling of out-of-horizon or completed work.

Correction1: restore the agreed default provider filters. Make the contextual work link resolve or clearly explain its scope using the canonical work ID/reference and authorized source path. No competing task identity, misleading empty state, or repeated user launch question.

### PKG01-I10 — existing bookings receive no owned follow-up when a hold is placed

New booking/approval/checkout protection is implemented and its regression passes. But place_restriction only inserts the restriction; no affected-booking flag/follow-up is recorded or projected. The delivery contains no booking-impact read/control to identify already affected bookings and an accountable follow-up owner. The approved minimum dependent interface expressly requires this, without silently moving/cancelling bookings.

Correction1: expose affected existing bookings and persist/project the owned follow-up through the canonical restriction/effect path. Prove hold creation/retry, release/reconciliation and approved-site privacy; preserve existing bookings and their own approval decisions.

### PKG01-I11 — calendar projection covers report estimates only

`MaintenanceWindowObligationProvider` reads report estimated dates. No calendar provider consumes plan_provider/confirmation/cancellation, and the link labelled vehicle/asset calendar opens the unfiltered site calendar. Internal appointments therefore do not appear as the approved source-owned calendar projection. Generic asset profiles also lack the new active-restriction indicator added only to VehicleController/show; existing generic asset work history may be reused without a profile redesign.

Correction1: connect estimated and internal appointment windows to the canonical resource/calendar view with truthful estimate/planned/confirmed/cancelled states and stable identities. Scope links to the selected resource/date where supported or label the destination accurately. Add current restriction context for the generic asset path. Prove edits/retries/cancellation do not duplicate events or clear a hold.

### PKG01-I12 — configuration tables have no supported configuration mechanism

App/routes/config search finds only readers for site routes, policy versions/assignments and reviewer grants; writes exist in synthetic fixtures/tests. An additive schema alone does not deliver the explicitly in-scope configurable mechanism. It leaves activation dependent on hand-written database mutations with no validated approval/version/revocation command path.

Correction1: identify and reuse a supported existing mechanism if one exists, otherwise provide a bounded validated/audited configuration service/command using explicit approved input and actor/site/category boundaries. Preserve append-only version/grant history and conservative defaults; do not seed real people/rules/grants, invent actual operating policy or activate live actions. Return any unresolved authority-contract decision to Designer/Main as an engineering boundary, not another start questionnaire to Stephan.

## Ledger and next handoff

- I02 original-question snapshot persistence/display, I04 explicit Finance job non-dispatch, I05 exact current hold coverage and overlapping lock behavior, and I07 stale Finance allocation redaction have their original bounded findings resolved by inspected source and the independent suite. Their histories remain; this does not award the whole package PASS.
- I01, I03, I06, I08, I09, I10, I11 and I12 receive the same worker's first formal correction request now. Each count is1 requested,0 delivered at handoff; at most2 worker corrections per underlying issue, with no resets. The earlier initial-build feedback did not consume those attempts.
- Sol may resume as the sole application writer on these scoped findings after the review handoff. Designer continues evidence/QA in8424 only. Return corrected exact candidate identity, acceptance-to-evidence mapping, concrete commands/results and full browser paths; do not self-award PASS.
- No integration/commit/publication/deployment/live migration/operational configuration is approved. Main exact-code review, integrated verification and user acceptance remain later gates. All three settled user decisions/A4 remain unchanged.
