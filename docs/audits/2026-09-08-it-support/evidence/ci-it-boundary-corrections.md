# IT architecture CI corrections — 11 September 2026

Previous goal turn: progress (API canonical pagination implemented and browser-verified; main checkpoint pushed). This turn: progress, preserving the existing working tree and keeping the complete goal active.

## Implemented and verified corrections

- tests/Architecture/ItSecurityDesktopReleaseAcceptanceBoundaryTest.php: explicit desktop-only inventory now includes the four existing governed command, device CRUD, discovery and monitoring lifecycle suites. The exact18-name list and existence checks remain enforced; no test was removed from browser configuration and no browser was resized.
- tests/Architecture/ItSecuritySingleTenantBoundaryTest.php: distinguish only the exact canonical Microsoft OAuth authority config read and its exact configuration-fixture key from application tenancy. Added negative cases for an unrelated file, a suffixed config key, tenant product text beside the valid config and an injected tenant_id query. Whole files are not exempted.
- The migration scanner no longer mistakes after('tenant_id') column positioning for adding a partition column. A regression proves adding a real partition in the same source is still rejected. Existing partition fields, constraints, rename forms, raw SQL and injected scope checks remain active.
- resources/js/pages/it/tickets/_dialogs.tsx: renamed local async confirm to submitResolutionConfirmation. This clarifies its actual purpose and avoids the browser-native dialog guard's name collision. The guard remains unchanged and still prohibits native alert/confirm/prompt calls; submission, cancellation and recovery logic are unchanged.

## Actual checks

- Full selected architecture run through the guarded wrapper:35 passed,11 failed,797 assertions. Both diagnostic and readable runs terminated with expected nonzero failure status; all14 postflight isolation checks passed, exact owned schemas absent. No schema import was needed by these pure architecture tests. First diagnostic tokenit_689bac55ed8b41e1; files ci-it-boundary-feature.txt, ci-it-boundary-readable.txt and matching diagnostic JSONL.
- Confirmed passing cases include the corrected desktop inventory, inert interaction guard, provider-setting guard (with its negative cases), partition-migration guard and the existing injected-tenant-scope/legacy-laundering checks. This is not a claim that the entire release architecture gate passes.
- Existing resolution confirmation UI suite:4 tests passed, ci-it-confirmation-ui.txt. Full TypeScript50734 passed terminal0, ci-it-boundary-types.txt. Scoped Pint passed; git diff --check passed.
- No new visual behavior was introduced by the callback identifier rename. No browser run or new production build was performed for it. Rebuild before the next browser journey so assets match subsequent source changes. Last browser runtime was already removed; no active test/build/import/owned runtime/tab or agents remain.

## Remaining evidence and next work

The remaining partition snapshot contains the Workforce coverage-action migration's new organization_id column and four legacy compatibility writes in Monitoring MetricRetentionTest. The payroll migration warning is resolved by recognizing column positioning. Do not silently change existing schema or exempt the whole retention suite.

Ten other architecture assertions still expect older ticket source strings/locations: approvals header/copy; merge-candidate requester restriction location; attachment cleanup location; intake Site/device fields; task labels; waiting projection; routing owner reset; classification fields; clear-filter callback; and API identity copy. They require review against current canonical services and behavioral tests. No permissions or services were weakened to make the tests pass.

W14 source inspection also confirmed raw payload.message is copied into new monitoring ticket descriptions/events and immutable monitoring snapshots. Snapshot presentation currently requires both current Control Room and Device access; IT timeline presentation returns the full payload to ticket workers. The next W14 privacy slice must address each projection and historical data carefully, preserving source evidence, before adding direct nonurgent intake/durable IT outcomes. Do not mistake removal from a single view for end-to-end redaction.

Full W13/W14, E01–E23 and final release verification remain incomplete. User working database, provider configuration, communications and production AI are unchanged. These corrections are local continuation changes after pushed checkpoint5fa7c6a4d.
