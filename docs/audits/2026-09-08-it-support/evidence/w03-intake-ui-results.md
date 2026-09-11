# W03 — Approved-Site and assessment intake UI

9 September 2026. **Implemented; focused automated verification passed. Current-assets desktop browser and persisted backend verification are root-owned and pending for this UI slice.** W03 remains open. Mapping: F12/A07 approved-Site and impact triage; E02 manual ownership/priority explanation and W01 scope/revocation; preserves W02/E01 command identity and recovery. E03 SLA truth is outside this UI slice. No operational owner, queue, cover arrangement or provider was configured.

## Implementation contract

- Both requester and technician creation use the approved shared Site selector. One approved Site is preselected; multiple Sites require an explicit choice. Missing or no-longer-approved choices prevent submission and an empty list explains the Site-access setup gap. The list comes from the canonical common `siteOptions` projection, not client-created Site IDs. Server validation remains authoritative.
- Both forms send `impact` (`individual`, `team`, `site`, `organization`) and `urgency` (`low`, `normal`, `high`, `critical`) using plain-language labels. Organisation is a display label for the existing `organization` enum; no tenant boundary is introduced. Requester intake sends the selected `site_id` and omits internal assignment and priority overrides.
- Technician assessment preview reads `intakePolicy.priority_matrix`, projected by root from `ItTicketPriorityService::MATRIX`; no second matrix exists in production JavaScript. Missing preview data is identified as unavailable and leaves the server responsible for assessment. Automatic priority omits the `priority` field. A deliberately selected priority sends it with `priority_reason`; the form requires the explanation. The review step shows impact, urgency, effective priority and any reason. Existing SLA date previews are not asserted to satisfy W04 clock semantics by this work.
- A manual technician selection requires `routing_reason`. Options are filtered to canonical `site_ids` containing the affected Site. An organisation-wide-only flag does not bypass a non-null Site, and missing Site scope fails closed for new assignment selection. Changing to an incompatible Site clears the assignee and reason. Refreshed ineligibility blocks submission with a corrective error. All scope and absence eligibility is still rechecked on the server.
- Routing copy identifies that routing is applied on save and that the saved ticket holds the real queue, team, accountable owner or setup gap. It makes no invented preview/health claim. Manual assignment explains its audit and intentional preservation until an authorized release. Root owns the saved routing presenter/detail integration and backend policy tests.
- Ticket `AssignDialog` now requires and submits `routing_reason` alongside the current `expected_version`; server rejection displays its field error and preserves the reason. Its provisioning variant retains its prior payload and does not send a ticket reason/version.
- UUID-only pending recovery remains intact. Recovery-only presentation hides unrelated form fields, form step navigation and completeness percentages; its header says **Check saved request**. Creation denial clears new assessment reasons as well as all prior private context/defaults. Requester denial also clears its new Site default. No new private browser persistence was introduced.

## Changed files

- `resources/js/components/it/ticket-intake-fields.tsx`: shared approved controls, enum labels and the typed server matrix contract.
- `resources/js/components/it/it-wizards.tsx`: creation/raise fields, assessment/override/review wiring, canonical assignment-option filtering, restricted assignment reason and access purge.
- `resources/js/pages/it/index.tsx`: limited typed `intakePolicy` pass-through. Existing KB privacy and other agents' detail work are preserved.
- `resources/js/components/it/ticket-command-wizard.tsx`: root-requested recovery-only header/navigation/completeness correction using existing WizardShell props.
- `resources/js/components/it/__tests__/ticket-intake-triage.test.tsx`: eight actual form/control tests. `ticket-creation-access.test.tsx` extends the expected cleared contract and verifies truthful recovery chrome.
- `evidence/w03-intake-ui.tsconfig.json`: reproducible strict command/creation/test compilation scope.

## Actual verification

- Final combined Vitest: **44 passed, five files, 8.14 seconds, exit 0**. This retains all 33 W02 hook/exit/creation tests, adds eight W03 tests and rechecks the three W01 knowledge audience tests because the shared wizard/index changed. All HTTP is mocked; no database or provider was used.
- W03 cases: choosing Site B from only A/B; dimensional frozen same-request retry; revoked Site and access-denial concealment; empty-Site setup gap; preview follows refreshed server matrix and priority override requires a reason; eligible-Site-only technician choice and manual reason payload; changing Site clears incompatible assignment; ticket version/reason preserved on backend rejection; provisioning assignment payload unchanged. Selects are exercised through their keyboard opening behavior.
- Initial combined run: **35 passed, six failed**, 5.48 seconds. Five failures were a test driver that did not open Radix Select with the synthetic pointer event; one used the wrong provisioning modal discriminator (`assign` instead of canonical `assign-request`). Correcting the test driver to ArrowDown and the fixture discriminator produced **eight W03 passes, 7.81 seconds**. No application permission or assertion was weakened to fix these harness failures. The final 44-case run includes the recovery chrome follow-up.
- Scoped ESLint: **passed with zero warnings** for the six changed production/test files, including index. Prettier and focused whitespace checks passed. An intermediate lint check reported a type-only `typeof form.data` reference as a missing effect dependency; explicit narrow enum values removed that unstable dependency need without changing the purge behavior.
- Scoped strict TypeScript: **passed** for the command/creation components, their imports and tests through `w03-intake-ui.tsconfig.json`. An expanded check including the full index graph reports the existing `resources/js/components/pull-to-refresh.tsx:38` `router.reload({ preserveScroll })` signature mismatch. No current W03 source diagnostic remained after correcting four invalid test-only `exact` query options. The index graph failure is not represented as a passing whole-app typecheck; root was notified and owns the broader release gate.

Commands (installed Node at `C:\Users\steph\.hermes\node\node.exe`):

```powershell
node node_modules/vitest/vitest.mjs run resources/js/hooks/use-it-ticket-command.test.tsx resources/js/components/it/__tests__/ticket-command-wizard.test.tsx resources/js/components/it/__tests__/ticket-creation-access.test.tsx resources/js/components/it/__tests__/ticket-intake-triage.test.tsx resources/js/pages/it/knowledge-access.test.tsx
node node_modules/eslint/bin/eslint.js resources/js/components/it/ticket-command-wizard.tsx resources/js/components/it/ticket-intake-fields.tsx resources/js/components/it/it-wizards.tsx resources/js/pages/it/index.tsx resources/js/components/it/__tests__/ticket-creation-access.test.tsx resources/js/components/it/__tests__/ticket-intake-triage.test.tsx --max-warnings=0
node node_modules/typescript/bin/tsc --project docs/audits/2026-09-08-it-support/evidence/w03-intake-ui.tsconfig.json
```

Vitest used reviewed local escalation for its installed esbuild runtime. No dependency, design source, database, live configuration or notification setting was changed by this UI slice.

## Precise resumption

Root must build these updated sources before desktop browser verification. Verify requester two-Site choice and disallowed third Site, persisted impact/urgency/priority, manual override reason, eligible technician selection, visible saved routing gaps and current keyboard/error recovery. Verify W02 reference-only recovery has no unrelated percentage/step navigation on the new assets. Backend routing/cover/setup tests are a separate concurrently owned slice; their pass status is not inferred from mocked component tests. No 390px or mobile-specific verification is added under the user's clarified desktop web scope.
