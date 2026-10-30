# PKG-NAV N01 correction — exact delta review

Status: corrected and verified; awaiting Main's exact-candidate approval before publication.

Main's handoff revision 2 and `PKG-NAV-main-review.md` explicitly authorised this minimal adapter on 26 September. The full candidate remains based on `fa7b5291988cebfb6beaa6e6e10c6c660fb2a959`. The preceding reviewed/amended head is `407126d71957a6c2ead7147f401dd609e0347537`; the final correction head is supplied in the review message. Use `git diff 407126d71957a6c2ead7147f401dd609e0347537 <final-head>` for N01 and `git diff fa7b5291988cebfb6beaa6e6e10c6c660fb2a959 <final-head>` for the complete candidate.

## Correction

- `HandleInertiaRequests.php` adds `auth.can.fleet.reportsView = $user->canDo('fleet.reports.view')`. The capability cache version advances from v8 to v9 so existing cached maps do not hide the new field for five minutes. No grant, policy, controller, route, schema or operational state changes.
- `resources/js/types/index.d.ts` explicitly types the existing Fleet fields and the new report projection.
- `fleet-navigation.ts` admits report readers through a separate module-discovery predicate and uses their existing permission for the five analytics destinations. The operational predicate is unchanged. Mileage remains independently gated by operational access.
- The Fleet module gate in `app-sidebar.tsx` uses that discovery predicate. Report-only users get exactly Reports, with a permitted `/fleet-assets/reports` landing. They do not get Overview, Maps, Daily checks, Vehicles, Work, Mileage or Settings from the new navigation.
- The existing UI test file adds the five requested role cases. The new pure PHP unit test exercises the real `buildUserPermissions` and real `User::canDo` against loaded role/override relations, including explicit deny precedence. Nothing is written to a database.
- The recorded fixture adds those role cases. Page bodies remain synthetic; shell/navigation/Inertia are the real changed components.

The earlier report-only limitation in REVIEW.md is resolved. No other implementation scope expanded. Single organisation across sites, canonical ownership and privacy remain unchanged.

## Results

- Frontend: **66 tests passed across 4 files**, including full-role seven links and retained navigation regressions.
- PHP: **7 tests, 26 assertions passed** on PHP 8.4.16 / PHPUnit 12.5.23. Cases: reports only, reports + assigned assets, reports + asset manager, unrelated global reports, Fleet view separately, no permission, explicit deny. The initial test setup errors were corrected by supplying the middleware's existing BoardPackAccessService constructor dependency; no production workaround was made.
- Scoped TypeScript: **zero diagnostics**, including changed shared typing through the changed runtime imports.
- Scoped ESLint (`--max-warnings=0`): pass for changed source and committed verification scripts. PHP syntax: pass. New PHP test's Pint check: pass. Diff whitespace: pass.
- Shared middleware Pint remains a **baseline failure**: fully_qualified_strict_types, unary_operator_spaces, braces_position, not_operator_with_successor_space, single_line_empty_body, ordered_imports. Those same findings reproduce on the untouched `fa7b529` file; the extracted temporary comparison also reported line endings. This navigation change does not rewrite unrelated middleware formatting.
- Browser: real Chrome on the same isolated `127.0.0.1:8876` worktree fixture. Report-only → Resource use → Usage by house reaches the canonical route, Reports stays selected, Back restores the report landing, and command search reaches the same Usage by house route. All five requested combinations were rendered. No console warnings/errors in the fresh N01 tab. See [role/click/search evidence](n01-browser.json) and [report-only screenshot](07-report-only.png).

Role results: reports only → Reports; reports + assigned assets → Overview, Assets, Maintenance→Daily checks, Maps & boundaries, Reports; reports + asset management → all seven with Fleet→Bookings and Reports→analytics; unrelated global reports alone and no permission → no Fleet module or Fleet report context. This does not claim real Laravel site-record authorization tests; the backend test checks actual permission projection, while existing site/record route rules remain untouched.

PHP reproduction (no application boot/database): copy `verification/php-bootstrap.php` to `.fleet/pkg-nav/php-bootstrap.php`, then run the installed Herd PHP against the installed vendor PHPUnit launcher with `--no-configuration --bootstrap .fleet/pkg-nav/php-bootstrap.php tests/Unit/FleetAssets/FleetNavigationPermissionProjectionTest.php`. The bootstrap prepends this checkout's App/Tests autoload paths before the existing read-only Composer dependencies, ensuring the middleware and User resolver under test come from worktree475b. All other fixture/test commands remain in REVIEW.md.

No push has occurred. Publication still requires Main's exact amended-candidate approval, then latest-main reconciliation and remote/check verification.
