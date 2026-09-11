# W11 mailbox settings and recovery

11 September 2026. Settings and polling recovery slices implemented; desktop browser verification in progress. Entire W11, E10 and the Goal remain incomplete.

## Behaviour and changed files

`ItMailboxConfigurationService` extends the existing connection record. Microsoft mailbox changes require the current record ID/version, fresh `integrations.manage_secrets`, no active operation/provider cooldown, and a read-only provider access probe under the existing exclusive connection claim. A rejected probe retains the saved address. Reconnects, concurrent edits and permission loss cannot overwrite newer credentials. Disconnect and manual polling use the same current identity/version boundary. Gmail uses the connected account's own inbox.

`ItMailboxConnectionPresenter` supplies explicit safe status, current-scope processing/acknowledgement/quarantine counts, last attempt/success, retry and operation state. It does not expose provider error bodies, credentials or discovery cursors. Controllers and `UpdateItMailboxRequest` use this contract. `PollItMailboxJob` and `ItMailboxPollState` bind manual work to the requested configuration version. Existing canonical services, records and scheduler are retained.

The settings page and new `it-mailbox-provider.tsx` / `it-mailbox-contract.ts` use the approved header, rail, meters, cards, controls and confirmation dialog. Provider details occupy the available content width. Drafts survive validation failures; conflicts and uncertain outcomes require an explicit read/review before another mutation. Stop waiting does not promise cancellation of server work. Session/access failure conceals both providers and drafts; authorized reload restores only current state. The shared settings leave guard preserves the existing SSO behaviour.

Current application/test source hashes: `w11-mailbox-settings-source-hashes.json`. Browser fixture helpers are separately fingerprinted in `w11-browser-build40-preview.json`; they are not production provider implementations.

## Actual verification

- Corrected settings backend: **17 passed,123 assertions,195 seconds**, wrapper85303 exit0. Exact disposable token `it_ea7d02e1c1e24f82`; all14 postflight checks and schema absence passed. Log `w11-mailbox-settings-final-tests.txt`.
- Actual separate-worker concurrency: **1 passed**, wrapper27286 exit0. Token `it_0f025af8d7284435`, all14 postflight checks/schema absence. Log `w11-mailbox-settings-concurrency-tests.txt`; diagnostic preserves worker execution evidence.
- UI: **11 tests across2 files passed,3.89 seconds**, including8 mailbox recovery cases and3 unchanged SSO leave-guard cases. Log `w11-mailbox-ui-tests.txt`.
- Whole-repository TypeScript and scoped ESLint passed before the final approved glass-header-button substitution. `git diff --check` passed. Build40 then passed in **3m13s**: app `app-DaGFDFjm.js`, mailbox `it-mailbox-xT9T0ey5.js`, manifest SHA256 `57ad833fd6e01dfa50b21f52b898c088aadfb2c8ed5184a671bf5d666f6cfa5f`.
- All10 protected design hashes unchanged: `w11-mailbox-build40-protected-design-check.json`.
- Preserve failed runs: the expanded suite had109 passed/2 settings rendering failures; diagnostic identified the test HTTP guard rejecting SSR. SSR was disabled only in that test and the guarded browser harness. Production SSR was not changed. Earlier import timeout and exact completed cleanup are recorded in `w11-paging-results.md`.

## Browser environment and remaining acceptance

Build40 reviewed fingerprint `3ae28f73e1e0270294713758cd2886323c66685f52124150b6131d64f7d563c5`; fresh token `1330a382400c4ac3`. Launcher78835 is preparing the isolated schema. Do not treat environment startup as browser verification. No browser viewport resize is authorized.

The opt-in fixture uses27 synthetic messages per provider and one simulated lost Microsoft mark-read acknowledgement after unread removal. All other HTTP is blocked; array mail and a fresh schema prevent real communications and working-database changes. This establishes local application behaviour only, not actual Microsoft/Google consent or delivery. Production configurations and the working database remain unchanged.

Next: inspect the current desktop page, dirty discard/cancel, denied/approved address save, provider drain and ACK-only recovery, stale edit review, disconnect, restricted access and expired-session recovery. Review same-provider/header/filter navigation carefully: confirming discard must actually reset a retained panel, not only clear its parent dirty flag.

W11 still requires complete RFC identity/reference handling, shared intake/reply commands and settled/merged policy, protected attachments, loop/bounce handling, quarantine management/recovery/retention and DP07 live-provider acceptance. No whole-package or release gate closure is claimed.
