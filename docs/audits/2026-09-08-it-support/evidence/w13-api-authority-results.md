# W13 current API authorization evidence

11 September 2026. Bounded W13 / E12 verification; the package and full release gate remain incomplete.

## Implementation

The existing API adapter now acquires current authorization evidence within its transaction: ordered actor/creator Users, identity, canonical role and permission evidence, employment profile, ticket and approved Sites. API intake, public comments and transitions retain those current checks through canonical service calls. Optional locking flags preserve the default behavior of other callers. Create already locked its selected Site before this slice; the newly confirmed Site gap concerned existing-ticket operations.

Direct API transitions now force the service identity's current actor and trusted `service_api` source/channel, retaining the supplied work-state fields, reviewed version and resolution evidence. A caller cannot substitute another actor or borrow requester-confirmation semantics.

Production files: `ItApiWorkItemService`, `ItWorkAccessService`, `ItTicketVersionService`, `ItTicketIntakeService`, `ItTicketInteractionService` and `ItWorkTransitionService` under `app/Domain/It/Services/`. Source hashes are recorded in `w13-api-authority-source-hashes.json`.

## Actual verification

- Initial Feature8969 / token `it_74a1683899b543cd`: 149 passed, 3 failed; 152 tests / 1594 assertions in total. The three failures were older controller fixtures omitting required current actor/version/reason fields or expecting an obsolete success flash for rejected selections. Reviewed fixture corrections preserve unchanged hidden records and the forged-Site 403 assertion. Full isolation postflight passed and the exact schema was absent. Evidence: `w13-api-authority-feature.txt` and its diagnostic JSONL.
- Standalone51231 / token `it_3a10245547ef45a9`: terminal 0, 1 test / 37 assertions passed. Real role-permission and Site-deactivation controller writers contended with authenticated API workers. Writers waited for the accepted command, then subsequent requests were denied without additional writes. Full 14-check isolation postflight passed and the exact schema was absent. Evidence: `w13-api-authority-concurrency.txt` and its diagnostic JSONL.
- Final 13-file Feature81729 / token `it_d192dfe9b69343ac`: terminal 1, 153 passed / 1 failed, 154 tests / 1616 assertions. Full isolation postflight passed and exact schema was absent. Sole failure was new provenance test line 157 assuming no audit rows, although `ItTicket` uses `AuditableChanges` for fixture creation. The expected rejection, unchanged resolved state and absent transition events passed. Corrected assertion compares exact pre/post audit IDs, preserving existing records and proving no new mutation. Evidence: `w13-api-authority-final.txt` and its diagnostic JSONL.
- Affected-file-only provenance recheck76374 / token `it_a5c4b8553fc0479e`: terminal 0, 2 tests / 14 assertions passed. Full 14-check isolation postflight passed and the exact schema was absent. Evidence: `w13-api-provenance-final.txt` and its diagnostic JSONL. No production change after the preceding Feature or standalone run. All 154 selected Feature scenarios now have passing evidence across the broad run and corrected affected-file rerun; this was not a second all-green combined run.
- Scoped Pint and reviewed test-file diff checks passed. Ten protected design files match the baseline; final ten-file source snapshot contains the six production services and four changed/new test or worker sources. No frontend source/build change or new browser verification in this slice. Prior canonical API browser evidence remains in `w13-api-canonical-results.md`; it does not prove the new races.

The existing wrapper confines imports and migrations to a randomized disposable local schema, with array mail and isolated notification/provider boundaries. Working-database migrations 17–23 remain unapplied. Two Terra High agents handled bounded test work and review; the parent reviewed changes and ran integration verification.

## Remaining boundaries

This does not prove every employment, membership, identity rotation or cross-channel lock race. Existing browser/API lock ordering, mutable linked-context evidence and credential-management authority need their own bounded review where relevant. W13 still requires versioned update/link operations, rotation and transient secret presentation, scoped failure operations and complete E12 acceptance. No provider, deployment, production migration or whole-module completion claim is made.

Terra's bounded next-slice review confirmed that API read exists, while update/link routes, abilities and API field policies are absent. Reuse canonical triage and related-ticket commands, with explicit API provenance and current evidence for both tickets; do not expose generic unrestricted link helpers. PATCH currently does not require the middleware's POST-only idempotency key, so mutation-method handling must be completed with the endpoint. Parent also confirmed that `TransitionItApiWorkItemRequest` omits and rejects `expected_version`; the existing canonical version guard therefore needs a complete HTTP request/controller contract. These are next work items, not verified functionality.
