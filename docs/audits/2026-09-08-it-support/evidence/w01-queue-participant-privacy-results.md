# W01 ticket queue participant privacy closure

9 September 2026. Implemented and focused automated verification passed; root desktop browser verification uses the coordinated next asset build. W01 overall remains subject to joined evidence.

The W03/W04 review found a concrete cross-surface privacy gap: the canonical queue used `applyViewScope`, which legitimately includes a technician's own/requested-for sensitive or unapproved-Site ticket, but serialized private routing and waiting data using global module authority. Detail/internal-file/activity boundaries already correctly required per-record `canWork`.

`ItProvisioningController::ticketPage` now derives each row's `can.manage` from canonical `canWork`. Rows without work authority keep the public ticket summary and safe live SLA projection; they omit routing, waiting reason, next action and waiting timestamp. Waiting party is reduced to requester/other, as in requester detail. Authorised technicians keep the existing full operational projection. Site/team/assignment responsibility exceptions and participant visibility are preserved.

The typed `TicketRow` requires `can.manage` and makes the genuinely restricted fields optional. `index.tsx` checks global and current per-row capability for work controls, context actions, checkbox and routing presentation. Select-all includes only eligible rows; current selections/version maps are filtered when eligible IDs change. An open assignment/resolve/waiting/close or agent-reopen form is concealed when refreshed row authority is revoked. Requester reopen flows retain their existing separate policy. Missing row authority fails closed. No protected design source was modified.

Actual verification:

- `ItTicketWorkspaceTest` filtered to the four sensitive/unapproved-Site requester/requested-for cases plus read-only IT case: **5 passed, 459 assertions, 353.32s, wrapper exit0**. Isolation checks all14 passed; disposable schema `oblivion_it_support_test_it_1fb42fda601740c1`; array mail/sync queue/null broadcast. The strengthened scenarios verify queue DTO omissions and false management capability, legitimate technician positive routing/reason projection, and existing JSON/Inertia/detail/internal-file/activity/participant-revocation behavior. [PHP output](w01-queue-participant-privacy-php.txt).
- `knowledge-access.test.tsx`6 + latest `ticket-intake-triage.test.tsx`10: **16 passed, 7.77s, exit0**. New queue scenario proves restricted row remains readable but has no management button/checkbox, bulk-select includes only the permitted ticket, permitted assignment opens, and refreshed revocation clears selection/conceals the editor without a mutation request. [UI output](w01-queue-participant-ui.txt).
- Full `tsc --noEmit` **exit0**; focused ESLint **zero warnings**; `git diff --check` clean for the slice. Type output: `w01-queue-participant-tsc.txt`.

Frontend source froze at **2026-09-09 02:12:21 UTC** for root's coordinated build. Root must verify current desktop queue behavior for existing restricted technician230 on sensitive participant12 and out-of-Site requested-for13, plus permitted14, using the new assets. The new separate knowledge/auditor grants have their own evidence in `w01-fine-capability-results.md`. This correction does not mark later work packages or the release gate complete.
