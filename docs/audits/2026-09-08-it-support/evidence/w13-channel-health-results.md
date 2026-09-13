# W13 channel health

11 September 2026. Root-only implementation, locally Verified. Maps to W13/E12 Operations diagnostics and the W11/W12 recovery surfaces. Full W13/E12 and the release gate remain incomplete.

## Implemented

- Extended ItMailboxConnectionPresenter with a fresh integrations.manage_secrets gate and an explicit safe Operations projection. It excludes credentials, account/mailbox addresses, sender/message content, scope hashes and raw provider errors. Unknown failure codes become unknown. Missing recovery columns report unavailable rather than an empty healthy mailbox.
- Mailbox facts distinguish the last completed poll from the last attempt, consecutive failed polls, incomplete scans, current poll activity, retry delay, pending processing/acknowledgement counts and attempts, oldest pending receipt, and retained quarantine age. All receipt queries use the current canonical mailbox scope.
- The permitted recovery action opens existing mailbox settings, which owns versioned polling and quarantine review. Ordinary IT managers receive no mailbox counts, details or recovery URL.
- Extended the canonical delivery service with a single scoped aggregate over the same visibleQuery as the register, including rows beyond its display limit. Queued, sending and accepted states remain distinct. Only delivered records contribute to the last confirmed delivery; pending timestamps remain unmeasured when absent.
- Added typed Operations health panels using existing Card, Button, StatusBadge and NZ date helpers. A changed viewer conceals the former viewer's health. Read-only snapshots show their check time, and unconfirmed deliveries do not gain a retry control. Corrected the API summary label from recent errors to recorded errors to match its retained-data query.

## Actual verification

- Guarded Feature8114, token it_15915c7858f14e52: 50 tests / 662 assertions passed, terminal0. New channel-health tests plus existing Operations, mailbox-settings and API-scope tests ran. All14 isolation postflight checks passed, including exact schema absence.
- Focused UI13983 initially passed29/failed1 because the new labelled health card lacked a region role. Added named regions to both health panels. Corrected recheck passed30/30 across new health and existing Operations tests.
- Scoped three-file ESLint26826, global incremental TypeScript27596 and PHP formatting passed. The opt-in browser fixture syntax check passed with the installed PHP runtime; the initial unprivileged shell could not access that executable and ran no PHP.
- Eight changed sources are fingerprinted in w13-channel-health-source-hashes.json. They remained frozen through the passing Vite45284 build and browser acceptance recorded below.
- The opt-in MailboxFixtures settings operator additionally receives synthetic it.view/manage grants to exercise Operations. Ordinary technician/settings-viewer fixtures keep their existing restrictions. No production grants or provider configuration changed.

## Next checks and remaining W13 work

The browser checks below completed. Continue with the remaining W13 work after this locally verified slice.

Subsequent W13 work remains: safe detailed API diagnostics (current manageable identity scope, attempts, outcome/category, last success/pending age and truthful original-client retry guidance), and automatic-ticket recovery coupled to W14. Read-only review also confirmed that Setup currently projects delivery.last_error directly and provider callbacks can retain arbitrary error strings; address safe delivery failure projection/category before completing W13, including legacy rows. Do not expose raw request paths, idempotency keys or response bodies in new API diagnostics.

No production AI execution, real communications, provider configuration or deployment occurred. The working database was not reset or migrated; migrations17–24 remain unapplied there.

## Final current-asset browser evidence

- Vite45284 passed in5m36s; app-tNGV-ai_.js, manifest7fa918d994b72476bfe523a830188bb78ec89fe50538e19c282e1646ccccf1e2. Generated incremental TypeScript28390 exited0. All8 reviewed source hashes and all10 protected design hashes remained unchanged.
- Reviewed MailboxFixtures preview and bootstrap2978 created owned run0445013db61148a5 with original fingerprint9c62d08d3da21f4d826f62ac79599d2e3eead385fa7feb0823ec1a5c5864178b. Runtime identity proved the exact checkout/schema/manifest, normal CSRF, synthetic provider/capture outcomes, mail array, queue sync and SSR disabled. No API fixtures were enabled.
- Owned in-app tab43 used normal w06-settings synthetic login and /it/setup?tab=operations. Initial Google/Microsoft health distinguished stored connection status from no completed poll. Delivery health showed zero queued and no confirmed delivery.
- Keyboard Tab navigation reached Review mailbox recovery, with visible focus. Return opened the existing /settings/it-mailbox page. No browser resizing occurred. Screenshots were inspected inline for the focused recovery control and the failed-health desktop layout; no saved PNG is claimed.
- The first real synthetic Microsoft poll produced its expected scanner/acknowledgement failures. Operations then showed scanner unavailable, one consecutive failed poll, one pending processing receipt, one pending acknowledgement, two processing attempts and one acknowledgement attempt on pending receipts, oldest pending time and10 quarantined receipts. It continued to show no completed poll. Delivery backlog showed28 queued and no confirmed delivery.
- The recovery link opened the canonical settings page. Poll remained disabled during its persisted retry delay. Quarantine review loaded its10 bounded records and only permitted retry actions. After the actual delay elapsed, Reload current state enabled polling; the second synthetic poll completed.
- Reloading Operations showed the completed poll time, no recorded failure, zero failed polls, zero pending processing/acknowledgement and retained10 quarantine records. Delivery backlog became29 queued, still with no confirmed delivery. The fixture evidence files firstpoll/recovered retain numeric provider observations: all43 Microsoft messages were acknowledged, message1 had two acknowledgement attempts but only one read, and the scanner-retried message39 had two reads. No live provider call or real mail was sent.
- Normal sign-out and w06-tech synthetic login verified the restricted view: permitted delivery totals remained visible; mailbox provider details and the recovery link were absent. Direct /settings/it-mailbox returned403, and Back returned to permitted Operations with restrictions intact.
- Tab43 closed, preserving usertab3. StopAndRemove90233 exited0 and removed only oblivion_it_draft_browser_0445013db61148a5 and its owned runtime directory. Independent read-only postflight exited0, confirmed both absent and performed no database mutations. No owned runtime, browser tab, test, import or build remains active.
