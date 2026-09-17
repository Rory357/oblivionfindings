from pathlib import Path
import hashlib
import json
import shutil
import subprocess

source = Path(r'C:\Users\steph\Herd\oblivionfindings')
target = Path(r'C:\Users\steph\.codex\visualizations\2026\09\12\01a094e0-dd40-7b73-b7c8-d0216a4a0e5c\myday-publish')
assert (target / '.git').is_file()

exact = '''
app/Domain/Hr/Services/AttendanceService.php
app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php
app/Domain/Shifts/Timesheets/TimesheetApprovalService.php
app/Domain/Shifts/Timesheets/TimesheetAllocationService.php
app/Http/Controllers/AttendanceController.php
app/Http/Controllers/MyDayActionsController.php
app/Http/Controllers/MyDayTaskController.php
app/Http/Controllers/MyDayTaskDraftController.php
app/Http/Controllers/MyTasksController.php
app/Http/Controllers/ShiftTaskController.php
app/Http/Controllers/TimesheetController.php
app/Http/Resources/MyShiftResource.php
app/Models/ShiftHandover.php
app/Models/ShiftTask.php
app/Models/ShiftTaskDraft.php
app/Services/HandoverWorkerNotes.php
app/Services/Operations/HandoverPresenter.php
app/Services/ShiftHandoverService.php
app/Services/Tasks/Providers/ShiftTaskProvider.php
app/Support/ShiftTaskSupport.php
database/migrations/2026_09_12_000060_add_my_day_shift_task_context.php
database/migrations/2026_09_12_000061_create_shift_task_drafts.php
database/migrations/2026_09_12_000062_add_shift_task_follow_through.php
database/migrations/2026_09_13_160000_add_worker_notes_to_shift_handovers.php
database/seeders/MyDayTaskPermissionSeeder.php
database/seeders/RbacSeeder.php
resources/css/app.css
resources/js/components/app-header.tsx
resources/js/components/end-of-shift-checklist.test.tsx
resources/js/components/end-of-shift-checklist.tsx
resources/js/components/handover-read-card.tsx
resources/js/components/handover-write-form.tsx
resources/js/components/handover-write-sheet.tsx
resources/js/components/handover-person-notes.test.tsx
resources/js/components/handover-person-notes.tsx
resources/js/components/page/page-header.tsx
resources/js/components/shift-task-list.tsx
resources/js/components/ui/button.tsx
resources/js/hooks/use-live-refresh.ts
resources/js/hooks/use-live-refresh.test.ts
resources/js/hooks/use-handover-editor.ts
resources/js/hooks/use-handover-draft-save.ts
resources/js/hooks/use-handover-draft-save.test.tsx
resources/js/lib/datetime.ts
resources/js/pages/sites/calendar/_parts.tsx
resources/js/pages/sites/calendar/work-schedule.tsx
resources/js/pages/sites/calendar/work-schedule.test.tsx
routes/shifts.php
tests/Feature/MyDayHandoverDigestTest.php
tests/Feature/MyDayTimesheetAllocationTest.php
tests/Feature/MyDayHandoverFollowUpTest.php
tests/Feature/MyDayPersonHandoverTest.php
tests/Feature/MyDayTaskHelpTest.php
tests/Feature/MyDayTaskWorkTest.php
tests/Feature/MyDayTimesheetReviewAuditTest.php
tests/Feature/Tasks/ShiftTaskProviderTest.php
docs/my-day-desktop-redesign-review-2026-09-12.md
docs/audits/2026-09-12-my-day/implementation-progress.md
docs/audits/2026-09-12-my-day/desktop-corrections-and-person-notes.md
docs/audits/2026-09-12-my-day/current-page-and-calendar-direction.md
docs/audits/2026-09-12-my-day/agenda-and-private-note-implementation.md
docs/audits/2026-09-12-my-day/evidence/agenda-vitest-final.txt
docs/audits/2026-09-12-my-day/evidence/agenda-pest.txt
docs/audits/2026-09-12-my-day/evidence/agenda-person-progress-retest.txt
docs/audits/2026-09-12-my-day/evidence/agenda-types-final.txt
docs/audits/2026-09-12-my-day/evidence/agenda-final-build.txt
docs/audits/2026-09-12-my-day/evidence/person-handover-modal-backend.txt
docs/audits/2026-09-12-my-day/evidence/backend-follow-through-d5.txt
docs/audits/2026-09-12-my-day/evidence/backend-clock-out-d6.txt
docs/audits/2026-09-12-my-day/evidence/backend-timesheet-recovery-d7.txt
'''.split()
changed = subprocess.check_output(['git', 'diff', '--name-only', 'HEAD'], cwd=source, text=True).splitlines()
untracked = subprocess.check_output(['git', 'ls-files', '--others', '--exclude-standard'], cwd=source, text=True).splitlines()
prefixes = ('app/Services/MyDay/', 'resources/js/pages/my-day/', 'resources/js/pages/operations/handovers/')
paths = sorted(set(exact + [p for p in changed + untracked if p.startswith(prefixes)]))
for name in paths:
    src, dst = source / name, target / name
    assert src.is_file(), name
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(src, dst)

# Include only the My Day route additions; leave concurrent IT and Today retirement local.
routes = target / 'routes/web.php'
body = subprocess.check_output(['git', 'show', 'origin/main:routes/web.php'], cwd=source).decode('utf-8')
body = body.replace('use App\\Http\\Controllers\\MyTasksController;', 'use App\\Http\\Controllers\\MyDayTaskController;\nuse App\\Http\\Controllers\\MyDayTaskDraftController;\nuse App\\Http\\Controllers\\MyTasksController;')
new_routes = '\n'.join(line for line in (source / 'routes/web.php').read_text(encoding='utf-8').splitlines() if "Route::" in line and "'/my-day/" in line and line not in body)
anchor = "    Route::post('/my-tasks/shift-task/{task}/complete'"
assert anchor in body
body = body.replace(anchor, new_routes + '\n' + anchor, 1)
routes.write_text(body, encoding='utf-8')

# Timesheet review requires disabled wizard steps; the unrelated IT focus feature is excluded.
for name in ['resources/js/components/wizard/shell.tsx', 'resources/js/components/wizard/shell.test.tsx']:
    (target / name).write_bytes(subprocess.check_output(['git', 'show', 'origin/main:' + name], cwd=source))
    options = ['--unified=0'] if name.endswith('.test.tsx') else []
    patch = subprocess.check_output(['git', 'diff', *options, 'HEAD', '--', name], cwd=source)
    apply_options = ['--unidiff-zero'] if options else []
    subprocess.run(['git', 'apply', '--check', *apply_options, '-'], cwd=target, input=patch, check=True)
    subprocess.run(['git', 'apply', *apply_options, '-'], cwd=target, input=patch, check=True)

manifest = [{'path': p, 'sha256': hashlib.sha256((source / p).read_bytes()).hexdigest()} for p in paths]
(source / 'docs/audits/2026-09-12-my-day/evidence/publish-source-manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
print(json.dumps({'copied_files': len(paths), 'partial_files': ['routes/web.php', 'resources/js/components/wizard/shell.tsx', 'resources/js/components/wizard/shell.test.tsx']}))
