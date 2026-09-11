# Report recovery correction — 2026-09-09

Status: Implemented and verified by focused UI tests. The corrected assets and desktop in-app browser journey remain pending with the root agent. This does not complete W04/W17 or a release gate.

The root agent's real browser check confirmed that, after logout in a second tab, changing the Reports range correctly removed the old results and showed an alert. Two recovery details were wrong: the settled heading still said `loading…`, and HTTP 401 advised checking the connection. The root separately verified that signing in as the same actor in the other tab and pressing Try again restored the actual scoped results on the preceding asset build.

Changed only `resources/js/components/it/it-reports.tsx` and its existing `__tests__/it-reports.test.tsx`:

- HTTP 401/419 explains the expired session and offers the existing normal `/login` link in a new tab, followed by an explicit retry.
- HTTP 403 explains the access change and offers Check access again. Prior results, dates and export links remain concealed; connection advice is absent.
- Other server failures invite a later retry. Network failures retain connection/retry guidance.
- The heading says loading only while a request is pending, unavailable after failure, and shows the returned range after success.
- Existing request epochs, abort cleanup and clearing of old data remain intact. A late authentication error from an aborted request cannot replace a successful newer range.

Actual verification: the focused Reports Vitest file passed **12 tests in 3.23 seconds**, including both session codes, successful explicit recovery, access-denial recheck, stale authentication failure and network retry. The tests mock Axios and invoke no database/provider. Focused ESLint passed both files. The prior completed asset build was `app-eTyglvnK`; this correction was made only after the root reported that build finished. No asset build or browser operation was performed by this subtask.

Resumption: root builds the current source and repeats desktop keyboard range change → expired session alert → Sign in again link → return → explicit retry, confirming the settled heading and actor-scoped restored metrics. Also exercise the actual restricted-role 403 state when a disposable actor can be safely used. Record fresh asset identity and browser evidence separately; do not claim these new controls verified from the preceding build.

Mappings: W04 evidence freshness and truthful recovery; W17 report loading/error/retry lifecycle; E03/E15 as applicable. No production communication, provider configuration, permission mutation or deployment occurred.
