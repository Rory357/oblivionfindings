# Oblivion Findings

Laravel 12, Inertia, React, and TypeScript application for frontline care operations. The app contains worker-facing flows such as My Day and My Roster, manager operations such as rostering and shifts, plus governance, finance, care, incidents, control room, privacy, and reporting surfaces.

## Stack

- PHP 8.2+, Laravel 12, Pest/PHPUnit, Laravel Dusk.
- React 19, Inertia 2, TypeScript, Vite, Tailwind CSS.
- Playwright for canonical end-to-end and visual coverage.
- MySQL test database `oblivion_findings_codex_test`.

## Bootstrap

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

On this Windows/Herd setup, PHP may be available through `C:\Users\steph\.config\herd\bin\php.bat` even when `php` is not on `PATH`. The Playwright helpers already look there first.

## Local Development

```powershell
composer run dev
```

That starts Laravel, the queue listener, and Vite. Herd-hosted domains such as `https://oblivionfindings.test` may already serve the app locally; check `public/hot` if a browser appears to be reading a different worktree.

## Testing

Backend:

```powershell
php artisan test
vendor\bin\pest --filter=Rostering
vendor\bin\pest tests\Feature\Routing
```

Frontend and browser:

```powershell
npm run types
npm run build
npm run visual:test
npx playwright test tests/e2e/operations-rostering-publish.spec.ts --project=chromium-desktop
```

Playwright is the canonical e2e harness for new browser coverage. Dusk remains for legacy interaction surfaces while parity is measured.

## Useful Docs

- `docs/TEST_SUITE_SUMMARY.md` - current test inventory and canonical commands.
- `docs/testing.md` - testing quick reference, Playwright notes, and screenshot baseline policy.
- `docs/architecture/shifts-module-map.md` - current shifts and rostering route/module map.
- `docs/architecture/shifts-route-deprecation.md` - legacy shifts redirect policy.
- `docs/route-ownership.md` - route ownership and canonical surface guidance.

## Maintenance Rules

- Keep worker-facing flows under `/my-day` and `/my-roster`; manager flows stay under `/operations/*`.
- When the test tree changes, update `docs/TEST_SUITE_SUMMARY.md` and `docs/testing.md`.
- Do not delete Dusk tests until the parity inventory says the behavior is covered elsewhere and the replacement suite has proven green.
- Regenerate the portable schema after structural migrations with `php artisan rostering:dump-schema-portable`.
