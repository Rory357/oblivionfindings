# Vendor agreements and shared vault — operational handover draft

## Current correction checkpoint — 13 September 2026

The user reported two navigation entries for the combined register and an incorrect Add Vendor modal, and explicitly approved the preceding concrete five-role contract-access proposal.

The shared navigation owner consolidated the IT register links into one **Vendors & Credentials** entry, opening the permitted initial tab and remaining active across both tabs and vendor details. The Sites contextual link is consistent. The vendor Add/Edit form now shares the canonical WizardShell with Vendor, Contact, Compliance and Review steps, required-field validation even after free step navigation, server-error return to the owning step, dirty-close confirmation, a create success pane and Add another. Site-context editing retains the site and all untouched compliance fields. Five new workflow tests plus eight existing register tests pass; scoped lint passes. No Vendor TypeScript diagnostics remain; unrelated My Day/Governance diagnostics are tracked separately.

The user-approved local permission operation is **applied**: c89f29 terminal 0, fingerprint a8e182e2df72927d17a036cb89b2d9938f749a4471a4a81dd74413a43b597d62. Exactly ten contract view/manage grants were added to the existing Finance, CEO, COO, CFO and Provider Manager roles, with three permission definitions. Existing definitions/grants, roles, user assignments, personal overrides and migrations were preserved. No credential-copy, personal or house/site-manager grant was added. Backup storage/app/private/vendor-vault-permissions-5e8916a2e9b8efaf.json.enc was encrypted and roundtrip-verified; SHA256 342425ef2e8b285a748ca1c871fd5e7487e317aef33f7f2dc8758dea7a2d0fce. Fresh post-preview f22cda reported no remaining definitions/grants. The prior automatic-review hold below is historical and was resolved by the user's explicit approval; site/house-manager role mapping remains pending.

The coordinated normal shared build passed: session 5318, terminal result 80f144, 05:57:04–06:01:19 UTC (4m15s). Current asset app-1if2QQuE.js; manifest SHA256 39b9420c6e0aace482bd5b8491aac75e345335644519dfc7ee4222dd876d29f9; public/hot absent. Current actual-Herd navigation/wizard browser verification PASSED: one combined IT entry, permission-appropriate initial tab, four-step Add Vendor flow, retained review and discard/reset, no horizontal overflow or console errors at1280x720. No vendor saved or secret revealed. Own tabs closed;7054-source postflight f70418 showed19 independent Governance page changes only, with served manifest and IT dependencies unchanged. Full current proof is in evidence/vendor-vault-browser-20260913.md. This build is local, not a production deployment.

Earlier checkpoints below remain historical; broader W23/W24 acceptance is not declared complete by these corrections.



Status: implementation and acceptance are still in progress. These notes authorize no working-database change, real credential rotation, access grant, deployment or provider action. Test results and browser evidence must be attached before release readiness is claimed.

Current local checkpoint: the separately reviewed050/051 migrations were applied successfully after encrypted backup and exact isolated-browser cleanup. Existing credential ciphertext and records were preserved, no new access grants were applied, and house-staff opt-in defaults remain false. Final build22455 and ordinary-Herd navigation proof passed; the closed-tab postflight matched all6814 source hashes. The Knowledge owner completed the host-specific PHP correction and reported the actual final retry passed:20MiB PDF through real ClamAV, Word text preview, oversized rejection and PDF original download. The host-only20M/64M settings have a verified encrypted backup; unrelated-site2M/8M defaults were preserved. Its test tab closed and final6814-source comparison also had zero drift. This closes the shared scanner/request-limit prerequisite, while contract-specific file browser limits remain scoped in the evidence. See evidence/vendor-vault-local-activation-review-20260913.md and evidence/vendor-vault-browser-20260913.md for actual results and limits.

The existing role catalogue contains Finance/CEO/COO/CFO/Provider Manager but not the proposed house_manager/site_manager names. The site/house-manager role mapping requires the user's clarification before permission activation is completed; do not grant contracts to all Team Leads or Coordinators by inference.

The known-role contract activation is also held for explicit user approval: automatic approval review rejected the proposed grant coordination because the trusted authorization did not specify recipients/resources/scope sufficiently. The concrete read-only proposal is documented in the local activation review. No permission-mutation call or indirect workaround has been attempted.

## Access and setup

The application serves one operating organisation. Approved Sites, exact roles and record capabilities remain the boundary. The additive vendor/vault migration must go through the separately reviewed working-database process. The explicit permission seeder is a separate deployment choice; the schema migration itself grants nobody access.

Commercial view and maintenance use separate permissions and the exact Finance/organisation-management/site-management role mapping. Auditor access to accounts payable is insufficient. Credential metadata, reveal, copy, management and audit are independent. The new house-staff checkbox is an explicit grant only to current staff assigned to that house. Historical is_shareable values grant no access.

SSO-only staff use a previously enrolled personal authenticator for vault step-up. This is distinct from any shared service authenticator stored inside the vault. Missing or unusable personal MFA fails closed; ordinary SSO sign-in does not automatically prove a new vault step-up. Existing account MFA enrolment is password-confirmed, so a passwordless account without enrolled personal MFA requires the organisation's separately controlled account-recovery/enrolment process. Do not create a local-password or identity bypass to make a test pass.

## Renewal and private files

Each canonical agreement owns its dates, terms, renewal evidence and single owner follow-up. Finance's supplier master continues to own supplier/payables data; assets remain in the existing asset register. Scheduler catch-up reschedules the existing follow-up. Worker-timezone calendar dates, owner access loss, retired vendor/service and agreement retirement require explicit acceptance evidence.

Original Word/PDF files remain on private storage. Opening a file must repeat current commercial authorization and record access before download. The product's explicit original-file download is the supported fallback where a browser cannot preview Word/PDF. Failed, unscanned or quarantined uploads are unavailable; a failed replacement must preserve the previous ready file. Scanner availability and PHP/web-server upload limits must be checked during deployment. Do not make private storage public or bypass scanning to resolve an upload failure.

## Recovery and key custody

Use the organisation's approved encrypted database/storage backup process. A coherent backup includes the canonical credential rows, encrypted credential versions, audit records, vendor agreements/events/file records, private file bytes and their ownership/permission dependencies. Back up application encryption keys through the approved key-custody process separately from the database; a database dump alone cannot recover encrypted values.

The existing Laravel ciphertext format is retained. Application-key recovery must use the matching current or approved previous key; config/app.php supports APP_PREVIOUS_KEYS. Never print keys, ciphertext payloads, recovered secrets or authenticator values into logs, screenshots, test output, Knowledge or this report. A missing key is an operational recovery failure, not permission to overwrite a credential with a new value.

For the isolated recovery drill, restore only disposable encrypted fixture rows in a fresh guarded schema, verify their canonical IDs and decryptability in assertions, verify that an unrelated key fails, and verify that an approved previous key can decrypt. Verify current Site and action authorization after restore. The application recovery action restores a retained encrypted value without reinstating historical sharing grants, clearing retirement or claiming an external password change. Record pass/fail and fixture identity only.

Storage-key maintenance is separate from external rotation. Saving a replacement value does not prove the external service changed. External attestation requires who/when/evidence; storing a replacement with an attestation is a separate explicit action. This implementation session must perform neither real external rotation nor bulk re-encryption.

## Retention and error handling

Retirement preserves canonical IDs, history and audit. Restoring a retired credential is separate from recovering a stored version. Retained encrypted versions are sensitive recovery material; disposal requires an approved retention decision. Failed private uploads require operational review and a retention decision, not public download or silent replacement.

Disclosure must commit its audit before returning a value. Copy logs an authorized intent before handing a value to the browser, then records only the browser-reported outcome. A browser can report clipboard success or failure; the product cannot verify later clipboard use or prevent manual copying of an already visible value.

Protected error responses must not contain raw exception messages, SQL bindings, request bodies or secret old-input sessions. Client-side fields use memory only and must conceal on timeout, page/session/context changes. Unknown save/upload outcomes require checking current record/history before retrying; retries must not imply that a supplier or external system was changed.
