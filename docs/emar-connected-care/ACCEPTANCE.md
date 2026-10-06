# Connected eMAR delivery and activation

## Scope delivered

Desktop web workflows for one operating organisation across approved houses. These extend the existing Orders, Stock, Reports and Settings screens without adding a left-navigation group.

| Capability | Implemented workflow | Production activation dependency |
| --- | --- | --- |
| Pharmacy ordering | Approved endpoint bridge, order-linked dispatch, authenticated acknowledgements, stable idempotency, delivery history, explicit unknown outcome and manual resolution | A pharmacy must agree to the documented protocol and supply approved endpoints, house mappings and separate credentials. This is not a Toniq adapter. |
| External prescribers | Dedicated isolated account, verified mailbox and MFA, verified identity, named-person expiring grants, chart access and start/change/stop requests for internal review | Verify each real prescriber and explicitly grant access. Existing password-reset and mailbox verification need the organisation's mail transport. No invitation is sent by provisioning. |
| Provider transfers | Reviewed outgoing packet, readable handover, receipt evidence, incoming unverified facts and linked canonical reconciliation | Agree a secure transfer channel and receiving workflow. The packet is the documented Oblivion format; it is not a universal inter-provider exchange or electronic prescribing signature. |
| Maintained medicine pictures | Versioned licensed sources, exact product matching, private image upload, review, expiry, revocation and verified medicine binding | Supply a licensed dataset and establish an owner/update schedule. No supplier imagery or automated supplier feed has been invented. |
| Protected backup emails | House-specific NZ schedule, approved current recipients, protected PDFs, daily deduplication, delivery history, separate password retrieval and retention | Configure reviewed qpdf and mail transport, assign permissions and recipients, then enable scheduling/delivery. |

## Validation record

- Frontend: 358 tests across 59 files passed on the final source, including request-history discovery and full handover facts. TypeScript and ESLint passed. Production asset build was repeated after the final handover refinement.
- Pharmacy: 12 feature tests / 108 assertions and 7 focused unit tests / 53 assertions passed.
- External access, handovers and existing login/MFA: 45 feature tests / 287 assertions passed with zero failures or skips; 6 focused unit tests / 15 assertions passed.
- Catalogue: the complete 12-case file passed / 67 assertions. Later shared changes add only the transfer permission to the bounded reconciliation snapshot and reuse already-loaded role evidence; catalogue implementation was unchanged.
- Catalogue/backup focused units: 20 tests / 61 assertions passed, including a real qpdf AES-256 encrypt/decrypt check, wrong/no-password rejection and safe stale temporary-file cleanup.
- Backup: all 18 feature tests / 122 assertions passed. A subsequent one-line recipient-limit repair adds a 19th case covering reapproval at capacity, unchanged data on denial, and reapproval after capacity is released; fresh CI must pass this final case before merge.

The GitHub medication release job includes all five connected feature files, the existing login/MFA regressions and focused units. It installs qpdf and checks its executable before running the real PDF test; a missing dependency must not silently count as encryption coverage.

## Synthetic desktop checks

A separate local preview on port8767 uses fictional people/accounts, isolated database, array mail and blocked stray HTTP. It does not use the production database or the user's other preview.

Verified: compact review wizards and switches; required-step gating; saved disabled NZ backup schedule; saved catalogue source draft; external mailbox/MFA sign-in; named-person chart; submitted request awaiting internal review; external internal-stock denial; minimal account-security navigation; saved outgoing fictional handover draft. Browser verification caught and fixed Fortify's separate MFA redirect and the request-history chart-selection dependency.

No production migrations, live clinical transmissions, invitations, supplier orders, recipient grants or deployment were performed.

## Deployment and operational handover

1. Apply the six additive migrations before exposing the new application release or restarting workers. In particular, the external-account marker is used by staff queries. The new permission migration does not grant access to baseline roles. Keep the prior application version available; do not roll back clinical-history tables as a routine code rollback.
2. Assign the exact management/send permissions to approved staff, preserving house, person, employment and controlled-medication checks. Confirm a staff account and a dedicated external account remain isolated.
3. Configure pharmacy partners from the documented contract. Test signed acceptance/rejection, timeout/unknown, duplicate acknowledgement and delayed acknowledgement in the supplier's approved test environment. Delivery acceptance never counts as physical stock receipt.
4. Import only licensed catalogue data. Review exact code/name/product strength/form and packaging, publish with an expiry, and verify a medicine binding and revocation. A catalogue image is an aid to identification, never authority to administer.
5. Configure an absolute official qpdf executable through EMAR_BACKUP_QPDF_PATH; configure the organisation's mail transport and approved recipient mailboxes. EMAR_BACKUP_SEND_ENABLED remains false until configured. Use approved private storage and a shared scheduler lock/cache on multiple application servers.
6. Before enabling routine backup delivery, demonstrate delivery and opening of a fictional protected PDF outside the application. Each generated PDF has a separate password. The authorised duty process must obtain and securely retain the current password before an outage; the unavailable application cannot reveal it during an outage. Do not email passwords alongside charts.
7. Monitor delivery history. A result marked unknown/uncertain needs a human check and is not automatically resent; some recipients may already have received it. Review retention and ensure scheduled maintenance runs.

Technical contracts are in pharmacy-contract.md, external-clinical-contract.md and catalogue-backups-contract.md. Source completion does not certify supplier interoperability, medicine-data licensing or production clinical governance.
