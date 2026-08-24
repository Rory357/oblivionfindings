# CAP-MED-CLIENT-MEDICAL-PROFILE

- Status: `SOURCE_BOUND_STATIC_TASK_CONTRACT`; not executed or scored.
- Module: `Clients`
- User job: Complete and maintain the authorised client's health and medical profile, including GP details, allergies, disabilities, histories and notes, within the client profile workflow.
- Matrix source owner, not assumed human actor: `ClientController and ClientMedicalProfile`
- Representative actor: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Application pin: `a0493442b9e392d324055c35bf25b69421dc2d35` / `f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1`
- Entry status: `ROUTE_AND_PAGE_SOURCE_ANCHORS_PRESENT_UNVALIDATED`

## Source anchors

- Navigation: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Route names: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Route paths: `routes/clients.php:122-124; routes/clients.php:41-44; routes/operations.php:166-169; routes/operations.php:251-254; routes/operations.php:308-310`
- Pages: `resources/js/pages/operations/clients/show.tsx:3173-3375`
- Backend: `app/Http/Controllers/ClientController.php:2632-2732; app/Http/Controllers/ClientController.php:2737-2828; app/Http/Controllers/ClientController.php:2831-2941; app/Http/Controllers/ClientController.php:431-438; app/Http/Controllers/ClientController.php:799-804; app/Http/Controllers/ClientMedicalController.php:89-94; app/Http/Controllers/ClientMedicalController.php:96-132; app/Http/Requests/UpdateClientRequest.php:83-99; app/Models/Client.php:180-183; app/Models/ClientMedicalProfile.php:10-13; app/Models/ClientMedicalProfile.php:81-106; app/Policies/ClientPolicy.php:121-124; app/Support/EmarUrl.php:17-22`
- Tests: `resources/js/test/client-profile-edit-dialog.test.tsx:32-78; tests/Feature/ClientControllerTest.php:1510-1556; tests/Feature/ClientControllerTest.php:2122-2205; tests/Feature/ClientMedicalControllerTest.php:53-58; tests/Feature/Operations/ClientProfileFoundationTest.php:139-360; tests/Feature/Operations/ClientProfileFoundationTest.php:80-137`

## Planned representative-role validation

1. Use only the listed source-supported entry. If no route or page anchor exists, stop and record the entry-point gap.
2. Establish the documented actor, permission, approved Site, canonical record ownership, direct-object and privacy boundary before disclosure or action.
3. Attempt only the matrix-defined user job: Complete and maintain the authorised client's health and medical profile, including GP details, allergies, disabilities, histories and notes, within the client profile workflow..
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
