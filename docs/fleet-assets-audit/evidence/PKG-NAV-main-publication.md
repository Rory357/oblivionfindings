# PKG-NAV — independent Main publication verification

Owner: MAIN ASTRA. Revision: 1. Updated: 2026-09-26. Status: **approved source verified on remote main; hosted checks in progress**.

Designer published `4ea64c547ed85a5b7504e59599db351f6eba7deb` by normal fast-forward from `fa7b5291988cebfb6beaa6e6e10c6c660fb2a959`. Main independently fetched origin/main and read `git ls-remote`: both resolve to the published commit. The approved source candidate `a94a22e3d12d1aabe447a84b7f2178ebdf906f41` is an ancestor (exit0). All **8 changed application/test blobs** match exactly between the approved candidate and published commit; **0 mismatches**.

The approval-to-publication delta is limited to the recorded Main approval evidence and the two requested evidence-file EOF whitespace cleanups. Full-range `git diff --check fa7b529..4ea64c547` now passes. No application logic changed after Main's exact review. Published application/test paths:

- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/components/app-sidebar.tsx`
- `resources/js/components/fleet-assets/fleet-workspace-navigation.test.tsx`
- `resources/js/components/fleet-assets/fleet-workspace-navigation.tsx`
- `resources/js/layouts/app/app-sidebar-layout.tsx`
- `resources/js/lib/fleet-navigation.ts`
- `resources/js/types/index.d.ts`
- `tests/Unit/FleetAssets/FleetNavigationPermissionProjectionTest.php`

The seven primary links preserve the existing33 destinations through focused context/search. The report-only permission correction is included. Main's independent27UI tests and7PHP permission tests/26assertions passed; Designer's broader66UI tests/4files and scoped type/lint passed, with overlap not summed. Main's actual candidate-shell click/Back/report-only browser checks and the limits of synthetic page/auth fixtures are recorded in [pre-integration reviewrev2](PKG-NAV-main-review.md). No actual server deployment or live-site role/data verification is claimed.

## Hosted checks at independent verification

All four queried GitHub runs report exact head `4ea64c547ed85a5b7504e59599db351f6eba7deb` and **in_progress**, with conclusion null:

- [tests, run36234167984](https://github.com/Rory357/oblivionfindings/actions/runs/36234167984)
- [visual-regression, run36234168020](https://github.com/Rory357/oblivionfindings/actions/runs/36234168020)
- [database-bootstrap, run36234167980](https://github.com/Rory357/oblivionfindings/actions/runs/36234167980)
- [linter, run36234168018](https://github.com/Rory357/oblivionfindings/actions/runs/36234168018)

Do not call full CI green, required integrated checks complete, page Accepted/Closed or runtime deployment verified while these outcomes remain open. Designer's baseline-failure statement is context, not proof of the new runs' outcomes. No broad fix or automatic recurring monitor is released.

Main's actual checkout HEAD remains `fa7b529` while origin/main is `4ea64c547`; its pre-existing dirty programme context and public/.user.ini are preserved. Main performed no application edit, local checkout merge/reset, force push, sibling contact or guide rewrite. The navigation task remains available for its check/acceptance gates. Other active mockups and Vehicle Profile ownership remain unchanged.
