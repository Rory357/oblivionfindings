# Frozen Transport evidence: bounded lint correction

Base: published and Main-approved `49e0be5b1c1716aeb4e681529bb71fdce2a7abc0`.

The repository-wide ESLint command includes immutable Transport design previews. A read-only check of v6 alone reported 12 errors and 28 warnings across 26 files. These are candidate-specific packaging diagnostics; they must not be described as inherited failures from the previous main commit. The running application's focused lint passed.

Main authorised this bounded follow-up in `evidence/PKG-05-main-lint-packaging-review.md`: add only `docs/fleet-assets-audit/previews/PKG-05/**` to the existing global ESLint ignores, with a frozen-evidence comment. No production rule, generic documentation exclusion, guide, frozen file or runtime source changes.

Verification (`lint-packaging-scope.json`):

- All **576** committed frozen files match their exact bytes in approved commit `49e0be5b1`.
- All **166** JavaScript/TypeScript source and bundle files in that evidence directory are excluded.
- Six representative Transport, Fleet booking and shared Site Calendar files remain included with identical effective lint rules before and after the correction.
- The unrelated PKG-02A preview's lint scope remains unchanged.
- Scoped lint of the actual Transport implementation, changed Fleet booking page, shared calendar and `eslint.config.js` passes with exit 0 and no diagnostics (`lint-packaging-source.log`). `git diff --check` passes.

The read-only verification compared effective configurations using ESLint's `isPathIgnored` and `calculateConfigForFile` APIs, and SHA256 of every frozen filesystem file against the approved Git blob. No autofix ran against frozen evidence. The application bundle and database contract are unchanged, so the approved runtime build/browser checks remain applicable. The hour-long whole-repository CI was not rerun merely to prepare this correction.

Exact successor approval and a new serial publication slot remain Main's responsibility. The existing user publication request applies after that gate; no new user confirmation is required.
