# W14 Fleet episode recovery — local implementation and verification

11 September 2026. Maps to W14 / F06 / B04 / E13. Full W14 and the Goal remain incomplete.

Implemented in existing canonical services and records:

- DetectFleetOfflineDevices delegates source publication to FleetSignalService. Offline sources retain the deterministic key and now record source-event, canonical device/asset-link and Site evidence when available.
- FleetTelemetryIngestService locks the state snapshot before reading its prior state. A returning heartbeat atomically persists its event, online state and recovery source/outbox. A duplicate frame or further online heartbeat does not create another recovery.
- FleetSignalService validates the original offline key against its recorded timestamp/event, the recovery key, exact source/event identities and current device/asset-link/Site scope. A replacement or re-paired device cannot establish recovery of the old episode. Legacy missing evidence remains explicitly unmatched.
- DispatchFleetSignalOutbox uses dedicated Fleet availability processing. Recovery records verification-required evidence on the exact matching alert and preserves its status. Recovery delivered before the offline outbox prevents a stale new alarm. An older recovery does not modify a later episode's alert.
- Delayed availability ingestion rejects a moved/inactive recorded Site before downstream publication. Availability projections exclude arbitrary Fleet payloads, person/trip/location context. Audit history and the existing failure/retry records are reused.

Changed production files: app/Jobs/DetectFleetOfflineDevices.php; app/Jobs/DispatchFleetSignalOutbox.php; app/Services/Fleet/FleetSignalService.php; app/Services/Fleet/FleetTelemetryIngestService.php; app/Services/ControlRoom/SignalProcessingService.php. Tests: tests/Feature/Fleet/FleetAvailabilityRecoveryTest.php; tests/Feature/Fleet/FleetOfflineSignalAtomicityTest.php. No migrations or live provider changes.

**Final affected regression: 76 passed / 3,443 assertions**, terminal 0, runner 53630 / token `it_73e0ac37b1a04538`. All 14 isolation postflight checks passed and exact disposable schema absent. [Raw results](w14-fleet-episode-recovery-recheck.txt), [style](w14-fleet-episode-recovery-recheck-style.txt), [current source hashes](w14-fleet-episode-recovery-source-hashes.json).

This includes all ten new recovery cases, all offline atomicity/configuration cases, the complete FleetTelemetryIngestTest file and the canonical SafetySignalDeliveryRecoveryTest file. The first run passed the normal recovery journey, then stopped on a fixture uniqueness error because the PHPUnit class lacked RefreshDatabase: 1 passed / 1 error / 72 pending / 22 assertions. [Initial results](w14-fleet-episode-recovery.txt), token `it_9c9b8127bbb64a10`, terminal 1/Pest 2, all 14 postflight checks/schema absence passed. Explicit per-test reset corrected that test setup. Recorded-Site and episode-key checks plus replacement-device cases were added before the final passing run.

No test/import/build/owned browser runtime is active. Working database and protected design files are unchanged. Earlier two-detector concurrency evidence predates this recovery adapter; it does **not** verify the new recovery race. Next: actual detector/heartbeat and outbox-order contention with real commits, review query/lock behavior, then durable IT outcomes, nonurgent intake, urgent create/link handoff and permission-safe browser recovery journeys. New routing/operational policies remain unselected; existing source ownership is preserved. No W14 browser acceptance or full-package completion is claimed.

## Actual worker verification completed

The expanded standalone test now verifies the current recovery implementation: **1 passed / 80 assertions**, terminal 0, runner41574 / token `it_b98af2cc157643e8`. All 14 isolation postflight checks passed; exact schema and owned barrier files are absent. [Raw results](w14-fleet-recovery-concurrency.txt), [style](w14-fleet-recovery-concurrency-style.txt), [current source hashes](w14-fleet-recovery-concurrency-source-hashes.json).

Changed test files: tests/Concurrency/It/FleetOfflinePublicationConcurrencyTest.php and new tests/Support/It/fleet-availability-concurrency-worker.php. Production source was unchanged from the 76-test passing regression.

The single standalone test includes the earlier competing stale scans plus these actual-process cases:

- Detector holds the snapshot lock while heartbeat reaches its own snapshot lock after acquiring device/asset locks. One transaction retries (asserted by the extra state-lock attempt); the final state is online with either no committed offline episode or one complete offline/recovery pair, never a lost recovery or orphaned source/outbox.
- Detector has persisted offline state/source/outbox but holds the transaction before commit. Heartbeat overlaps and waits on the canonical device. After release, it publishes exactly one matched recovery. Queue and domain-event callbacks observe transaction level zero.
- Two workers deliver the same offline outbox and then the same recovery outbox: one alert, one recovery audit/evidence record, preserved open status and processed signals.
- Offline and recovery outboxes are delivered concurrently after both sources exist: both deliveries complete, with no stale new alarm. All workers finish outside transactions.

This supersedes the earlier outstanding concurrency note for these tested paths. Full W14 remains incomplete. Next: durable downstream IT outcomes and direct nonurgent intake through the existing device/Fleet outboxes and canonical ticket services, then urgent create/link handoff and permission-safe browser journeys. Current native listener returns early when no Control Room alert exists; a sent source outbox does not yet prove queued IT completion. Preserve source ownership, operational-policy boundaries and safe evidence projections while extending those paths.
