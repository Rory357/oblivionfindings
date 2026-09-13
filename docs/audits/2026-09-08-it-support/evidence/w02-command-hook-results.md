# W02 ticket command hook verification

Date: 2026-09-09. Scope: shared browser command state and mocked HTTP contract tests. This evidence does not mark the full W02 package or real browser intake journeys verified.

## Implemented

- `resources/js/hooks/use-it-ticket-command.ts` owns one secure request UUID and one private, in-memory multipart snapshot. Retries copy the original snapshot and preserve its UUID. Double submission and stale asynchronous results are guarded.
- Success requires HTTP 200/201, `status: committed`, a positive canonical ticket ID, the canonical ticket URL, an IT reference, an exact matching request UUID and a boolean replay flag. Invalid, missing or redirected response data never reports success.
- First-attempt 422 exposes field errors and permits correction with the same unreserved UUID. A later 422 after an uncertain attempt preserves uncertainty and the original submitted details.
- Network failure, timeout, HTTP 500 and cancelled waiting retain the original command. Recovery 404 cannot disprove an in-flight commit. Conflict permits recovery only. Session expiry permits recovery or an identical retry after session restoration.
- HTTP 403 purges the hook's retained payload. The integrating wizard must also conceal and clear its own form state. Reset requires an explicit `new` or `discard` reason; the integrating UI is responsible for an explicit acknowledgement before abandoning an uncertain request.
- Cancellation and unmount abort only the browser HTTP operation. They make no rollback claim. No browser storage or provider execution is used.

## Actual checks

- Focused Vitest: **16 tests passed**, one file, final duration **1.46 seconds**. Covers multipart identity and duplicate prevention, six malformed success cases, timeout plus recovery 404 with frozen strings/files, validation correction and uncertain retry rejection, 401/419, access-revocation purge, conflict recovery, cancellation with late completion, reset/unmount cleanup and secure UUID fallback/unavailability.
- Scoped ESLint: **passed with zero warnings** for the hook and its test file.
- Scoped strict TypeScript: **passed**, after the final abort-cleanup refactor.
- Prettier: hook and test file formatted; final hook check unchanged.
- Tests use mocked Axios only. No database or provider was contacted by these checks.

Commands:

```powershell
& node_modules/.bin/vitest.cmd run resources/js/hooks/use-it-ticket-command.test.tsx
& node_modules/.bin/eslint.cmd resources/js/hooks/use-it-ticket-command.ts resources/js/hooks/use-it-ticket-command.test.tsx --max-warnings=0
& node_modules/.bin/tsc.cmd --noEmit --target esnext --module esnext --moduleResolution bundler --jsx react-jsx --strict --skipLibCheck --esModuleInterop --types vitest/globals resources/js/hooks/use-it-ticket-command.ts resources/js/hooks/use-it-ticket-command.test.tsx
```

Vitest required the approved unsandboxed local test invocation because the bundled esbuild startup could not access its parent runtime path in the filesystem sandbox. No dependency was installed or changed.

## Follow-up: record access revoked during recovery

An actor-owned committed receipt whose ticket is now inaccessible returns HTTP 404 with the typed code `access_unavailable`. The hook treats this response as access denial and purges its submitted copy, both on initial/replayed submission and recovery. HTTP 403 has the same behavior. An ordinary 404 retains the original frozen payload because a submission may still be running. Unknown UUIDs and another actor's receipts do not acquire this typed signal.

The expanded hook suite passed **19 tests in 1.47 seconds**. Scoped ESLint and strict TypeScript passed again. These checks use mocked HTTP and do not replace the companion PHP command-contract or real browser verification.

## Remaining verification

Root owns integration in `it-wizards.tsx`, accessibility/focus behavior, draft concealment, dirty-close acknowledgement and real in-app browser journeys against the current assets. The full application typecheck and any unrelated baseline errors are outside this focused hook result. Verify creation, lost-response recovery, retry, server validation, session/access recovery and cancelled waiting end to end before marking the corresponding W02 criteria verified.
