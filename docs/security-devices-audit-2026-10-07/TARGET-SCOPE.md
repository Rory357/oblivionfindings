# Agreed direction and configurable operating model

7 October 2026 · Updated from the user's eight clarification answers. This is an audit/planning specification, not a claim that these additions have been implemented.

## Confirmed decisions

1. Oblivion is still in development. Nothing is in production. Use a synthetic development pilot.
2. Research broad supported-living equipment coverage; the user does not need to supply an initial shopping list. The resulting [24-family catalogue](MARKET-RESEARCH.md) is the proposed coverage baseline.
3. Use a hybrid implementation: common Oblivion workflows with supported specialist providers where appropriate.
4. Each installation serves one operating organisation across multiple sites. Different organisations may deploy separate installations with different equipment, policies and licenses. This does not introduce tenant switching or shared cross-organisation data.
5. Monitoring response can be internal, external or combined. Make it configurable.
6. Evidence collection timing, before/after windows and source selection must be customisable within Oblivion.
7. CCTV/location/evidence permissions must be customisable, using market-informed role and scope controls.
8. Dedicated HR Attendance owns work time. Recommend a strong default, while making device-assisted attendance customisable; My Day is the confirmation/correction surface.
9. Endpoint operating systems, ownership modes, counts and licenses vary by deployment. Plan for the full range rather than assuming a fixed installed fleet.
10. **One complete release covering the agreed scope.** Work packages and development/test stages are internal dependencies, not phased customer releases or an MVP. No release date was specified.
11. **Rory's rules are mandatory.** Follow DESIGN.md and the shared design guides across all new/changed screens and interactions. The [conformance mapping](RORY-CONFORMANCE.md) is part of final release acceptance.
12. **Latest endpoint direction: Oblivion-native RMM with read-only Intune visibility.** This supersedes the earlier NinjaOne selection. Intune retains MDM actions and policies; Oblivion owns its native computer-management functions and combined visibility.

## Common device workspace

Each device/asset needs a canonical identity, provider/model/firmware, lifecycle state, approved site/room, historical assignments, responsible team, service/warranty information, monitoring freshness, events, incidents and supported actions. Present what a user is permitted and able to do, with actual provider availability and last verified result.

Keep source-owned records connected: Fleet owns vehicle operations, Facilities/Assets owns physical maintenance/custody where already canonical, HR owns time, care modules own clinical records, IT owns technical tickets, Control Room owns immediate response and Incidents/H&S owns investigation/review. Cross-module summaries link back to the same record.

## Monitoring arrangements

Configure a response policy at organisation-default level, then authorised overrides for site, event family and severity, with schedules/holiday cover and explicit effective dates. Preview the effective policy and conflicting overrides before activation.

Supported modes:

- **Internal:** site staff/Control Room/on-call receive and accept responsibility.
- **External:** a named monitoring provider receives selected events and returns accepted-response status where its interface supports it. Oblivion retains source/delivery/outcome records and a fallback contact.
- **Combined:** internal and external parties receive defined responsibilities, in parallel or primary/fallback order. One accountable response owner is visible for each required action.

Model **created → queued → delivered → acknowledged → responsibility accepted → responding → outcome recorded** as distinct facts. Provider transport acknowledgement is not human acceptance. If an interface cannot report human acceptance, show unknown and use the configured manual verification/escalation; never manufacture an acknowledgement.

Configuration includes provider/account reference, permitted sites/event types, minimal outbound data, operating hours, response deadlines, escalation recipients, fallback path and closure/reconciliation rules. Store delivery attempts, duplicate identity, late/out-of-order status and provider references. A provider resolving its ticket does not automatically close a care incident or H&S investigation.

Test provider outage, rejection, timeout, duplicate/replayed callbacks, shift handover and simultaneous internal/external response. The UI must show when response ownership is unaccepted. Choose sensible policy templates during implementation, but do not make up contracted response-time guarantees.

## Configurable incident evidence collection

Expose plain-language settings for event families/sites: collect on detection, on operator confirmation or on demand; minutes before/after; allowed source types; room/zone scope; approved camera mappings; retention/preservation behaviour; permitted reviewers; and rules for expanding a window.

Suggested development template: collect on fall detection, five minutes before and fifteen after, restricted to relevant authorised sources. This is a synthetic test default, not a settled operational threshold.

Validate ranges and resource limits; preserve the policy version used for each event. Explain which rule won when site/event overrides apply. Changing a default affects future collections; re-collecting an earlier incident creates a new version with actor, reason and expanded scope. An expansion can only retrieve what the source still retains.

Show collecting/partial/complete-for-requested-sources/failed/unavailable states, clock uncertainty and missing sources. Distinguish facts, observations and reviewer interpretations. Live viewing is labelled Live now; vendor playback references identify incident time. Recording bookmarks, preservation requests, confirmed preservation and retained exports have different statuses.

## Customisable permissions

Separate **what an action permits** from **which records it covers**. Authorisation is the intersection of role action, approved site/device/person scope, canonical source rules, applicable privacy/consent and any time/purpose restriction. An incident link does not bypass a restricted source.

Offer editable role templates for frontline worker, site manager, Control Room responder, investigator, IT technician and external responder. These are starting templates mapped to existing permissions, not new fixed authorities. Suggested starting posture:

- Frontline staff can report, see assigned work and acknowledge assigned tasks; camera/location access requires an explicit grant.
- Control Room sees permitted active-response context and approved site media. Historical playback, audio/talkback and export are separately granted.
- H&S investigators see authorised incident evidence and can request an expanded window. An investigation role does not grant unrestricted historical staff tracking.
- IT can monitor/manage approved endpoints and networks without automatically seeing care narratives or person tracking.
- External responders receive an alert-specific minimal view or contracted integration payload. Their access is scoped, expires as configured and is revocable.

Configure live video, recorded playback, audio, snapshots, export, evidence preservation, staff current/history location, supported-person location, device administration, command execution and policy editing separately. Add expiry and explicit approval rules for sensitive actions where required by the deployment. Show a “test this role”/effective-access preview and audit permission changes, media sessions and exports.

Market basis: [Milestone device/action permissions](https://doc.milestonesys.com/xprotect/xprotect-management-client/2026r1/en/device-tab--roles-), [Intune action and object scoping](https://learn.microsoft.com/en-us/intune/intune-service/fundamentals/scope-tags). These sources support the pattern; Oblivion's proposed templates are a design recommendation.

## Recommended attendance solution

**Use evidence-assisted timekeeping as the default.** A worker starts/ends attendance in My Day or reviews a suggestion from an authorised reader. HR creates the canonical attendance/time-entry record, the worker confirms or corrects it, and normal HR approval controls what reaches payroll. The schedule remains planned time; door activity remains observed access; paid time is an explicit HR outcome.

Provide three configurable modes per site/work pattern:

1. **Manual/app:** My Day or a dedicated kiosk records an explicit start/end/break; suitable for offsite, outreach and home-based work.
2. **Assisted — recommended default:** trusted identity-bearing reader events suggest times, paired with the relevant shift. My Day shows the suggestion and its source. The worker confirms or corrects it with a reason.
3. **Dedicated automatic reader:** designated attendance readers record start/end automatically under a configured identity, direction and shift policy. Do not apply this to every door. Automatic capture still allows worker review and exception correction; payroll approval remains HR-owned.

Reader integration must retain raw source events, stable worker/credential mapping valid at occurrence, direction/reader purpose, occurred/received times and duplicate identity. Apply configurable tolerance, duplicate-swipe and shift-matching rules. Site arrival, walking outside, camera presence or GPS proximity alone must not silently decide hours worked.

My Day should show an end-of-shift or timesheet review with proposed start/end, breaks and source, highlighting exceptions. Allow bulk confirmation of straightforward shifts with explicit worker attestation; require attention for missing/conflicting entries. Preserve the original evidence alongside corrections. Approved/exported periods use a recorded adjustment/reapproval path rather than rewriting payroll history.

Handle missed swipes, no exit reader, temporary exits, offsite work, travel between assignments, training, unscheduled work, split/overnight shifts, sleepovers/on-call and daylight-saving changes. Missing evidence creates an exception, not zero pay. Keep UTC/original offset and NZ display; distinguish the shift's occurrence date from processing date. Policy controls may identify variances; they must not silently erase actual time by snapping everything to the roster.

Market evidence supports worker correction/confirmation and managed approval: [Microsoft Shifts](https://support.microsoft.com/en-us/teams/shifts/clock-in-and-out-with-shifts), [Deputy review and correction](https://help.deputy.com/hc/en-au/articles/17528765586319-Review-edit-and-approve-timesheets), [ShiftCare mobile attendance](https://help.shiftcare.com/en/articles/3022277-clock-in-and-clock-out-of-shifts-from-the-mobile-app). Employment NZ emphasises accurate wage/time records; detailed pay rules belong in the separate HR/payroll requirements. [Employment NZ record keeping](https://www.employment.govt.nz/starting-employment/rights-and-responsibilities/record-keeping).

## Endpoint management across deployments

**Latest approved direction — 7 October 2026:** build **Oblivion-native computer management**, replacing the earlier NinjaOne decision. Use **read-only Microsoft Intune visibility** alongside it; the user proposed visibility, we recommended this boundary, and it is the working implementation direction. Intune retains MDM enrolment, policy/app deployment, lock/wipe and other write actions.

- **Native Oblivion RMM:** downloadable Windows/Linux endpoint agents; inventory and installed software; health and alerts; governed restarts/service actions; approved scripts, software and update tasks; maintenance scheduling, result history and IT links. Integrate a maintained remote-support engine and verify its session behaviour. The endpoint agent is separate from the site network collector.
- **Read-only Intune:** explicitly map existing canonical devices to Microsoft managed-device IDs. Show supported device identity, ownership, OS, compliance, encryption and last-sync information with original observation times and clear stale/missing/failed states. Use Microsoft Graph read permissions and provide a link to Intune for management. This integration must not issue remote actions or policy/app changes. [Microsoft device-read contract](https://learn.microsoft.com/en-us/graph/api/intune-devices-manageddevice-list?view=graph-rest-1.0).
- **One workspace:** preserve canonical device/site/person ownership and existing roles/permissions. A computer can have native and Intune observations without becoming two device records. Assign one owner for any overlapping update/application workload; Intune-managed workloads cannot be silently taken over by the native agent.

The native agent needs one-use enrolment, separately revocable credentials, protected local state, verified transport, signed bounded commands, replay protection, durable execution receipts and safe recovery after interruption. Reuse the existing command approvals and revalidate current device/site/requester access at delivery. Queued, delivered, executed and observed-success states remain distinct.

Use a deployment profile for OS, device ownership, approved tasks, maintenance windows, support consent and licences. Native Windows/Linux management and broader Intune visibility have distinct OS/action acceptance matrices; do not imply equal capabilities on every platform. Read-only Intune visibility requires the organisation's licensed Intune service.

An Intune mapping changed during a fetch must await a fresh sync. A failed or partial fetch retains prior observations and their timestamps; it must not fabricate a current healthy result or overwrite native management ownership.

NinjaOne is no longer a required dependency or committed connector. Kaseya/NinjaOne remain market capability references only. The implementation is in the isolated native-endpoint-management checkout, within the full Security & Devices goal. Source code, tests and live provider/hardware evidence must each be assessed separately.


## One final release: acceptance contract

All agreed workstreams are in scope: monitoring/topology, device lifecycle/actions, alarms, live CCTV/vendor recordings, access/HR attendance, care equipment, tracking/lone-worker welfare, signed collector downloads, RMM/MDM, configurable response/access/evidence policies and H&S reconstruction.

Internal work order remains useful: fix safety boundaries, implement shared contracts, integrate providers, build connected UI, run synthetic/laboratory/hardware acceptance and complete installation/operations documentation. This is not permission to ship partial customer releases. Every declared supported capability must have implementation, actual tests, visible failure handling and the appropriate provider/hardware evidence before the complete-release claim.

An external API/license/partner dependency is a visible release-scope dependency. An unsupported device can still be registered for inventory/maintenance, but cannot be counted as remotely managed. Resolve the final supported model/action matrix explicitly instead of using “all equipment” as an untestable promise.
