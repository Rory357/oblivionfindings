# IT, Security & Devices, and Monitoring Single-Tenant Remediation Plan

> **Status:** In progress; Tasks 1-7 are complete and Task 8 is next.
>
> **Product boundary:** Oblivion Findings is one application for one operating organisation across all configured sites. Site access, canonical record ownership, role/capability, direct-object denial, and privacy policy are the security boundaries. A legacy `tenant_id` or `organization_id` column is storage compatibility only and must never decide access.
>
> **Safety rule:** Do not remove an existing tenant filter until the replacement site, ownership, queue/team, sensitivity, or privacy rule is tested. Removing filters mechanically would expose stale and restricted records.

## Goal

Remove active multi-tenant product and authorization behaviour from IT & Support, Security & Devices, and native Monitoring without weakening their real access boundaries. Replace tenant-derived controller/service inputs, model scopes, provider ownership, tests, UI copy, and active documentation. Add forward migrations for global application identities only after collision and provenance evidence is reviewed.

This plan is authoritative for the single-tenant cleanup named in the IT/Security/Monitoring completion goal. The native monitoring runtime plan remains authoritative for protocol and runtime delivery work.

## Confirmed risks

The 2026-07-21 read-only audit found 264 active tenant-resolver or `forTenant` references across 48 committed production files in the three target domains. The highest-risk confirmed paths are:

- all ten main IT controllers reuse `ResolvesHrTenant` as an authorization partition;
- `ItTicketContextPresenter` exposes linked device, Control Room, Problem, Change, and Major Incident context by tenant equality rather than the viewer's actual site/privacy access;
- `ItTicketPolicy::view()` treats every `it.view` user as an all-ticket agent and does not account for site or sensitivity;
- `InboundEmailIngestor` accepts a known ticket reference from any recognized staff sender without requester, watcher, site, sensitivity, or ticket-policy authorization;
- allowed-site service identities can operate null-site tickets through `ItApiWorkItemController`;
- `BuildsItOptions::assetOptions()` returns active assets without site or asset-policy filtering;
- `securityDevices.integrations.manage` currently behaves as an all-sites/device-visibility bypass, including tracker, healthcare, Client, and staff assignments;
- provider and collector ownership is expressed as tenant-wide identity rather than one application connection plus approved site/network/device scope;
- tenant-leading unique keys and indexes would become incorrect or inefficient if query filters were removed first.

## Required access kernels

### IT work access

Create one `ItWorkAccessService` consumed by policies, HTTP controllers, service API, email ingress, presenters, bulk/export paths, and child-record services. It must combine:

- requester and requested-for ownership;
- approved site access;
- queue/team responsibility;
- an explicit organisation-wide IT capability;
- a separate sensitive-work capability;
- direct-object 404 behaviour;
- parent-derived access for comments, attachments, events, approvals, links, tasks, deliveries, Problems, Changes, and Major Incidents;
- default denial for `site_id = null` unless the record is explicitly marked organisation-wide.

### Security & Devices access

Refactor `SecurityDevicesAccessService` so visibility is derived from:

- active canonical Site assignment or Room parent Site;
- Client policy and Client Site;
- current staff/HR Site;
- vehicle/Asset policy and canonical Site provenance;
- device class and privacy rules;
- explicit inventory-manager access to unassigned stock;
- a dedicated all-sites permission that is not implied by integration administration;
- provider credentials governed by integration permission and mappings governed by approved Site;
- collector scope governed by approved Site, network, device, and capability;
- monitor/observation scope derived from canonical Device and collector Site.

## Task 1: Lock the behavioural boundary and produce collision evidence

**Create:**

- `tests/Architecture/ItSecuritySingleTenantBoundaryTest.php`
- `app/Console/Commands/AuditItSecuritySingleTenantData.php`
- `docs/audits/it-security-single-tenant-data-audit.md`

The architecture test scans only `app/Domain/It`, `app/Domain/Monitoring`, `app/Domain/SecurityDevices`, the ten IT controllers and their IT concerns, IT API middleware/controller, IT listeners/policies/models, the named IT/Security frontend pages, and active IT/Security/monitoring documents. It rejects active `forTenant`, `scopeForTenant`, tenant resolvers/parameters, tenant-based comparisons/queries, `canViewAllTenantSites`, `*MatchesTenant`, `tenantSecret`, user-facing tenant copy, fictional tenant acceptance fixtures, and new tenant-partition migrations. Explicit legacy storage fields remain temporarily allowlisted.

The read-only command reports, without mutation:

- distinct legacy IDs and contradictory Site/record provenance;
- duplicate values that would collide under global keys;
- orphan links and provider mappings;
- null-site tickets and whether they have evidence of organisation-wide intent;
- unassigned or ambiguously assigned devices;
- duplicate ticket references and inbound-email ambiguity;
- tenant-leading indexes that need replacements.

Commit the report as evidence. No normalization occurs in this task.

Task 1 uses an exact path, rule, count, and line-independent normalized bounded statement-context fingerprint baseline for known active debt. The initial baseline contains 476 path-rule entries representing 3,083 matched occurrences. A green gate proves only that no new, moved, or equal-count semantically replaced tenant behavior was introduced; it does not prove remediation is complete. The legacy storage allowlist is separately limited to exact model storage declarations.

## Task 2: Add explicit IT site, team, sensitivity, and organisation-wide access

**Create/modify:**

- `app/Domain/It/Services/ItWorkAccessService.php`
- `app/Policies/ItTicketPolicy.php`
- IT Problem/Change/Major Incident/Task/KB/Provisioning policies as required
- a forward migration for an explicit organisation-wide scope marker if the current schema cannot distinguish it from accidental `site_id = null`
- permission seeding for organisation-wide IT access and sensitive-work access
- focused policy/access tests

Start with denied tests for a same-organisation technician at an unapproved Site, unrelated requester, wrong queue/team, sensitive work without the sensitive capability, accidental null-Site work, and forged direct IDs. Prove allowed requester, approved-Site technician, responsible queue/team, explicit organisation-wide manager, and sensitive-work operator paths.

Child records must delegate to the parent work item's access. Bulk actions, counts, search, filters, options, exports, and direct show/mutation routes must share the same boundary.

## Task 3: Close high-risk IT ingress and linked-context paths

**Status: Completed in `5f54c8a0d`.** Inbound email, service API, linked context, delivery retry, service identities, and monitoring-ticket correlation now reauthorize canonical records at use time and fail closed when Site, participant, sensitivity, device, alert, or execution-principal evidence is absent. The final independent review returned approved with no Critical or Important findings.

**Modify first:**

- `app/Domain/It/Presenters/ItTicketContextPresenter.php`
- `app/Domain/It/Services/InboundEmailIngestor.php`
- `app/Http/Controllers/Api/V1/ItApiWorkItemController.php`
- `app/Http/Middleware/AuthenticateItServiceIdentity.php`
- `app/Http/Middleware/RecordItApiRequest.php`
- `app/Domain/It/Services/ItApiWorkItemService.php`
- `app/Domain/It/Services/ItTicketLinkService.php`
- `app/Listeners/It/CreateOrUpdateMonitoringTicket.php`
- attachment, bulk, export, and delivery paths reached by those workflows

Linked devices must pass `SecurityDevicesAccessService::visibleDevices()` or a stricter canonical equivalent for the exact viewer. Linked Control Room alerts must pass the shared `UserSiteAccessService` alert scope and the alert permission. Related IT records must pass their policy, Site, and sensitivity boundary; tenant equality is never sufficient.

Inbound email globally matches one immutable ticket reference, then authorizes the sender as requester, requested-for user, watcher, assigned agent/team, or explicit mailbox principal through `ItWorkAccessService`. Unknown, ambiguous, sensitive, or unauthorized replies are quarantined with bounded evidence and no ticket comment.

Service identities authenticate by active credential and execution account, then by explicit abilities, allowed Sites, permitted work types/fields, and sensitivity policy. Null-Site work is denied unless the identity has a separate organisation-wide ability. Every direct object and mutation is reauthorized at use time.

Monitoring-created tickets use canonical Device/Site evidence and a system principal with only the required operation, never a tenant-derived shortcut.

## Task 4: Refactor all remaining IT controllers and services

**Status: Completed in `d215bb060`.** All remaining IT controllers and services now use canonical Site, participant, queue/team, sensitivity, explicit organisation-wide, or application-configuration boundaries. Legacy partition fields are write-only compatibility data through `LegacyStorageContext`; they no longer select or authorize IT work. The complete IT feature suite passed 304 tests / 3,039 assertions, the architecture ratchet passed 6 / 31 and fell to 350 path-rule entries / 2,078 occurrences, and all static/client/SSR gates passed.

Remove `ResolvesHrTenant` from:

- `ItCatalogController`
- `ItChangeController`
- `ItKbController`
- `ItMajorIncidentController`
- `ItProblemController`
- `ItProvisioningController`
- `ItReportsController`
- `ItServiceManagementSetupController`
- `ItTicketController`
- `ItWorkTaskController`

Replace tenant arguments and model scopes throughout IT catalogue, routing, lifecycle, provisioning, setup, email, reporting, work transition, Problems, Changes, Major Incidents, tasks, queues, teams, services, and KB with the Task 2 access kernel and global application configuration queries. `ItStaffDirectory` must use active/approved account, role/capability, team/queue, and approved-Site criteria rather than organisation filtering.

Replace `ItTransitionInput::$tenantId`, tenant-shaped email context, and tenant-derived audit inputs with canonical work item, actor/system-principal, Site, queue/team, and bounded audit context.

## Task 5: Replace Security & Devices tenant access with canonical visibility

**Status: Completed in `401db9b58`.** Security & Devices visibility, counts, options, exports, direct-object access, and mutations now use canonical Site, Room, Client, staff, vehicle/Asset, Device, assignment, inventory-stock, and privacy evidence. Integration administration no longer grants all-device visibility; unassigned stock and all-Sites operation require separate explicit permissions. Client Profile device, tracker, location, and geofence paths now fail closed on mixed, ambiguous, inactive, or wrong-Site provenance.

Refactor `SecurityDevicesAccessService`, `ResolvesDeviceTenant`, `DeviceRegistryService`, `DeviceGroupAutoRuleService`, Device/Profile/Discovery/Estate/Facilities/Healthcare/Tracking/Network/Monitoring/Settings presenters, controller queries, exports, and mutations.

Required tests use one organisation with allowed and denied Sites, hidden Clients/staff/assets, inaccessible Rooms, unassigned stock, ambiguous assignments, site-limited users, inventory managers, integration managers, and explicit all-sites operators. Prove:

- same-organisation different-Site denial;
- Client/staff/vehicle privacy;
- direct-ID denial and zero mutation;
- count/search/filter/export parity;
- integration administration does not imply all-device visibility;
- unassigned stock is inventory-manager only;
- explicit all-sites behavior is separately granted and audited.

Do not remove tenant filters until each caller uses the canonical replacement.

## Task 6: Rename and govern provider connections as single-application resources

**Status: Completed in `401db9b58`.** Runtime provider ownership now uses a global application-level `IntegrationProviderConnection`, while credentials, mappings, capabilities, sync state, and projections remain bounded to approved Sites. Provider webhooks, UniFi/Milesight/Queclink discovery and health jobs, canonical device resolution, Queclink audit evidence, Device/Asset history, and secret-safe frontend contracts were independently reviewed and approved. The global provider-connection uniqueness constraint from Task 8 was safely pulled forward with collision preflight.

Replace tenant-wide provider terminology and behavior in UniFi, Milesight, Queclink, integration services, controllers, presenters, and frontend contracts:

- `IntegrationTenantSecret` and `tenantSecret` become an application/provider connection secret contract;
- provider `resolveTenantId()` methods are removed;
- one provider connection is globally identified per application where the provider supports it;
- Site credentials, mappings, sync cursors, capabilities, and device projections remain Site-scoped;
- Queclink Device/Asset/history access uses canonical device and asset visibility, not tenant equality;
- audit records retain canonical provider connection, Site, Device, actor, and bounded outcome.

Update active UI copy such as “Tenant scope” and “tenant-wide.” Secret values remain write-only and never appear in props, logs, exceptions, or audits.

## Task 7: Remove active tenant behavior from native Monitoring foundations

**Status: Completed in `cfb078645`.** Monitoring collectors and profiles now have global application identities, while every monitor and observation is resolved through its canonical Device, Site, and optional collector. Observation provenance is derived and immutable across normal, quiet, and bulk Eloquent paths. The forward migration uses an expand/reconcile/contract sequence: provenance columns remain nullable during the compatibility release, existing rows are backfilled only from canonical relationships, and the explicit `monitoring:reconcile-observation-provenance` command fails closed until no gap remains. A later `NOT NULL` contract migration is intentionally deferred until this migration is deployed, old workers are drained and restarted, reconciliation reports zero gaps, and an observation period confirms all writers are current.

Remove `scopeForTenant` and active tenant propagation from `Monitor`, `MonitoringCollector`, `MonitoringProfile`, `MonitorObservation`, factories, presenters, listeners, and tests. Collectors are globally identified and Site-scoped. Profiles are globally named unless an explicit Site override is designed. Monitors derive scope from their canonical Device and optional collector Site. Observations inherit immutable monitor/device/site evidence, not a tenant partition.

Add a forward migration after collision evidence:

- `monitoring_collectors(tenant_id, collector_uuid)` to global `collector_uuid`;
- `monitoring_profiles(tenant_id, name)` to global `name`;
- tenant-leading monitor/observation indexes to non-tenant state/time/device/source indexes.

Legacy columns may remain temporarily only when a zero-downtime compatibility writer requires them; the application must not query them for access.

## Task 8: Normalize data and replace IT/Security global identities and indexes

Use the Task 1 report to normalize or quarantine contradictions. Never merge or expose records merely because an old tenant value matches.

Add forward global constraints, after collision handling, for:

- ticket reference;
- SLA priority;
- KB slug;
- mailbox provider connection;
- team name, queue key, service key, catalogue slug;
- catalogue requester/idempotency identity;
- provisioning workflow source-event key;
- Device Group name;
- integration provider connection and provider event identity;
- Queclink preset slug.

Add replacement non-tenant indexes before switching query paths. Update `ItTicket::nextReference()` to serialize against the global reference identity and make inbound email reject any pre-existing ambiguity. Stop active writers from sourcing legacy values from authenticated users; if a required compatibility field remains, one application-level compatibility provider supplies it without influencing access.

## Task 9: Replace fixtures, copy, active docs, and close the architecture gate

Replace fictional cross/foreign-tenant tests in the IT, Security & Devices, and Monitoring suites with one-organisation cases covering allowed/denied Sites, restricted roles, unrelated people/assets/devices, sensitive work, accidental null-Site rows, forged IDs, bulk mixtures, exports, collectors, and explicit organisation-wide/all-sites authority.

Rename/remove active product and code language including `tenantId`, `resolveTenantId`, `resolveDeviceTenantId`, `resolveHrTenantIdForUser`, `forTenant`, `scopeForTenant`, `canViewAllTenantSites`, `tenantUserOptions`, `tenantSecret`, “same tenant,” “tenant-wide,” “tenant-scoped,” and “foreign tenant.” Compatibility schema field names stay only in the architecture allowlist and migration/storage adapters.

Update at minimum:

- `docs/it-support-security-devices-completion-goal.md`
- `docs/it-support-service-api-v1.md`
- `docs/security-devices-restructure-plan.md`
- `docs/superpowers/plans/2026-07-18-it-support-service-management-expansion.md`
- affected Security & Devices and IT React props/copy

Run focused domain suites, the complete IT/Security/Monitoring backend matrix, frontend tests, TypeScript, ESLint, client build, SSR build, migration/collision verification, route/schedule checks, architecture gates, and standard/compact desktop browser acceptance. No mobile acceptance is required.

Task 9 must reduce the Task 1 active-debt baseline to an empty list. The final architecture gate must then pass as an absolute zero-active-tenant-behavior gate; retaining the Task 1 baseline is not acceptable completion evidence.

## Optional Task 10: Remove legacy columns

Only after a repository-wide dependency audit, data backup/restore rehearsal, and separate approval, remove obsolete legacy columns and indexes in child-to-parent order. This optional schema simplification is not required to prove single-tenant behavior; Tasks 1–9 are required.

## Completion ledger

| Task | Status | Evidence |
| --- | --- | --- |
| 1. Boundary gate and collision report | Completed | Commit `4eaa0771b`; independently approved initial 476 path-rule / 3,083-occurrence no-regression baseline; 206-line redacted local development audit; 12 focused tests / 149 assertions |
| 2. IT access kernel | Completed | Commit `a1696b8c3`; independently approved Site/team/sensitivity/explicit organisation-wide boundary; pre-validation direct-object concealment across ticket, child, approval, merge, and nested-task routes; 18 focused tests / 196 assertions plus parent gate 24 tests / 242 assertions; ratchet reduced to 472 path-rule entries / 2,935 occurrences |
| 3. High-risk IT ingress and context | Completed | Commit `5f54c8a0d`; independent review approved with no Critical/Important findings; 78 affected tests / 770 assertions; architecture gate 6 tests / 31 assertions and ratchet reduced to 458 path-rule entries / 2,877 occurrences; TypeScript, targeted ESLint/Prettier, Pint, 36-file PHP syntax and diff checks; client and SSR production builds |
| 4. Remaining IT refactor | Completed | Commit `d215bb060`; all ten IT controllers plus catalogue, routing, lifecycle, provisioning, setup, email, reporting, work transition, Problems, Changes, Major Incidents, tasks, queues, teams, services, and KB use canonical access or application-wide configuration; full IT suite 304 tests / 3,039 assertions; architecture gate 6 / 31 with ratchet reduced to 350 entries / 2,078 occurrences; TypeScript, targeted ESLint/Prettier, Pint, 98-file PHP syntax and diff checks, client build at 4,993 modules, and SSR build at 1,645 modules |
| 5. Security & Devices access refactor | Completed | Commit `401db9b58`; canonical Site/Room/Client/staff/vehicle/Asset/Device visibility with privacy, direct-object, count, option, export, mutation, unassigned-stock, and explicit all-Sites coverage; Client Profile tracker/location/geofence provenance hardened; independent review approved |
| 6. Provider connection refactor | Completed | Commit `401db9b58`; global application provider identity with Site-scoped credentials/mappings/capabilities/sync/projections; canonical UniFi/Milesight/Queclink device resolution, global webhook idempotency, write-only secrets, canonical Queclink audit/history, and collision-preflight uniqueness; consolidated 105 tests / 1,076 assertions plus fresh Client Profile 14 / 301, frontend 4 files / 20 tests, TypeScript, Pint, 85-file PHP syntax, diff, client 4,993-module and SSR 1,645-module builds; independent review approved |
| 7. Monitoring foundation refactor | Completed | Commit `cfb078645`; global collector/profile identity with collision preflight, canonical Device/Site/collector enforcement, immutable observation provenance, nullable zero-downtime expansion plus explicit fail-closed reconciliation, soft-delete and collision coverage; broad matrix 134 passing tests / 1,234 assertions with the sole stale offline-call expectation corrected by a fresh 9-test / 56-assertion resolver run, and Device Profile fresh at 8 / 145; architecture gate 7 / 269 with ratchet reduced to 229 path-rule entries / 1,185 occurrences; TypeScript, Pint, 34-file PHP syntax, diff, client 4,993-module and SSR 1,645-module builds; independent review approved |
| 8. Data and global identity migration | Pending | Collision resolution, migration, uniqueness, and query-plan evidence |
| 9. Fixtures/docs/full acceptance | Pending | Architecture, backend, frontend, build, and desktop browser gates |
| 10. Legacy column removal | Optional | Separate approval and dependency/restore evidence |

The goal is not complete until Tasks 1–9 are proven. Legacy column removal may remain deferred, but no active product, authorization, query, API, UI, test, or current document may continue to present Oblivion Findings as multi-tenant.
