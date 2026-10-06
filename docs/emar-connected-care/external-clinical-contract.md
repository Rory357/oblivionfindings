# External clinical access and provider handovers

One operating organisation across approved sites. External providers are named recipients and clinicians, not tenants.

## Routes and page contracts
GET /emar/connected-care?client_id renders emar/ConnectedCare: clients [{id,name}], selected_client {id,name,date_of_birth}|null, clinicians, grants, proposals, transfers, can {manage_access,manage_orders,transfer,export}, witnesses (P04 witness picker).
POST /emar/connected-care/clinicians: client_id,name,email,provider_name,registration_authority,registration_number,identity_evidence,expires_at,identity_confirmed=true. Fresh dedicated account only, no invitation or email.
POST /emar/connected-care/clinicians/{id}/revoke: client_id,reason.
POST /emar/connected-care/grants: client_id,clinician_id,purpose,expires_at,can_propose,include_controlled.
POST /emar/connected-care/grants/{id}/revoke: reason.
POST /emar/connected-care/proposals/{id}/decision: decision=accept|reject|link_stop,decision_note. Accept requires source_confirmed=true and current P04 source {type:written|phone|verbal,prescriber,received_at,description,read_back_confirmed,witness_id,witness_pin},source_file for written (attached proposal file may be reused). Link recorded stop requires source_confirmed=true,source_reference,ceased_version and a newer exact canonical ceased version.
GET /clinical-portal?client_id renders standalone emar/ClinicalPortal: clinician {name,provider_name,registration_authority,registration_number,expires_at}, people [{id,name,date_of_birth,expires_at,can_propose,include_controlled}],selected_client {id,name,date_of_birth,medications,allergies}|null,proposals (own permitted proposals only).
POST /clinical-portal/people/{client}/proposals: kind=start|change|stop,medication_id and expected_version for change/stop,prescription for start/change,reason,request_key,optional source_file PDF/JPEG/PNG <=10MiB.
GET /clinical-portal/proposals/{id}/source and GET /emar/connected-care/proposals/{id}/source return private source under current identity/person/controlled permission.
POST /emar/connected-care/transfers: client_id,direction=outgoing|incoming,provider_name,recipient_name,purpose,disclosure_basis,identity_evidence,request_key. Incoming also source_snapshot,source_reference,identity_confirmed=true.
POST /emar/connected-care/transfers/{id}/transition: action=review|receipt|start_reconciliation|complete|cancel,expected_version,request_key,note. Review requires identity_confirmed,facts_checked,recipient_confirmed. Receipt requires receipt_reference. Incoming reconciliation requires confirmed identity. Complete requires linked canonical signed reconciliation.
GET /emar/connected-care/transfers/{id}/packet: reviewed versioned JSON attachment under current medication export and transfer permissions.
GET /emar/connected-care/transfers/{id}/handover: same reviewed facts readable in the UI, same privacy checks.

Clinicians: {id,user_id,name,email,provider_name,registration_authority,registration_number,identity_verified_at,expires_at,revoked_at}.
Grants: {id,clinician_id,clinician_name,client_id,site_id,purpose,can_propose,include_controlled,expires_at,revoked_at,active,availability:ready|account_setup_required|account_unavailable|identity_unavailable|site_changed|expired|revoked}.
Proposals: {id,client_id,client_name,clinician_name,kind,medication_id,expected_version,prescription,reason,status:submitted|accepted|rejected,submitted_at,revision_id,decision_note,has_source_file}.
Transfers: {id,direction,client_id,client_name,provider_name,recipient_name,purpose,disclosure_basis,status:draft|reviewed|received|reconciliation_started|reconciled|cancelled,version,identity_evidence,reviewed_at,received_at,reconciliation_id,snapshot_sha256,snapshot}.
Canonical medication payload adds id,version,state,approval_status. Allergies are {allergen,reaction,severity}.

## Machine packet and safety
Packet: format=oblivion-medication-handover,format_version=1,transfer_id,transfer_version,direction,provider_name,recipient_name,purpose,disclosure_basis,reviewed_at,snapshot_sha256,snapshot.
Snapshot: captured_at,person {name,date_of_birth,nhi_number},medications [{id,version,state,approval_status,prescription,last_dose,next_due_at}],allergies [{allergen,reaction,severity,notes}],limitations.
Incoming captured_at, medications[].last_dose.given_at and medications[].next_due_at are ISO date-times with an explicit Z or ±HH:MM offset (seconds optional; fractional seconds up to six digits when seconds are supplied). Accepted instants are stored and exported in UTC with the same instant and fractional precision. Capture and last-given times cannot be in the future. Date of birth and prescription start_date/end_date remain YYYY-MM-DD calendar dates. Medications and allergies must be JSON lists starting at index zero; keyed dictionaries are rejected. Empty lists remain valid unverified source evidence and do not confirm no allergies.
Digest identifies source bytes; it is not a supplier signature. Incoming medicine and allergy facts are unverified evidence only; no active order or allergy record is imported automatically. Internal canonical reconciliation must resolve each medicine. No source discharge occurs on export.
External accounts require approval, verified mailbox, confirmed MFA, unexpired manually verified clinician identity and explicit named-person grant. Site movement invalidates grant. Immutable account marker survives revocation and forged ordinary administrator grants. No internal navigation, counts, search, notifications or export permission is shared.
Proposals request clinical review; they are not digital prescribing signatures. Internal acceptance creates a pending P04 revision; independent current checks remain necessary. Link recorded stop only links a cessation already completed in canonical Orders.
Transfers record local evidence; receipt is not a transport acknowledgement. No automatic external transmission or invitation. A supplier adapter requires agreed published schema, recipient authentication, terminology, acknowledgement, reconciliation and data-sharing agreement; no 1CHART endpoint is invented.

## Mutations and pagination
All writes return JSON success, message, id, revision_id and reconciliation_id where present when the request accepts JSON; Inertia requests retain redirects and flash messages.
Internal lists expose pagination.clinicians, pagination.grants, pagination.proposals and pagination.transfers, each {current_page,last_page,total}, 100 rows per server page. Query keys are clinicians_page, grants_page, proposals_page and transfers_page. Portal pagination.proposals uses proposals_page. Preserve client_id while switching pages.
Transfers also expose allowed_actions: draft [review,cancel], reviewed [receipt,cancel], incoming received [start_reconciliation,cancel], outgoing received [], reconciliation_started [complete], reconciled/cancelled []. Completion requires allergies_reviewed=true and allergy_review_reference. Identity and grant expiry require a real ISO datetime with explicit timezone offset.
Incoming packets retain supported canonical prescription fields and source dose facts; unknown person/allergy fields are stripped. Imported facts remain verified=false. The receiving organisation records each allergy decision separately and references that review before completing the transfer.
Provisioning does not send mail or disclose a password. The fresh account can use the existing Forgot password flow to set its password, verify its mailbox and enrol confirmed two-factor authentication. Security setup routes remain available before clinical access and after grant revocation; internal application routes remain denied.

Portal people, chart facts and own proposals are selected under one canonical Client/account/site/identity/grant snapshot. A revoked or moved-person grant cannot authorize a later page query. Completing the MFA challenge uses a dedicated Fortify response that clears internal intended destinations and opens the clinical portal; staff retain the existing response.
Prescription start_date, end_date and review_date are calendar strings (YYYY-MM-DD), and dose_times is an array. Exported clinical instants use ISO datetimes with explicit timezone offsets; access expiry inputs also require an explicit offset. Incoming facts remain unverified even when their structure passes the canonical prescription validator.
