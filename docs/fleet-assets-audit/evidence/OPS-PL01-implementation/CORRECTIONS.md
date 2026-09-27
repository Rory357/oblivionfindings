# OPS-PL01 — Main review corrections

Prepared 28 September 2026 in the existing `codex/ops-pl01-implementation` worktree, following Main's review of `ff856d9814086006d158dc788f4ebe55b9838305`.

**T-PL01-01, T-PL01-02 and T-PL01-03 are corrected. T-PL01-04 remains partly verified and blocked on manual 125% page-zoom setup. This is not an integration approval or a claim of complete browser acceptance.**

## T-PL01-01 — Current alert and capability authority

The workspace refreshes its actor after observation/history reads and before composing independently controlled alerts, map boundaries, preferences and capability flags. Revoked history authority clears the history projection. The new regression removes Control Room/history/export/staff permissions during a read, retains the permitted person, and withholds alert identity, details, links and counts together with the revoked flags. Main's original alert probe passes unchanged.

## T-PL01-02 — Current Transport scope and selected window

History re-resolves journeys with the fresh actor and client after observation reads, through the canonical Transport scope. The response uses that fresh list. A selected journey that disappears is withheld with 404; a changed selected journey projection/window yields 409. Day history remains independently readable with an empty current journey list when Transport permission ends. The export controller's subsequent recheck remains intact.

New regressions cover permission loss, changed journey Site, resident binding, vehicle Site, passenger window, unchanged authorised access, and day history through both workspace and report preview. Main's original Transport probe passes unchanged.

## T-PL01-03 — Semantic map colours and the actual branding path

Historical point markers and shared circle/polygon geometry use `var(--primary)`, matching the established Fleet map pattern. Actual Chrome DOM/computed-style checks cover default and alternate brands, each in light/dark mode, for the shared circle and history markers. Polygon code uses the same property/token; a separate polygon fixture was not rendered. Warning-state marker colours stay independent of the brand.

The alternate-brand check exposed a small necessary shared-template dependency: `resources/views/app.blade.php` contained literal malformed Blade delimiters in the organisation theme style block, and equal-specificity defaults loaded later would override it. The block now renders its existing escaped/allowlisted CSS variables using `html:root` and `html.dark`, retaining personal inline accent precedence. This is the only additional product path beyond the original 41-file manifest. A feature regression verifies both rendered theme variants; the real browser then confirms the changed colour on map geometry and historical points. No Rory guide, provider setting or PKG-08 implementation was changed.

Eight final cases plus initial observations are in `correction-brand-browser.json`. Normal-window screenshots are retained. The temporary synthetic organisation colours and reviewer preferences were backed up and restored.

## T-PL01-04 — Ordinary browser checks pass; actual 125% still pending

Verified a filled passenger report, keyboard search/selection within the nested journey picker, inner and outer Escape, focus return, a complete settled Tab cycle, reachable report footer/close controls, calculation dialog, and resizing between measured 1440×1000 and 1280×720 CSS viewports. See `correction-dialog-browser.json`. Initial measurements taken during popup animation are preserved separately from the settled checks.

**These are not the required actual125% browser-zoom checks.** Native Chrome control access timed out awaiting app approval. Navigating to Chrome's settings page was rejected by the browser tool's URL security policy; no workaround or security-policy bypass was attempted. The user has been asked asynchronously to set the People Locations Chrome tab to125% and reply. That answer has not arrived. The observed1.25 device-pixel ratio is the baseline display ratio and is deliberately not treated as proof of page zoom. After the user sets it, repeat the filled dialogs, nested pickers, keyboard and resize checks and add actual zoom evidence before requesting Main's integration release.

Viewport overrides were reset, original branding/theme restored, and the preview remains open at `http://127.0.0.1:8776/operations/people-locations/map?date=2026-09-27&q=&selected=c1`. Resized screenshots had display-scaling/capture artifacts and are excluded from visual acceptance; DOM geometry/focus evidence and ordinary-window captures are distinguished.

## Validation and reproducibility

- Scoped backend suite:37 tests /228 assertions passed (`correction-backend.txt`, JUnit alongside). New shared-template test:1 test /5 assertions passed (`correction-brand-template.txt`).
- Original Main probes:2 tests /9 assertions passed (`correction-main-probes.txt`, JUnit alongside). The Main-owned file was not edited; its hash is recorded in `correction-checks.json`.
- The first run crossed Auckland midnight while cold schemas were built. Relative40-minute journey fixtures fell into yesterday and failed before the intended read callback. Those initial logs are retained as `correction-midnight-*`. Source tests now freeze a midday clock. The unchanged Main probes use the explicit `correction-main-probe-bootstrap.php` clock harness, with `vendor/bin/phpunit --bootstrap <bootstrap-path> <Main-owned-probe-path>` from this candidate. The final assertions prove the revocation callbacks actually ran.
- Frontend:16 tests passed; full TypeScript and focused ESLint passed. Production build passed; existing large-chunk warnings remain. The build covers the final frontend changes; subsequent edits were PHP/Blade/tests/evidence.
- Metadata-only cleanup verification found no remaining schemas for this work's `of_people_correction_20260927_*`, `of_people_brand_correction_20260928_*` or unchanged Main harness `of_main_people_review_20260927_*` runs.
- All42 source hashes are in the updated `code-manifest.json`. Frozen v5 still matches all41 files, digest `10b27c27220c1be0ca122464f2f4e2ca8e92036a7dfed32b609574f96fb15b7d`. All six protected references match. Whitespace checks passed.

Main remains outside this worktree's write scope. No Main merge, Main application/compiled-asset write, operational migration, permission grant, deployment or remote push was performed. Existing rollout limits and the upstream telemetry-unit dependency remain unchanged.
