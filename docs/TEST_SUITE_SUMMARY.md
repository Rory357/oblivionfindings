# Test Suite Summary

**Last verified:** 2026-05-03

This is the current test inventory for the Laravel/Inertia application. Counts are file counts, not assertion counts, because Pest, Dusk, and Playwright all expand tests differently at runtime.

## Current Counts

| Area | Location | Count |
|---|---:|---:|
| Feature tests | `tests/Feature/**/*.php` | 268 |
| Unit tests | `tests/Unit/**/*.php` | 65 |
| Integration tests | `tests/Integration/**/*.php` | 1 |
| Browser tests (Dusk) | `tests/Browser/**/*.php` | 106 |
| Playwright e2e specs | `tests/e2e/**/*.spec.ts` | 27 |
| Playwright visual specs | `tests/visual/**/*.spec.ts` | 1 |
| Canonical screenshots | `tests/__screenshots__/**/*` | 24 |
| Legacy visual screenshots | `tests/visual/__screenshots__/**/*` | 22 |

Re-run the inventory in PowerShell:

```powershell
(Get-ChildItem tests\Feature -Recurse -Filter *.php).Count
(Get-ChildItem tests\Unit -Recurse -Filter *.php).Count
(Get-ChildItem tests\Integration -Recurse -Filter *.php).Count
(Get-ChildItem tests\Browser -Recurse -Filter *.php).Count
(Get-ChildItem tests\e2e -Recurse -Filter *.spec.ts).Count
(Get-ChildItem tests\visual -Recurse -Filter *.spec.ts).Count
(Get-ChildItem tests\__screenshots__ -Recurse -File).Count
(Get-ChildItem tests\visual\__screenshots__ -Recurse -File).Count
```

## Canonical Harnesses

- Backend contracts and domain behavior: Pest/PHPUnit under `tests/Feature`, `tests/Unit`, and `tests/Integration`.
- New browser and e2e coverage: Playwright under `tests/e2e`.
- Visual regression coverage: Playwright under `tests/visual`.
- Legacy browser coverage: Dusk under `tests/Browser`; keep it until parity is inventoried and replacement coverage has proven green.

New e2e tests should be Playwright unless a code review calls out a specific Dusk-only reason.

## Rostering And Shifts Coverage

Rostering is covered by backend, Playwright, and legacy Dusk layers:

- `tests/Feature/RosterControllerTest.php` covers frontline roster visibility, per-org publish flag behavior, `my-roster.data`, and calendar JSON.
- `tests/Feature/Rostering/` covers publishing, suggestion application, template application, archive cron, published-shift cancellation/completion/payroll locks, and republish guardrails.
- `tests/Unit/Rostering/` covers eligibility scoring and suggestion context.
- `tests/Unit/Shifts/` covers lifecycle and timesheet approval services.
- `tests/Feature/Routing/` covers legacy route redirects and canonical permission contracts.
- `tests/e2e/operations-rostering-*.spec.ts`, `template-apply-conflict.spec.ts`, `frontline-published-visibility.spec.ts`, and `operations-shifts-detail.spec.ts` cover the manager/frontline browser seams.

The Dusk files under `tests/Browser/Shifts` are page-load smoke tests. They are retained for parity tracking but are not the canonical proof that rostering publish, suggestions, conflicts, or frontline visibility work.

## Common Commands

```powershell
# Full backend suite
vendor\bin\pest

# Rostering subset
vendor\bin\pest --filter=Rostering

# Route contracts
vendor\bin\pest tests\Feature\Routing

# TypeScript and production build
npm run types
npm run build

# Full Playwright suite
npm run visual:test

# Focused rostering browser suite
npx playwright test tests/e2e/operations-rostering-publish.spec.ts tests/e2e/operations-rostering-suggestions.spec.ts tests/e2e/operations-rostering-a11y.spec.ts tests/e2e/operations-rostering-performance.spec.ts tests/e2e/template-apply-conflict.spec.ts tests/e2e/frontline-published-visibility.spec.ts tests/e2e/operations-rostering-conflicts.spec.ts tests/e2e/operations-rostering-republish.spec.ts tests/e2e/operations-shifts-detail.spec.ts --project=chromium-desktop --reporter=list
```

On Windows/Herd, use the explicit PHP binary when needed:

```powershell
& C:\Users\steph\.config\herd\bin\php.bat vendor\bin\pest --filter=Rostering
```

## Playwright Notes

`playwright.config.ts` injects `FEATURE_ROSTERING_PUBLISH=true` and `FEATURE_ROSTERING_AUTO_SCHEDULE=true` unless they are already set. It serves the app through the repo-root `server.php` router so Vite assets load correctly under the PHP built-in server.

`tests/e2e/global-setup.ts` temporarily moves `public/hot` aside before browser tests and `global-teardown.ts` restores it. If a run is killed mid-flight, restore `public/.hot.playwright.bak` to `public/hot`.

## Screenshot Baselines

The current canonical snapshot path is configured by `snapshotPathTemplate` and writes to `tests/__screenshots__`. The older `tests/visual/__screenshots__` tree is retained as historical baseline material until a deliberate `npm run visual:update` comparison decides what to remove.

## Maintenance

Update this file when test directories, canonical commands, or browser harness policy changes. Count drift of a few files is normal; stale harness guidance is not.
