# Actual application verification plan

Prepared while backend I1 is being implemented. None of these checks is claimed complete here. Frozen v13 remains the visual reference; preview behavior is not evidence that the production implementation works.

## Owned runtime

Use the existing repository TestCase harness pattern to create a unique **separate browser database base** `oblivion_findings_pkg02b_5b0a_browser` with the actual harness process PID, after a dedicated exact-root/environment/autoload/database-name preflight. No .env or inherited DB URL/config cache, no operational storage. The parent stays running while its loopback-only PHP child serves actual local application source; parent shutdown stops the child and cleans only its owned schema. Use a distinct cookie and local session storage, null queues/broadcasting, array mail and fake scan/provider adapters limited to this isolated fixture. Start only at a custody boundary after pending migrations are stable.

Reference the existing `docs/audits/2026-09-12-my-day/evidence/desktop-browser-fixture.php` and router pattern, not a new auth bypass. Sign in through the normal UI using a synthetic fixture user with explicit scoped permissions and approved current HR/site relationships. Create a second restricted user/site and denied objects. Never attach or change real users/devices. Use an available explicit localhost port and verify the exact checkout, manifest/source build and route before screenshots.

Build real application assets in this worktree. node_modules is a read-only junction; no install writes to Main. If route-generation tooling bootstraps Laravel, pass only the verified isolated configuration; do not let a frontend build discover Main's database. No `public/hot` or stale build from another tree. Record command, commit, manifest hash, bundle paths and browser-observed identity.

## End-to-end scenario records

- Ready vehicle with expressly synthetic assessed compliance, applicable RUC and accepted observed mileage; no implied operating policy.
- Vehicle with original failed check and independent Maintenance restriction; canonical repair, evidence, retest and reviewer/custody grants configured only in fixture.
- Vehicle with unknown applicability/missing dates/legacy observation and zero uploads, no tracker.
- Partial/stale telemetry and trip route gaps, unknown driver and verified driver, personal/consent-blocked hidden histories, unavailable scoring policy/provider.
- Multi-file document set with renewal, clean older version, pending replacement, duplicate retry and archive history.
- Month-based and historic day-based service schedules; actual completion linked to a work record.
- Pending required approval, evidenced no-approval request, overlapping booking and completed return with an unresolved hold.
- Current reminder owner and withdrawn owner permission; safe in-app Tasks/Calendar projection with no external delivery.

## UI verification sequence

1. Overview/readiness: one clear next action, compact content, source-linked meters, correct unknown/blocked/ready states; photo entry and actual private image upload/replacement.
2. Details/documents/Finance: searchable choices and explicit Add new persist after reload; reason/VIN remain appropriate text; direct expiry editing in the evidence/upload workflow; immutable version history and one renewal identity; Finance source links obey its own permissions and do not duplicate cost.
3. Compliance/mileage: save needs-assessment without fictional evidence, record sourced Not applicable, direct date/range change, retained correction, tracker discrepancy clearly separate; verify B01/B02 UI cannot confirm use.
4. Service/reminders: add/edit source interval months/km, upload supporting evidence, plan canonical appointment, record actual completion/history, add/snooze/reassign/complete reminder and follow it into Calendar/Tasks without completing the underlying obligation.
5. Checks: customise/new version, typed questions/options/evidence, publish/retire separate from operating rule, submit original snapshots, failed-source create/link Maintenance and durable retry/recovery. Confirm original questions remain after template change.
6. Maintenance: open exact source, add evidence, canonical repair/retest/independent release and return to vehicle; another hold still restricts use. No work completion or reminder action silently releases the vehicle.
7. Calendar: all five views, date navigation, explicit source legend/privacy, bounded filters, empty-slot and source-record context menus with keyboard alternatives. Booking request defaults current/future; validation focuses correct step/date/time; both approval routes, conflict, checkout observation, custody and return refresh the same source entry.
8. Map/telemetry: greyscale full canvas, compact reported-state icons with observed/received time/units/unknown values, marker hover and distinct map/vehicle context menus; shared geofence selection/history with monitoring inactive until authorised; imagery failure retains coordinates and controls.
9. Trips/insights/alerts: compact toolbar and map/list proportions, searchable driver, range paging, event address/provenance and map focus, event List/Cards/Table, per-trip coverage/contributors/disputes; actual Control Room receipt/delivery/triage/create-or-link work without claiming delivery before it exists.
10. Exports: normal native file selection, multi-day actual PDF/XLSX download, Māori macrons/Unicode/logo, recorded route images and gaps, page/sheet counts and filter parity; private guessed IDs denied. Inspect PDF renders and actual spreadsheet image rendering. If native capability is unavailable, retain that as an explicit verification limitation rather than claiming green.

## Presentation and recovery

Check 1280 and 1440 desktop widths (and wide desktop as supported), light/dark themes, sidebar expanded/collapsed, long names, empty/denied/partial data, keyboard-only navigation, context-menu Escape/focus return, free wizard rail navigation, validation step jumps, file rejection/retry, stale-version conflict and dirty-draft close/resume. Verify genuine browser zoom using actual browser zoom controls and reported rendering scale; a viewport override or CSS scale is not zoom evidence. Reset temporary viewport/zoom changes when done.

Inspect browser error logs and actual source identities. One scoped verification pass plus corrections justified by a failure/change; no repeated broad CI polling. Root reviews the complete candidate against v13 before one consolidated Main review packet.
