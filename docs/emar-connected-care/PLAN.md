# Connected eMAR implementation plan

Build on the completed eMAR release, for one operating organisation across approved houses. Desktop web only. Preserve ordinary medication, controlled-drug, reconciliation and stock authority.

## Deliverables

1. Pharmacy connections: deployment-approved partner endpoints, stable dispatch identifiers, signed acknowledgements, durable delivery state and explicit uncertainty. A sent order never means stock was received. The generic JSON bridge requires a supplier agreement; it does not claim Toniq compatibility.
2. External clinicians: dedicated isolated accounts, verified identity and email, MFA, named-person grants with expiry, read-only chart facts and reviewed start/change/stop proposals. Existing order evidence and independent checks remain mandatory.
3. Provider handovers: identity and disclosure evidence, reviewed versioned packet and readable summary, recipient receipt evidence, incoming unverified reconciliation and canonical sign-off. Export never silently discharges a person or stops medication.
4. Medicine images: licensed source records, versioned exact code/name/product-strength/form matching, bounded private images, publication review, expiry and revocation. No scraped or invented catalogue.
5. Protected backups: house-specific Auckland schedules, current verified recipients, AES-256 PDF protection, separate password access, durable daily deduplication and delivery history, source revalidation, uncertain-send handling and retention.

## UI placement

Use existing Orders, Stock and Settings entry points. No extra left navigation group. Connected care has Access, Requests and Transfers views. Pharmacy sending lives inside the existing supply order. Catalogue is reached from Settings connections and Stock. Protected backups are reached from Reports, including for approved recipients who cannot manage Settings. Reuse PageHeader, compact desktop controls, searchable selectors, EntityTable, WizardShell, ReviewCard, SettingsModal and shared date/time/upload controls.

## Acceptance

- Direct-object and stale-authority denial for every read, write, export and send.
- External accounts cannot enter the internal application even after revocation or accidental role assignment.
- Clinical proposals never activate medication directly; controlled access remains explicit.
- No duplicate pharmacy stock, dispatch or automatic retry of uncertain external effects.
- No ambiguous medicine image; licence, exact identity, review and expiry must all pass.
- Encrypt a fictional PDF and prove correct-password readability plus wrong/no-password denial.
- Mail and HTTP fakes during tests. No live clinical transmission, production schema change or deployment.
- Test each API and desktop workflow, review the integrated diff, then publish source with precise outstanding deployment prerequisites.

## External prerequisites

Approved supplier endpoints, authentication and acknowledgement agreement; licensed medicine-image dataset; verified prescriber identities and access grants; configured qpdf executable, approved mail transport and recipient approvals. Shipping local workflow code does not establish these external arrangements.
