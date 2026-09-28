# Final Settings focus integration

This packet accompanies the normal merge of frozen fleet consolidation `a3cb72895ce6682bcf39fe6276ce38955987bd7d` (first parent) and reviewed Settings focus correction `2f788b0b880e9b838c0b89b3c8eba2ecd57bf097` (second parent). Both ancestors are retained on `codex/pkg08-final-focus-integration` in the existing PKG-08 isolated checkout. The original `codex/pkg08-settings-implementation` branch remains frozen at the focus correction.

The code delta from the consolidation is exactly `_maps.tsx`, `_ui.tsx`, and `maps-focus.test.tsx` under `resources/js/pages/fleet-assets/settings/`. Their bytes match the reviewed focus correction. Confirmed discard of a provider draft lets the existing parent dialog restore focus to Configure provider; dismissing just the confirmation keeps normal focus restoration. Shared Dialog and Wizard components are unchanged.

## Verification

- All 15 affected frontend tests passed across four files: Maps focus, Settings draft semantics, Wizard focus and shared dialog focus. Scoped lint passed with no errors or warnings.
- The production build passed, then full TypeScript passed after Wayfinder route generation. Complete logs and result records are retained. The build uses the unchanged consolidation Vite config and existing dependency installation.
- The initial test attempt failed during config loading because the runner loader did not supply `__dirname`. No tests ran in that attempt. The QA harness supplies the same repository-root value used by the normal bundled config loader. This avoids writing bundled config/cache files through the existing dependency junction. `tests.log` and `tests-result.json` preserve the initial failure; `tests-final.*` record the passing run. No application config was changed for this accommodation.
- The consolidation's existing backend evidence is reused: 494 passing tests, 5,905 assertions. Database suites were not repeated.
- Main reported actual Chrome125% at CSS1024x576 verification on the reviewed focus commit, including nested discard and map/preference409 conflicts. The three focus source files here are byte-identical. This integration owner performed no new browser verification.

## Identity and preservation

`source-manifest.json` identifies both parents, the exact three source hashes and the pre-packet merged tree. `preservation.json` verifies every existing consolidation path except the two reviewed application files retains its Git object and mode; the new test and correction evidence are the only additions. Historical programme, evidence, authority and reference bytes remain unchanged from the consolidation. Prior focus failure logs remain unchanged from the focus commit. The canonical A7 master and untracked publication receipts are checked by SHA256. No documentation conflicts occurred.

`build-manifest.json` binds the build to the source manifest, the copied Vite manifest and `build-assets.json`. The latter records every manifest-addressed built file and its SHA256. Built application bundles stay in ignored `public/build`; they are not committed as source.

The runner scripts and exact process arguments are retained for review. Execution used Node22.23.2 and the existing Herd PHP84 executable. Test/build caches are scoped to `storage/framework/pkg08-map-regression/final-focus-qa-cache` in the isolated checkout. TypeScript runs after the build, never during route generation.

This is a reversible local integration candidate. No Main checkout source, old serial refs, shared GitHub branch, credentials, providers, devices, notifications, fixtures or migrations were changed. The earlier publication approval block and Main-executes-pushes exception remain separate.
