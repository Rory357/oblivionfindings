# W02 — Acknowledged exit and reference recovery

9 September 2026. **Implemented; focused automated verification passed. Current-assets desktop browser verification is pending with root.** This is the bounded W02 exit/recovery and W01 creation-concealment follow-up; W06 private draft persistence is not implemented here. The user's web-only clarification means the next browser check is desktop only.

## Accepted identity and exit contract

- An unknown, conflicting or expired-session result can be closed through the existing shared AlertDialog pattern. It explains that the request may already be saved, closing does not cancel it, and the form details/files will be discarded. Initial keyboard focus is on **Go back**. Going back preserves the original in-memory command, identity, files and applicable same-request retry. Closing while busy first stops browser waiting; the server is not told to cancel.
- **Close and keep reference** explicitly retains only the UUID under `sessionStorage['it.pending-ticket-command.v1.actor.<current authenticated user ID>']`. No title, body, attachments, ticket ID, provisioning ID, Site ID, secret, result or draft is stored. Scope is this browser tab and signed-in actor. Closing the whole tab may lose the reference; the dialog provides a selectable reference for manual retention. This is an existing receipt lookup aid, not another ticket or draft system.
- Reopening either creation wizard for that actor resumes recovery with the same UUID and no frozen payload. Only **Check saved request** is available for submission recovery; it issues the existing authenticated `GET /it/ticket-commands/{UUID}`. New form fields and their unrelated provisioning defaults are concealed during reference recovery. A recovered success shows the canonical receipt/reference and a truthful generic message, without attributing the old ticket to new form defaults. No new POST is sent by recovery.
- Ordinary receipt 404 remains uncertain because a submission may still be running. HTTP 401/419 preserves the reference and offers session restoration. HTTP 403 or the typed actor-owned HTTP 404 `access_unavailable` clears the marker and private form state. A valid matching committed receipt clears the marker. Canonical server authorization still decides every lookup; the marker grants no access.
- After reopening without a retry payload, **Start a different request** requires a second dialog acknowledging that the original may already be saved and another may duplicate it. Only explicit **Forget reference and start another** clears that marker and allocates a new identity. Merely cancelling the dialog or unmounting does not forget it. This is distinct from discarding a never-submitted local form.
- If browser reference storage fails, the first exit attempt stays open and says it could not retain the reference. The user can go back without losing the in-memory retry or explicitly choose **Close without retained reference**. There is no false storage success and no permanent close trap.
- Creation access denial now clears both current Inertia form data and its reset defaults. It explicitly removes provisioning-derived title/ID, description, subcategory, linked Site/service/requester/assignee/asset/device IDs, watchers and files. Ordinary fixed category/priority/work-type defaults remain. Calling `reset()` after denial cannot restore private provisioning context. Creation wizards are keyed to the current actor; the command hook also aborts stale operations and clears its private state on an actor change.

## Changed files

- `resources/js/hooks/use-it-ticket-command.ts`: optional actor scope; UUID-only retention/resume, cleanup and stale-actor protection; accurate no-payload recovery messages.
- `resources/js/components/it/ticket-command-wizard.tsx`: acknowledged exit/forget flows, storage failure recovery, reference display, shared focus and concealment behavior.
- `resources/js/components/it/it-wizards.tsx`: limited creation actor wiring, provisioning data/default purge, and truthful reference-recovery success presentation. Other agents' version/KB work is preserved.
- `resources/js/hooks/use-it-ticket-command.test.tsx`, `resources/js/components/it/__tests__/ticket-command-wizard.test.tsx`, `resources/js/components/it/__tests__/ticket-creation-access.test.tsx`: focused hook and actual component regressions.
- `evidence/w02-command-ui.tsconfig.json`: reproducible scoped strict TypeScript inputs with the existing test matcher declarations.

## Actual checks

- Final Vitest: **33 passed across three files, 4.36 seconds, exit 0**. Breakdown: 25 hook cases, six shared wizard cases (including HTTP 500/409/419 cases), two real creation-wizard cases. Tests use actual React form/wizard/dialog behavior and mocked Axios. Confirmation focus, cancel/go-back, acknowledged exit/reopen, late results, cross-actor separation, plain 404 uncertainty, typed denial, storage failure, explicit forgetting and provisioning reset concealment were exercised. No database or provider was contacted.
- Scoped ESLint: **passed, zero warnings**, all six changed implementation/test files.
- Scoped strict TypeScript: **passed** with `w02-command-ui.tsconfig.json`. An initial config attempt omitted the Vitest jest-dom matcher declarations and failed only on missing matcher types; adding the existing matcher declarations fixed the check. No source typing requirement was weakened.
- Prettier applied to the changed UI/hook/test files; focused whitespace check passed. Earlier passing 32-case runs were extended by the real provisioning recovery regression; the final 33-case result is authoritative.

Commands (installed Node at `C:\Users\steph\.hermes\node\node.exe`):

```powershell
node node_modules/vitest/vitest.mjs run resources/js/hooks/use-it-ticket-command.test.tsx resources/js/components/it/__tests__/ticket-command-wizard.test.tsx resources/js/components/it/__tests__/ticket-creation-access.test.tsx
node node_modules/eslint/bin/eslint.js resources/js/hooks/use-it-ticket-command.ts resources/js/hooks/use-it-ticket-command.test.tsx resources/js/components/it/ticket-command-wizard.tsx resources/js/components/it/it-wizards.tsx resources/js/components/it/__tests__/ticket-command-wizard.test.tsx resources/js/components/it/__tests__/ticket-creation-access.test.tsx --max-warnings=0
node node_modules/typescript/bin/tsc --project docs/audits/2026-09-08-it-support/evidence/w02-command-ui.tsconfig.json
```

Vitest used approved local escalation for installed esbuild runtime access, as in the earlier command-hook checks. No dependency was installed. No live provider, notification, database or production configuration was changed.

## Resumption

Root was notified that a new asset build is required. Verify desktop requester and agent uncertain-exit/reopen/receipt success, keyboard Go back, restored-session recovery, and provisioning revocation against the correct checkout/current assets. Existing browser checks made before these asset changes do not verify this follow-up. The earlier no-browser-storage claim in `w02-command-hook-results.md` is superseded only for the explicitly acknowledged UUID marker described here; private form content remains memory-only. W01/W02 and the full release gate remain open under the root progress ledger.
