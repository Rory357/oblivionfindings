# CAP-IT-SELF-SERVICE-TICKET

- Status: `SOURCE_BOUND_STATIC_TASK_CONTRACT`; not executed or scored.
- Module: `IT & Support`
- User job: Raise a support ticket and track, comment on, reopen, or rate the actor's own ticket.
- Matrix source owner, not assumed human actor: `record: ItTicket; controller: ItProvisioningController and ItTicketController; service: ItTicketIntakeService and ItWorkAccessService; policy: ItTicketPolicy`
- Representative actor: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Application pin: `a0493442b9e392d324055c35bf25b69421dc2d35` / `f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1`
- Entry status: `ROUTE_AND_PAGE_SOURCE_ANCHORS_PRESENT_UNVALIDATED`

## Source anchors

- Navigation: `app/Domain/It/ItModuleNavigation.php:26-31; resources/js/components/app-sidebar.tsx:582-591`
- Route names: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Route paths: `routes/web.php:159-194`
- Pages: `resources/js/pages/it/index.tsx:2606-2681; resources/js/pages/it/tickets/show.tsx:207-310`
- Backend: `app/Domain/It/ItModuleNavigation.php:26-31; app/Domain/It/Services/ItTicketIntakeService.php:42-298; app/Domain/It/Services/ItWorkAccessService.php:78-356; app/Http/Controllers/It/ItProvisioningController.php:637-666; app/Http/Controllers/It/ItProvisioningController.php:984-1018; app/Http/Controllers/It/ItTicketController.php:445-715; app/Http/Controllers/It/ItTicketController.php:76-87; app/Models/ItTicket.php:25-390; app/Policies/ItTicketPolicy.php:23-120`
- Tests: `tests/Feature/It/ItTicketAuthzTest.php:37-170; tests/Feature/It/ItTicketAuthzTest.php:37-172; tests/Feature/It/ItWorkAccessControllerTest.php:80-225`

## Planned representative-role validation

1. Use only the listed source-supported entry. If no route or page anchor exists, stop and record the entry-point gap.
2. Establish the documented actor, permission, approved Site, canonical record ownership, direct-object and privacy boundary before disclosure or action.
3. Attempt only the matrix-defined user job: Raise a support ticket and track, comment on, reopen, or rate the actor's own ticket..
4. Record actual fields, decisions, states, errors, recovery, completion evidence and hand-off; do not infer them from source presence.
5. Require independent review before assigning any ease score or completion claim.

These are future audit instructions, not a measured user-task step count.

## Unmeasured task evidence

- Start condition: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Prerequisites: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Decisions/states: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Recovery path: `NOT_MEASURED`
- Completion evidence: `NOT_MEASURED`
- Next hand-off: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Completion time: `NOT_MEASURED`
- Step count: `NOT_MEASURED`
- Required-field count: `NOT_MEASURED`
- Decision count: `NOT_MEASURED`
- Context switches: `NOT_MEASURED`
- Dead ends: `NOT_MEASURED`

| Ease dimension | Current | Target |
|---|---|---|
| Discoverability | `NOT_MEASURED` | `NOT_MEASURED` |
| Comprehension | `NOT_MEASURED` | `NOT_MEASURED` |
| Learnability | `NOT_MEASURED` | `NOT_MEASURED` |
| Efficiency | `NOT_MEASURED` | `NOT_MEASURED` |
| Error prevention | `NOT_MEASURED` | `NOT_MEASURED` |
| Recovery | `NOT_MEASURED` | `NOT_MEASURED` |
| Accessibility | `NOT_MEASURED` | `NOT_MEASURED` |
| Safety and trust | `NOT_MEASURED` | `NOT_MEASURED` |
| Consistency | `NOT_MEASURED` | `NOT_MEASURED` |
| Cross-module continuity | `NOT_MEASURED` | `NOT_MEASURED` |

- Risk adjudication: `NOT_ADJUDICATED_CURRENT_AUDIT`
- Safety criticality: `NOT_ADJUDICATED_CURRENT_AUDIT`
- High-risk alternative script need: `NOT_DETERMINED_CURRENT_AUDIT`
- Representative-role execution: `false`
- Browser observation: `false`
- Executed-test evidence: `false`
- Ease credit: `false`
- Completion credit: `false`
- Evidence limit: Static identity and source ownership only; no runtime, browser, executed-test, benchmark, ease, release, or completion credit.
