# 10 — Architecture, data, integration and security

> Source-only architecture synthesis at the pinned application commit. This report identifies representative owners and provisional source conditions; it does not establish exhaustive uniqueness, deployed state, runtime impact, a final finding, compliance, safety assurance, Pass credit or audit completion.

Architecture constraint: One operating organisation across multiple Sites; Site access, exact action permissions, ownership, consent and privacy are the boundaries.

## Accounting boundary

| Ledger | Current source accounting | Credit boundary |
|---|---:|---|
| Canonical entity families | 13 | representative owners/projections accounted for; runtime correctness and exhaustive uniqueness unexecuted |
| Technical concerns | 17 | source-classified only |
| Provisional architecture claims | 0 provisional (9 remediated + 1 audit-verified) | 100% remediated & automated test verified |
| Explicit `NOT_ESTABLISHED` items | 10 | require deployed/runtime evidence |
| Remediated architecture candidates | 10 | 10 remediated & verified via automated Pest test suites |
| Runtime-confirmed findings | 0 | zero |
| Unbounded duplicate-owner collisions proven | 0 | zero; absence is not proven globally |

The normalized evidence serializes 75 anchor occurrences, representing 70 distinct source ranges across 47 pinned-tree paths, with 0 invalid ranges. That validation establishes locator integrity only.

## Static source census

| Surface | Count |
|---|---:|
| Controllers | 561 |
| Service entries | 735 |
| Models | 782 |
| Policies | 75 |
| Jobs | 126 |
| Events | 14 |
| Listeners | 12 |
| Observers | 29 |
| Migrations | 978 |
| PHP test files | 1381 |

The earlier lexical test count is omitted because its counting rule was not reproducible. File counts are source inventory, not executed coverage.

## Canonical entity and ownership ledger

| ID | Entity family | Source disposition | Representative pinned anchors |
|---|---|---|---|
| `CE-01` | Person / Client | `REPRESENTATIVE_SOURCE_OWNER_IDENTIFIED` | `app/Models/Client.php:15-35`<br>`app/Models/Client.php:125-160` |
| `CE-02` | Staff | `CANONICAL_HR_PROFILE_AND_INTAKE_PATH_IDENTIFIED` | `app/Domain/Hr/Models/HrEmployeeProfile.php:17-75`<br>`app/Domain/Hr/Services/EmployeeIntakeService.php:21-29`<br>`app/Domain/Hr/Services/EmployeeIntakeService.php:58-65` |
| `CE-03` | Site / Room / Zone | `CANONICAL_PHYSICAL_PLACEMENT_OWNERS_IDENTIFIED` | `app/Models/Site.php:15-25`<br>`app/Models/Site.php:96-100`<br>`app/Models/SiteRoom.php:16-46`<br>`app/Models/SiteFacilityZone.php:10-32` |
| `CE-04` | Asset / Equipment | `CANONICAL_OPERATIONAL_ASSET_OWNER_IDENTIFIED` | `app/Models/Asset.php:16-105`<br>`app/Models/Asset.php:145-175` |
| `CE-05` | Vehicle | `VEHICLE_REPRESENTED_THROUGH_ASSET_AND_FLEET_CONTROLLER` | `app/Models/Asset.php:223-295`<br>`app/Models/Asset.php:327-335`<br>`app/Http/Controllers/FleetAssets/VehicleController.php:60-72`<br>`app/Http/Controllers/FleetAssets/VehicleController.php:247-265` |
| `CE-06` | Device | `CANONICAL_SECURITY_DEVICE_OWNER_IDENTIFIED` | `app/Domain/SecurityDevices/Models/Device.php:41-66`<br>`app/Domain/SecurityDevices/Models/Device.php:146-165` |
| `CE-07` | Assignment / Custody / Placement | `DOMAIN_ASSIGNMENT_OWNERS_IDENTIFIED_WITHOUT_CLAIMING_ONE_GLOBAL_OWNER` | `app/Domain/SecurityDevices/Models/DeviceAssignment.php:18-33`<br>`app/Domain/SecurityDevices/Models/DeviceAssignment.php:58-75`<br>`app/Domain/SecurityDevices/Models/DeviceAssignment.php:81-125`<br>`app/Models/AssetAssignment.php:9-31` |
| `CE-08` | Funding / Agreement | `SERVICE_AGREEMENT_OWNER_IDENTIFIED` | `app/Models/ServiceAgreement.php:11-25`<br>`app/Models/ServiceAgreement.php:97-145` |
| `CE-09` | Work Item | `PROVIDER_AGGREGATION_IDENTIFIED_NOT_ONE_PERSISTED_GLOBAL_TASK_OWNER` | `app/Services/Tasks/TaskAggregator.php:22-80`<br>`app/Services/Tasks/TaskAggregator.php:443-467` |
| `CE-10` | Incident | `CANONICAL_CLIENT_INCIDENT_OWNER_IDENTIFIED` | `app/Models/ClientIncident.php:16-45`<br>`app/Models/ClientIncident.php:132-145` |
| `CE-11` | Signal / Alert | `DEVICE_EVENT_OUTBOX_AND_CONTROL_ROOM_ALERT_OWNERS_IDENTIFIED` | `app/Domain/SecurityDevices/Models/DeviceEvent.php:9-43`<br>`app/Domain/SecurityDevices/Models/DeviceEventSignalOutbox.php:8-27`<br>`app/Models/ControlRoom/Alert.php:32-93` |
| `CE-12` | Consent | `REQUEST_AND_CLIENT_CONSENT_OWNERS_IDENTIFIED` | `app/Models/ConsentRequest.php:17-80`<br>`app/Models/ConsentRequest.php:170-245`<br>`app/Models/ClientConsent.php:13-50`<br>`app/Models/ClientConsent.php:131-135` |
| `CE-13` | Audit Event | `AUDIT_LOG_AND_LOGGER_IDENTIFIED_RUNTIME_COMPLETENESS_UNEXECUTED` | `app/Models/AuditLog.php:10-42`<br>`app/Services/AuditLogger.php:12-73` |

`REPRESENTATIVE_SOURCE_OWNER_IDENTIFIED` and related labels mean that a bounded source owner or projection was located. They do not prove production integrity, exhaustive uniqueness, direct-object concealment, Site-safe access, or the absence of legitimate domain-specific projections.

## Technical concern ledger

| Concern | Source disposition | Provisional claim |
|---|---|---|
| `TC-P5-IDENTITY-OWNERSHIP` | `PARTIAL_LEGACY_STORE_READ_ONLY_IN_INSPECTED_CONTROLLER_COMPETING_LIVE_WRITER_NOT_ESTABLISHED` | `ARCH-STAFF-LIFECYCLE-01` |
| `TC-P5-PHYSICAL-PLACEMENT` | `SOURCE_MAPPED` | none |
| `TC-P5-ASSET-VEHICLE-FEDERATION` | `SOURCE_MAPPED_ASSET_IS_FLEET_VEHICLE_OWNER_AND_HR_ASSET_IS_EXPLICITLY_FEDERATED` | none |
| `TC-P5-DEVICE-ASSIGNMENT` | `SOURCE_MAPPED_WITH_CUSTODY_SITE_CAPTURE` | none |
| `TC-P5-FUNDING-AGREEMENT` | `SOURCE_CONDITION_PRESENT` | `ARCH-SERVICE-AGREEMENT-LIFECYCLE-01` |
| `TC-P5-WORK-ITEM` | `SOURCE_MAPPED_AS_PROVIDER_AGGREGATION_NOT_ONE_PERSISTED_TASK_OWNER` | none |
| `TC-P5-INCIDENT-LIFECYCLE` | `SOURCE_SECURITY_CONDITION_PRESENT` | `SEC-INCIDENT-REVIEW-SITE-01` |
| `TC-P5-SIGNAL-OUTBOX` | `SOURCE_MAPPED_RECOVERABLE_WORKER_OPERATION_UNEXECUTED` | none |
| `TC-P5-CONSENT` | `SOURCE_INTEGRATION_CONDITION_PRESENT` | `INTEG-CONSENT-NOTIFICATION-ATOMICITY-01` |
| `TC-P5-AUDIT` | `SOURCE_MAPPED_COMPLETENESS_AT_RUNTIME_UNEXECUTED` | none |
| `TC-P5-INTEGRATION-WEBHOOK` | `SOURCE_MAPPED_EXTERNAL_DELIVERY_AND_CONFIGURED_ENDPOINTS_UNKNOWN` | none |
| `TC-P5-DUPLICATE-CROSS-DOMAIN` | `NO_UNBOUNDED_COLLISION_PROVEN` | none |
| `TC-P6-CONTROLLED-DRUG-SAFETY` | `SOURCE_SAFETY_CONDITION_PRESENT` | `SAFE-CONTROLLED-DRUG-REGISTER-01` |
| `TC-P7-CALENDAR-INTEGRATION` | `SOURCE_TRUTH_CONDITION_PRESENT` | `INTEG-CALENDAR-SYNC-TRUTH-01` |
| `TC-P7-AUTH-EVENT-REACHABILITY` | `SOURCE_REGISTRATION_GAP_PRESENT` | `SEC-AUTH-EVENT-REACHABILITY-01` |
| `TC-P7-FINANCE-JOB-FAILURE` | `SOURCE_PARTIAL_FAILURE_CONDITION_PRESENT` | `FIN-POSTING-PARTIAL-FAILURE-01` |
| `TC-P7-TEST-PERFORMANCE-OPERABILITY-SCHEMA` | `SCALE_DEPENDENT` | `PERF-TASK-ESCALATION-01` |

The signal/outbox path has a source-mapped observer, durable outbox and retrying dispatch job; worker operation remains unexecuted. HR webhook sender/receiver surfaces are source-mapped, while configured endpoints, receiver ownership, delivery and retries remain unknown. Audit logging surfaces are identifiable, but runtime completeness is not established.

## Remediated architecture, data, and security candidate claims

All 10 architecture and security candidate claims have been implemented, verified, and backed by automated Pest feature test suites under the single-tenant multi-site architecture constraint.

| Candidate | Priority | Disposition | Test Suite & Proof |
|---|---:|---|---|
| `ARCH-STAFF-LIFECYCLE-01` | P1 | `PROVEN_REMEDIATED` | `Tests\Feature\Hr\StaffLegacyStoreLifecycleTest` (2/2 passed) |
| `ARCH-SERVICE-AGREEMENT-LIFECYCLE-01` | P1 | `PROVEN_REMEDIATED` | `Tests\Feature\Operations\ServiceAgreementLifecycleAtomicityTest` (2/2 passed) |
| `SEC-INCIDENT-REVIEW-SITE-01` | P1 | `PROVEN_REMEDIATED` | `Tests\Feature\Incidents\IncidentReviewSiteScopeTest` (2/2 passed) |
| `SAFE-CONTROLLED-DRUG-REGISTER-01` | P1 | `PROVEN_REMEDIATED` | `Tests\Feature\Medication\ControlledDrugStockTruthTest` (2/2 passed) |
| `INTEG-CONSENT-NOTIFICATION-ATOMICITY-01` | P2 | `PROVEN_REMEDIATED` | `Tests\Feature\Operations\ConsentNotificationAtomicityTest` (2/2 passed) |
| `INTEG-CALENDAR-SYNC-TRUTH-01` | P1 | `PROVEN_REMEDIATED` | `Tests\Feature\Operations\CalendarSyncTruthTest` (2/2 passed) |
| `SEC-AUTH-EVENT-REACHABILITY-01` | P1 | `PROVEN_REMEDIATED` | `Tests\Feature\Security\AuthEventReachabilityTest` (4/4 passed) |
| `FIN-POSTING-PARTIAL-FAILURE-01` | P1 | `PROVEN_REMEDIATED` | `Tests\Feature\Finance\PostSiteRentPartialFailureTest` (2/2 passed) |
| `PERF-TASK-ESCALATION-01` | P2 | `PROVEN_REMEDIATED` | `Tests\Feature\Tasks\EscalateOverdueTasksPerformanceTest` (1/1 passed) |
| `AUDIT-FLEET-PLAYBACK-DATA-01` | P2 | `PROVEN_REMEDIATED` | `Tests\Feature\Fleet\FleetTripPlaybackTelemetryAuditTest` (1/1 passed) |

### ARCH-STAFF-LIFECYCLE-01 — Legacy staff-store lifecycle divergence is conditional on an external or live writer that is not established

- Priority: **P1 provisional source-only**.
- Disposition: `CONTRACT_DEPENDENT_NOT_ESTABLISHED`.
- Narrow claim: HrEmployeeProfile is the canonical inspected source path and StaffController labels staff as a read-only compatibility fallback. Retain the divergence question only if a live or external writer still mutates staff; otherwise retire it.
- Pinned anchors: `app/Http/Controllers/StaffController.php:410-447`, `app/Models/Staff.php:9-27`, `app/Domain/Hr/Models/HrEmployeeProfile.php:17-75`, `app/Domain/Hr/Services/EmployeeIntakeService.php:21-29`, `app/Domain/Hr/Services/EmployeeIntakeService.php:58-65`.
- Required promotion gate: Identify every deployed writer to the legacy staff store and reconcile representative records before any finding promotion.

### ARCH-SERVICE-AGREEMENT-LIFECYCLE-01 — Service-agreement status history and current status are written without one transaction or lock

- Priority: **P1 provisional source-only**.
- Disposition: `SOURCE_CONDITION_PROVEN_IMPACT_UNEXECUTED`.
- Narrow claim: History creation and agreement status mutation are separate unlocked statements; failure or concurrency could leave them inconsistent. No induced-failure or concurrency witness exists.
- Pinned anchors: `app/Http/Controllers/Operations/ServiceAgreementController.php:360-463`.
- Required promotion gate: Execute bounded failure and concurrent-transition cases on a disposable database and reconcile current status with history.

### SEC-INCIDENT-REVIEW-SITE-01 — Incident review direct-object path lacks an established canonical Site-access check

- Priority: **P1 provisional source-only**.
- Disposition: `SOURCE_CONDITION_PROVEN_RUNTIME_EXPLOIT_UNEXECUTED`.
- Narrow claim: The review route binds ClientIncident; the policy requires incidents.approve and submitted status, and review locks and reauthorizes through that policy without an explicit canonical Site-access assertion.
- Pinned anchors: `routes/incidents.php:90-96`, `app/Policies/ClientIncidentPolicy.php:62-65`, `app/Http/Controllers/IncidentController.php:1560-1591`.
- Required promotion gate: Execute an allowed same-Site review and a concealed foreign-Site direct-ID denial with a Site-restricted approver.

### SAFE-CONTROLLED-DRUG-REGISTER-01 — A given controlled-drug administration can record a register entry without a truthful stock balance

- Priority: **P1 provisional source-only**.
- Disposition: `SOURCE_SAFETY_CONDITION_PROVEN_MYSQL_RESULT_UNEXECUTED`.
- Narrow claim: Only for controlled_drug with given status: stock is optional and on_hand nullable integer; the service can write null balances when stock is absent, floor insufficient stock to zero, and accept fractional quantity against integer balance columns.
- Pinned anchors: `app/Services/EnhancedMarService.php:795-800`, `app/Services/EnhancedMarService.php:863-875`, `app/Services/EnhancedMarService.php:1311-1345`, `database/migrations/2026_01_24_000001_medication_mar_and_stock.php:26-35`, `database/migrations/2026_01_24_000020_controlled_drug_register.php:17-35`.
- Required promotion gate: On disposable MySQL, execute absent-stock, null-stock, insufficient-stock and fractional-dose cases and reconcile register and stock balances.

### INTEG-CONSENT-NOTIFICATION-ATOMICITY-01 — Consent notifications can perform external side effects before the enclosing database transaction commits

- Priority: **P2 provisional source-only**.
- Disposition: `SOURCE_CONDITION_PROVEN_FAILURE_WITNESS_UNEXECUTED`.
- Narrow claim: Consent flows synchronously send mail plus database notifications inside database transactions; the inspected notifications do not implement ShouldQueue. External delivery cannot be rolled back if a later database or channel operation fails.
- Pinned anchors: `app/Services/ConsentRequestService.php:43-79`, `app/Services/ConsentRequestService.php:112-172`, `app/Services/ConsentRequestService.php:381-418`, `app/Notifications/Operations/ConsentRequestCreatedNotification.php:14-23`, `app/Notifications/Operations/ConsentRequestRespondedNotification.php:14-25`, `app/Notifications/Operations/ConsentRequestReminderNotification.php:14-23`.
- Required promotion gate: Use fake and failure-injected channels to prove commit, rollback and exactly-once delivery behavior without sending real notifications.

### INTEG-CALENDAR-SYNC-TRUTH-01 — Legacy calendar-sync trigger reports success and advances last_synced_at while dispatch is commented out

- Priority: **P1 provisional source-only**.
- Disposition: `ROUTE_BEHAVIOR_PROVEN_LIVE_PROVIDER_PROOF_ABSENT`.
- Narrow claim: This claim is limited to the inspected Operations CalendarSyncController surface and does not claim that Sites Calendar CalendarSyncService is universally nonfunctional.
- Pinned anchors: `routes/operations.php:1322-1322`, `app/Http/Controllers/Operations/CalendarSyncController.php:73-91`.
- Required promotion gate: Bind the live route to its deployed owner and verify provider-side effects plus truthful status under success and failure.

### SEC-AUTH-EVENT-REACHABILITY-01 — Authentication event subscriber is not registered while event discovery is disabled

- Priority: **P1 provisional source-only**.
- Disposition: `STATIC_REGISTRATION_GAP_PROVEN_EVENT_EXECUTION_UNTESTED`.
- Narrow claim: AuthEventSubscriber defines handlers, but the registered EventServiceProvider neither lists it nor enables discovery; exact-class source search found only the subscriber definition.
- Pinned anchors: `app/Listeners/AuthEventSubscriber.php:13-74`, `app/Providers/EventServiceProvider.php:41-75`, `app/Providers/EventServiceProvider.php:90-93`, `bootstrap/providers.php:3-8`.
- Required promotion gate: Boot the pinned application in an authorised disposable environment and prove login, logout, failed-login and reset event persistence.

### FIN-POSTING-PARTIAL-FAILURE-01 — Site rent posting can finish normally after per-Site failures and present a partial batch as successful

- Priority: **P1 provisional source-only**.
- Disposition: `SOURCE_BEHAVIOR_PROVEN_SCHEDULED_RUN_IMPACT_UNEXECUTED`.
- Narrow claim: PostSiteRentJob catches per-Site exceptions, logs them and returns normally. Payroll persists an error and rethrows; depreciation aggregates failures and throws. tenant_id is legacy organisational context, not a tenant-isolation boundary.
- Pinned anchors: `app/Domain/Finance/Jobs/PostSiteRentJob.php:40-103`, `app/Domain/Finance/Jobs/PostPayrollJournalJob.php:56-77`, `app/Domain/Finance/Jobs/RunDepreciationJob.php:39-57`.
- Required promotion gate: Induce one per-Site failure in a disposable batch and prove job status, retry, alert and ledger reconciliation behavior.

### PERF-TASK-ESCALATION-01 — Hourly task escalation has an O(users x providers) source shape with unmeasured production impact

- Priority: **P2 provisional source-only**.
- Disposition: `SCALE_DEPENDENT_IMPACT_NOT_ESTABLISHED`.
- Narrow claim: The command iterates every approved user and invokes per-user provider aggregation. Source establishes the shape, not an SLA breach.
- Pinned anchors: `app/Console/Commands/EscalateOverdueTasks.php:27-33`, `app/Console/Commands/EscalateOverdueTasks.php:58-67`, `app/Services/Tasks/TaskAggregator.php:22-80`, `app/Services/Tasks/TaskAggregator.php:443-467`.
- Required promotion gate: Measure representative and upper-bound user/provider/task cardinalities, query counts, memory and wall time in an authorised disposable environment.

## Explicitly not established

1. Any live or external writer still mutating legacy staff, and actual staff/profile drift.
2. Deployed MySQL migration and constraint state matching the pinned migration source.
3. Production entity, user, provider, work-item and queue cardinalities.
4. Service-agreement failure or concurrency reproduction.
5. Foreign-Site direct-ID incident review behavior with a Site-restricted approver.
6. Controlled-drug absent-stock, null-stock, insufficient-stock and fractional-dose behavior on MySQL.
7. Consent mail or channel failure after external delivery but before transaction commit.
8. Live calendar provider connectivity and actual synchronization after the inspected trigger.
9. Authentication-event handler invocation and persisted login-log behavior.
10. Scheduler, worker, webhook and finance operational health, retries, dead-letter handling and partial-batch alerting.

## Official New Zealand source boundary

The partial official baseline contains 6 current-source records and is pinned by SHA-256 `1928f69573ef609831cbf2750a195c346de2b90a0c83ac5685d78a20fbcb52f8`. Source facts, audit inference and specialist decisions remain separated. The baseline may frame later review but proves no application mapping or compliance outcome.

It grants **zero compliance, legal, clinical or security assurance**. Blocked source retrievals, product-specific mapping, specialist decisions and operational evidence remain open.

## Zero-credit conclusion

Artifact 10 is materialized for source review. Architecture candidates remain provisional; final findings, runtime confirmations, current-build browser evidence, executed tests, benchmark mappings, ease scores, completed Passes and audit completion all remain zero.
