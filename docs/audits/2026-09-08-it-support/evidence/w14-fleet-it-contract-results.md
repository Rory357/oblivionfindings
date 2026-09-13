# W14 Fleet IT delivery contract — 12 September 2026

Mappings: W14 / F06 / B04 / E13. This is the delivery-contract portion of Fleet intake; full Fleet intake and W14 remain incomplete.

Implemented:

- Extracted the existing Device IT transaction into `ItTechnicalDeliveryService`, used by Device and Fleet. Each consumer write and mandatory completion audit commits with its IT outcome. A failure rolls back those writes, retains source acknowledgement and records only a bounded failure code. Automatic attempts remain bounded at three.
- Extended `FleetSignalOutbox` with a separate IT outcome and immutable-by-preparation source binding, using additive migration000028. Pre-contract source acknowledgements remain unknown. Rollback refuses to discard recorded outcomes. No new outbox, ticket system, DeviceEvent, tenant concept or provider was introduced.
- `ItFleetDeliveryService` re-reads canonical source records under transaction locks. Only availability events qualify; other operational events and suppressed signals are ignored. Delayed delivery checks the active source, actual source/Signal identity and occurrence, approved Site, current device–asset pairing, provider, episode and recorded routing decision.
- `ItFleetAvailability` passes the actual source/recovery/offline records to the future ticket consumer. The persisted IT binding contains technical identifiers and a digest; no coordinates, driver, trip or raw diagnostic copy.
- Fleet's owning availability service also rejects an archived timestamp and requires recovery occurrence to match the persisted heartbeat receipt time.

Changed implementation files:

- app/Domain/It/Services/ItTechnicalDeliveryService.php
- app/Domain/It/Services/ItMonitoringDeliveryService.php
- app/Domain/It/Services/ItFleetDeliveryService.php
- app/Domain/It/Services/ItFleetAvailability.php
- app/Models/FleetSignalOutbox.php
- app/Services/Fleet/FleetSignalService.php
- database/migrations/2026_09_12_000028_add_fleet_it_delivery.php
- tests/Feature/It/ItFleetDeliveryContractTest.php

Verification:

- Initial combined run26439/tokenit_b5ff666d7e7746d9: **48 passed / 362 assertions / 186.72s / terminal0**. All14 isolation postflight checks passed, including exact owned schema absence. This predates the final archived-Site and canonical recovery-time changes.
- Expanded run68949/tokenit_c4a2adc287ab40ad: **110 passed / 3,665 assertions / 197.74s / terminal0**. It includes the contract, existing Device delivery, Fleet availability recovery, offline atomicity, telemetry ingestion and legacy monitoring recovery. All14 isolation postflight checks passed, including exact owned schema absence. No active owned process/schema remains.
- PHP formatting passed. All10 immutable design baselines match (`w14-fleet-it-contract-design-check.json`).
- All8 final implementation/test hashes match `w14-fleet-it-contract-final-source-hashes.json`; application/test/migration whitespace check passed. Earlier7-file source bundle is retained as the initial-run evidence.

Not yet implemented or verified: production Fleet source-worker preparation/dispatch, ticket creation/update/recovery consumer, deterministic direct/urgent routing, linked Asset/Device evidence and permission projections, sealed Fleet snapshots, scheduled/manual IT retry wiring and real browser journeys. The contract tests invoke the source transaction seam and a clearly synthetic test consumer; they do not prove production ticket integration. No production automatic intake has been activated and no browser evidence is claimed for this slice.

No working database migration or provider/notification action was performed. The working database still has migrations17–28 unapplied. No browser/runtime was started. Next: connect the canonical Fleet ticket/link/evidence lifecycle and verify its actual desktop journeys. The delivery contract and source-proof changes are locally verified; production Fleet intake remains incomplete.
