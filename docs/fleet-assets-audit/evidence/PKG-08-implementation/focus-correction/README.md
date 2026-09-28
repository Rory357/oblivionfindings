# PKG-08 nested map discard focus correction

Parent: `38239d35506bf97c4b6f865a13aa10933b4cb439`. Isolated checkout: `codex/pkg08-settings-implementation`. This successor is for Main review, with no integration or publication performed.

Main reported a real Chrome125% reproduction at CSS1024x576: Configure provider, edit the Google project reference, Continue twice, Cancel, then Discard changes. Both dialogs closed, but the nested confirmation restored focus to the global header after the provider dialog tried to restore its opener. Clean Escape and dismissing only the confirmation were already correct.

The correction delegates close autofocus through the Settings-local Modal wrapper. A ref marks only confirmed discard of the provider. In that case the confirmation prevents its own close autofocus and clears the marker; the unchanged provider dialog remains responsible for returning to its original opener. Keep editing and Escape retain the normal nested restoration. No new focus target, timeout, shared Dialog/Wizard change, layout, saved configuration or provider behaviour is introduced.

## Evidence

- `before-tests.json`: the actual Maps component and shared dialog primitives reproduce the header-focus failure. Three control cases pass; confirmed discard fails with the header at tabindex=-1.
- `after-tests.json`:15 passing cases across four files, covering the four map-focus cases, existing map/notification draft semantics, WizardShell focus and shared dialog focus. Only external map tools and Inertia navigation are mocked in the focused regression; fetch is asserted unused.
- `eslint.json`, `typecheck.txt`, `typecheck-result.json`, and `qa.json` retain the scoped lint and full TypeScript outcomes.
- `typecheck-initial.txt` retains the initial test-only TS2769: Testing Library's role query does not accept the Playwright-style `exact` option. Removing that redundant option keeps the same role/name assertion; final outcomes are recorded separately.
- `source-manifest.json` binds the two changed application files and new regression file to the parent. Prior source manifests, screenshots and QA logs are unchanged.

No browser was used by this correction owner while Main controlled Chrome. Main's rendered verification of this successor remains required; these component tests do not claim125% browser acceptance.

## Existing synthetic optimistic-conflict procedure

Use only the existing guarded8794 preview and its synthetic Settings account/database. Open two tabs from the same saved state before making either draft; leave both mounted. Record the initial choices so they can be restored through the UI. Do not use the operating app/database, direct database writes, provider tools, diagnostic delivery actions or source-event creation.

Maps: in both tabs open Configure provider. Keep Google and all capability switches off. Change only the project reference to two different synthetic strings. Save tab A, then save tab B without reloading it. The second PUT carries the old revision and returns409 with the latest snapshot, opening Map configuration changed. Load current and Keep my changes can be inspected without activating a provider. Discard remaining drafts, restore the original project reference through the same reviewed UI, then reload both tabs.

Notifications: open the same user's Personal preferences in both tabs. Make different optional in-app channel edits. Review and save tab A, then review and save the already-open tab B. Its stale revision returns409 and opens Preferences changed elsewhere. Keep editing retains the draft; Keep my edited channels merges against the latest snapshot but requires another explicit review/save. Preference saves do not send notifications. Discard unsaved drafts, restore the recorded initial channel overrides through the UI, then reload both tabs. A synthetic save changes audit history and revision even when the original values are restored; do not reseed or delete that evidence.

This procedure is supplied for Main's synthetic verification; no preferences, provider configuration, credentials, database rows or notifications were changed in this correction turn. The earlier push authorization block and MAIN-TELEM-01 remain separate.
