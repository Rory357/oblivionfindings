# PKG-02A v1 — revalidated source and future contract

This is design evidence at `2302ca33a95616e442a78e957ddce82d8db98669`, not implemented enforcement. Source files are read-only in this task. The application is one operating organisation across approved sites; roles, permissions, site/client access, canonical ownership and privacy checks form the boundary. No new tenant transport, selection or policy is proposed.

## Existing enforcement and evidenced gap

- `PersonalTrackingPrivacyService` evaluates the current personal-tracking assignment against client, site, device, purpose, canonical consent, type/version, authority, audience and retention constraints. Its withdrawal path locks canonical records, stops the assignment and clears location projections while retaining governed evidence. Preserve and extend that authority rather than duplicating it in React.
- `DeviceAssignment` is the canonical device/client/site assignment and collection-decision association. Existing organisational/legacy fields and defaults are not adopted as approved privacy policy and are not removed in this scope.
- `ClientController` location, history, privacy-status, command and export paths use current client access and tracking-privacy checks. `ClientProfileSectionAccess` separately evaluates location capabilities; consent permissions are separately governed by `ClientConsentPolicy`. Direct-object access needs denial even when a user can view another profile or site.
- Existing `client-location-tab.tsx` provides a map, history and device context. Its data and failure presentation do not clearly explain every missing, uncertain or ended state; a failed history request must not masquerade as zero observations. The proposed UI adds explicit states around the existing canonical workflow.
- `use-personal-location-privacy.ts` already performs fail-closed, no-store revalidation on an interval and on focus and clears protected state when access ends. Preserve this behavior and apply it to every current observation, history, selected point and derived summary.
- `PortalLocationController` calls portal client access and `authorisedClientAssignment` for current/history/privacy status. The inspected `User::canAccessClientPortal` relationship/role path does not establish an independent named-recipient, purpose-scoped, time-bounded disclosure decision. This is the evidenced sharing-contract gap. A relationship or valid collection assignment must not be presented as that grant. Client self-access requires a separate explicit path and policy.
- `PersonalTrackingLocationExportService` applies export permission, retained-period and collection-start bounds, rechecks canonical assignment/consent/device after reading, records a purpose/reason in audit and streams with private no-store headers. Preserve this race-aware path; grant scope must never implicitly permit export.
- `FleetRealtimeAuthorizationService` checks exact device/client/site, active asset association and current collection authority before realtime delivery. Later sharing work must recheck the exact recipient and grant on every applicable subscription/delivery boundary.
- `PrunePersonalTrackingTelemetry` and retained raw-frame handling are existing retention boundaries. A withdrawn viewing grant does not mean historical evidence is deleted; retention changes need approved rules and a separately reviewed implementation.

Read alongside `routes/operations.php`, `routes/portal.php`, approved WF-08 and FA-R01/R02/R03/T01. Hashes of the inspected files and design guides are in `baseline-inputs.json`.

## Proposed later interface boundaries

These describe required outcomes for a future scoped handoff; they neither invent an endpoint nor authorize implementation.

1. **Location response:** current canonical client and assignment references; independent collection/viewer/sharing decisions; permitted actions; observation `observed_at`, received time, timezone, accuracy/source/age or explicit absence; current privacy revision/check time. Sensitive consent, device and recipient details are omitted for an unauthorised viewer. Loading, empty, unavailable and failed access checks remain distinct.
2. **History:** validated calendar period and timezone, server-resolved instants, retained-period bounds and the same current assignment/access checks as the latest view. Access change cancels or invalidates in-flight responses, clears previously rendered rows/points, and cannot be undone by a stale response. Network failure retains the period and a safe retry action.
3. **Recipient review:** locked canonical client; one verified account reference; explicit purpose and requested disclosure scope; start, end and review instants; current canonical decision/evidence reference; revision/concurrency token and request identity for any eventual mutation. An account picker searches only the permitted directory. Unknown authority, purpose mismatch, expired evidence or missing policy must not activate a grant.
4. **Server validation:** reject incomplete/reversed periods, disallowed duration or review rules, invalid recipient/client binding, unsupported scope, non-current authority/version and timezone gaps/ambiguities under the approved policy. Resolve Pacific/Auckland wall times/DST server-side. The prototype only checks wall-clock input structure/order and demonstrates review; it is not a time-authority implementation.
5. **Withdrawal:** exact named recipient and grant, reason and current revision; explicit confirmation; auditable and retry-safe command; recheck authorization at execution. End future disclosure across page/API/history/export/realtime/cached projections and relevant jobs without implying deletion of retained evidence or withdrawal of the separate collection decision.
6. **Action ownership:** collection belongs to canonical Consents, assignment to canonical Security Devices/Fleet integration, staff access to existing role/site/client policies, and recipient disclosure to the approved independent decision contract. No parallel consent/evidence store. Preserve private no-store responses and avoid putting precise locations or decision details in logs, URLs or unrestricted events.

## Privacy-owner decisions before enabling disclosure

- Which named audiences and purposes may receive latest observations, history or export, through which channel? What does the client's own access require?
- Which decision-maker/representative authority, evidence/type/version and verification/review roles apply, including when authority cannot be verified?
- What start/end, maximum duration, review due, expiration, renewal and timezone/DST rules apply? No default duration or emergency bypass is invented here.
- What stops on collection withdrawal, recipient withdrawal, assignment/device/site change and account/role changes? Which audit/evidence records remain under which retention rules, including holds and existing export records?
- Which source-age, accuracy, device-contact and battery rules determine observation labels? Missing data or alerts cannot establish wellbeing.

## Later implementation ownership

Only after the documented gates: Designer owns React/components/styles/copy/interactions and combined fidelity/QA; one supervised Sol owns the agreed backend-only files and tests. Define an explicit request/response and test contract first, then sequence application writers with a handoff. Relevant existing test families include ClientLocationConsentDisclosure, ClientLocationAssetSecurity, DeviceAssignmentConsentEnforcement, PersonalTrackingConsentWithdrawal and FleetRealtimePrivacy. They were located as future verification anchors, not run or claimed as backend acceptance for this mockup.

