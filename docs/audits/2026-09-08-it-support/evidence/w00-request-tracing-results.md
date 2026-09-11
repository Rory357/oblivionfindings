# W00 requester intake tracing

Implemented 9 September 2026 against the current local checkout. This is diagnostic evidence for F01/W00/W02, not proof that the original interrupted-session 504 had an application-code cause.

## Scope and integration

- `TraceItTicketCreation` prepends the HTTP middleware stack and immediately bypasses everything except `POST /it/tickets`. It starts before application session, authentication and CSRF handling.
- `ItTicketRequestTrace::from($request)?->mark($stage)` records an allowlisted phase. Supported phases are `validated`, `committed`, `replayed`, `dispatch_started`, `dispatch_queued`, `dispatch_failed`, `dispatch_deferred`, `conflict` and `domain_rejected`.
- The response carries a server-generated `X-IT-Request-ID` UUID and `Server-Timing: it_request;dur=...`. A supplied trace header is never trusted or echoed. Each retry has a separate trace ID; this is independent of persistent command idempotency.
- Existing application logging receives `IT ticket request trace` with only correlation ID, fixed operation/stage, monotonic elapsed milliseconds and milliseconds since the preceding phase. Terminal evidence adds actual HTTP status and an allowlisted failure category when an exception is available. Ordinary logger timestamps join the trace to the browser attempt time.
- The exception response hook uses Laravel's rendered response status. Authentication/validation redirects remain redirects; tracing does not invent a 401/422. Unrendered exceptions are rethrown unchanged and have no guessed HTTP status. Terminal logging is idempotent.
- Request bodies, private messages, HTTP headers, URLs, filenames, command identities, actor/record IDs, SQL and exception messages are excluded. Diagnostic logging failure cannot alter the committed response.

The controller owner adds the phase calls around validated intake, committed/replayed result and notification dispatch. A `validated` phase measures time from middleware entry through all preceding work; it is not a claim of isolated validator CPU time. Distinct stages make the final interval attributable without copying input into logs.

## Actual checks

Command:

```powershell
& docs/audits/2026-09-08-it-support/evidence/run-isolated-it-tests.ps1 -TestPaths tests/Unit/It/ItTicketRequestTraceTest.php
```

Result: **5 passed, 26 assertions, 0.31 seconds**. The no-database unit cases verify correlated stages without submitted content, deterministic monotonic timings, response/status preservation, no double completion, unrelated-route bypass, independent request state, rejected caller-supplied header/stage values and logger failure behavior. The wrapper's 14 isolation preflight checks passed; these unit tests do not instantiate the database-refresh test case.

Pint ran only against the two new tracing classes, its unit test and the bootstrap registration. It corrected import/brace formatting; no design files changed.

## Browser and operational limits

Actual browser headers and staged log correlation remain to be captured after the controller hooks and current assets are ready. No provider verification is implied.

A proxy timeout before PHP/middleware starts can produce no trace. A process killed after `started` can omit terminal evidence. Logger failure can also omit evidence, so a missing terminal entry does not independently prove worker death or a successful/failed commit. An outer proxy may replace the response and omit `X-IT-Request-ID`; compare the bounded attempt timestamp, recorded stages and persistent command recovery result rather than assuming the browser received the original response.

This helper does not change Herd/Nginx timeouts, global PHP configuration, live delivery providers or production logging destinations.
