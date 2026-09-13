# My Day publication — 13 September 2026

The approved desktop My Day redesign, quick tasks with steps, named help and handover follow-ups, per-person private shift notes, multi-person timesheet review, Agenda/Day work presentation and finish summary are packaged from remote main `ada9be439`.

The source was isolated from the saved local checkout. Unpublished IT commits, Governance changes, Today retirement and unrelated site changes are excluded. Shared calendar availability changes are already present in the remote base. Runtime fixtures, credentials, generated routes and built assets are not committed.

## Verification of the publishing candidate

- 80 distinct UI checks pass across 22 test files: 79 passed on the first run, then the corrected shared-button assertion passed in the seven-test wizard rerun. The assertion now recognises the approved soft-depth button variant. Logs: `evidence/publish-ui.txt` and `evidence/publish-wizard-retest.txt`.
- Full TypeScript check passes with freshly generated routes: `evidence/publish-types.txt`.
- Focused ESLint passes: `evidence/publish-lint.txt`.
- Standard production build passes in 4m 1s, without a custom Vite configuration: `evidence/publish-build.txt`.
- All 78 backend regression checks pass (455 assertions), covering task creation/steps, named help, handover follow-ups, per-person notes/privacy, timesheet allocation and canonical task-provider access: `evidence/publish-backend.txt`.
- The prior three-person browser verification and its limits are documented in `agenda-and-private-note-implementation.md` and `desktop-corrections-and-person-notes.md`.

## Installation on an existing environment

Run the normal migration and build process. This package includes the four additive My Day migrations: task context (`000060`), private task drafts (`000061`), task steps/follow-through (`000062`), and encrypted person notes (`2026_09_13_160000`).

For an existing installation, run `php artisan db:seed --class=MyDayTaskPermissionSeeder --force` to append the previously approved `shifts.tasks.createSelf` permission to the support-worker role. This narrow seeder preserves other grants and individual overrides. Fresh installations receive the permission through `RbacSeeder`.

Publishing source to Git does not deploy it or run migrations against another environment. The configured local application already received the migrations and approved narrow permission activation during implementation.
