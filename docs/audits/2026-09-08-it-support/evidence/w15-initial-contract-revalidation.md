# W15 initial contract revalidation

Read-only preparation while the W14 browser correction builds. W15 is not implemented or verified by this note. Sources: the W15/E07/E14 criteria in the full implementation plan and the current canonical catalogue/provisioning services/models. This is bounded revalidation, not a repeated whole-module audit.

Preserve existing behaviour:

- ItCatalogSubmission already stores immutable schema/version/submitted values and an actor idempotency key. Extend it rather than adding a parallel request ledger.
- ItCatalogSubmissionService validates internal/restricted field visibility and canonical entity choices, and routes ticket outcomes through the existing ticket/SLA/routing services.
- ItProvisioningWorkflowService already materializes template task fields into independent request records, including task keys, approval/evidence requirements, dependencies and employee context. Do not claim that editing a template currently rewrites those copied active requests.
- ItProvisioningRequestLifecycleService already centralizes assign/approve/fulfil/fail/cancel, validates required evidence and dependencies, reconciles canonical targets and records events/audits. Extend these methods and access boundaries.

Confirmed contract gaps to address in reviewable slices:

1. Catalogue authoring increments form_schema_version only when form_schema changes. Outcome/service/approval/description/audience changes are not a complete versioned publication contract. Submission snapshots currently retain only the schema, not the full selected service contract.
2. Discovery/management expose internal_only but no explicit permitted audience/Site fields in the canonical item model/management editable list. Add current author/requester Site access enforcement through existing approved-Site services, not organisation switching.
3. Submission looks up a published item before checking an existing idempotency key. A previously successful request therefore cannot recover through that service after the item is withdrawn. Replays must bind the original input and enforce current result visibility without authorizing new submissions to withdrawn items.
4. Ticket catalogue outcomes currently set requested_for_user_id to the actor and use the actor's default Site. Provisioning outcomes have an employee selector/access check. Complete the requested-for contract consistently without treating a posted user ID as authority.
5. Provisioning catalogue creation currently makes a standalone request with type/name/notes but does not carry the catalogue requires_approval flag or select/version the configured task workflow. A declared approval requirement cannot be silently omitted.
6. Provisioning template updates replace task rows; workflow stores a template foreign key and employee-context snapshots but no full immutable template version. Preserve the copied request fields while adding durable reviewed version evidence.
7. Provisioning approval currently relies on the general request-management guard. Complete the plan's eligible approver/rejection/expiry/cover semantics using existing approval and permission boundaries; do not infer an operational approver policy.

Still to inspect before implementation: HTTP request validation/controllers, author/requester UI, attachment handling, workflow dependency/approval integration, partial-failure/retry/reversal rules and the existing focused tests. No new test, provider action, migration or application edit was performed for W15 during this preparation. Start the smallest complete authoring/publication/submission contract slice after the W14 local acceptance work, then continue provisioning lifecycle and desktop verification.
