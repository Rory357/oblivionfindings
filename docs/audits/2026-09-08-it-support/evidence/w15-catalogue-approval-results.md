# W15 catalogue provisioning approval propagation

Implemented and focused backend verified. W15/E07/E14 remain incomplete; browser acceptance is next.

Confirmed defect: a published provisioning catalogue item advertised requires_approval, but ItCatalogSubmissionService omitted it when creating the canonical ItProvisioningRequest. The existing fulfilment guard consequently treated that request as not requiring approval.

The submission service now copies the requirement into approval_required and initializes approval_status to pending or not_required. The append-only created event records the requirement. The existing request, approval, fulfilment and audit paths remain canonical; no new workflow, permission or operational approver policy was invented. Later catalogue edits do not change the request's copied approval requirement. No migration, design change or provider action.

Meaningful HTTP coverage exercises submission, idempotent replay after a catalogue approval edit, preservation of the original gate, failed fulfilment with no fulfilled event, requester approval403, current authorised manager approval and completion, one approval/fulfilment event, one submission and no duplicate ticket. Existing non-approval intake verifies not_required. This verifies the existing management permission boundary, not the full future eligible-approver/self-approval/rejection/expiry/cover contract.

Pint passed. Isolated run68103/token it_56587a4befa54d9e completed terminal0:20passed/20finished/332assertions, zero failures/errors, all14 postflight checks passed and exact schema absent. Exact counts come from the preserved diagnostic JSONL; the standard Pest summary was not emitted. Both changed-source hashes match the tested versions. No live test/build/browser/server/schema remains. Browser runtime4caa257ede23b718 was cleaned before this test run started.

Next: extend the existing isolated browser fixture minimally for approved/non-approved catalogue provisioning, then verify the current author/requester/technician controls and persisted approval gate. Continue full versioned publication, permitted audiences/Sites, requested-for and attachments, immutable submission evidence and provisioning lifecycle in the original plan. Existing source revalidation is in w15-initial-contract-revalidation.md. No W15 browser verification yet.
