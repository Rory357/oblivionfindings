# Prioritised native remediation roadmap

This is a recommendation register, not implementation authorization. Every design is an original Oblivion-native build; exact sequencing requires the named clinical/privacy/security/finance owners.

## Wave 0 — immediate stop-gaps (now, operational)

- Restrict global clinical, H&S, HR, device/location, asset and Control Room child permissions to explicitly global accountable roles.
- Until the Financial Insights object-scope decision is made and tested, grant `finance.dashboard` only to explicitly approved global finance roles; do not treat possession of a client/site API URL as authorization.
- Do not use caller-controlled medication override; require current medication competency before granting/signing capability; reconcile all administrations to resident/order/round/assignment.
- Suspend retention manual/scheduled execution pending allowlists, preview, holds, approval and restore proof.
- Fail direct roster assignment and job-board approval when eligibility cannot complete.
- Run reconciliation for safety source records lacking outbox/alert; surface and manually replay failures.
- Treat informational NOK consent and generated capacity/best-interest fields as non-authoritative pending clinical/legal review.
- Suppress affirmative `Certified` and `Cover OK` UI states until an authoritative, scoped certificate/readiness record exists; unknown must remain unknown.
- Restrict consent evidence and generated privacy exports to private storage and explicitly authorised delivery; do not rely on a public-disk path.
- Have the Privacy Officer replace the inconsistent public statement and approve current HIPC/Rule 3A language, entity/contact details, effective version and collection-point ownership.
- Until the email-verification contract is aligned and tested, treat approval plus strong authentication as the effective access gates and manually confirm mailbox control before operational access; do not claim `verified` middleware enforcement from route labels alone.
- Do not grant broad `hr.compliance.view` solely to make vetting or driver exports work. Document the current permission conjunction for support staff while HR, Fleet and Authorization owners decide the dataset-specific policy.
- Do not publish or distribute the inactive `dataset=renewals` selector. Treat the shared compliance export as sensitive until an isolated compliance-only/driver-denied test proves a fail-closed mixed-data boundary or the branch is removed.

## Wave 1 — shared authorization graph (first 30 days, XL)

Owner: Authorization Platform + domain owners. Introduce explicit scope results (`global`, assigned sites, denied), common parent-scoped resolvers and authoritative custody/relationship queries. Apply first to `ARCH-P0-A`, `ARCH-P0-B`, `SAFE-NESTED-01`, `CLIN-SITE-01`, `HS-SITE-01`, `RESP-SCOPE-01`, `FLEET-TRANSPORT-01`, `WF-HR-PROFILE-SITE-PRIVACY`, `FIN-INSIGHTS-DIRECT-OBJECT-01`, `AUTH-EMAIL-VERIFY-CONTRACT-01`, `HR-COMPLIANCE-EXPORT-PERMISSION-01`, `HR-COMPLIANCE-RENEWALS-DISCLOSURE-01`, tasks/watchers and funding claims. Add two-site role/action/direct-ID matrices before rollout.

## Wave 2 — fail-closed medication and clinical commands (days 15–45, XL)

Owner: Medication Safety + Clinical Governance. One server-authoritative administration command; structured privileged override; current competency/exemption; verifier separation; CD/stock action capabilities; schedule-protocol binding. Preserve break-glass and necessary clinical steps—ease must not remove safeguards.

## Wave 3 — durable events and operational lifecycle (days 30–60, XL)

Owner: Control Room/Integration Reliability. Transactional source/outbox or deterministic reconciliation; signal claim/unique alert key; provider site/device binding and replay window; dead-letter/replay UI; symmetric incident/safeguarding close/reopen requests; operator metrics and failure-injection tests.

## Wave 4 — legal/destructive authority (days 30–75, L/XL)

Owner: Privacy Officer + Clinical/Legal. Per-model retention registry, dry-run, four-eyes activation, holds/exemptions and tested restore. Separate acknowledgement, self-consent, substitute authority, decision-specific capacity and attestation/version evidence. Use official NZ sources and record accountable policy decisions; do not encode legal conclusions from this audit alone.

## Wave 5 — workforce and finance provenance (days 45–90, XL)

Owner: Rostering/Payroll/Finance. Local-time interval segmentation; leave/offboarding coverage actions; leave-to-payroll source ledger; attendance/timesheet site/client scope; delivery-to-one-monetisation provenance; GST source-tax ledger; exactly-once reversal/recurring/match/reconciliation/settlement state. Quarantine consolidation unless product/finance owners define a valid single-tenant legal-entity use.

## Parallel privacy/P2 and design-system work (after immediate safeguards, L)

Complete verified-authority/scope-before-export, private evidence storage and the owner-approved public privacy notice. Correct the global mobile navigation semantics and measured 390px overflow; first establish per-instance hero/overlay trigger evidence before asserting system-wide density or focus defects. Adjudicate the Control Room accessible-name signals manually. Use existing PageHero, Dialog/Sheet and WizardShell families where evidence supports them. Add a test/dev build marker first so deployed visual evidence can be tied to source.

## Exit criteria

- Every P0/P1 acceptance plan passes unit, feature, architecture, browser/a11y, performance and concurrency/failure tests as applicable.
- First establish the distinct-user-capability denominator and rebuild/independently approve each feature-specific task script; then representative actor/site fixtures execute every accepted script. The eight cross-module journeys complete with visible recovery and hand-off evidence.
- The deployment identifies its commit; browser/source drift is eliminated or explicitly versioned.
- Clinical, legal, privacy, H&S and accounting owners sign their policy decisions; the audit itself is not the sign-off.
