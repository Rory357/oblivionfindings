# Revision 10 requirement and workflow coverage

**Approval checkpoint 2026-09-19 09:53:08 UTC:** Stephan approved the Section 12 scope/navigation/WF-01–WF-10 and first PKG-01 design release; [exact decision](14-scope-workflow-approval.md). Original audit findings and proposal text below are retained as the reviewed artifact. No mockup/implementation approval is implied.

Owner: MAIN ASTRA. Revision: 1. Date: 2026-09-19.
Authority: Revision 10 + A1/A2. Local baseline `19354ecbc70046d12dfdf9c86f888e65fa1879d1`.
**Audit coverage, not implementation acceptance.** `Present` = source foundation; `Partial` = incomplete target; `Gap` = not located in bounded audit; `Unverified` = runtime/policy evidence missing; `Gate` = future stage intentionally not started. Combined rows retain all listed requirements; full wording remains in the canonical master. Each row links to concrete finding acceptance and proposed blueprint, avoiding a second competing specification.

| Stable requirement ID | Master / requirement | Current status and evidence mapping | Proposed workflow / observable acceptance owner |
|---|---|---|---|
| R10-001 | §0 prompt/codebase validation, conflicts before work | Completed Section 0; [08](08-prompt-codebase-validation.md), A1/A2 [09](09-amendments.md) | Main; approved amendments only, original product requirements preserved. |
| R10-002 | §1 project, stack, repository/Rory references | Located, protected; [04](04-design-rules.md), baseline evidence | FA-N02/T04; reference hashes unchanged, exact baseline explicit. |
| R10-003 | §2 pages/routes/tabs/forms/actions/hidden/legacy/background audit | Inventoried; [12](12-navigation-page-inventory.md), [static source](evidence/source-inventory.md); read-only render/source limits explicit | FA-T04; no source presence called E2E PASS. |
| R10-004 | §3 canonical domain ownership | Present foundations; [01](01-domain-ownership.md), [13](13-integration-matrix.md) | All WFs; no duplicate Fleet vehicle, care, HR, accounting, geometry/device identity. |
| R10-005 | §4 vehicle acquisition/profile/documents/responsibility/accessibility | Partial; FA-F07/A01 | WF-05; trackerless onboarding and dependency-aware archive. |
| R10-006 | §4 vehicle-specific WoF/CoF/licensing/RUC; current NZ rules | Partial/unsafe unknown handling; FA-F03; official-source notes | WF-01/02; unknown distinct from compliant, applicability/evidence verified. |
| R10-007 | §4 due → appointment → unavailable → pass/fail → repair/retest → next due | Partial; FA-F03/F06 | WF-01; reminder/internal appointment/external confirmation/outcome distinct. |
| R10-008 | §4 approval/checkout/return/defects/cancellation/overdue | Partial; FA-F02/F05 | WF-02/04; actual readiness and custody revalidated. |
| R10-009 | §4 maintenance date/distance/hours/provider/estimate/parts/labour/downtime | Partial; FA-F06/I05 | WF-01; applicable triggers, work/evidence/release sequence. |
| R10-010 | §4 fuel/charge/tyres/insurance/lease/road costs/replacement | Partial; FA-I01/I04/F07 | WF-09; complete cost sources, approval and missing-data treatment. |
| R10-011 | §4 optional trackers, trip/freshness/timeline, trackerless use | Present identity, partial truthful status; FA-R03/I04/A01 | WF-05/08; no tracker dependency, observation provenance. |
| R10-012 | §4 supported-living passenger/outing/roster/medication/incident safeguards | Present guarded services, full journey unverified; FA-F04/I03 | WF-03/04; preserve existing care/eMAR boundary and replay safety. |
| R10-013 | §4 safe retirement preserves historical obligations | Partial; FA-F07 | WF-05; active booking/key/assignment/device review, Finance disposal distinct. |
| R10-014 | §4A per-vehicle day/week/month/agenda calendar and accessible alternative | Gap on profile; wider weekly fails in observed local build; FA-F01 | WF-02; direct vehicle context, Today/navigation/legend/list and shared calendar. |
| R10-015 | §4A slot-prefilled direct booking, requester vs driver, on-behalf permission | Partial; FA-F01/F02 | WF-02; preserve vehicle/site/times, minimal purpose/context, correct actor roles. |
| R10-016 | §4A confirmed/pending/in-use/overdue/maintenance/inspection/manual blocks | Partial; FA-F01/F02/F06 | WF-01/02; informational versus blocking source-labelled events. |
| R10-017 | §4A one atomic availability calculation at all transitions | Partial; creation lock exists; FA-F02 | WF-02; concurrent create/edit/approve/extend/checkout tests. |
| R10-018 | §4A finite pending holds, release, conflict reasons/alternatives | Gap contract; FA-F02 | WF-02; explicit policy duration, expiry and safe reacquisition. |
| R10-019 | §4A event details, edit/reschedule/cancel, audit/notification dedupe | Partial; FA-F01/F02/M02 | WF-02/10; authorised actions, explicit confirmation and keyboard alternative. |
| R10-020 | §4A overnight/multi-day/Auckland DST/cross-site/early/late/breakdown | Partial; weekly UTC conversion exists; FA-F02/F05 | WF-02/04; no scheduled-end automatic release or silent future changes. |
| R10-021 | §4A free/busy vs detail, search/export/feed privacy | Partial site scope, independent busy permission gap; FA-F01/T01/I02 | WF-02/08; minimal backend payload, scoped direct records and exports. |
| R10-022 | §4A vehicle-list/Site/aggregated views with same records and stale recheck | Partial; FA-F01/S03 | WF-02; shared projections and source ownership, exact selection retained. |
| R10-023 | §4A no map/external-calendar subscription required; external/recurrence separate | Internal foundation present; external is future; FA-M01/E01 | WF-02/10; core bookings survive missing imagery/provider. |
| R10-024 | §4A populated/direct/pending/maintenance/conflict/busy/detail-edit mockup | Gate, intentionally not produced | Designer only after scope/WF approval; all states required before worker. |
| R10-025 | §4B requested need before vehicle/driver, allocation and fulfilment reasons | Partial Client request exists; FA-F04 | WF-03; one request→reservation→journey, client choice distinct. |
| R10-026 | §4C hard blockers/warnings/unknown with sources/freshness/owner | Gap complete assessment; FA-F02/F03 | WF-02; missing evidence cannot pass, no generic override. |
| R10-027 | §4C appropriate shared equipment reservation reuse | Assessment required; FA-A03/F02 | WF-02/05; do not make all static assets bookable or add parallel engines. |
| R10-028 | §4D physical keys/equipment/receipt, condition, return exceptions | Partial guarded ledger; FA-F05/A01 | WF-04/05; responsibility and readiness tied to actual receipt. |
| R10-029 | §5 static asset full lifecycle/ownership/room/docs/calibration/loans | Partial; FA-A01/F07/I05 | WF-05; site-first manual lifecycle and canonical owner records. |
| R10-030 | §5 assigned/manual/tracker/tag observation provenance | Partial; FA-A01/R03 | WF-05; times/source labels, no stale scan as GPS. |
| R10-031 | §5 optional RF/RFID extension without speculative integration | Future; FA-E02 | WF-06; observations require reconciliation; no hardware implementation now. |
| R10-032 | §5A parent/components/kits/service history/no double Finance count | Gap contract; FA-A03 | WF-05; independent meaningful component and removable custody handling. |
| R10-033 | §5A stocktake expected→observe→discrepancy→correct→sign-off | Gap full lifecycle; scan foundation; FA-A02 | WF-06; duplicates/wrong tags/unmatched/missing/cross-site cases. |
| R10-034 | §6 Site-led boundary creation/discoverability/reuse | Present identity, partial coherent edit; FA-S01 | WF-07; one geometry, radius/custom, plain usage preview. |
| R10-035 | §6 physical geometry vs use rules, impact/history/access | Partial; FA-S01/T01 | WF-07; edits preserve rules/history, no ordinary resident access. |
| R10-036 | §6 overlap/accuracy/dwell/order/stale/duplicate/storm/server evaluation | Partial server evaluator exists; FA-S02 | WF-07; jitter/replay/order tests without map open. |
| R10-037 | §7 visible Client tracking/consent review/expiry/next actions | Present consent entry, partial connected summary; FA-R01 | WF-08; use existing grouped sections and contextual links. |
| R10-038 | §7 purpose/scope/version/authority/evidence/history; autonomy | Present consent foundations; policy unverified; FA-R01/R02 | WF-08; no relative-authority inference or universal legal model. |
| R10-039 | §7 backend collection/processing/disclosure/export/jobs/realtime/cache/portal | Present guarded paths, independent sharing gap; FA-R02/T03 | WF-08; channel-by-channel withdrawal/expiry/reassignment checks. |
| R10-040 | §7 freshness/accuracy/battery/no device/denied/stale/paused/emergency | Partial/unsafe UI; FA-R03 | WF-08; last-known is not live/wellbeing; emergency policy separate. |
| R10-041 | §7A expected outing/overdue/stale/fault distinctions and responder lifecycle | Partial Control Room source; FA-R03/S02/I03 | WF-07/08; acknowledgement/escalation/handover/resolution evidence. |
| R10-042 | §7A tracking consent vs viewing vs family sharing | Missing separate grant in portal path; FA-R02 | WF-08; tracking+relationship alone cannot authorise sharing. |
| R10-043 | §8 one hub choice/every item Keep-Merge-Relocate-Remove/deep links | Audit proposal complete; FA-N01, [12](12-navigation-page-inventory.md) | Approval pending; every route/filter/capability preserved. |
| R10-044 | §8 global queues/context views/templates-schedules-work-evidence distinctions | Partial; FA-N01/F06/S03 | WF-01/02/05; same records, no duplicate tabs/modules. |
| R10-045 | §8A report→triage→hold→owner→repair→release→feedback | Partial; FA-F06/T02 | WF-01; duplicate report linkage, waiting states and independent release. |
| R10-046 | §8A recalls/cleaning/required inspections category policies | Assessment gap; FA-A03/F06 | WF-01/05; owned affected-resource case, no speculative recall feed. |
| R10-047 | §8B owner/next action/target/blocker/escalation/reassignment/acknowledgement | Partial shared task sources; FA-I03 | WF-04/10; leave/offboarding/shift receipt, email is not resolution. |
| R10-048 | §9 Maps Google toggle/config/status/OSM fallback | Gap user-facing contract; FA-M01 | WF-10; disabled/missing/bad/quota/outage states; bounded requests. |
| R10-049 | §9 display/tiles/search/geocode/routing/edit capabilities | Partial separate services; FA-M01 | WF-10; capability matrix and honest degradation. |
| R10-050 | §9 shared provider interface preserving geometry; greyscale base only | Partial Leaflet, target gap; FA-M01 | WF-07/10; overlays semantic/accessible; no duplicate boundaries. |
| R10-051 | §9 list/clusters/filters/legend/selection/time/loading/error/keyboard | Partial rendered maps; FA-M01/N02/R03 | WF-10; usable list/no imagery and meaningful default for static assets. |
| R10-052 | §9 current terms/key restrictions/secrets/attribution/privacy/cache/capacity | Official sources located, configuration unverified; FA-M01/T04 | Provider-specific approval/test before paid integration; no unlimited public tile assumption. |
| R10-053 | §10 Finance purchase/receipt/expense/invoice/cost/budget/fixed/disposal/no duplicates | Partial, posting gap; FA-I01/F07/I04 | WF-09; Finance approval, visible failed/unposted and reconciliation. |
| R10-054 | §10 HR/roster staff issue/offboarding/licence validity | Partial; FA-I02/F05 | WF-02/04/05; minimal current projection and accountable recovery. |
| R10-055 | §10 Clients/care/accessibility/consent/outings/meds/incidents | Partial strong existing services; FA-F04/R01 | WF-03/08; preserve safeguards, no care duplication. |
| R10-056 | §10 Site vehicles/assets/rooms/maintenance/geofences/calendars/emergency context | Partial; FA-S01/S03/A01 | WF-01/05/07; same source identities and permissions. |
| R10-057 | §10 per-vehicle/Fleet/Site calendar consistency/source ownership | Gap full booking adapter; FA-F01/F02/S03 | WF-02; changes propagate once; internal event not external confirmation. |
| R10-058 | §10 Devices/Control Room/shared documents/suppliers/tasks/notifications/audit | Partial; FA-R03/I03/I05/M02/T03, [matrix](13-integration-matrix.md) | WF-07/09/10; trigger→effect→recovery test, not link-only acceptance. |
| R10-059 | §10A narrow contractor evidence, financial/release separation | Staff-mediated proposal; external future; FA-I05/E02 | WF-01/10; private attributable evidence; revoked grant denies. |
| R10-060 | §10A employee location/private-use/retention/export separate from resident consent | Policy and enforcement assessment incomplete; FA-I02 | WF-08; vehicle access not automatic private journey access. |
| R10-061 | §11 desktop rules, hierarchy/density/forms/focus/status/all UI states | Partial, approved rules located; FA-N02 | Package mockup/QA gate; Rory references read-only, no mobile scope. |
| R10-062 | §11A My Day/Site booking/check/checkout/return/defect/equipment | Gap complete chain; FA-I03/F05 | WF-04/10; source-linked actions and pending responsibilities. |
| R10-063 | §11A authenticated desktop QR/manual/scanner, no public credentials | Present QR foundation; FA-A02/T01 | WF-05/06; manual lookup works, no phone-camera workflow. |
| R10-064 | §11A honest network failure/unsaved/retry/no sensitive persistent cache | Partial, not write-tested; FA-N02/T03 | WF-04/10; no success before server, retry revalidates permission/conflict. |
| R10-065 | §11B exact immutable template/rule/questions/evidence/author/amendments | Gap/overwrite defect; FA-T02 | WF-01; old evidence unchanged after template change/correction. |
| R10-066 | §11C manual-first setup/import/duplicate/policy effective history | Partial create, import gap; FA-A04 | WF-06; row preview/reconciliation, no unknown-as-compliant defaults. |
| R10-067 | §11D useful reports/defined metrics/nulls/choice vs shortage/no rankings | Partial misleading metrics; FA-I04 | WF-09; scoped drilldowns, valid definitions and rate history. |
| R10-068 | §12 exact findings/map/inventory/coverage/ownership/gaps/priorities/order/blueprints | Delivered [10](10-full-audit.md)–[13](13-integration-matrix.md), context [02](02-approved-workflows.md)/[03](03-dependency-map.md)/[04](04-design-rules.md) | STOP for Stephan scope/navigation/workflow and first-package decision. |
| R10-069 | §§13–14F sequential Main/Designer/Implementer, mockup/corrections/technical/publication gates | Gate; no child/code writer/worker correction used; [05](05-page-register.md) | No stage advancement by audit approval alone; exact Main approval before integration. |
| R10-070 | §14G worker model/effort/cost/trial/correction policy | Preserved [06](06-model-selection.md)/[07](07-model-results.md); no worker/benchmark/cost invented | Verify effective child settings after approval; correction limit per underlying issue remains two. |
| R10-071 | §15 wired cross-module acceptance including restricted-site/object/privacy | Unverified runtime beyond read-only observations; FA-T01/T04 | Future isolated tests, real browser and failure/concurrency evidence required. |
| R10-072 | §15A realistic organisation/per-site scale, telemetry/map/calendar/import/migration recovery | Unverified; FA-T03/T04 | Agree scale, query/payload budgets, retry/replay/backfill/rollback evidence; no tenant fixtures. |
| R10-073 | §15B usability/rollout and §15C exact integrated completion/release next page | Gate; no production readiness claimed | Approved desktop user journey, migration/rollout checks, Main publication verification and Stephan page acceptance before next package. |

## Verification coverage and gaps

- **Executed read-only:** file/source inspection, local authenticated desktop list/profile/tab observations, static inventory, public official-reference research, Git/status/hash/reference checks. See [evidence](evidence/full-audit-verification.md).
- **Not executed:** create/approve/checkout/return/release/import/consent/Finance mutations, exports, device commands, restricted-role/portal tests, background jobs, concurrency, unit/feature/browser suites, build, migrations, benchmarks and production release. Thus no full lifecycle is certified.
- **Existing test source to reuse:** VehicleBookingSitePrivacyTest, FleetKeyLedgerSiteAccessTest, AssetMutationBoundaryTest, FleetWorkOrderSiteScopeTest, FleetMaintenanceWiringTest, FleetDashboardResidentSiteIsolationTest, FleetLiveMapSiteAccessTest, FleetTripPlaybackSitePrivacyTest, FleetRealtimePrivacyTest, ClientLocationConsentDisclosureTest, DeviceAssignmentConsentEnforcementTest, PersonalTrackingConsentWithdrawalTest, ResidentTransportJourneySecurityTest, ResidentTransportMedicationTransitTest, SiteGeofenceTest, OperationsGeofenceCompatibilityTest and ProcessFinancialEventJobDispatchTest. Discovery is not a passing run.
- **Mandatory future evidence:** each package must replace applicable unverified cells with exact source/test/run/role/browser evidence, including null/malformed data, direct foreign object, no Site, read-only role, concurrency/duplicate retry, lost network, stale state, withdrawal/expiry and correctly owned recovery. Neither this audit nor a screenshot can close those acceptance criteria.
