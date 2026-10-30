# Map provider setup correction — 28 September 2026

The user chose deployment-managed Google credentials, then deferred obtaining a real key. The app is ready for that later setup. No Google billing activation or real-key provisioning is claimed.

## Implemented

- Restored the four-stage connection, capabilities, check and review flow from the approved PKG-08 v6 map design. The deployment-backed implementation identifies keys using safe fingerprints and provides concrete instructions instead of an unsupported integration-store selector.
- Added the server-key and optional project-reference environment documentation. See [the deployment guide](../../google-maps-deployment-setup.md).
- Added field-specific prerequisites, focused errors, explicit Google display dependency, separate optional APIs, busy-state protection, and credential refresh that preserves drafts and detects changed revisions.
- Retained recovery, saved-state checks, conflict merging, audited writes, management permissions and nested discard focus.
- Distinguished Google off, not selected, missing credential, missing display prerequisite, review required and configured-but-unverified states. Added bounded, revision-specific request observations with no search text, coordinates, identity or keys.
- Added shared-tool entry points from Transport and Maps & boundaries. Canonical boundary editing and transport manual entry retain their existing behaviour. Google-derived viewer results remain temporary and provider-attributed.

## Automated verification

- New backend suite: **6 tests, 56 assertions**, passed against a separately created synthetic MySQL database. See `backend.log` and `backend.xml`.
- Existing PKG-08 permission, persistence, notification and provider regressions: **12 tests, 78 assertions**, passed. These used the dedicated synthetic browser database inside rollback transactions. See `regression.log` and `regression.xml`.
- Focused Vitest suites: **5 files, 20 tests**, passed. Includes setup validation, 422/409 handling, discard focus and Google renderer/SDK regression coverage. See `vitest-final.log`.
- Repository TypeScript check, scoped ESLint, Prettier and Pint passed. Production Vite build passed; existing large-chunk warnings remain.
- Formatting and type checks were repeated after the final copy/disabled-state refinements. The successful production build preceded those small refinements.

Provider HTTP responses in backend tests were mocked. Tests deny unexpected outbound provider requests. A successful test is not evidence of a working real Google key.

## Authenticated Chrome verification

The tested app served this correction checkout through `http://127.0.0.1:8796`; Vite scripts came from `http://127.0.0.1:5196/resources/js/app.tsx`. The runtime's controller reflection resolved to this worktree. A disposable test coordinator, approved site, isolated database and isolated session cookie were used. No operational records were edited.

Verified in the rendered application:

1. Real Fleet sidebar navigation to `/fleet-assets/settings/notifications#maps` and the four-stage wizard.
2. Missing browser credential blocks enabling Google, focuses the error, and retains the entered project.
3. Nested discard → Keep editing restores focus to the wizard's Close control.
4. A simulated session loss retains a recoverable local draft. After isolating the preview cookie from other local previews, recovery and save succeed.
5. Saving a project with Google off succeeds without either credential.
6. Adding a preview-only fake browser credential shows a fingerprint. An optional server API is blocked without the server key. Display-only settings save and survive full page reload as **Configured · not verified**.
7. Removing that fake credential during a dirty draft triggers conflict review. Keeping edits retains the changed project; saving Google then returns to the missing-key error. Disabling Google still saves successfully.
8. Transport → More actions → Address & route tools opens the shared explorer. OSM is displayed and Google requests are disabled with no key.
9. Maps & boundaries canonical navigation redirects `/fleet-assets/map` to `/fleet-assets/geofences?tab=map`; Map service status exposes shared configuration and tool entry points, while retaining boundary editing.
10. Light and dark rendering inspected. Desktop CSS viewport 1536×711; shorter CSS viewport 1024×560; narrow CSS viewport 392×720. The narrow dialog fits after viewport relayout, hides its rail, scrolls its body and retains visible footer actions. No document horizontal overflow. Browser viewport override was reset. Browser zoom itself was not independently inspected.
11. Final browser error log was empty.

Screenshots are `maps-setup-light.png`, `maps-missing-key-dark.png`, `maps-setup-mobile-dark.png`, and `transport-osm-fallback.png`. These contain synthetic data only.

## Remaining live dependency

Real Google display, Places, Geocoding and Routes acceptance remains unverified until restricted keys, enabled APIs and billing are configured. Setup checks local presence only and sends no automatic paid probe. Quotas, restrictions and provider terms must be reviewed in the actual project before enabling it. The normal no-key state is OSM with manual inputs available.
