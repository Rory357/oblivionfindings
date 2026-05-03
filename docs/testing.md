# Testing Quick Reference

Last updated: 2026-05-03

## Canonical Harnesses

- Backend: Pest/PHPUnit under `tests/Unit`, `tests/Integration`, and `tests/Feature`.
- Canonical e2e: Playwright under `tests/e2e` and `tests/visual`.
- Legacy browser coverage: Dusk under `tests/Browser`. New e2e coverage should be Playwright unless a review explicitly calls for Dusk.

## Current Inventory

Counts verified from the working tree on 2026-05-03:

| Area | Count |
|---|---:|
| Feature test files | 268 |
| Unit test files | 65 |
| Integration test files | 1 |
| Dusk browser files | 106 |
| Playwright e2e specs | 27 |
| Playwright visual specs | 1 |
| `tests/__screenshots__` baselines | 24 |
| `tests/visual/__screenshots__` baselines | 22 |

Recount with:

```powershell
(Get-ChildItem -Path tests\Feature -Recurse -Filter *.php).Count
(Get-ChildItem -Path tests\Unit -Recurse -Filter *.php).Count
(Get-ChildItem -Path tests\Integration -Recurse -Filter *.php).Count
(Get-ChildItem -Path tests\Browser -Recurse -Filter *.php).Count
(Get-ChildItem -Path tests\e2e -Recurse -Filter *.spec.ts).Count
(Get-ChildItem -Path tests\visual -Recurse -Filter *.spec.ts).Count
(Get-ChildItem -Path tests\__screenshots__ -Recurse -File).Count
(Get-ChildItem -Path tests\visual\__screenshots__ -Recurse -File).Count
```

## PHP

Use Herd PHP directly if `php` is not on PATH:

```powershell
& 'C:\Users\steph\.config\herd\bin\php.bat' vendor\bin\pest
& 'C:\Users\steph\.config\herd\bin\php.bat' vendor\bin\pest --filter=Rostering
& 'C:\Users\steph\.config\herd\bin\php.bat' vendor\bin\pest tests\Feature\RosterControllerTest.php
& 'C:\Users\steph\.config\herd\bin\php.bat' vendor\bin\pest tests\Feature\Routing
& 'C:\Users\steph\.config\herd\bin\php.bat' vendor\bin\pest --filter='ShiftLifecycle|ShiftSafetyNet|ShiftCancellationCascade'
```

Run Pint on changed PHP files before committing PHP edits:

```powershell
& 'C:\Users\steph\.config\herd\bin\php.bat' vendor\bin\pint app\Services\ShiftTimelineService.php app\Domain\Shifts\Lifecycle\ShiftLifecycleService.php tests\Unit\Shifts\Lifecycle\ShiftLifecycleServiceTest.php
```

## Playwright

Playwright uses `playwright.config.ts`, which starts the app with the PHP built-in server:

```text
php -S 127.0.0.1:${port} -t public server.php
```

The config also defaults these rostering feature flags to enabled and passes them to the spawned server:

```text
FEATURE_ROSTERING_PUBLISH=true
FEATURE_ROSTERING_AUTO_SCHEDULE=true
```

On Windows, make Herd PHP discoverable before running Playwright if plain `php` is unavailable:

```powershell
$env:PATH = "C:\Users\steph\.config\herd\bin;$env:PATH"
$env:FEATURE_ROSTERING_PUBLISH = 'true'
$env:FEATURE_ROSTERING_AUTO_SCHEDULE = 'true'
```

Focused rostering readiness run:

```powershell
npx playwright test `
  tests/e2e/operations-rostering-publish.spec.ts `
  tests/e2e/operations-rostering-suggestions.spec.ts `
  tests/e2e/operations-rostering-a11y.spec.ts `
  tests/e2e/operations-rostering-performance.spec.ts `
  tests/e2e/template-apply-conflict.spec.ts `
  tests/e2e/frontline-published-visibility.spec.ts `
  tests/e2e/operations-rostering-conflicts.spec.ts `
  tests/e2e/operations-rostering-republish.spec.ts `
  tests/e2e/operations-shifts-detail.spec.ts `
  --project=chromium-desktop --reporter=list
```

Full visual/e2e CI-style run:

```powershell
npm run visual:test
```

Update visual baselines only for intentional UI changes:

```powershell
npm run visual:update
```

## Frontend

Use these when frontend source or Playwright fixtures change:

```powershell
npm run types
npm run build
```

## Screenshot Baselines

There are two screenshot trees today:

- `tests/__screenshots__`: the active Playwright snapshot location from `snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{arg}{ext}'`, where `testDir` is `./tests`.
- `tests/visual/__screenshots__`: legacy visual-regression baselines from the visual app-shell surface.

Do not delete either tree in routine readiness work. The current decision is to keep both until a dedicated visual update run (`npm run visual:update`) is reviewed and the resulting baseline movement is compared.

## Common Failure Modes

- If Playwright serves stale Vite assets, check whether `public/hot` was restored after a killed run. `tests/e2e/global-setup.ts` moves it aside and `global-teardown.ts` restores it.
- If Playwright cannot start the web server, make sure Herd PHP is on PATH or run from a shell where `php` resolves.
- If a rostering spec skips unexpectedly, check `FEATURE_ROSTERING_PUBLISH` and `FEATURE_ROSTERING_AUTO_SCHEDULE`.
- If Dusk appears green for a rostering flow, verify whether it is only a route smoke. `docs/dusk-operations-test-coverage.md` is the parity inventory.
