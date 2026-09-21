# Client Location: tracker modes and fall delivery

21 September 2026. Continues the requested client Location implementation after the safe-zone → Control Room work.

## Implemented behaviour

- The Tracking source panel now has one consistent Tracker mode card and a responsive mode dialog: Standard (30-second reports), Live tracking (10 seconds), and Power saving (120 seconds). Page live-view refresh remains a separate control and never sends a device command.
- Supported GL30-family units use published, immutable Queclink configuration profiles. The client endpoint accepts an exact reviewed profile revision, reason, current approved change and impact acknowledgement. Existing identity confirmation, independent approval, signed command contract, audit, dispatch, credential leasing, serial provider delivery and protected configuration readback are retained.
- The client origin is checked against the exact client, assignment, consent, custody site, requester and access fingerprint through intake and provider delivery. Client-origin configuration requests are restricted to the three designated tracking profiles. Arbitrary sections, passwords, commands, emergency bypass and caller-supplied origin fields are not accepted.
- Mode requests and confirmed modes are presented separately. Only a matching protected tracker configuration snapshot from the current collection period can supply a reported mode. Unknown models, missing permissions/profiles/connections, stale profiles and missing approved changes have explicit unavailable states. Retry retains the same request identity. Closing the view does not imply cancellation of a recorded request.
- Power saving reduces reporting frequency; it does not turn off GNSS or SOS. Safe-zone detection can be delayed between reports. Live tracking persists until a different mode is confirmed; no automatic device-side expiry is claimed.
- An explicit normalized `fall_detected` event is evaluated independently of GPS availability. Motion and `man_down` do not imply a fall. Current assignment, consent, collection window, retention, Control Room audience, device/asset/site lineage and event identity are checked before emission and again before queued publication.
- A qualifying fall creates one durable `resident.fall_detected` Fleet signal/outbox entry and a Personal Tracker / Fall Detected critical-priority Control Room alert. The existing response lifecycle remains authoritative. Control Room shows the report and receipt times and response guidance; the client indicator reads the same canonical event.

## Verification and preview

- Frontend suite: `.pkg02a-complete-frontend.log` — 60 tests across 14 files passed. Covers reviewed selection, exact retry, unsupported hardware, stale/unmounted response handling, plus existing location/editor/status flows.
- TypeScript: `.pkg02a-complete-types-final.log`; scoped ESLint: `.pkg02a-complete-eslint.log`. Both passed. The first typecheck found a callback signature mismatch, which was corrected before the final build was installed.
- Production build: `.pkg02a-complete-build.log`, completed successfully; existing large-app-chunk advisory remains. The installed client bundle contains the final access-change handling.
- Backend history is preserved in `.pkg02a-complete-backend.log` and `.pkg02a-complete-backend-final.log`. Test fixtures initially lacked per-test rollback and then referenced `collection_ended_at` instead of the actual `collection_stopped_at`; both fixture issues were corrected. The existing nine Locate and nine native Queclink lifecycle tests passed, including approval, protected readback, profile retirement, command serialization and identity mismatch.
- Final mode/fall verification: `.pkg02a-complete-verified.log` — **7 tests / 46 assertions passed**, exit 0, with the existing absent-`.env` bootstrap warnings. Only the existing forced PID-isolated test configuration was used; no environment files or shared test harness were changed.
- Actual browser preview: `http://127.0.0.1:4335/operations/clients/1?tab=location`. Desktop and 390×844 mobile verification cover the new dialog, grayscale map, capability denial and 44px Close controls; the dialog is 362px wide with no page overflow at 390px.
- The preview fixture is explicitly a synthetic manual tracker, without mode-control permissions or a native device connection. Its controls correctly remain unavailable; it is not represented as a responding physical tracker.
- Synthetic fall alert **CR-2026-0002** was created through the actual durable outbox in `oblivion_findings_pkg02a_2b9f_browser`. Notifications were faked, outbound HTTP blocked, and device command count remained zero. The client indicator updated to Fall reported.
- The actual Control Room dialog displays the canonical client, critical severity, report/receipt times and response guidance. Escape closes the mode dialog and returns focus to its trigger. Viewport override was reset after mobile checks; both preview tabs recorded zero browser console errors.

## Hardware and rollout boundary

No production deployment, operational database update, physical tracker command or live notification was performed. Existing deployments publish the new presets with `QueclinkPresetSeeder`, and retain their normal device-management permissions, approvals, connection/credential configuration and outbox workers. A new tracker vendor/model must supply its authenticated, documented normalization to `fall_detected`; no undocumented future hardware protocol or sensor-enabled status is invented. Physical tracker acceptance and production rollout remain necessary operational work.
