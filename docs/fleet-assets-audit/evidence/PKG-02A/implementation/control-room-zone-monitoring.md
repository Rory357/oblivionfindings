# Client safe-zone monitoring → Control Room

User-requested implementation, 21 September 2026. This supersedes the earlier draft-only implementation scope. It does not change earlier command-dispatch release boundaries.

Subsequent client tracker modes and explicit fall-event delivery are implemented and verified in [Client tracker modes and falls](client-tracker-modes-and-falls.md). The scope notes below describe this safe-zone change specifically.

## Implemented

- Saved custom circles/polygons or a reviewed snapshot of an eligible site boundary can be explicitly activated and paused. Editing requires pausing. Re-activation records a new monitoring run against an exact immutable zone revision, current assignment, consent, actor and access fingerprint.
- Activation revalidates the persisted shape and schedule against the existing draft contract, including legacy site-boundary snapshots, before monitoring can start.
- Agreed zones alert on an outside report; attention zones alert on an inside report. The first qualifying report may open a breach episode. Each zone is evaluated independently. There is no implicit union of overlapping zones.
- Schedules use Pacific/Auckland, ISO weekdays, first/last dates, overnight windows, exclusive finish times and exceptions identified by the window's start date. Each new scheduled window re-arms monitoring.
- Canonical Fleet telemetry ingestion evaluates fresh position reports. Duplicate/out-of-order, future, preactivation, privacy-blocked, wrong-device/asset/site and more-than-five-minute-old reports cannot initiate alerts. Invalid or uncertain positions do not close an existing breach; a new schedule window resets its baseline even when its first fix is uncertain. Reported accuracy touching a boundary is uncertain. Missing accuracy is disclosed rather than fabricated.
- A breach creates one durable Fleet signal/outbox entry per episode. The existing outbox projects it to the Personal Tracker source and the People Safety geofence signal in Control Room. It uses current configured high-priority routing and the existing alert lifecycle, notification intent, recovery, permissions and site scoping. No separate alert system or worker was introduced.
- Assignment audience must explicitly include Control Room. Canonical consent, custody, assignment, site and device/asset lineage are checked again before queued publication. Invalid queued provenance is retained as unroutable through existing recovery handling. Valid already-pending alerts survive an ordinary pause.
- Control Room displays client, zone/revision, trigger, report time, accuracy and response instructions. Later return reports re-arm the breach episode without automatically resolving an operational alert.
- Location cards show draft, scheduled monitoring, paused or changed-authority review states. Active zones cannot be edited silently. Regular live-view refresh also refreshes monitoring state while retaining editor state. Map tiles remain grayscale.

## Verification

- `.pkg02a-monitor-backend-final.log`: 33 tests / 224 assertions; exit 0. Covers monitoring/ingestion/outbox, schedule edges, consent/audience/provenance, exact-revision activation, retries, pause identity, audit rollback, existing draft persistence and Control Room nested provenance. Existing no-`.env` test-bootstrap warnings remain; no environment file or test harness was changed.
- `.pkg02a-monitor-snapshot-validation.log`: final monitoring follow-up, 15 tests / 71 assertions; exit 0. Re-runs the monitoring suite after adding activation-time validation of persisted geometry and schedules, including denial of malformed legacy boundary snapshots.
- `.pkg02a-monitor-frontend-release.log`: 57 tests / 13 files; exit 0. Includes activation review, retained retry identity, bound pause, access loss and ignored responses after unmount.
- Final TypeScript, scoped ESLint and production-build outputs are recorded in candidate r6's manifest. Builds retain the existing large-app-chunk advisory.
- Earlier `.pkg02a-monitor-backend.log` and `-2.log` failures are preserved. The new fixture froze a non-UTC clock, shifting persisted consent evidence away from its recorded timestamps. A rollback-only diagnostic established the binding mismatch; freezing the storage clock in UTC corrected it without weakening consent checks. The second run also exercised 10 unchanged Control Room atomic-delivery tests successfully.
- Desktop and 390×844 browser verification: review required before activation; active/edit lock; pause and reactivation; Escape/focus return; grayscale basemap; no horizontal overflow; Control Room's actual existing alert workspace displays the new summary. The new monitoring dialog's Cancel, action and Close buttons each measure 44px high (Close also 44px wide).

## Synthetic preview and rollout

Only `oblivion_findings_pkg02a_2b9f_browser` and PID-suffixed isolated test databases were used. Migration `2026_09_21_000005_create_client_geofence_monitors.php` is installed in the synthetic preview. Its rollback refuses to delete populated monitoring evidence.

The local preview contains Casey Example's reviewed zone revision 3 and synthetic alert **CR-2026-0001**, created through the real durable outbox service. Its response text explicitly identifies the synthetic preview. All test notifications were faked, network dispatch blocked and device command count stayed zero. The preview's dedicated synthetic reviewer has site-scoped alert read permission; operational users/permissions were not changed.

Operational rollout still requires applying the migration and running the existing durable outbox workers/recovery sweep with configured Personal Tracker source, geofence signal type and an eligible queue. No real tracker, operational database, production worker or production notification was activated here. Physical-device acceptance remains separate. Power-saving commands and future hardware fall-detection protocol support are outside this safe-zone change.
