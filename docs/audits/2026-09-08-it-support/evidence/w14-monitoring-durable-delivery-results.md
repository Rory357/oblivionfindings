# W14 durable device-to-IT delivery

Status: Implemented first native-device slice; focused Feature, audit and bounded actual-worker checks passed. Browser verification and full W14 / F06 / B04 / E13 remain open.

The device source outbox now owns a separate IT destination outcome. Control Room `status=sent` remains its acknowledgement; it is never rewritten by an IT ticket failure. New nullable columns preserve unknown legacy outcomes without backfilling or inventing ticket completion. The new migration is `2026_09_11_000025_add_device_monitoring_it_delivery.php`; apply only in wrapper-owned schemas at this stage, never the working database.

Implemented contracts:

- Persist the IT intent and source/signal/approved-Site binding before the Control Room transaction commits. Unsupported event/domain combinations are explicit ignored outcomes.
- Existing `DeviceSignalPublished` listener and a scheduled recovery job share one locked canonical consumer. It re-reads Device, event, signal, active source and approved Site before calling the existing ticket/link/evidence/routing services.
- Ticket, immutable evidence, activity, IT completion and required audit are atomic. A settled source intent is not replayed into a new ticket after resolution.
- Failed work retains a bounded retry count/code. Exhaustion is dead-letter; changed or unavailable source context is unroutable. Manual retry records a new bounded allowance without resetting the attempt count; a queue dispatch failure leaves pending intent for the existing sweep.
- Existing `safety-signals:recover` reports the IT outcome separately. `safety-signals:retry device_it <outbox>` requests a retry. CLI wording does not claim delivery/queue success after a dispatch exception.
- Legacy acknowledged deliveries with no IT outcome remain `legacy_unverified`; automated recovery does not recreate their potentially settled work.

## Current verification

Initial formatting and whitespace checks passed. First isolated runner98468 / token `it_7cca38fe9cd245f5`: **51 passed / 446 assertions / 189.55s**, terminal0; all14 postflight checks and exact schema absence passed. Log `w14-monitoring-durable-delivery-tests.txt`. Selected suites: new ItMonitoringDeliveryRecoveryTest, existing monitoring integration, cross-module context, ingress access and safety delivery recovery.

Review follow-up implemented: the shared protected-domain AuditLogger metadata filter drops arbitrary keys. Completion and retry now use its structured before/after state contract. `SafeOperationalData` accepts only bounded nonnegative integer IT attempt/allowance counters; provider text, booleans, arrays, fractions and overflow are rejected. The delivery audit identifies completion as a system action and preserves the actual request actor for manual retry.

Final audit-contract runner86192 / token `it_62435d7ef1eb46a7`: **39 passed / 631 assertions / 452.36s**, terminal0; all14 postflight checks and exact schema absence passed. Log `w14-monitoring-durable-audit-tests.txt`. Covers the updated delivery suite plus existing SecurityDevices SettingsAuditTest. Current 11-source hash bundle: `w14-monitoring-durable-delivery-source-hashes.json`. Final formatting/whitespace/protected-design checks passed. No active test/import/build or owned browser runtime remains.

## Still required

Bounded actual worker concurrency/interruption now passes **1 test / 87 assertions**, including duplicate deliveries, interrupted insertion, acknowledgement loss and racing manual retries; see `w14-monitoring-concurrency-results.md`. Source-authorized Operations UI and desktop browser proof remain open. Direct nonurgent intake and urgent create/link handoff remain unimplemented; currently a confirmed offline signal without an alert records `technical_routing_unavailable`, not false success. Fleet IT adapter, exact issue episodes, maintenance/flapping/recurrence and full E13 release acceptance remain open. No production AI, provider changes, live communications or deployment.
