# CAP-INT-SITE-PROVIDER-CONNECTION

- Status: `SOURCE_BOUND_STATIC_TASK_CONTRACT`; not executed or scored.
- Module: `Integrations`
- User job: Configure Site provider connections and governed secrets
- Matrix source owner, not assumed human actor: `Integration controllers and IntegrationSecretManager`
- Representative actor: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Application pin: `a0493442b9e392d324055c35bf25b69421dc2d35` / `f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1`
- Entry status: `ROUTE_AND_PAGE_SOURCE_ANCHORS_PRESENT_UNVALIDATED`

## Source anchors

- Navigation: `resources/js/components/app-sidebar.tsx:1970-1975`
- Route names: `NOT_ESTABLISHED_CURRENT_AUDIT`
- Route paths: `routes/security-devices.php:385-387; routes/security-devices.php:504; routes/security-devices.php:504-520; routes/security-devices.php:508-509; routes/security-devices.php:598-599; routes/security-devices.php:598-614; routes/sites.php:423-447; routes/sites.php:77-78`
- Pages: `resources/js/pages/security-devices/integrations.tsx:468-555; resources/js/pages/security-devices/integrations/milesight.tsx:146-790; resources/js/pages/security-devices/integrations/unifi.tsx:263-683`
- Backend: `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:39-381; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:53-513; app/Http/Controllers/Sites/SiteIntegrationController.php:253-290; app/Http/Controllers/Sites/SiteIntegrationController.php:29-162; app/Http/Controllers/Sites/SiteIntegrationController.php:504-582; app/Http/Controllers/Sites/SiteIntegrationController.php:94-101; app/Http/Controllers/Sites/SiteIntegrationController.php:94-603; app/Models/Integration/IntegrationProviderConnection.php:20-99; app/Models/Integration/IntegrationSiteConfig.php:15-88; app/Models/Integration/IntegrationSiteSecret.php:14-75; app/Policies/SitePolicy.php:20-34; app/Services/Integration/IntegrationSecretManager.php:21; app/Services/Integration/IntegrationSecretManager.php:21-130; app/Services/Integration/IntegrationSecretManager.php:41-600`
- Tests: `tests/Feature/Integrations/UnifiTransportSecurityTest.php:51-166; tests/Feature/SecurityDevices/IntegrationProviderSecretCutoverTest.php:267-445; tests/Feature/SecurityDevices/MilesightCommonContractTest.php:38-91; tests/Feature/SecurityDevices/UnifiSettingsRefactorTest.php:67-317; tests/Feature/Sites/SiteIntegrationMutationSafetyTest.php:74-189; tests/Feature/Sites/SiteIntegrationReadBoundaryTest.php:20-94`

## Planned representative-role validation

1. Use only the listed source-supported entry. If no route or page anchor exists, stop and record the entry-point gap.
2. Establish the documented actor, permission, approved Site, canonical record ownership, direct-object and privacy boundary before disclosure or action.
3. Attempt only the matrix-defined user job: Configure Site provider connections and governed secrets.
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
