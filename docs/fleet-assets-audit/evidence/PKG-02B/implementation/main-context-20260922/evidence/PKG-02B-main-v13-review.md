# PKG-02B — Main v13 correction review

Owner: MAIN ASTRA. Revision 1. 22 September 2026. **B01–B03 resolved for the synthetic design candidate. Ready for Stephan's exact mockup decision, with the explicit desktop/production limits below. No implementation or integration release.**

## Candidate and preservation

Same Designer task `01a0c2bb-fcff-7cb1-8bab-882d84477c6c`, worktree `C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings`, branch `codex/pkg-02b-vehicle-profile-design`, base `5307692ec59be84f3503c06354419b7da95be805`. The correction continuation `01a0c728-7a05-7050-8e31-0ba7ef189671` was independently verified as gpt-6-astra/xhigh before this work, as recorded in the v12 review and model register. No new worker or task.

Preview: http://127.0.0.1:4348/PKG-02B/v13/#/fleet-assets/vehicles/14/compliance

Exact candidate: `56781fb5239a1b5a65fe69be015f782e15f4b665ea7a3c203bb00ca355090181`. Manifest SHA256: `C537D72D3617A05467C3A9FC91CA9A9E7B422FCF30E7D1C87A687D2145874C30`.

Main independently verified all84 manifest entries (53 preview,31 evidence), byte lengths and hashes with zero mismatches, and reconstructed the candidate ID using ordinal repository-relative paths/TAB/lowercase hashes/LF. Main also verified all497 entries across available v1 and v4–v12 manifests, including all67 v12 and109 v11 files. V2/v3 have no manifests at the reported locations; no independent manifest-based claim is made for them. The three protected guide hashes match the v12 record: DESIGN A9A47765…, POPUP853541BA…, MAP_GEOFENCING810ED32D…. The Designer's status contains only the previously authorised guide additions and PKG-02B preview/evidence; no application/backend/schema change is present.

Main compared the actual v12/v13 sources. Substantive changes are the preview-only readiness/time helper and its use in operations, header/calendar/readiness presentation, booking notice and validation focus. Other changes are version identifiers, server port/build and line endings. No source change to inherited document logic was found after line-ending normalization. The server's inspected diff is version/port substitution, with the existing bounded synthetic map-image proxy retained. Actual browser title and script/style references match frozen `index-CoWuwo4M.js` and `index-BaqBSZ-_.css`.

## B01 — unresolved evidence and booking/release consistency

Main inspected `readiness-contract.ts`, operations request/approval/checkout/release call sites, main's readiness calculation, calendar projection and compliance display. Applicable outcomes other than Passed/Recorded remain unresolved even with an old reference/future date. Missing/failed/expired evidence and unknown applicability retain their denial; source-backed Not applicable remains supported. The observation remains savable without fabricated evidence/date. Use decisions consume the same compliance result; this is a preview contract, not a released backend service.

Main independently repeated the original failing browser journey in v13: Ready scenario → applicable WoF Needs assessment with its existing reference and future date → save. Both header and calendar now say Needs assessment. A10:00–11:00 Training request using Approval not required, an authority reason and readiness acknowledgement saves as **Pending approval**. Review & approve with filled acknowledgement/notes is blocked on the editable review step with **WoF: assessment is unresolved.** Main visually inspected that modal and its draft-discard protection. The old Ready/Confirmed behavior is no longer reproduced.

Main read Designer's frozen blocked checkout/release and restored-evidence positive transition evidence; the source routes those decisions through the same assessment. Main did not independently repeat every checkout/release transition. **B01 resolved for this mockup.** Production needs current-authority, atomic server-side revalidation and corresponding concurrency/access tests before any implementation acceptance.

## B02 — recorded RUC coverage

Main read Designer's actual v12 baseline snapshots: explicit approval became Confirmed and checkout became Checked out at82,460km despite the recorded82,000km end. This upgrades the original Main source-only finding with Designer's reproduced baseline evidence; Main did not itself repeat the baseline browser route.

The reviewed v13 helper checks valid recorded range and retained observed odometer. Request auto-confirmation, explicit approval, checkout and independent release all use it. Checkout also checks the larger entered observation and names that source in its error. The existing strict-greater end comparison is preserved; this review does not establish statutory applicability or thresholds. Tracker planning distance remains distinct from manual observation, and the display identifies its mileage source. Not applicable with a basis retains older evidence while suppressing misleading active coverage display.

Main independently ran the helper's boundary/provenance regressions and inspected the call-site arguments. Main read frozen evidence for blocked auto/explicit confirmation,82,460-versus82,000 checkout/release, a90,001 checkout observation against90,000 coverage, Keep restricted, and positive Not applicable release/checkout. **B02 resolved for this mockup**, based on code review, independent helper execution and Designer's rendered transition evidence, not a second Main browser execution of all those paths. Actual accepted mileage/RUC policy and operational source reconciliation remain implementation prerequisites.

## B03 — valid default and editable error location

Main independently observed the general calendar action defaulting to22September10:00–11:00 against the fixed09:30 preview clock. Main changed pickup to09:00 and pressed Continue. The error stayed on **Vehicle & times** and read Choose a current or future pickup / block start. Read-only DOM inspection confirmed activeElement `op-start-time`, aria-label `Pickup / block start time: 09:00 AM`. Restoring10:00 allowed progression. Required-field focus and final/step time validation are wired in the inspected wizard source.

Main read the historical-edit evidence and executed the helper's current/future/historical/conflict checks. Existing supported historical edits remain distinct from creating a new past booking. **B03 resolved for this mockup.**

## Verification and limits

- Main independently executed readiness-contract.test.mjs: **27 assertions pass, exit0**; evidence states, recorded-range boundaries/provenance, valid defaults, historical edits and field targets.
- Main independently executed the v13-targeted document-workflow regression: **pass, exit0**; dates/owners, grouped upload, metadata/replacement, reminder identity/pause/resume and source boundaries. It does not imply native picker or durable storage verification.
- Main used the actual isolated v13 in-app browser, checked exact asset references, observed B01/B03 transitions and inspected the corrected modal visually. Console returned zero error entries. Temporary Main review tab was closed; user-owned tabs were not altered.
- Designer reports final TypeScript and Vite pass with existing Leaflet mixed-import and chunk-size warnings. Main inspected the resulting bundle identity and source; Main did not rerun those builds or call reported results independent execution.
- Genuine browser zoom remains unverified. Main's user decision request should include inspecting the main page and a modal at200% actual browser zoom; a narrow viewport and PKG-02A's earlier confirmation are not substitutes. No new mobile scope.
- Native file-picker transfer, native Excel picture rendering, unchanged export-engine reruns and live provider/hardware/delivery/Finance/backend enforcement remain unverified in this correction pass. The earlier PDF macron/transliteration/font limitation remains a production report acceptance requirement. No whole-product or operational QA claim is made.

## Current gate

The expanded user-requested scope, narrow read-only guide permissions, canonical ownership and dependencies in [Main v12 review](PKG-02B-main-v12-review.md) remain in force. No further scope or policy amendment occurred. The canonical Revision10+A1–A6 master remains unchanged. Earlier Maintenance/Client Location unfinished work, operating acceptance and the separate archived-preview CI packaging finding remain open.

The same Designer now waits at the **exact mockup approval** gate for candidate56781fb5…. Main has resolved its three returned design findings; this is not Stephan's approval, Accepted/Closed, or Approved for integration. After an explicit design decision, Main must reconcile the recorded shared contracts and bound the next implementation slice under the existing model and custody policy. No worker, application implementation, merge/push or activation is released by this review. This Main checkpoint is local programme documentation, not retrospectively part of published5307692.
