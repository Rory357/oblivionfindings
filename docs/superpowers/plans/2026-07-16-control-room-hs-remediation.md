# Control Room, Incident, H&S and Universal Tasks Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix every D-01 through D-19 finding from the 16 July 2026 live multi-role audit, then prove the complete Control Room → Incident → H&S → investigation → corrective action → independent verification → final closure journey locally and on the live development server with no unresolved finding.

**Architecture:** Strengthen the existing canonical records and services. `ControlRoomAlert`, `ClientIncident`, `HsEvent`, `HsInvestigation`, `HsCorrectiveAction`, `AlertTask`, and `TaskAggregator` remain authoritative; this work adds explicit WorkSafe decision state, evidence-complete corrective-action verification, atomic responsibility transfer, shared closure gates, bounded handover scope, permission-aware task actions, and reliable shared UI controls.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL, Inertia, React 19, TypeScript, Radix UI, Tailwind CSS, Pest/PHPUnit, Vitest/Testing Library, Playwright, Vite client and SSR builds.

---

## Authoritative inputs and non-negotiable rules

- Approved design: `docs/superpowers/specs/2026-07-16-control-room-hs-remediation-design.md`
- Authoritative manual audit: `C:\Users\steph\Herd\oblivionfindings\docs\audits\control-room-multi-role-manual-ux-audit-2026-07-16.md`
- Isolated worktree: `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-control-room-hs-remediation`
- Branch: `codex/control-room-hs-remediation`
- Base: clean `origin/main` at `b5b5df463ce788fbbf988c74f5142b7fcbb52628`
- Do not edit the dirty canonical checkout while implementing.
- Follow red → green → refactor for every behavior change. A test that passes before the implementation is not proof of the new requirement.
- Run focused tests after every small implementation step and commit each completed task.
- Do not weaken authorization, site scoping, separation of duties, or server-side lifecycle enforcement to make a UI test pass.
- Do not create a second incident journey, task engine, attachment table, or closure lifecycle.
- Keep date-only values as `YYYY-MM-DD`; do not parse them through a JavaScript instant.
- New uploads use the private disk and authenticated download routes.
- No finding can be moved outside this plan or closed without direct evidence.
- Completion requires local automation, production-built browser proof, merge/push/deployment, actual desktop Chrome acceptance for all seven personas, alternate branches A–F, and final database/log integrity evidence.

## Finding ownership matrix

| Finding | Primary tasks | Required proof |
|---|---|---|
| D-01 verifier cannot see evidence | 8, 9 | verifier sees notes, files, completer, timestamps, recommendation, source task, return reason, and readable history |
| D-02 false WorkSafe completion | 2, 3, 4 | explicit unknown/false/true decision, notification lifecycle, truthful closure gate |
| D-03 Tasks says Completed | 10 | `Awaiting independent verification`, active bucket until closure |
| D-04 owner picker absent | 5, 6, 7 | required eligible owner and due date on every creation path |
| D-05 unusable shift handover | 15, 16 | bounded changed-work scope, carry-forward summary, stale override, incoming acceptance |
| D-06 search omissions | 10 | client, site, assignee, source task, narrative, and lifecycle-state search |
| D-07 duplicate responsibility | 5, 6, 10 | one retry-safe task transfer and one active Universal Tasks responsibility |
| D-08 evidence fragmentation | 12, 13 | immediate controls and linked Control Room evidence visible in Incident and H&S |
| D-09 worker CTA → 403 | 11 | permission-aware CTA or `No action for you`, recoverable authorization drift |
| D-10 resolve preflight misleading | 14 | shared server gate distinguishes resolve from final closure and links blockers |
| D-11 picker mis-selection | 17 | mouse and keyboard selection commit the intended option |
| D-12 rework reason invisible | 9 | owner and verifier see return reason and resubmission history |
| D-13 date shifted | 17 | date-only persistence/rendering in NZ and UTC-adjacent zones |
| D-14 manager cannot find action | 6, 7, 10 | assigned manager receives/finds the action by natural search |
| D-15 H&S dashboard misses acceptance | 18 | first-priority awaiting-acceptance worklist |
| D-16 Escape loses focus | 11, 18 | Escape/Close restores the invoking row and visible focus |
| D-17 Select warnings | 17 | controlled from first render; zero controlled/uncontrolled warnings |
| D-18 machine activity labels | 9, 18 | human lifecycle labels across Tasks and H&S |
| D-19 Back/sidebar inconsistency | 11, 18 | filtered Back/Close recovery and no empty role-only sidebar groups |

## Task 1: Create the remediation ledger and pin the baseline

**Files:**

- Create from the authoritative audit without altering its baseline content: `docs/audits/control-room-multi-role-manual-ux-audit-2026-07-16.md`
- Create: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`
- Modify throughout execution: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [ ] **Step 1: Bring the authoritative audit into the isolated branch**

Use `apply_patch` to add the current contents of:

`C:\Users\steph\Herd\oblivionfindings\docs\audits\control-room-multi-role-manual-ux-audit-2026-07-16.md`

at:

`docs/audits/control-room-multi-role-manual-ux-audit-2026-07-16.md`

Verify the branch copy is byte-for-byte equivalent before any closeout edits:

```powershell
$source = (Get-Content -Raw 'C:\Users\steph\Herd\oblivionfindings\docs\audits\control-room-multi-role-manual-ux-audit-2026-07-16.md').Replace("`r`n", "`n")
$branch = (Get-Content -Raw 'docs/audits/control-room-multi-role-manual-ux-audit-2026-07-16.md').Replace("`r`n", "`n")
if ($source -ne $branch) { throw 'Audit copy differs from the authoritative source.' }
```

- [ ] **Step 2: Create a 19-row completion ledger**

Use one row per D-01 through D-19 with these columns:

```markdown
| ID | Owner task | Code evidence | Automated proof | Browser proof | Live proof | Status |
|---|---:|---|---|---|---|---|
| D-XX | task number | file and symbol | command and result | screenshot | deployed record | Open |
```

Add separate matrices for:

- seven personas;
- golden journey acceptance criteria 1–17;
- alternate branches A–F;
- local verification commands;
- deployed SHA, migration counts, permission sync, database integrity, logs, and screenshots.

- [ ] **Step 3: Record the clean implementation baseline**

Run:

```powershell
git status --short --branch
git rev-parse HEAD
php artisan test tests/Feature/HealthSafety/HsEventWorksafeTest.php tests/Feature/HealthSafety/HsEventWorkflowTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php
npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/incidents/incident-detail-dialog.test.tsx
```

Expected:

- branch is `codex/control-room-hs-remediation`;
- HEAD includes the approved design commit;
- backend baseline is 23 tests and 193 assertions with no failures;
- frontend baseline is 3 files and 13 passing tests;
- worktree has only the branch copy of the audit and the new ledger after this step.

- [ ] **Step 4: Add the exact baseline results to the ledger**

Record command, exit code, test count, assertion count, and SHA. Do not use a generic “green” label.

- [ ] **Step 5: Commit the audit baseline and ledger**

```powershell
git add docs/audits/control-room-multi-role-manual-ux-audit-2026-07-16.md docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "docs(control-room): start remediation evidence ledger"
```

## Task 2: Make WorkSafe decision state explicit in the schema

**Files:**

- Create: `database/migrations/2026_07_16_000100_make_hs_worksafe_decision_explicit.php`
- Create: `app/Console/Commands/ReportHsWorksafeDecisionCounts.php`
- Modify: `app/Models/HsEvent.php`
- Modify: `database/factories/HsEventFactory.php`
- Create: `tests/Feature/HealthSafety/HsWorksafeDecisionSchemaTest.php`
- Create: `tests/Feature/Console/ReportHsWorksafeDecisionCountsTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [ ] **Step 1: Write failing schema and backfill tests**

Cover:

```php
it('stores an undecided open event as null', function () {
    $event = HsEvent::factory()->create(['status' => HsEvent::STATUS_OPEN]);
    expect($event->fresh()->worksafe_notifiable)->toBeNull();
});

it('preserves closed false history while reopening open implicit false as unknown', function () {
    // Insert pre-migration-shaped rows, run the migration, and assert:
    // open false => null; closed false => false; true/notified => true + migration metadata.
});
```

The command test must assert exact totals for:

- undecided;
- explicit not notifiable;
- notifiable pending;
- notified;
- acknowledged;
- closed legacy false.

- [ ] **Step 2: Run the tests and prove the current schema fails**

```powershell
php artisan test tests/Feature/HealthSafety/HsWorksafeDecisionSchemaTest.php tests/Feature/Console/ReportHsWorksafeDecisionCountsTest.php
```

Expected: failures for missing nullable decision metadata and missing command.

- [ ] **Step 3: Implement the migration**

Use explicit columns and conservative backfill:

```php
Schema::table('hs_events', function (Blueprint $table) {
    $table->boolean('worksafe_notifiable')->nullable()->default(null)->change();
    $table->timestamp('worksafe_decided_at')->nullable()->after('worksafe_notifiable');
    $table->foreignId('worksafe_decided_by_user_id')->nullable()
        ->after('worksafe_decided_at')->constrained('users')->nullOnDelete();
    $table->text('worksafe_decision_reason')->nullable()->after('worksafe_decided_by_user_id');
    $table->string('worksafe_decision_source', 32)->nullable()->after('worksafe_decision_reason');
});

DB::table('hs_events')
    ->where(function ($query) {
        $query->where('worksafe_notifiable', true)
            ->orWhereNotNull('worksafe_status')
            ->orWhereNotNull('worksafe_notified_at');
    })
    ->update([
        'worksafe_notifiable' => true,
        'worksafe_decided_at' => DB::raw('COALESCE(worksafe_notified_at, updated_at, created_at)'),
        'worksafe_decision_reason' => 'Existing notifiable or notification state preserved during migration.',
        'worksafe_decision_source' => 'migration',
    ]);

DB::table('hs_events')
    ->where('worksafe_notifiable', false)
    ->where('status', '!=', HsEvent::STATUS_CLOSED)
    ->whereNull('worksafe_decided_at')
    ->update(['worksafe_notifiable' => null]);
```

The down migration must drop the decision metadata and restore `null` to `false` before making the boolean non-null.

- [ ] **Step 4: Update the model and factory**

Add fillable/casts/relationship:

```php
'worksafe_decided_at' => 'datetime',
'worksafe_notifiable' => 'boolean',

public function worksafeDecidedBy(): BelongsTo
{
    return $this->belongsTo(User::class, 'worksafe_decided_by_user_id');
}
```

Factory defaults:

```php
'worksafe_notifiable' => null,
'worksafe_status' => null,
'worksafe_decided_at' => null,
'worksafe_decided_by_user_id' => null,
'worksafe_decision_reason' => null,
'worksafe_decision_source' => null,
```

Factory states must distinguish `worksafeUndecided()`, `worksafeNotNotifiable(User $actor)`, and `worksafeNotifiable(User $actor)`.

- [ ] **Step 5: Implement the read-only count command**

Command signature:

```php
protected $signature = 'health-safety:worksafe-decision-counts {--json}';
```

It must never mutate rows and must return a non-zero exit code if inconsistent combinations exist, such as notified state with `worksafe_notifiable !== true`.

- [ ] **Step 6: Run migration-specific proof**

```powershell
php artisan migrate --path=database/migrations/2026_07_16_000100_make_hs_worksafe_decision_explicit.php
php artisan health-safety:worksafe-decision-counts --json
php artisan migrate:rollback --path=database/migrations/2026_07_16_000100_make_hs_worksafe_decision_explicit.php
php artisan migrate --path=database/migrations/2026_07_16_000100_make_hs_worksafe_decision_explicit.php
php artisan test tests/Feature/HealthSafety/HsWorksafeDecisionSchemaTest.php tests/Feature/Console/ReportHsWorksafeDecisionCountsTest.php
```

Expected: migration, rollback, re-migration, command, and both test files pass.

- [x] **Step 7: Commit**

```powershell
git add database/migrations/2026_07_16_000100_make_hs_worksafe_decision_explicit.php app/Console/Commands/ReportHsWorksafeDecisionCounts.php app/Models/HsEvent.php database/factories/HsEventFactory.php tests/Feature/HealthSafety/HsWorksafeDecisionSchemaTest.php tests/Feature/Console/ReportHsWorksafeDecisionCountsTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(health-safety): make WorkSafe decisions explicit"
```

## Task 3: Add the WorkSafe decision API, audit trail, and closure truth

**Files:**

- Create: `app/Http/Requests/HealthSafety/RecordHsWorksafeDecisionRequest.php`
- Create: `app/Http/Controllers/HealthSafety/HsWorksafeDecisionController.php`
- Modify: `app/Services/HealthSafety/HsEventService.php`
- Modify: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Modify: `routes/health-safety.php`
- Modify: `tests/Feature/HealthSafety/HsEventWorksafeTest.php`
- Modify: `tests/Feature/HealthSafety/HsEventClosureTest.php`
- Create: `tests/Feature/HealthSafety/HsWorksafeDecisionAuthorizationTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [ ] **Step 1: Write the decision and closure matrix tests**

The matrix must prove:

```php
dataset('worksafe closure truth', [
    'unknown blocks' => [null, null, false],
    'explicit false closes' => [false, 'manual', true],
    'true pending blocks' => [true, HsEvent::WORKSAFE_PENDING, false],
    'true notified closes regulatory gate' => [true, HsEvent::WORKSAFE_NOTIFIED, true],
    'true acknowledged closes regulatory gate' => [true, HsEvent::WORKSAFE_ACKNOWLEDGED, true],
]);
```

Also prove:

- reason, actor, time, and source are mandatory/persisted;
- `hazards.manage` is required;
- cross-site and cross-tenant access fails;
- changing a notified event to false is rejected;
- retrying the same decision is safe and audited once per actual change;
- setting true initializes `worksafe_status=pending`;
- setting false clears only pending-only state;
- notify cannot run while undecided or explicitly false;
- close blocker includes the direct WorkSafe action URL.

- [ ] **Step 2: Run focused tests and observe failures**

```powershell
php artisan test tests/Feature/HealthSafety/HsEventWorksafeTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsWorksafeDecisionAuthorizationTest.php
```

Expected: new tests fail for the missing endpoint and false-completion gate.

- [ ] **Step 3: Implement request validation**

```php
public function rules(): array
{
    return [
        'notifiable' => ['required', 'boolean'],
        'reason' => ['required', 'string', 'min:10', 'max:2000'],
        'source' => ['nullable', Rule::in(['manual', 'incident_report', 'classifier'])],
    ];
}
```

- [ ] **Step 4: Implement the transactional service operation**

```php
public function recordWorksafeDecision(
    HsEvent $event,
    bool $notifiable,
    string $reason,
    User $actor,
    string $source = 'manual',
): HsEvent {
    return DB::transaction(function () use ($event, $notifiable, $reason, $actor, $source) {
        $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);

        if ($locked->worksafe_notifiable === true
            && in_array($locked->worksafe_status, [HsEvent::WORKSAFE_NOTIFIED, HsEvent::WORKSAFE_ACKNOWLEDGED], true)
            && ! $notifiable) {
            throw new DomainException('A notified WorkSafe event cannot be changed to not notifiable.');
        }

        $before = $this->worksafeDecisionSnapshot($locked);
        $locked->forceFill([
            'worksafe_notifiable' => $notifiable,
            'worksafe_status' => $notifiable ? ($locked->worksafe_status ?: HsEvent::WORKSAFE_PENDING) : null,
            'worksafe_decided_at' => now(),
            'worksafe_decided_by_user_id' => $actor->id,
            'worksafe_decision_reason' => trim($reason),
            'worksafe_decision_source' => $source,
        ])->save();

        AuditLogger::logOrFail('healthSafety.event.worksafeDecisionRecorded', $locked, [
            'before' => $before,
            'after' => $this->worksafeDecisionSnapshot($locked),
        ]);

        return $locked->fresh(['worksafeDecidedBy:id,name']);
    });
}
```

- [ ] **Step 5: Make the closure gate structured and truthful**

Preserve current keys while adding a `requirements` array:

```php
$worksafeOk = $event->worksafe_notifiable === false
    ? $event->worksafe_decided_at !== null && $event->worksafe_decided_by_user_id !== null
    : ($event->worksafe_notifiable === true
        && in_array($event->worksafe_status, [HsEvent::WORKSAFE_NOTIFIED, HsEvent::WORKSAFE_ACKNOWLEDGED], true));

$requirements[] = [
    'key' => 'worksafe_decision',
    'complete' => $worksafeOk,
    'label' => $event->worksafe_notifiable === null
        ? 'Record the WorkSafe notifiability decision'
        : $this->worksafeRequirementLabel($event),
    'href' => "/health-safety/events/{$event->id}?action=worksafe-decision",
];
```

- [ ] **Step 6: Add controller and route**

```php
Route::post('/events/{hsEvent}/worksafe/decision', HsWorksafeDecisionController::class)
    ->name('events.worksafe.decision');
```

The controller must load the site-scoped event, require `hazards.manage`, call the service, and return to the originating H&S detail state with a plain success message.

- [ ] **Step 7: Re-run focused tests**

```powershell
php artisan test tests/Feature/HealthSafety/HsEventWorksafeTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsWorksafeDecisionAuthorizationTest.php
```

Expected: all tests pass.

- [x] **Step 8: Commit**

```powershell
git add app/Http/Requests/HealthSafety/RecordHsWorksafeDecisionRequest.php app/Http/Controllers/HealthSafety/HsWorksafeDecisionController.php app/Services/HealthSafety/HsEventService.php app/Http/Controllers/HealthSafety/HsEventController.php routes/health-safety.php tests/Feature/HealthSafety/HsEventWorksafeTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsWorksafeDecisionAuthorizationTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(health-safety): enforce WorkSafe decision truth"
```

## Task 4: Build the WorkSafe decision and notification UI

**Files:**

- Modify: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- Modify: `resources/js/pages/health-safety/events/index.tsx`
- Modify: `resources/js/pages/health-safety/components/worklists.tsx`
- Modify: `tests/Feature/HealthSafety/HsEventRegisterTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [ ] **Step 1: Write failing presenter and component tests**

Prove all visible labels:

```tsx
expect(screen.getByText('Decision not recorded')).toBeInTheDocument();
expect(screen.getByRole('button', { name: 'Record WorkSafe decision' })).toBeEnabled();
expect(screen.getByLabelText('Notifiable')).toBeInTheDocument();
expect(screen.getByLabelText('Not notifiable')).toBeInTheDocument();
expect(screen.getByLabelText('Decision rationale')).toBeRequired();
```

Add states for:

- explicit false with actor/time/reason;
- true pending with Notify CTA;
- notified with acknowledgement CTA;
- acknowledged;
- view-only user with decision state but no mutation CTA;
- close checklist displaying the same server gate label and direct link.

- [ ] **Step 2: Run tests and confirm the current conditional UI fails**

```powershell
php artisan test tests/Feature/HealthSafety/HsEventRegisterTest.php
npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx
```

- [ ] **Step 3: Expose nullable decision truth in the event payload**

```php
'worksafe' => [
    'notifiable' => $hsEvent->worksafe_notifiable,
    'status' => $hsEvent->worksafe_status,
    'decision_reason' => $hsEvent->worksafe_decision_reason,
    'decision_source' => $hsEvent->worksafe_decision_source,
    'decided_at' => $hsEvent->worksafe_decided_at?->toIso8601String(),
    'decided_by' => $hsEvent->worksafeDecidedBy
        ? ['id' => $hsEvent->worksafeDecidedBy->id, 'name' => $hsEvent->worksafeDecidedBy->name]
        : null,
    'can_decide' => $canManage,
    'can_notify' => $canManage
        && $hsEvent->worksafe_notifiable === true
        && $hsEvent->worksafe_status === HsEvent::WORKSAFE_PENDING,
],
```

Remove boolean coercions that turn `null` into `false`.

- [ ] **Step 4: Add a focused WorkSafe pane**

The form payload must be:

```tsx
const form = useForm({
    notifiable: d.worksafe.notifiable ?? false,
    reason: d.worksafe.decision_reason ?? '',
    source: 'manual',
});

form.post(`/health-safety/events/${d.id}/worksafe/decision`);
```

Render the decision before notification controls and show the current actor/time when revising.

- [ ] **Step 5: Use the same labels in register rows and worklists**

Implement one frontend helper:

```tsx
function worksafeLabel(worksafe: WorksafeState): string {
    if (worksafe.notifiable === null) return 'Decision not recorded';
    if (worksafe.notifiable === false) return 'Not notifiable — decision recorded';
    if (worksafe.status === 'pending') return 'Notification pending';
    if (worksafe.status === 'notified') return 'Notified — acknowledgement pending';
    return 'Acknowledged';
}
```

- [ ] **Step 6: Re-run backend and frontend tests**

```powershell
php artisan test tests/Feature/HealthSafety/HsEventRegisterTest.php tests/Feature/HealthSafety/HsEventWorksafeTest.php tests/Feature/HealthSafety/HsEventClosureTest.php
npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx
```

- [ ] **Step 7: Commit**

```powershell
git add app/Http/Controllers/HealthSafety/HsEventController.php resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/events/index.tsx resources/js/pages/health-safety/components/worklists.tsx tests/Feature/HealthSafety/HsEventRegisterTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(health-safety): expose WorkSafe governance workflow"
```

## Task 5: Add corrective-action source ownership and evidence relationships

**Files:**

- Create: `database/migrations/2026_07_16_000200_link_hs_actions_to_control_room_tasks.php`
- Modify: `app/Models/HsCorrectiveAction.php`
- Modify: `app/Models/ControlRoom/AlertTask.php`
- Modify: `database/factories/HsCorrectiveActionFactory.php`
- Create: `tests/Feature/HealthSafety/HsCorrectiveActionOwnershipSchemaTest.php`
- Modify: `tests/Feature/Tasks/AllTasksIncidentJourneyTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [ ] **Step 1: Write failing relationship and uniqueness tests**

Prove:

```php
$action = HsCorrectiveAction::factory()->create([
    'source_control_room_task_id' => $task->id,
]);

expect($action->sourceControlRoomTask->is($task))->toBeTrue()
    ->and($task->transferredCorrectiveAction->is($action))->toBeTrue();
```

Attempting to link the same source task to a second action must fail at service validation and database uniqueness.

- [ ] **Step 2: Run the tests and confirm missing-column failure**

```powershell
php artisan test tests/Feature/HealthSafety/HsCorrectiveActionOwnershipSchemaTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php
```

- [ ] **Step 3: Add the reciprocal source-task link**

```php
Schema::table('hs_corrective_actions', function (Blueprint $table) {
    $table->foreignId('source_control_room_task_id')->nullable()
        ->after('hs_investigation_id')
        ->unique()
        ->constrained('control_room_alert_tasks')
        ->nullOnDelete();
});
```

- [ ] **Step 4: Add model relationships**

```php
public function sourceControlRoomTask(): BelongsTo
{
    return $this->belongsTo(AlertTask::class, 'source_control_room_task_id');
}

public function attachments(): MorphMany
{
    return $this->morphMany(HsAttachment::class, 'attachable');
}
```

`AlertTask::transferredCorrectiveAction()` must first prefer the reciprocal `source_control_room_task_id` relation and remain compatible with the existing `transferred_to_hs_corrective_action_id`.

- [ ] **Step 5: Verify migration rollback and tests**

```powershell
php artisan migrate --path=database/migrations/2026_07_16_000200_link_hs_actions_to_control_room_tasks.php
php artisan migrate:rollback --path=database/migrations/2026_07_16_000200_link_hs_actions_to_control_room_tasks.php
php artisan migrate --path=database/migrations/2026_07_16_000200_link_hs_actions_to_control_room_tasks.php
php artisan test tests/Feature/HealthSafety/HsCorrectiveActionOwnershipSchemaTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php
```

- [ ] **Step 6: Commit**

```powershell
git add database/migrations/2026_07_16_000200_link_hs_actions_to_control_room_tasks.php app/Models/HsCorrectiveAction.php app/Models/ControlRoom/AlertTask.php database/factories/HsCorrectiveActionFactory.php tests/Feature/HealthSafety/HsCorrectiveActionOwnershipSchemaTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(health-safety): link corrective actions to source tasks"
```

## Task 6: Require an eligible owner, due date, and explicit responsibility choice

**Files:**

- Create: `app/Http/Requests/HealthSafety/StoreHsCorrectiveActionRequest.php`
- Create: `app/Http/Requests/HealthSafety/CreateHsCorrectiveActionFromRecommendationRequest.php`
- Modify: `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php`
- Modify: `app/Http/Controllers/HealthSafety/HsInvestigationController.php`
- Modify: `app/Http/Controllers/IncidentController.php`
- Modify: `app/Services/HealthSafety/HsCorrectiveActionService.php`
- Modify: `app/Services/ControlRoom/ControlRoomAlertLifecycleService.php`
- Modify: `app/Services/HealthSafety/HsInvestigationService.php`
- Modify: `app/Services/UserSiteAccessService.php`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/incidents/incident-detail-dialog.tsx`
- Modify: `resources/js/components/incidents/incident-detail-dialog.test.tsx`
- Modify: `tests/Feature/HealthSafety/HsCorrectiveActionTest.php`
- Modify: `tests/Feature/HealthSafety/HsRecommendationDispositionTest.php`
- Modify: `tests/Feature/HealthSafety/HsEventClosureTest.php`
- Modify: `tests/Feature/HealthSafety/HsEventSiteIsolationTest.php`
- Modify: `tests/Feature/HealthSafety/HsEventWorkflowTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php`
- Modify: `tests/Feature/Tasks/AllTasksIncidentJourneyTest.php`
- Modify: `tests/Feature/IncidentControllerTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing creation-contract tests**

Every new action path must reject:

- missing owner;
- missing due date;
- ineligible owner;
- owner from another tenant/site;
- recommendation creation with neither `transfer_task` nor `new_responsibility`;
- `new_responsibility` without a reason;
- source task from another alert/journey;
- transfer before H&S acceptance.

Retry test:

```php
$first = $service->createFromRecommendation($investigation, 0, $payload, $actor);
$retry = $service->createFromRecommendation($investigation, 0, $payload, $actor);

expect($retry->id)->toBe($first->id)
    ->and($task->fresh()->status)->toBe(AlertTask::STATUS_TRANSFERRED);
```

- [x] **Step 2: Run focused tests**

```powershell
php artisan test tests/Feature/HealthSafety/HsCorrectiveActionTest.php tests/Feature/HealthSafety/HsRecommendationDispositionTest.php tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php
```

Expected: owner/source-choice tests fail.

- [x] **Step 3: Add strict request contracts**

```php
return [
    'assigned_to_user_id' => ['required', 'integer'],
    'due_date' => ['required', 'date_format:Y-m-d'],
    'priority' => ['required', Rule::in([
        HsCorrectiveAction::PRIORITY_LOW,
        HsCorrectiveAction::PRIORITY_MEDIUM,
        HsCorrectiveAction::PRIORITY_HIGH,
        HsCorrectiveAction::PRIORITY_CRITICAL,
    ])],
    'responsibility_choice' => ['required', Rule::in(['transfer_task', 'new_responsibility'])],
    'source_control_room_task_id' => ['required_if:responsibility_choice,transfer_task', 'nullable', 'integer'],
    'new_responsibility_reason' => ['required_if:responsibility_choice,new_responsibility', 'nullable', 'string', 'min:10', 'max:1000'],
];
```

- [x] **Step 4: Make creation atomic and retry-safe**

Use one transactional service entry:

```php
public function createFromRecommendation(
    HsInvestigation $investigation,
    int $recommendationIndex,
    array $data,
    User $actor,
): HsCorrectiveAction
```

Inside the transaction:

1. lock the investigation and recommendation disposition;
2. return the existing action for the same recommendation on an exact retry;
3. require accepted H&S handover when incident-backed;
4. validate owner site eligibility;
5. lock the selected `AlertTask`;
6. reject a task already transferred to another action;
7. create the assigned action with due date and source link;
8. set the existing task transfer columns and `status=transferred`;
9. create/update the recommendation disposition;
10. write reciprocal audit entries and notify the owner.

Use:

```php
$task->forceFill([
    'status' => AlertTask::STATUS_TRANSFERRED,
    'transferred_to_hs_corrective_action_id' => $action->id,
    'transferred_at' => now(),
    'transferred_by_user_id' => $actor->id,
])->save();
```

- [x] **Step 5: Remove ownerless defaults from standalone and bulk creation**

Do not silently suggest an owner. `bulkCreateFromRecommendations()` must accept an explicit per-recommendation assignment map or reject the call; update every current caller/test fixture.

- [x] **Step 6: Re-run focused tests**

```powershell
php artisan test tests/Feature/HealthSafety/HsCorrectiveActionTest.php tests/Feature/HealthSafety/HsRecommendationDispositionTest.php tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php
```

Expected: all creation, transfer, duplicate-prevention, and authorization tests pass.

- [x] **Step 7: Commit**

```powershell
git add app/Http/Requests/HealthSafety/StoreHsCorrectiveActionRequest.php app/Http/Requests/HealthSafety/CreateHsCorrectiveActionFromRecommendationRequest.php app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php app/Services/HealthSafety/HsCorrectiveActionService.php app/Services/ControlRoom/ControlRoomAlertLifecycleService.php app/Services/HealthSafety/HsInvestigationService.php tests/Feature/HealthSafety/HsCorrectiveActionTest.php tests/Feature/HealthSafety/HsRecommendationDispositionTest.php tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(health-safety): require explicit corrective action ownership"
```

## Task 7: Build the corrective-action ownership handover UI

**Files:**

- Modify: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- Modify: `resources/js/pages/health-safety/corrective-actions/index.tsx`
- Create: `resources/js/components/health-safety/corrective-action-handover-pane.tsx`
- Create: `resources/js/components/health-safety/corrective-action-handover-pane.test.tsx`
- Modify: `tests/Feature/HealthSafety/HsRecommendationDispositionTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing UI tests**

Prove the pane:

- displays recommendation text;
- requires a site-scoped owner;
- requires `YYYY-MM-DD` due date;
- shows unresolved linked Control Room tasks;
- requires transfer vs new responsibility;
- requires a reason for new work;
- displays the selected owner in the final review;
- cannot submit until all required fields are valid;
- sends the exact selected user and task IDs.

Example:

```tsx
await user.click(screen.getByRole('combobox', { name: 'Action owner' }));
await user.click(screen.getByRole('option', { name: 'Playwright Incident Reviewer' }));
await user.click(screen.getByLabelText('Transfer this operational task'));
expect(screen.getByRole('button', { name: 'Create and hand over action' })).toBeEnabled();
```

- [x] **Step 2: Run tests and prove missing UI**

```powershell
npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/health-safety/corrective-action-handover-pane.test.tsx
php artisan test tests/Feature/HealthSafety/HsRecommendationDispositionTest.php
```

- [x] **Step 3: Expose eligible owners and unresolved source tasks**

Payload shape:

```php
'action_handover' => [
    'eligible_owners' => $eligibleOwners,
    'unresolved_control_room_tasks' => $sourceTasks->map(fn (AlertTask $task) => [
        'id' => $task->id,
        'title' => $task->title,
        'description' => $task->description,
        'status' => $task->status,
        'due_at' => $task->due_at?->toIso8601String(),
    ])->values(),
],
```

- [x] **Step 4: Implement the focused pane**

Post:

```tsx
form.post(
    `/health-safety/events/${event.id}/investigations/${investigation.id}/seed-action`,
    { preserveScroll: true },
);
```

The form payload includes `recommendation_index: recommendationIndex` alongside the owner, due date, priority, responsibility choice, selected source task, and new-responsibility reason.

After success, refresh the event detail and show:

`Assigned to {owner} · due {date} · source task transferred to {reference}`

- [x] **Step 5: Make the register show ownership and source**

The corrective-actions register row/detail must show owner, due date, recommendation, and:

- `Transferred from Control Room task: {title}`; or
- `New responsibility: {reason}`.

- [x] **Step 6: Re-run tests**

```powershell
npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/health-safety/corrective-action-handover-pane.test.tsx
php artisan test tests/Feature/HealthSafety/HsRecommendationDispositionTest.php tests/Feature/HealthSafety/HsCorrectiveActionsRegisterTest.php
```

- [x] **Step 7: Commit**

```powershell
git add app/Http/Controllers/HealthSafety/HsEventController.php resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/corrective-actions/index.tsx resources/js/components/health-safety/corrective-action-handover-pane.tsx resources/js/components/health-safety/corrective-action-handover-pane.test.tsx tests/Feature/HealthSafety/HsRecommendationDispositionTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(health-safety): add corrective action handover"
```

## Task 8: Add private corrective-action evidence upload, download, and removal

**Files:**

- Create: `app/Http/Requests/HealthSafety/UploadHsCorrectiveActionEvidenceRequest.php`
- Create: `app/Http/Controllers/HealthSafety/HsCorrectiveActionEvidenceController.php`
- Modify: `app/Models/HsCorrectiveAction.php`
- Modify: `routes/health-safety.php`
- Create: `tests/Feature/HealthSafety/HsCorrectiveActionEvidenceTest.php`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- Modify: `resources/js/pages/health-safety/corrective-actions/index.tsx`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing storage and authorization tests**

Cover:

- PDF, JPEG, PNG, WEBP, DOC, and DOCX accepted;
- SVG, HTML, executable, and oversized files rejected;
- files use the `private` disk;
- original name, generated path, MIME, size, uploader, and description persist;
- action owner and authorised H&S staff can download;
- unrelated site/tenant users cannot download;
- removal works only before verified/closed;
- database failure deletes the newly stored file;
- missing storage file returns 404 and does not expose a path.

- [x] **Step 2: Run the tests**

```powershell
php artisan test tests/Feature/HealthSafety/HsCorrectiveActionEvidenceTest.php
```

Expected: missing request/controller/routes fail.

- [x] **Step 3: Implement strict upload validation**

```php
return [
    'file' => [
        'required',
        'file',
        'max:10240',
        'mimes:pdf,jpg,jpeg,png,webp,doc,docx',
    ],
    'description' => ['nullable', 'string', 'max:500'],
];
```

- [x] **Step 4: Implement private storage with compensation**

```php
$path = $request->file('file')->store(
    "health-safety/corrective-actions/{$action->id}",
    'private',
);

try {
    $attachment = $action->attachments()->create([
        'uploaded_by' => $request->user()->id,
        'original_name' => $request->file('file')->getClientOriginalName(),
        'path' => $path,
        'disk' => 'private',
        'mime_type' => $request->file('file')->getMimeType(),
        'size_bytes' => $request->file('file')->getSize(),
        'description' => $request->string('description')->trim()->value() ?: null,
    ]);
} catch (Throwable $error) {
    Storage::disk('private')->delete($path);
    throw $error;
}
```

Download response must include `X-Content-Type-Options: nosniff`.

- [x] **Step 5: Add routes**

```php
Route::post('/events/{event}/corrective-actions/{action}/evidence', [HsCorrectiveActionEvidenceController::class, 'store'])
    ->name('events.corrective-actions.evidence.store');
Route::get('/events/{event}/corrective-actions/{action}/evidence/{attachment}', [HsCorrectiveActionEvidenceController::class, 'download'])
    ->name('events.corrective-actions.evidence.download');
Route::delete('/events/{event}/corrective-actions/{action}/evidence/{attachment}', [HsCorrectiveActionEvidenceController::class, 'destroy'])
    ->name('events.corrective-actions.evidence.destroy');
```

Place these routes in the authenticated H&S route group rather than the blanket `hazards.manage` group. The controller must authorize each operation against site access, action ownership, H&S management permission, and current lifecycle state so the assigned action owner can upload evidence without receiving governance-wide mutation rights.

- [x] **Step 6: Add upload/remove UI**

Show retained files before completion:

```tsx
<input
    aria-label="Add completion evidence"
    type="file"
    accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
    multiple
/>
```

Display per-file upload state, authenticated download, and removal. A failed upload must not clear completion notes.

- [x] **Step 7: Re-run backend and frontend tests**

```powershell
php artisan test tests/Feature/HealthSafety/HsCorrectiveActionEvidenceTest.php
npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx
```

- [x] **Step 8: Commit**

```powershell
git add app/Http/Requests/HealthSafety/UploadHsCorrectiveActionEvidenceRequest.php app/Http/Controllers/HealthSafety/HsCorrectiveActionEvidenceController.php app/Models/HsCorrectiveAction.php routes/health-safety.php tests/Feature/HealthSafety/HsCorrectiveActionEvidenceTest.php resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/corrective-actions/index.tsx docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(health-safety): add private action evidence"
```

## Task 9: Make completion, rework, resubmission, and verification evidence-complete

**Files:**

- Modify: `app/Services/HealthSafety/HsCorrectiveActionService.php`
- Modify: `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php`
- Modify: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Modify: `app/Models/HsCorrectiveAction.php`
- Create: `app/Support/HealthSafety/HsCorrectiveActionPresenter.php`
- Create: `app/Support/HealthSafety/HsCorrectiveActionActivityLabels.php`
- Modify: `tests/Feature/HealthSafety/HsCorrectiveActionTest.php`
- Create: `tests/Feature/HealthSafety/HsCorrectiveActionPresentationTest.php`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- Modify: `resources/js/pages/health-safety/corrective-actions/index.tsx`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing lifecycle and payload tests**

Prove:

- completion requires notes or one retained attachment;
- completion records completer and time;
- return for rework preserves the submitted evidence;
- owner sees latest return reason;
- resubmission records a distinct history entry;
- verifier sees recommendation, source task, owner, due date, completion notes, attachments, legacy paths, completer/time, return reason, and history;
- `evidence_reviewed=true` is required;
- evidence-load failure disables verification in UI;
- owner cannot verify their own action;
- removal is blocked once verified/closed.

Expected payload fragment:

```php
->where('detail.corrective_actions.0.evidence.completion_notes', 'Installed anti-slip surfacing.')
->where('detail.corrective_actions.0.evidence.attachments.0.original_name', 'after-photo.jpg')
->where('detail.corrective_actions.0.rework.latest_reason', 'Add a wider-angle photo.')
->where('detail.corrective_actions.0.history.0.label', 'Action returned for rework');
```

- [x] **Step 2: Run tests and confirm the current presenter fails**

```powershell
php artisan test tests/Feature/HealthSafety/HsCorrectiveActionTest.php tests/Feature/HealthSafety/HsCorrectiveActionPresentationTest.php
npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx
```

- [x] **Step 3: Require evidence acknowledgement server-side**

Controller validation:

```php
'evidence_reviewed' => ['accepted'],
'effective' => ['required', 'boolean'],
'verification_notes' => ['nullable', 'string', 'max:2000'],
```

Service guard:

```php
if (! ($data['evidence_reviewed'] ?? false)) {
    throw ValidationException::withMessages([
        'evidence_reviewed' => 'Review the owner submission before verifying this action.',
    ]);
}
```

- [x] **Step 4: Build one presenter for event and register surfaces**

```php
return [
    'id' => $action->id,
    'reference_number' => $action->reference_number,
    'owner' => $this->user($action->assignedTo),
    'due_date' => $action->due_date?->format('Y-m-d'),
    'recommendation' => $this->recommendation($action),
    'source_task' => $this->sourceTask($action),
    'evidence' => [
        'completion_notes' => $action->completion_notes,
        'attachments' => $this->attachments($action),
        'legacy_paths' => $action->completion_evidence_paths ?? [],
        'completed_by' => $this->user($action->completedBy),
        'completed_at' => $action->completed_at?->toIso8601String(),
        'load_state' => 'loaded',
    ],
    'rework' => ['latest_reason' => $action->verification_notes],
    'history' => $this->history($action),
];
```

Map audit actions to labels such as:

- `Action created`;
- `Owner started action`;
- `Owner submitted evidence`;
- `Action returned for rework`;
- `Owner resubmitted evidence`;
- `Action independently verified`;
- `Action closed`.

- [x] **Step 5: Rebuild the verifier pane in evidence-first order**

Sections:

1. What was required;
2. What the owner submitted;
3. Prior rework and resubmission;
4. Verifier decision.

Enable Verify only when:

```tsx
const canVerify =
    action.evidence.load_state === 'loaded' &&
    evidenceReviewed &&
    detail.can.verify_corrective_actions;
```

- [x] **Step 6: Re-run focused tests**

```powershell
php artisan test tests/Feature/HealthSafety/HsCorrectiveActionTest.php tests/Feature/HealthSafety/HsCorrectiveActionPresentationTest.php tests/Feature/HealthSafety/HsCorrectiveActionsRegisterTest.php
npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx
```

- [ ] **Step 7: Commit**

```powershell
git add app/Services/HealthSafety/HsCorrectiveActionService.php app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php app/Http/Controllers/HealthSafety/HsEventController.php app/Support/HealthSafety/HsCorrectiveActionPresenter.php app/Support/HealthSafety/HsCorrectiveActionActivityLabels.php tests/Feature/HealthSafety/HsCorrectiveActionTest.php tests/Feature/HealthSafety/HsCorrectiveActionPresentationTest.php resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/corrective-actions/index.tsx docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(health-safety): make verification evidence complete"
```

## Task 10: Make Universal Tasks truthful and findable

**Files:**

- Modify: `app/Services/Tasks/TaskItem.php`
- Modify: `app/Services/Tasks/TaskAggregator.php`
- Modify: `app/Services/Tasks/Providers/HsCorrectiveActionProvider.php`
- Modify: `app/Services/Tasks/Providers/ControlRoomAlertProvider.php`
- Modify: `app/Services/Tasks/Providers/ClientIncidentProvider.php`
- Modify: `app/Services/Tasks/Providers/IncidentFollowupProvider.php`
- Modify: `app/Services/Tasks/Providers/HsEventProvider.php`
- Modify: `app/Services/Tasks/Providers/HsInvestigationProvider.php`
- Modify: `app/Services/Tasks/IncidentJourneyTaskContext.php`
- Create: `app/Services/Tasks/TaskSearch.php`
- Modify: `app/Http/Controllers/AllTasksController.php`
- Modify: `resources/js/pages/tasks/types.ts`
- Modify: `resources/js/pages/tasks/index.tsx`
- Modify: `resources/js/pages/tasks/task-detail-dialog.tsx`
- Create: `resources/js/pages/tasks/tasks-incident-journey.test.tsx`
- Modify: `tests/Feature/Tasks/AllTasksIncidentJourneyTest.php`
- Modify: `tests/Feature/Tasks/AllTasksDashboardTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing status, bucket, and search tests**

Backend tests must assert:

```php
expect($item->status)->toBe(HsCorrectiveAction::STATUS_COMPLETED)
    ->and($item->displayState)->toBe('Awaiting independent verification')
    ->and($item->bucket)->toBe(TaskItem::BUCKET_IN_PROGRESS);
```

Search must find the journey by:

- `Playwright Aroha Handover`;
- `Playwright Incident Handover House`;
- exact source Control Room task title;
- source task description;
- owner name;
- CR, INC, HS, INV, and CA references;
- incident narrative;
- `awaiting independent verification`.

Closed actions alone belong in done/history.

- [x] **Step 2: Run backend and frontend tests**

```powershell
php artisan test tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/Tasks/AllTasksDashboardTest.php
npx vitest run resources/js/pages/tasks/tasks-incident-journey.test.tsx
```

- [x] **Step 3: Add explicit display and search fields to `TaskItem`**

```php
public function __construct(
    // existing arguments
    public ?string $displayState = null,
    public array $searchTerms = [],
    public ?string $actionHelp = null,
) {}
```

Serialize `displayState` and `actionHelp` in `toArray()`. Keep `searchTerms`
server-side only and remove journey `search_terms` before serialization so
private narrative, witness, consequence, source-task, and owner search material
never enters an Inertia or detail payload.

- [x] **Step 4: Map corrective-action lifecycle truth**

```php
displayState: match ($action->status) {
    HsCorrectiveAction::STATUS_OPEN => 'Not started',
    HsCorrectiveAction::STATUS_IN_PROGRESS => 'In progress',
    HsCorrectiveAction::STATUS_COMPLETED => 'Awaiting independent verification',
    HsCorrectiveAction::STATUS_VERIFIED => 'Verified — ready to close',
    HsCorrectiveAction::STATUS_CLOSED => 'Closed',
},
```

Keep `completed` and `verified` in `BUCKET_IN_PROGRESS`.

- [x] **Step 5: Expand the search haystack**

```php
$haystack = strtolower(implode(' ', array_filter([
    $item->ref,
    $journeyReferences,
    $item->title,
    $item->description,
    $item->sourceContext,
    $item->displayState,
    data_get($item->client, 'name'),
    data_get($item->site, 'name'),
    data_get($item->assignee, 'name'),
    ...$item->searchTerms,
])));
```

Populate source task title/description and incident narrative in the journey task context, not through new database columns.
Merge every responsibility owner into that shared server-side journey haystack,
so searching any Control Room, incident, follow-up, investigation, or corrective
action owner returns the complete journey.

Normal dashboard feeds remain capped at 300 rows per provider and omit private
search-only relationship graphs. When `q` is nonblank, only the six audited
incident-journey providers use the exhaustive path. `TaskSearch` pushes the
shared journey predicate into SQL before hydration, and the controller passes
any selected source filter into that deep pass instead of re-running unrelated
providers. Stable dashboard stats continue to use the normal capped feed.

- [x] **Step 6: Render `displayState` everywhere**

Replace `humanise(item.status)` for the primary lifecycle label with:

```tsx
item.displayState ?? humanise(item.status)
```

The row, detail dialog, stats, and history tab must agree.

- [x] **Step 7: Re-run tests**

```powershell
php artisan test tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/Tasks/AllTasksDashboardTest.php
npx vitest run resources/js/pages/tasks/tasks-incident-journey.test.tsx
```

Final Task 10 result: 24 backend tests passed with 223 assertions and 3
frontend tests passed. TypeScript, targeted ESLint, Prettier, Pint, PHP syntax,
and diff integrity are clean.

- [x] **Step 8: Commit**

```powershell
git add app/Http/Controllers/AllTasksController.php app/Services/Tasks app/Services/Tasks/Providers resources/js/pages/tasks/types.ts resources/js/pages/tasks/index.tsx resources/js/pages/tasks/task-detail-dialog.tsx resources/js/pages/tasks/tasks-incident-journey.test.tsx tests/Feature/Tasks/AllTasksIncidentJourneyTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md docs/superpowers/plans/2026-07-16-control-room-hs-remediation.md
git commit -m "feat(tasks): show truthful journey responsibility state"
```

## Task 11: Align Universal Tasks actions with authorization and restore context

**Files:**

- Create: `app/Services/ControlRoom/ControlRoomAlertAccessService.php`
- Create: `app/Exceptions/RecoverableTaskAuthorizationException.php`
- Modify: `app/Services/Tasks/Providers/ControlRoomAlertProvider.php`
- Create: `app/Services/Tasks/Contracts/ProvidesTaskSourceAliases.php`
- Modify: `app/Services/Tasks/Contracts/TaskProvider.php`
- Modify: `app/Services/Tasks/TaskAggregator.php`
- Modify: `app/Services/Tasks/TaskAssignmentNotifier.php`
- Modify: `app/Services/Tasks/TaskItem.php`
- Modify: `app/Models/TaskWatcher.php`
- Modify: all registered `app/Services/Tasks/Providers/*Provider.php` exact-record queries
- Modify: `app/Console/Commands/EscalateOverdueTasks.php`
- Modify: `app/Services/ControlRoom/AlertWorkspaceService.php`
- Modify: `app/Http/Controllers/ControlRoom/Concerns/AuthorizesControlRoomAlertAccess.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`
- Modify: `app/Http/Controllers/AllTasksController.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/control-room.php`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.tsx`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.test.tsx`
- Modify: `resources/js/pages/control-room/show.tsx`
- Create: `resources/js/pages/control-room/show.test.tsx`
- Modify: `resources/js/pages/tasks/index.tsx`
- Modify: `resources/js/pages/tasks/task-detail-dialog.tsx`
- Modify: `resources/js/pages/tasks/types.ts`
- Create: `resources/js/pages/tasks/task-detail-dialog.test.tsx`
- Modify: `tests/Feature/Tasks/AllTasksIncidentJourneyTest.php`
- Create: `tests/Feature/Tasks/AllTasksPermissionRecoveryTest.php`
- Modify: `tests/Feature/Tasks/TaskWatchersTest.php`
- Modify: `tests/Feature/Tasks/EscalateOverdueTasksTest.php`
- Modify: `tests/Feature/FleetAssets/FleetMaintenanceWiringTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomAlertNestedProvenanceTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing permission-parity tests**

Prove:

- manager/operator receives `Continue Control Room response`;
- view-only worker receives `View alert`;
- user without a readable destination receives no link, `No action for you`, and current owner guidance;
- Watch, Assign, Continue, Review, and governance actions are absent when unauthorized;
- a permission revoked between list and click returns to `/tasks` with `q`, module, bucket, and other filters preserved plus a plain message;
- no tested path ends on a bare 403.

- [x] **Step 2: Write failing focus and Back tests**

```tsx
const rowButton = screen.getByRole('button', { name: /Open CR-2026-2135/ });
await user.click(rowButton);
await user.keyboard('{Escape}');
expect(rowButton).toHaveFocus();
```

Also prove Close restores focus and browser Back returns to the same filtered URL.

- [x] **Step 3: Run tests**

```powershell
php artisan test tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/Tasks/AllTasksPermissionRecoveryTest.php
npx vitest run resources/js/pages/tasks/task-detail-dialog.test.tsx
```

- [x] **Step 4: Centralize the Control Room access decision**

```php
public function destinationFor(ControlRoomAlert $alert, User $user): array
{
    if ($this->canManage($alert, $user)) {
        return ['href' => "/control-room/alerts/{$alert->id}", 'label' => 'Continue Control Room response'];
    }

    if ($this->canView($alert, $user)) {
        return ['href' => "/control-room/alerts/{$alert->id}", 'label' => 'View alert'];
    }

    return ['href' => null, 'label' => 'No action for you'];
}
```

Use the same service from the provider, workspace payload, and controller concern.

- [x] **Step 5: Preserve task queue context**

Add `return_to` containing only an internal `/tasks` URL with validated query keys. On authorization drift:

```php
throw new RecoverableTaskAuthorizationException(
    returnTo: $returnTo,
    message: 'Your access changed. The item is still listed, but you can no longer open that Control Room response.',
);
```

- [x] **Step 6: Register a narrowly scoped exception renderer**

In `bootstrap/app.php`:

```php
$exceptions->render(function (
    RecoverableTaskAuthorizationException $exception,
    Request $request,
) {
    if (! $request->expectsJson()) {
        return redirect($exception->returnTo)
            ->with('error', $exception->getMessage());
    }

    return response()->json(['message' => $exception->getMessage()], 403);
});
```

The exception constructor must accept only a validated internal `/tasks` URL. All unrelated authorization failures retain the normal 403 behavior.

- [x] **Step 7: Restore focus explicitly**

Keep a ref to the invoking row and pass it into the dialog:

```tsx
onOpenChange={(open) => {
    setOpen(open);
    if (!open) requestAnimationFrame(() => triggerRef.current?.focus());
}}
```

Do not focus `document.body`.

- [x] **Step 8: Re-run tests**

```powershell
php artisan test tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/Tasks/AllTasksPermissionRecoveryTest.php
npx vitest run resources/js/pages/tasks/task-detail-dialog.test.tsx resources/js/pages/tasks/tasks-incident-journey.test.tsx
```

- [x] **Step 9: Commit**

```powershell
git add app/Services/ControlRoom/ControlRoomAlertAccessService.php app/Exceptions/RecoverableTaskAuthorizationException.php app/Services/Tasks/Providers/ControlRoomAlertProvider.php app/Services/ControlRoom/AlertWorkspaceService.php app/Http/Controllers/ControlRoom/Concerns/AuthorizesControlRoomAlertAccess.php app/Http/Controllers/AllTasksController.php bootstrap/app.php resources/js/pages/tasks/index.tsx resources/js/pages/tasks/task-detail-dialog.tsx resources/js/pages/tasks/types.ts resources/js/pages/tasks/task-detail-dialog.test.tsx tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/Tasks/AllTasksPermissionRecoveryTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "fix(tasks): align actions with role permissions"
```

Final Task 11 result:

- the Control Room provider, alert workspace, deep-link controller, and Universal Tasks drawer share one permission/site decision;
- role-aware actions are `Continue Control Room response`, `View alert`, or `No action for you` with owner guidance;
- validated internal `return_to` recovery preserves the exact filtered task URL without weakening unrelated 403 responses;
- Escape and Close restore the invoking row, while the alert page and browser Back return to the same filtered queue;
- exact-record authorization is independent of provider feed caps and search predicates across all 23 providers;
- watcher mutation, detail, assignment notification, and overdue notification paths enforce current per-record access, prune stale rows only on write/notification paths, keep restricted own-follow state private but usable, and allow unfollow after permission revocation;
- the composite Fleet provider uses stable subtype identities for colliding numeric IDs and deterministic legacy ownership that cannot change with lifecycle or due-window state.

Final verification on 2026-07-17:

- backend: 177 tests passed with 1,246 assertions across the 11-file Control Room, Universal Tasks, watcher, escalation, incident-journey, and Fleet regression matrix;
- frontend: 4 files passed with 12 tests;
- TypeScript, targeted ESLint, Prettier, Pint, PHP syntax across 43 changed/new files, and `git diff --check` are clean;
- independent review iterated through every authorization, watcher, cap, composite-identity, lifecycle, privacy, and focus finding; final re-review returned no findings.

## Task 12: Capture typed immediate controls and require them for serious incidents

**Files:**

- Create: `database/migrations/2026_07_16_000300_add_purpose_to_control_room_operator_notes.php`
- Modify: `app/Models/ControlRoom/OperatorNote.php`
- Modify: `app/Services/ControlRoom/ControlRoomAlertLifecycleService.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomShiftController.php`
- Modify: `app/Services/ControlRoom/AlertWorkspaceService.php`
- Modify: `app/Services/ControlRoom/SensorIncidentBridgeService.php`
- Modify: `app/Services/Incidents/IncidentJourneyService.php`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.tsx`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.test.tsx`
- Modify: `resources/js/components/control-room/alert-workspace/linked-journey.tsx`
- Modify: `resources/js/components/control-room/flag-incident-dialog.tsx`
- Create: `resources/js/components/control-room/flag-incident-dialog.test.tsx`
- Modify: `tests/Feature/ControlRoom/ControlRoomAlertControllerTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomIncidentControllerTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomJourneyAuthorizationTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomSafetyHandoverTest.php`
- Modify: `tests/Feature/ControlRoom/SensorIncidentJourneyTest.php`
- Modify: `tests/Feature/Incidents/IncidentJourneyServiceTest.php`
- Create: `tests/Unit/ControlRoom/OperatorNotePurposeMigrationTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing typed-note and incident tests**

Prove:

- note purpose accepts `general`, `immediate_controls`, `escalation_handover`;
- latest immediate-controls note is deterministic by creation time/id;
- high/critical incident creation pre-fills from that note;
- high/critical submission without immediate action is rejected;
- operator may edit the prefill;
- explicit `No immediate control was possible` text is accepted when that is the recorded truth;
- low/medium incident behavior remains valid;
- site/tenant scope remains enforced.

- [x] **Step 2: Run focused tests**

```powershell
php artisan test tests/Feature/ControlRoom/ControlRoomIncidentControllerTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php
npx vitest run resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/control-room/flag-incident-dialog.test.tsx
```

- [x] **Step 3: Add the note purpose column**

```php
Schema::table('control_room_operator_notes', function (Blueprint $table) {
    $table->string('purpose', 32)->default('general')->after('type')->index();
});
```

Map existing `escalation` and `handover` types to `escalation_handover`; leave other history as `general`.

- [x] **Step 4: Persist note purpose**

```php
public function appendOperatorNote(
    ControlRoomAlert $alert,
    User $actor,
    string $content,
    string $type = 'note',
    string $purpose = 'general',
): OperatorNote
```

Validate the supported values and audit the purpose.

- [x] **Step 5: Prefill and validate incident immediate action**

Presenter:

```php
'incident_defaults' => [
    'immediate_action_taken' => $alert->operatorNotes()
        ->where('purpose', 'immediate_controls')
        ->latest('created_at')
        ->latest('id')
        ->value('content') ?? '',
],
```

Request rule:

```php
'immediate_action_taken' => [
    Rule::requiredIf(fn () => in_array($alert->severity, ['critical', 'high'], true)),
    'nullable',
    'string',
    'max:5000',
],
```

- [x] **Step 6: Add purpose selection and prefill notice in the UI**

Use plain options:

- General update;
- Immediate controls;
- Escalation or handover.

The incident form must say where the prefill came from and allow edits before submission.

- [x] **Step 7: Re-run tests and migration rollback**

```powershell
php artisan migrate --path=database/migrations/2026_07_16_000300_add_purpose_to_control_room_operator_notes.php
php artisan test tests/Feature/ControlRoom/ControlRoomIncidentControllerTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php
npx vitest run resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/control-room/flag-incident-dialog.test.tsx
```

- [x] **Step 8: Commit**

```powershell
git add database/migrations/2026_07_16_000300_add_purpose_to_control_room_operator_notes.php app/Models/ControlRoom/OperatorNote.php app/Services/ControlRoom/ControlRoomAlertLifecycleService.php app/Http/Controllers/ControlRoom/ControlRoomAlertController.php app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php app/Http/Controllers/ControlRoom/ControlRoomShiftController.php app/Services/ControlRoom/AlertWorkspaceService.php app/Services/ControlRoom/SensorIncidentBridgeService.php app/Services/Incidents/IncidentJourneyService.php resources/js/components/control-room/alert-workspace-dialog.tsx resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/control-room/alert-workspace/linked-journey.tsx resources/js/components/control-room/flag-incident-dialog.tsx resources/js/components/control-room/flag-incident-dialog.test.tsx tests/Feature/ControlRoom/ControlRoomAlertControllerTest.php tests/Feature/ControlRoom/ControlRoomIncidentControllerTest.php tests/Feature/ControlRoom/ControlRoomJourneyAuthorizationTest.php tests/Feature/ControlRoom/ControlRoomSafetyHandoverTest.php tests/Feature/ControlRoom/SensorIncidentJourneyTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php tests/Unit/ControlRoom/OperatorNotePurposeMigrationTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md docs/superpowers/plans/2026-07-16-control-room-hs-remediation.md
git commit -m "feat(control-room): carry immediate controls into incidents"
```

Task 12 final verification:

- the typed-note and serious-incident regressions were observed red before implementation;
- alert notes now persist one first-class `OperatorNote` row with a validated purpose, purpose-aware audit metadata, and a compatible activity-log entry;
- the latest `immediate_controls` note is selected deterministically by `created_at` then `id`, with author/time provenance in the workspace payload;
- high/critical alert incident creation, serious quick flags, and serious sensor confirmation require an explicit immediate-action truth at request boundaries and from effective submitted severity at canonical journey boundaries, with a critical-alert floor;
- `No immediate control was possible` is accepted as explicit truth, while low/medium behavior remains valid;
- the workspace create-incident and sensor panes expose an editable prefill and remove the former empty-payload handover shortcut;
- alternate attach paths enforce the same invariant, submitted incidents permit only missing-field repair without overwriting existing controls, and reviewed/closed link-only retries remain immutable;
- the migration backfill, index, rollback, and reapply pass in an isolated database test;
- the authoritative affected backend matrix passes 161 tests with 1,404 assertions;
- frontend verification passes 2 files with 11 tests; TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, client and SSR production builds, and diff integrity are clean;
- independent review iterated through effective severity, alternate attach paths, submitted repair, missing-source copy, and the critical-alert floor; final re-review returned no findings.

## Task 13: Present linked Control Room evidence in Incident and H&S

**Files:**

- Modify: `app/Services/Incidents/IncidentJourneyPresenter.php`
- Modify: `app/Http/Controllers/IncidentController.php`
- Modify: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Modify: `app/Services/ControlRoom/AlertWorkspaceService.php`
- Create: `app/Support/Incidents/LinkedOperationalEvidencePresenter.php`
- Modify: `routes/incidents.php`
- Modify: `tests/Feature/Incidents/IncidentJourneyPresenterTest.php`
- Verify: `tests/Feature/Incidents/IncidentJourneyServiceTest.php`
- Create: `tests/Feature/HealthSafety/HsLinkedOperationalEvidenceTest.php`
- Modify: `tests/Feature/HealthSafety/HsHandoverAcceptanceTest.php`
- Modify: `resources/js/components/incidents/incident-detail-dialog.tsx`
- Modify: `resources/js/components/incidents/incident-detail-dialog.test.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- Create: `resources/js/components/incidents/linked-operational-evidence.tsx`
- Create: `resources/js/components/incidents/linked-operational-evidence.test.tsx`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`
- Modify: `docs/superpowers/plans/2026-07-16-control-room-hs-remediation.md`

- [x] **Step 1: Write failing cross-module payload tests**

Prove Incident and H&S receive the same read-only linked data:

- typed operational notes;
- source tasks with transfer state and CA reference;
- evidence packs and items;
- communication summaries;
- client/site/source timestamps;
- authenticated download URLs;
- labels distinguishing linked operational evidence from official incident attachments/follow-ups.

- [x] **Step 2: Run tests**

```powershell
php artisan test tests/Feature/Incidents/IncidentJourneyPresenterTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php tests/Feature/HealthSafety/HsLinkedOperationalEvidenceTest.php tests/Feature/HealthSafety/HsHandoverAcceptanceTest.php
npx vitest run resources/js/components/incidents/incident-detail-dialog.test.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/incidents/linked-operational-evidence.test.tsx
```

- [x] **Step 3: Build one authorized presenter**

```php
return [
    'notes' => $this->notes($alert, $viewer),
    'tasks' => $this->tasks($alert, $viewer),
    'evidence_packs' => $this->evidencePacks($alert, $viewer),
    'communications' => $this->communications($alert, $viewer),
    'source' => [
        'reference' => $alert->reference_number,
        'href' => $this->access->canView($alert, $viewer)
            ? "/control-room/alerts/{$alert->id}"
            : null,
        'created_at' => $alert->created_at?->toIso8601String(),
        'updated_at' => $alert->updated_at?->toIso8601String(),
    ],
];
```

Do not copy files or note bodies into incident tables.

- [x] **Step 4: Render a shared read-only evidence section**

Use headings:

- `Linked Control Room evidence`;
- `Official incident attachments`;
- `Incident follow-ups`.

Transferred tasks display:

`Transferred to CA-YYYY-NNNN`

Open tasks display owner/due state.

- [x] **Step 5: Re-run tests**

```powershell
php artisan test tests/Feature/Incidents/IncidentJourneyPresenterTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php tests/Feature/HealthSafety/HsLinkedOperationalEvidenceTest.php tests/Feature/HealthSafety/HsHandoverAcceptanceTest.php
npx vitest run resources/js/components/incidents/incident-detail-dialog.test.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/incidents/linked-operational-evidence.test.tsx
```

- [x] **Step 6: Commit**

```powershell
git add app/Services/Incidents/IncidentJourneyPresenter.php app/Http/Controllers/IncidentController.php app/Http/Controllers/HealthSafety/HsEventController.php app/Services/ControlRoom/AlertWorkspaceService.php app/Support/Incidents/LinkedOperationalEvidencePresenter.php routes/incidents.php tests/Feature/Incidents/IncidentJourneyPresenterTest.php tests/Feature/HealthSafety/HsLinkedOperationalEvidenceTest.php tests/Feature/HealthSafety/HsHandoverAcceptanceTest.php resources/js/components/incidents/incident-detail-dialog.tsx resources/js/components/incidents/incident-detail-dialog.test.tsx resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/incidents/linked-operational-evidence.tsx resources/js/components/incidents/linked-operational-evidence.test.tsx docs/audits/control-room-hs-remediation-ledger-2026-07-16.md docs/superpowers/plans/2026-07-16-control-room-hs-remediation.md
git commit -m "feat(incidents): preserve linked operational evidence"
```

Task 13 final verification:

- the cross-module payload, exact parent-scoped download, context-only legacy journey, latest-communication window, and shared rendering regressions were observed red before implementation;
- one canonical `LinkedOperationalEvidencePresenter` supplies Incident, H&S, and Control Room workspace payloads without copying files or note bodies into incident tables;
- direct incident/H&S links and supported context-only legacy alerts use the same canonical journey resolution for detail and download paths;
- linked evidence remains visible through the authorized parent record, while the Control Room jump-back link is exposed only through exact-alert read permission and never through list-only permission;
- source context, typed operator notes, tasks and transfer state, evidence packs/items, authenticated downloads, and the latest 20 communications are presented consistently, with those communications restored to chronological display order;
- the shared UI renders `Linked Control Room evidence`, `Official incident attachments`, and `Incident follow-ups`; transferred tasks use `Transferred to CA-...`, open tasks show owner/due state, and communication direction remains visible;
- the authoritative backend matrix passes 55 tests with 864 assertions in 196.60s; the frontend matrix passes 3 files with 30 tests;
- TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, and diff integrity are clean;
- the production client build passes with 4,967 modules in 3m 13s and SSR passes with 1,619 modules in 40.41s;
- independent review found and closed three issues: latest-20 selection, canonical legacy journey resolution, and communication direction. Final re-review returned no findings.

## Task 14: Unify closure gates and truthful cross-module language

**Files:**

- Create: `app/Support/Journeys/JourneyGate.php`
- Modify: `app/Services/ControlRoom/ControlRoomAlertLifecycleService.php`
- Modify: `app/Services/HealthSafety/HsEventService.php`
- Modify: `app/Services/HealthSafety/HsInvestigationService.php`
- Modify: `app/Services/Incidents/IncidentJourneyService.php`
- Modify: `app/Services/Incidents/IncidentJourneyPresenter.php`
- Modify: `app/Services/ControlRoom/AlertWorkspaceService.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`
- Modify: `app/Http/Controllers/IncidentController.php`
- Modify: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Create: `resources/js/components/incidents/journey-gate-list.tsx`
- Create: `resources/js/components/incidents/journey-gate-list.test.tsx`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.tsx`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.test.tsx`
- Modify: `resources/js/components/incidents/incident-detail-dialog.tsx`
- Modify: `resources/js/components/incidents/incident-detail-dialog.test.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- Modify: `resources/js/pages/health-safety/events/show.tsx`
- Create: `resources/js/pages/health-safety/events/show.test.tsx`
- Modify: `tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php`
- Modify: `tests/Feature/HealthSafety/HsEventClosureTest.php`
- Modify: `tests/Feature/HealthSafety/HsEventRegisterTest.php`
- Modify: `tests/Feature/HealthSafety/HsInvestigationTest.php`
- Modify: `tests/Feature/IncidentControllerTest.php`
- Modify: `tests/Feature/Incidents/IncidentJourneyPresenterTest.php`
- Modify: `tests/Feature/Incidents/IncidentJourneyServiceTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing gate-matrix tests**

Cover:

- alert resolve blocked by open/cancel-pending operational task;
- transferred task no longer blocks resolve;
- resolve requires resolution note but does not require H&S closure;
- alert close requires linked incident and H&S closed;
- incident close requires review, follow-ups, investigation, and H&S closure when applicable;
- H&S close requires acceptance, explicit WorkSafe truth, investigation, dispositions, verified/closed actions, and summary;
- incident/H&S direct-link ownership conflicts and duplicate or foreign standalone H&S rows fail closed;
- investigation creation serializes with event closure by locking and rechecking the event first;
- every blocker has `key`, `complete`, `label`, and `href`;
- each UI renders the server gate without reconstructing it.

- [x] **Step 2: Run focused tests**

```powershell
php artisan test tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsInvestigationTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php tests/Feature/Incidents/IncidentJourneyPresenterTest.php
npx vitest run resources/js/components/incidents/journey-gate-list.test.tsx resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/incidents/incident-detail-dialog.test.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/events/show.test.tsx
```

- [x] **Step 3: Implement a typed gate value**

```php
final readonly class JourneyGate
{
    public function __construct(
        public bool $allowed,
        public array $requirements,
    ) {}

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'requirements' => $this->requirements,
        ];
    }
}
```

Each service owns its domain requirements but returns this shape.

- [x] **Step 4: Separate resolve and close semantics**

Visible states:

- Operational response active;
- Operationally resolved;
- Incident review complete;
- H&S acceptance pending;
- H&S governance active;
- Awaiting independent verification;
- Governance closed;
- Journey closed.

Resolve dialog copy:

`Resolve ends the live operational response. It does not close the linked incident or H&S governance.`

Close dialog copy:

`Close is available only when the incident and H&S governance are closed.`

- [x] **Step 5: Render shared direct-action blockers**

```tsx
<JourneyGateList gate={detail.resolve_gate} />
<Button disabled={!detail.resolve_gate.allowed}>Resolve alert</Button>
```

Remove UI language saying an open task will remain while still enabling Resolve.

- [x] **Step 6: Re-run focused tests**

```powershell
php artisan test tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsInvestigationTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php tests/Feature/Incidents/IncidentJourneyPresenterTest.php
npx vitest run resources/js/components/incidents/journey-gate-list.test.tsx resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/incidents/incident-detail-dialog.test.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/events/show.test.tsx
```

- [x] **Step 7: Commit**

```powershell
git add app/Support/Journeys/JourneyGate.php app/Services/ControlRoom/ControlRoomAlertLifecycleService.php app/Services/HealthSafety/HsEventService.php app/Services/HealthSafety/HsInvestigationService.php app/Services/Incidents/IncidentJourneyService.php app/Services/Incidents/IncidentJourneyPresenter.php app/Services/ControlRoom/AlertWorkspaceService.php app/Http/Controllers/ControlRoom/ControlRoomAlertController.php app/Http/Controllers/IncidentController.php app/Http/Controllers/HealthSafety/HsEventController.php resources/js/components/incidents/journey-gate-list.tsx resources/js/components/incidents/journey-gate-list.test.tsx resources/js/components/control-room/alert-workspace-dialog.tsx resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/incidents/incident-detail-dialog.tsx resources/js/components/incidents/incident-detail-dialog.test.tsx resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/events/show.tsx resources/js/pages/health-safety/events/show.test.tsx tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsEventRegisterTest.php tests/Feature/HealthSafety/HsInvestigationTest.php tests/Feature/IncidentControllerTest.php tests/Feature/Incidents/IncidentJourneyPresenterTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md docs/superpowers/plans/2026-07-16-control-room-hs-remediation.md
git commit -m "feat(journeys): unify closure gate truth"
```

Task 14 final verification:

- the original gate matrix was observed red with 5 backend failures and 70 passes, while the shared frontend gate component, server-owned payloads, exact state labels, direct actions, and truthful resolve/close copy were absent;
- one `JourneyGate` value now supplies typed requirements from Control Room, Incident, and H&S domain services; each UI renders that server truth through one shared `JourneyGateList`;
- Resolve ends only the live operational response, while Close enforces final incident and H&S governance truth; exact cross-module journey states are presented consistently;
- server mutation boundaries enforce the same requirements as the UI, lock operational work, serialize investigation creation with event closure, validate direct ownership tuples, and reject duplicate or foreign standalone H&S rows;
- role-aware serialization removes inaccessible incident/H&S links while retaining plain required-action guidance;
- the final expanded backend matrix passes 112 tests with 829 assertions in 187.83s; the changed H&S register payload passes 1 test with 81 assertions, and the restricted Incident detail payload passes 1 test with 15 assertions;
- the frontend matrix passes 5 files with 51 tests; TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, and diff integrity are clean;
- the exact final production client build passes with 4,968 modules in 3m 01s and SSR passes with 1,620 modules in 40.34s;
- iterative independent review closed poisoned H&S reads, the investigation-create/event-close race, permission-blind links, standalone final-state labels, conflicting alert links, and duplicate/foreign standalone H&S closure. Final re-review returned no findings.

## Task 15: Bound shift handover to changed and decision-relevant work

**Files:**

- Create: `app/Services/ControlRoom/ControlRoomHandoverScopeService.php`
- Create: `app/Services/ControlRoom/ControlRoomPreparedHandoverSnapshotService.php`
- Modify: `app/Services/ControlRoom/ControlRoomShiftHandoverService.php`
- Modify: `app/Services/ControlRoom/ControlRoomAlertProvenanceService.php`
- Modify: `app/Services/ControlRoom/AlertWorklistQuery.php`
- Modify: `app/Services/ControlRoom/AlertWorklistPresenter.php`
- Modify: `app/Services/Incidents/IncidentJourneyService.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomHandoverController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomShiftController.php`
- Modify: `app/Models/ControlRoom/Shift.php`
- Modify: `app/Models/ControlRoomAlert.php`
- Create: `tests/Feature/ControlRoom/ControlRoomShiftHandoverScopeTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomHandoverControllerTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomAlertControllerTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomShiftControllerTest.php`
- Modify: `tests/Unit/ControlRoom/AlertWorklistPresenterTest.php`
- Modify: `resources/js/pages/control-room/shifts/handover.tsx`
- Create: `resources/js/pages/control-room/shifts/handover.test.tsx`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing scope tests**

An alert requires individual review when it was:

- created during the shift;
- acknowledged, triaged, escalated, snoozed, unsnoozed, or materially updated during the shift;
- assigned to or watched by a shift member;
- SLA-breached or at risk during the shift;
- given an open task due before the next expected shift;
- moved into incident, H&S acceptance, WorkSafe, corrective-action verification, or closure decision state;
- explicitly pinned.

Untouched pre-existing active alerts must be counted in carry-forward, not individual review.

Prove no critical alert matching the scope is hidden by pagination or a cap.

- [x] **Step 2: Run tests**

```powershell
php artisan test tests/Feature/ControlRoom/ControlRoomShiftHandoverScopeTest.php tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php
npx vitest run resources/js/pages/control-room/shifts/handover.test.tsx
```

- [x] **Step 3: Implement the scope service**

Public contract:

```php
public function build(Shift $shift, User $viewer): array
{
    return [
        'criteria_at' => now()->toIso8601String(),
        'required_alerts' => $this->requiredAlerts($shift, $viewer),
        'carry_forward' => $this->carryForwardSummary($shift, $viewer),
    ];
}
```

`carry_forward` contains:

```php
[
    'total' => 118,
    'by_severity' => ['critical' => 2, 'high' => 14, 'medium' => 62, 'low' => 40],
    'by_queue' => [...],
    'oldest_created_at' => '...',
    'breached_count' => 3,
    'href' => '/control-room/alerts?lens=active&handover=carry-forward',
]
```

- [x] **Step 4: Replace `urgentAlertsFor()`**

`ControlRoomShiftHandoverService::prepare()` must compare reviewed IDs only with `required_alerts`. Store the exact criteria timestamp, criteria labels, required snapshots, and carry-forward summary in the immutable snapshot.

- [x] **Step 5: Rebuild the handover page sections**

Render:

1. individually required alerts;
2. explicit carry-forward acknowledgement;
3. pinned/follow-up notes;
4. incoming lead/team;
5. review summary.

Required copy:

`118 unchanged active alerts will carry forward as a summary. You do not need to open each one.`

- [x] **Step 6: Re-run tests**

```powershell
php artisan test tests/Feature/ControlRoom/ControlRoomShiftHandoverScopeTest.php tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php
npx vitest run resources/js/pages/control-room/shifts/handover.test.tsx
```

- [x] **Step 7: Commit**

```powershell
git add app/Services/ControlRoom/ControlRoomHandoverScopeService.php app/Services/ControlRoom/ControlRoomShiftHandoverService.php app/Http/Controllers/ControlRoom/ControlRoomHandoverController.php app/Models/ControlRoom/Shift.php tests/Feature/ControlRoom/ControlRoomShiftHandoverScopeTest.php tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php resources/js/pages/control-room/shifts/handover.tsx resources/js/pages/control-room/shifts/handover.test.tsx docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(control-room): bound shift handover scope"
```

Completed evidence:

- scope selection is canonical and uncapped across the seven approved criteria, including current snoozed work, current shift-member ownership/watch state, SLA risk, due-before-next-shift tasks, governance changes, and outgoing-lead pins;
- unchanged active work is frozen as an exact carry-forward ID set plus severity, queue, age, breach, signature, and exact-link summary, while incoming-lead visibility is checked both at preparation and acceptance;
- prepared snapshots are immutable and fail closed: actor/team authorization comes from persisted shift state, the criteria taxonomy and row shapes are validated server-side, and access drift or malformed snapshots cannot switch shifts;
- canonical Incident/H&S governance is resolved in bounded batches, alert-management capability is precomputed, and the 40-alert full-scope query-budget regression passes without per-row governance, SLA, or RBAC growth;
- the definitive backend matrix passes 110 tests with 718 assertions; the isolated full SLA query-budget proof passes 1 test with 4 assertions;
- the handover frontend passes 1 file with 3 tests; TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, diff integrity, and final independent review are clean;
- the production client build passes with 4,968 modules in 2m 56s and SSR passes with 1,620 modules in 39.79s.

## Task 16: Add stale-shift override, permission ownership, and clean live fixtures

**Files:**

- Create: `config/control-room.php`
- Modify: `database/seeders/RbacSeeder.php`
- Modify: `database/seeders/IncidentHandoverE2ESeeder.php`
- Modify: `database/seeders/DuskDatabaseSeeder.php`
- Modify: `app/Models/ControlRoom/Shift.php`
- Modify: `app/Services/ControlRoom/ControlRoomShiftHandoverService.php`
- Modify: `app/Services/ControlRoom/ControlRoomPreparedHandoverSnapshotService.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomHandoverController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomShiftController.php`
- Modify: `resources/js/pages/control-room/shifts/handover.tsx`
- Modify: `resources/js/pages/control-room/shifts/handover.test.tsx`
- Create: `tests/Feature/ControlRoom/ControlRoomStaleShiftHandoverTest.php`
- Modify: `tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [x] **Step 1: Write failing stale-shift tests**

Prove:

- stale threshold comes from configuration;
- stale banner appears after the threshold;
- ordinary manager cannot override another outgoing lead;
- `controlRoom.handovers.override` permits preparation with a required reason;
- override uses the same required-alert and carry-forward checks;
- override actor/reason/time are in snapshot and audit;
- override never bypasses selected incoming acceptance;
- fresh fixture shift has a bounded required set and no inherited global backlog.

- [x] **Step 2: Run tests**

```powershell
php artisan test tests/Feature/ControlRoom/ControlRoomStaleShiftHandoverTest.php tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php
npx vitest run resources/js/pages/control-room/shifts/handover.test.tsx
```

- [x] **Step 3: Add configuration and permission**

```php
// config/control-room.php
'handover_stale_after_hours' => (int) env('CONTROL_ROOM_HANDOVER_STALE_AFTER_HOURS', 16),
```

Seeder row:

```php
[
    'key' => 'controlRoom.handovers.override',
    'description' => 'Prepare a stale Control Room shift handover when the outgoing lead is unavailable',
    'group' => 'control_room',
    'module' => 'System',
],
```

Grant only to Admin, Provider Manager, and the appropriate Control Room coordinator role. Add it to Dusk fixture permission setup.

- [x] **Step 4: Extend the prepare contract**

```php
public function prepare(
    Shift $outgoing,
    User $incomingLead,
    array $reviewedAlertIds,
    User $actor,
    int $expectedVersion,
    ?string $overrideReason = null,
): Shift
```

If the actor is not outgoing lead:

- shift must be stale;
- actor must hold the override permission;
- reason must contain at least 10 characters.

- [x] **Step 5: Add stale banner and override form**

Required copy:

`This shift is stale. The named outgoing lead has not completed handover. An authorised manager may prepare it with an audited reason; the incoming lead must still accept it.`

- [x] **Step 6: Make the E2E seeder idempotently create a fresh bounded shift**

The seeder must:

- complete/retire its prior tagged active fixture shift only;
- create one fresh active shift for the fixture operator;
- create a small deterministic set of required alerts;
- leave unrelated demo records untouched;
- create or reuse the tagged incoming operator;
- print the fixture marker, users, site, client, shift, and record IDs.

- [x] **Step 7: Re-run tests and permission seeding**

```powershell
php artisan db:seed --class=RbacSeeder
php artisan test tests/Feature/ControlRoom/ControlRoomStaleShiftHandoverTest.php tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php
npx vitest run resources/js/pages/control-room/shifts/handover.test.tsx
```

- [x] **Step 8: Commit**

```powershell
git add config/control-room.php database/seeders/RbacSeeder.php database/seeders/IncidentHandoverE2ESeeder.php database/seeders/DuskDatabaseSeeder.php app/Services/ControlRoom/ControlRoomShiftHandoverService.php app/Http/Controllers/ControlRoom/ControlRoomHandoverController.php app/Http/Controllers/ControlRoom/ControlRoomShiftController.php resources/js/pages/control-room/shifts/handover.tsx resources/js/pages/control-room/shifts/handover.test.tsx tests/Feature/ControlRoom/ControlRoomStaleShiftHandoverTest.php tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(control-room): add audited stale handover recovery"
```

Completed evidence:

- the configurable stale threshold is enforced under the locked shift; the outgoing lead retains normal ownership, while only Admin, Provider Manager, and Coordinator receive the dedicated override permission;
- a non-lead override requires a 10–2,000 character reason for both draft recovery and preparation, preserves the full required-alert/carry-forward gates, and never permits the override actor to accept for the selected incoming lead;
- actor, reason, and time are frozen in both snapshot and audit; prepared-page access is derived from persisted shift/audit state, and acceptance rejects structurally valid snapshot tampering that does not match the committed audit row;
- the stale banner and form use the approved copy, expose the audited boundary before editing, and show the override provenance to the incoming lead;
- the E2E seeder retires only its own prior tagged active shift, creates one site-bounded two-alert required set and tagged incoming operator, leaves unrelated records untouched, prints the complete manifest, and fails closed rather than mutating an unrelated active shift;
- `RbacSeeder` completes successfully; the authoritative backend pair passes 19 tests with 238 assertions, and the handover frontend passes 1 file with 4 tests;
- TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, diff integrity, client build (4,968 modules in 2m 56s), and SSR build (1,620 modules in 39.08s) are clean.

## Task 17: Repair shared Select behavior and date-only rendering

**Files:**

- Modify: `resources/js/components/wizard/primitives.tsx`
- Create: `resources/js/components/wizard/primitives.test.tsx`
- Modify: `resources/js/lib/datetime.ts`
- Create: `resources/js/lib/datetime.test.ts`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- Modify: `resources/js/pages/health-safety/corrective-actions/index.tsx`
- Modify: `resources/js/pages/health-safety/components/worklists.tsx`
- Modify: `resources/js/components/control-room/new-alert-wizard.tsx`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.tsx`
- Create: `resources/js/components/control-room/select-regressions.test.tsx`
- Modify: `tests/Feature/HealthSafety/HsInvestigationTest.php`
- Modify: `tests/Feature/HealthSafety/HsCorrectiveActionTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [ ] **Step 1: Write failing controlled Select tests**

Prove:

- empty value is controlled from first render;
- mouse click commits the clicked client;
- keyboard selection commits the highlighted client;
- task assignee click commits exactly one intended user;
- reopening shows the persisted value;
- no uncontrolled-to-controlled warning is emitted.

Use a console spy:

```tsx
const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
// render, select, rerender
expect(error).not.toHaveBeenCalledWith(
    expect.stringContaining('changing an uncontrolled input to be controlled'),
);
```

- [ ] **Step 2: Write failing date-only tests**

```tsx
expect(formatDateOnly('2026-07-21', 'Pacific/Auckland')).toBe('21 Jul 2026');
expect(formatDateOnly('2026-07-21', 'America/Los_Angeles')).toBe('21 Jul 2026');
```

Backend tests must assert stored/presented `target_completion_date` and `due_date` remain exactly `2026-07-21`.

- [ ] **Step 3: Run tests**

```powershell
npx vitest run resources/js/components/wizard/primitives.test.tsx resources/js/lib/datetime.test.ts resources/js/components/control-room/select-regressions.test.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx
php artisan test tests/Feature/HealthSafety/HsInvestigationTest.php tests/Feature/HealthSafety/HsCorrectiveActionTest.php
```

- [ ] **Step 4: Keep `SelectInput` controlled from first render**

Replace:

```tsx
<Select value={value || undefined} onValueChange={onChange}>
```

with:

```tsx
<Select value={value} onValueChange={onChange}>
```

Use a named non-empty sentinel only where a Radix item represents “none”; translate it at the component boundary.

- [ ] **Step 5: Add a date-only formatter that never creates an instant**

```ts
export function formatDateOnly(
    value: string | null | undefined,
    fallback = '—',
): string {
    if (!value) return fallback;
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!match) return fallback;
    const [, year, month, day] = match;
    const months = [
        'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];
    const monthLabel = months[Number(month) - 1];
    return monthLabel ? `${Number(day)} ${monthLabel} ${year}` : fallback;
}
```

Use it for investigation target and corrective-action due dates. Do not use `formatDateTime`, `new Date('YYYY-MM-DD')` in feature components, or `toISOString()` for these fields.

- [ ] **Step 6: Re-run tests**

```powershell
npx vitest run resources/js/components/wizard/primitives.test.tsx resources/js/lib/datetime.test.ts resources/js/components/control-room/select-regressions.test.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx
php artisan test tests/Feature/HealthSafety/HsInvestigationTest.php tests/Feature/HealthSafety/HsCorrectiveActionTest.php
```

- [ ] **Step 7: Commit**

```powershell
git add resources/js/components/wizard/primitives.tsx resources/js/components/wizard/primitives.test.tsx resources/js/lib/datetime.ts resources/js/lib/datetime.test.ts resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/corrective-actions/index.tsx resources/js/pages/health-safety/components/worklists.tsx resources/js/components/control-room/new-alert-wizard.tsx resources/js/components/control-room/alert-workspace-dialog.tsx resources/js/components/control-room/select-regressions.test.tsx tests/Feature/HealthSafety/HsInvestigationTest.php tests/Feature/HealthSafety/HsCorrectiveActionTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "fix(ui): stabilize selects and date-only values"
```

## Task 18: Finish H&S worklists, navigation, focus, terminology, and activity copy

**Files:**

- Modify: `app/Services/HealthSafety/HsDashboardService.php`
- Modify: `app/Http/Controllers/HealthSafety/HealthSafetyDashboardController.php`
- Modify: `resources/js/pages/health-safety/dashboard.tsx`
- Modify: `resources/js/pages/health-safety/components/worklists.tsx`
- Create: `resources/js/pages/health-safety/dashboard.test.tsx`
- Modify: `resources/js/components/app-sidebar.tsx`
- Create: `resources/js/components/app-sidebar-role-filter.test.tsx`
- Modify: `resources/js/pages/tasks/task-detail-dialog.tsx`
- Modify: `resources/js/pages/tasks/task-detail-dialog.test.tsx`
- Create: `resources/js/lib/journey-labels.ts`
- Create: `resources/js/lib/journey-labels.test.ts`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.tsx`
- Modify: `resources/js/components/incidents/incident-detail-dialog.tsx`
- Modify: `tests/Feature/HealthSafety/HsDashboardWorklistTest.php`
- Modify: `tests/Feature/Navigation/ControlRoomNavigationTest.php`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [ ] **Step 1: Write failing H&S acceptance worklist tests**

Prove:

- awaiting H&S acceptance is the first attention worklist;
- it is site-scoped;
- its count and rows link directly to the acceptance action;
- accepted/closed events leave the list;
- the manual fixture event is findable without a register filter.

- [ ] **Step 2: Write failing navigation and terminology tests**

Prove:

- empty menu groups are not rendered;
- expanded and collapsed sidebar user menus work;
- Back and Close preserve the originating query;
- focus rings are visible;
- status/severity/priority/escalation/SLA help text is available;
- machine audit action strings are never rendered to users.

- [ ] **Step 3: Run tests**

```powershell
php artisan test tests/Feature/HealthSafety/HsDashboardWorklistTest.php tests/Feature/Navigation/ControlRoomNavigationTest.php
npx vitest run resources/js/pages/health-safety/dashboard.test.tsx resources/js/components/app-sidebar-role-filter.test.tsx resources/js/pages/tasks/task-detail-dialog.test.tsx resources/js/lib/journey-labels.test.ts
```

- [ ] **Step 4: Add the H&S acceptance worklist**

Server row:

```php
[
    'key' => 'awaiting_hs_acceptance',
    'label' => 'Awaiting H&S acceptance',
    'help' => 'A named H&S owner must accept governance responsibility.',
    'count' => $awaitingAcceptance->count(),
    'items' => $this->presentEvents($awaitingAcceptance),
]
```

- [ ] **Step 5: Centralize human labels**

```ts
export const JOURNEY_ACTIVITY_LABELS: Record<string, string> = {
    'healthSafety.correctiveAction.completed': 'Owner submitted evidence',
    'healthSafety.correctiveAction.returnedForRework': 'Action returned for rework',
    'healthSafety.correctiveAction.verified': 'Action independently verified',
    'healthSafety.event.handoverAccepted': 'H&S handover accepted',
    'controlRoom.shift.handoverPrepared': 'Shift handover prepared',
    'controlRoom.shift.handoverAccepted': 'Incoming lead accepted handover',
};
```

Unknown actions fall back to `Activity recorded`, not machine class/action names.

- [ ] **Step 6: Filter sidebar groups after permission filtering**

```tsx
const visibleGroups = groups
    .map((group) => ({ ...group, items: group.items.filter(canSee) }))
    .filter((group) => group.items.length > 0);
```

- [ ] **Step 7: Add concise inline definitions**

Use one reusable help popover for:

- severity = potential harm;
- priority = work order;
- escalation = management attention level;
- SLA = required response time;
- governance stage = accountable review state.

- [ ] **Step 8: Re-run tests**

```powershell
php artisan test tests/Feature/HealthSafety/HsDashboardWorklistTest.php tests/Feature/Navigation/ControlRoomNavigationTest.php
npx vitest run resources/js/pages/health-safety/dashboard.test.tsx resources/js/components/app-sidebar-role-filter.test.tsx resources/js/pages/tasks/task-detail-dialog.test.tsx resources/js/lib/journey-labels.test.ts
```

- [ ] **Step 9: Commit**

```powershell
git add app/Services/HealthSafety/HsDashboardService.php app/Http/Controllers/HealthSafety/HealthSafetyDashboardController.php resources/js/pages/health-safety/dashboard.tsx resources/js/pages/health-safety/components/worklists.tsx resources/js/pages/health-safety/dashboard.test.tsx resources/js/components/app-sidebar.tsx resources/js/components/app-sidebar-role-filter.test.tsx resources/js/pages/tasks/task-detail-dialog.tsx resources/js/pages/tasks/task-detail-dialog.test.tsx resources/js/lib/journey-labels.ts resources/js/lib/journey-labels.test.ts resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/control-room/alert-workspace-dialog.tsx resources/js/components/incidents/incident-detail-dialog.tsx tests/Feature/HealthSafety/HsDashboardWorklistTest.php tests/Feature/Navigation/ControlRoomNavigationTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "feat(journeys): finish worklist and recovery UX"
```

## Task 19: Add deterministic end-to-end browser coverage for the complete journey

**Files:**

- Create: `tests/e2e/control-room-incident-hs-golden-journey.spec.ts`
- Create: `tests/e2e/control-room-incident-hs-alternate-branches.spec.ts`
- Modify: `database/seeders/IncidentHandoverE2ESeeder.php`
- Create: `tests/Feature/Incidents/IncidentHandoverE2ESeederTest.php`
- Create: `tests/e2e/control-room-incident-hs-helpers.ts`
- Modify: `tests/e2e/incident-handover-helpers.ts`
- Modify: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [ ] **Step 1: Write the failing seeder contract test**

Assert the seeder creates/reuses:

- experienced operator;
- incident reviewer/provider manager;
- H&S owner;
- corrective-action owner/site manager;
- independent verifier;
- incoming Control Room operator;
- novice support worker;
- one scoped site/client;
- one fresh active shift with a bounded required-alert set;
- no duplicate tagged fixture after a second run.

- [ ] **Step 2: Write the golden browser scenario**

The automated relay must log in as seven distinct users and perform:

1. operator creates/claims/acknowledges/triages alert, records typed immediate controls, task, evidence, escalation, incident/H&S handover;
2. reviewer finds by client/task/reference and completes incident review;
3. H&S owner accepts, records explicit WorkSafe decision, completes investigation, dispositions recommendation, selects owner/due/source transfer;
4. action owner finds assigned work, starts, uploads evidence, completes;
5. independent verifier reviews evidence, returns once, sees resubmission, verifies;
6. incoming operator accepts bounded handover and closes operational work only after governance closure;
7. novice worker receives read-only truth, no forbidden CTA, and focus recovery.

The scenario then closes action, H&S, incident, and alert in the correct order and asserts Universal Tasks active/history reconciliation.

- [ ] **Step 3: Write alternate branches A–F**

Create separate tagged records and prove:

- A: routine alert closes without creating incident;
- B: false-positive sensor confirm/dismiss removes active queue/SLA pressure and preserves audit;
- C: resolved alert reopens for incident without losing history/evidence;
- D: snooze/unsnooze and escalation queue behavior;
- E: Control Room task transfers one-for-one to H&S corrective action;
- F: each closure gate blocks, links to the unmet item, preserves entered text, and succeeds after the prerequisite is completed.

- [ ] **Step 4: Add console, failed-request, and focus assertions**

Fail the suite on:

- browser console error;
- uncaught page error;
- failed 4xx/5xx request except explicitly asserted authorization probes;
- controlled/uncontrolled warning;
- modal focus ending on `body`;
- missing direct route;
- duplicate active responsibility.

- [ ] **Step 5: Run the seeder and browser tests against a production build**

```powershell
php artisan test tests/Feature/Incidents/IncidentHandoverE2ESeederTest.php
npm run build
npx vite build --ssr
php artisan db:seed --class=IncidentHandoverE2ESeeder
npx playwright test tests/e2e/control-room-incident-hs-golden-journey.spec.ts tests/e2e/control-room-incident-hs-alternate-branches.spec.ts --project=chromium-desktop
```

Expected: both browser files pass with no console or failed-request exceptions.

- [ ] **Step 6: Commit**

```powershell
git add tests/e2e/control-room-incident-hs-golden-journey.spec.ts tests/e2e/control-room-incident-hs-alternate-branches.spec.ts tests/e2e/control-room-incident-hs-helpers.ts tests/e2e/incident-handover-helpers.ts database/seeders/IncidentHandoverE2ESeeder.php tests/Feature/Incidents/IncidentHandoverE2ESeederTest.php docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "test(journeys): cover seven-role closure relay"
```

## Task 20: Run the complete local release gate and remediate every failure

**Files:**

- Modify only files required to fix failures found by the commands below.
- Update: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`

- [ ] **Step 1: Run the full relevant backend suites**

```powershell
php artisan test tests/Feature/ControlRoom tests/Feature/Incidents tests/Feature/HealthSafety tests/Feature/Tasks
```

Expected: zero failures. Record exact test and assertion counts.

- [ ] **Step 2: Run all changed frontend tests**

```powershell
npx vitest run resources/js/components/control-room resources/js/components/incidents resources/js/components/health-safety resources/js/pages/control-room/shifts resources/js/pages/health-safety resources/js/pages/tasks resources/js/components/wizard/primitives.test.tsx resources/js/lib/datetime.test.ts resources/js/lib/journey-labels.test.ts
```

Expected: zero failures and zero React controlled/uncontrolled warnings.

- [ ] **Step 3: Generate routes and run static checks**

```powershell
php artisan wayfinder:generate
npm run types
npx eslint resources/js/components/control-room resources/js/components/incidents resources/js/components/health-safety resources/js/pages/control-room/shifts resources/js/pages/health-safety resources/js/pages/tasks resources/js/components/wizard/primitives.tsx resources/js/lib/datetime.ts resources/js/lib/journey-labels.ts
vendor\bin\pint --test app/Http/Controllers/ControlRoom app/Http/Controllers/HealthSafety app/Http/Requests/HealthSafety app/Models/ControlRoom app/Models/HsCorrectiveAction.php app/Models/HsEvent.php app/Services/ControlRoom app/Services/HealthSafety app/Services/Incidents app/Services/Tasks app/Support database/migrations database/seeders tests/Feature/ControlRoom tests/Feature/HealthSafety tests/Feature/Incidents tests/Feature/Tasks
git diff --check
```

Expected: every command exits 0.

- [ ] **Step 4: Run both production builds**

```powershell
npm run build
npx vite build --ssr
```

Expected: client and SSR builds exit 0.

- [ ] **Step 5: Run production-built browser suites**

```powershell
npx playwright test tests/e2e/control-room-incident-hs-golden-journey.spec.ts tests/e2e/control-room-incident-hs-alternate-branches.spec.ts --project=chromium-desktop
```

Expected: all scenarios pass.

- [ ] **Step 6: Self-review against all 19 findings**

Run:

```powershell
rg -n "D-(0[1-9]|1[0-9])" docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
$draftTerms = @(
    ('T' + 'BD'),
    ('TO' + 'DO'),
    ('FIX' + 'ME'),
    ('as ' + 'needed'),
    ('similar ' + 'to')
)
foreach ($term in $draftTerms) {
    rg -n --fixed-strings $term docs/superpowers/plans/2026-07-16-control-room-hs-remediation.md docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
    if ($LASTEXITCODE -eq 0) { throw "Incomplete drafting term found: $term" }
    if ($LASTEXITCODE -ne 1) { throw "Drafting scan failed for: $term" }
}
git status --short
git diff origin/main...HEAD --stat
```

Expected:

- exactly 19 finding rows;
- no incomplete drafting language;
- no untracked generated files or screenshots in the source tree;
- every ledger row has concrete code and automated evidence;
- no finding status remains Open, Partial, or Not tested before live verification begins.

- [ ] **Step 7: Request code review and fix every actionable issue**

Use `superpowers:requesting-code-review`. Review must cover:

- domain truth and data migration;
- authorization/site isolation;
- file security;
- transaction/retry safety;
- separation of duties;
- cross-module duplication;
- accessibility/focus;
- date-only/timezone behavior;
- live acceptance completeness.

Re-run the affected focused tests after each fix and the complete gate after all review fixes.

- [ ] **Step 8: Commit release-gate fixes and ledger evidence**

```powershell
git add -A
git commit -m "fix(journeys): close remediation review findings"
```

If there are no code-review changes, commit the updated ledger only:

```powershell
git add docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git commit -m "docs(journeys): record local remediation proof"
```

## Task 21: Merge, push, deploy, and complete live seven-persona acceptance

**Files:**

- Update: `docs/audits/control-room-multi-role-manual-ux-audit-2026-07-16.md`
- Update: `docs/audits/control-room-hs-remediation-ledger-2026-07-16.md`
- Create live evidence under: `output/manual-audits/control-room-multi-role-remediation-2026-07-16/`

- [ ] **Step 1: Verify the branch is clean and current**

```powershell
git status --short --branch
git fetch origin
git log --oneline --decorate -10
git merge-base --is-ancestor origin/main HEAD
```

Expected: clean branch and no missing upstream main commits. If main advanced, merge `origin/main`, resolve carefully, and repeat Task 20.

- [ ] **Step 2: Integrate the branch into remote main without touching the dirty canonical checkout**

Use `superpowers:finishing-a-development-branch`. Keep the isolated remediation branch checked out and push its verified HEAD to main only after confirming `origin/main` is an ancestor:

```powershell
git fetch origin
git merge-base --is-ancestor origin/main HEAD
if ($LASTEXITCODE -ne 0) { throw 'origin/main advanced; merge it and repeat Task 20.' }
git push origin HEAD:main
```

Expected: remote main fast-forwards to the fully verified remediation HEAD; record the pushed SHA. Do not switch, reset, or clean `C:\Users\steph\Herd\oblivionfindings`.

- [ ] **Step 3: Take live pre-migration evidence**

On the development server at `/var/www/oblivionfindings`, using the approved SSH credential from the secure session:

```bash
cd /var/www/oblivionfindings
git status --short --branch
git rev-parse HEAD
php artisan health-safety:worksafe-decision-counts --json
php artisan migrate:status
backup_file="/var/backups/oblivionfindings-before-control-room-remediation-$(date +%Y%m%d-%H%M%S).sql"
db_name="$(php artisan tinker --execute='echo config("database.connections.mysql.database");' --no-ansi)"
db_host="$(php artisan tinker --execute='echo config("database.connections.mysql.host");' --no-ansi)"
db_port="$(php artisan tinker --execute='echo config("database.connections.mysql.port");' --no-ansi)"
db_user="$(php artisan tinker --execute='echo config("database.connections.mysql.username");' --no-ansi)"
db_pass="$(php artisan tinker --execute='echo config("database.connections.mysql.password");' --no-ansi)"
MYSQL_PWD="$db_pass" mysqldump --single-transaction --quick --routines --triggers -h "$db_host" -P "$db_port" -u "$db_user" "$db_name" > "$backup_file"
unset db_pass MYSQL_PWD
test -s "$backup_file"
```

Expected: clean `main`, current pre-deploy SHA, readable WorkSafe counts, migration status captured, and backup file created with non-zero size.

- [ ] **Step 4: Deploy with the repository script**

```bash
cd /var/www/oblivionfindings
bash scripts/deploy-server.sh
php artisan db:seed --class=RbacSeeder --force
php artisan health-safety:worksafe-decision-counts --json
php artisan migrate:status
git rev-parse HEAD
```

Expected:

- deploy script exits 0;
- migration is applied;
- permission is present;
- WorkSafe count command has no inconsistent rows;
- server SHA equals pushed main SHA.

- [ ] **Step 5: Seed a fresh tagged live acceptance fixture**

```bash
cd /var/www/oblivionfindings
php artisan db:seed --class=IncidentHandoverE2ESeeder --force
```

Record the printed marker, seven account IDs, site/client IDs, fresh active shift ID, and bounded required-alert count.

- [ ] **Step 6: Perform the actual desktop Chrome seven-persona relay**

Use actual Chrome at 1440 × 900. Log out between roles. Use the tagged fixture accounts, not Admin substitution.

Tester 1 — Experienced Control Room Operator:

- create the tagged alert;
- use mouse client selection and confirm the intended client;
- claim, acknowledge, triage;
- add an `Immediate controls` note;
- create a task and select the intended assignee;
- upload Control Room evidence;
- escalate;
- create the incident/H&S handover;
- prove immediate action is prefilled and editable.

Tester 2 — Incident Reviewer / Provider Manager:

- find by client name, site name, exact task title, CR reference, and INC reference;
- open the journey from `/tasks`;
- see linked operational evidence;
- complete manager review;
- confirm closure is blocked by direct H&S/investigation requirements.

Tester 3 — H&S Owner:

- find `Awaiting H&S acceptance` on the dashboard;
- accept the handover;
- record an explicit WorkSafe decision and rationale;
- if notifiable, notify and acknowledge;
- complete the investigation;
- disposition every recommendation;
- select the intended action owner and due date;
- transfer the matching operational task.

Tester 4 — Corrective Action Owner / Site Manager:

- find assigned work by My Tasks, client name, and task title;
- see recommendation and source task;
- start the action;
- enter completion notes and upload evidence;
- submit for verification;
- after rework, see the reason, amend notes/evidence, and resubmit.

Tester 5 — Independent H&S Verifier:

- see the complete owner submission;
- verify separation of duties;
- return once with a reason;
- after resubmission, see old and new evidence/history;
- acknowledge evidence review;
- verify and close the action.

Tester 6 — Incoming Control Room Operator / Closure Auditor:

- see a bounded prepared handover;
- review required changed work and carry-forward summary;
- accept the handover;
- confirm the new shift is active;
- confirm resolve/close gate wording;
- after H&S and incident closure, resolve and close the alert.

Tester 7 — Novice Support Worker:

- find the scoped journey;
- see plain-language ownership and next action;
- confirm restricted mutation CTAs are absent;
- open the read-only destination;
- close with Escape and verify focus returns to the invoking task row;
- use Back and confirm filtered Tasks context is preserved.

Capture before/after screenshots for every tester and record every reference/actor/time.

- [ ] **Step 7: Exercise alternate branches A–F in actual Chrome**

Use separate tagged records:

- A routine alert with no incident;
- B false-positive sensor confirmation/dismissal;
- C resolved alert reopened for incident;
- D snooze, unsnooze, and escalation;
- E one-for-one task transfer to H&S;
- F every closure blocker and recovery path.

Every row must be Pass; no row may be inferred from automation.

- [ ] **Step 8: Run live database and log integrity checks**

Read-only evidence must prove:

- exactly one CR/INC/HS/INV/CA chain for the golden marker;
- CR, INC, HS, client, site, and tenant links agree;
- WorkSafe decision actor/time/reason/source and notification state agree with UI;
- source AlertTask is transferred and linked to exactly one CA;
- action owner, completer, independent verifier, evidence attachments, return reason, and timestamps agree;
- final action/H&S/incident/alert closure actors/times agree;
- Universal Tasks has no duplicate active responsibility and preserves closed history;
- new shift handover has outgoing/override actor, incoming acceptor, bounded required set, and carry-forward summary;
- no cross-site rows were exposed or changed;
- Laravel logs since deployment have zero unexplained `ERROR`, `CRITICAL`, `ALERT`, or `EMERGENCY`;
- browser console and failed-request captures are empty.

- [ ] **Step 9: Update the authoritative audit and completion ledger**

The final audit must:

- replace the unsafe release decision with the evidence-backed final decision;
- include deployed SHA and exact commands/counts;
- show Pass for all seven testers;
- show Pass for acceptance criteria 1–17;
- show Pass for alternate A–F;
- close D-01 through D-19 with code/test/live evidence;
- list screenshot paths and record references;
- state explicitly that no finding was deferred;
- contain no `Deferred`, `Not tested`, `Partial`, unresolved P0–P3, or ambiguous completion language.

- [ ] **Step 10: Commit and push the final evidence**

Do not commit credentials, server dumps, or sensitive raw data. Commit the audit, ledger, and appropriate redacted screenshots:

```powershell
git add docs/audits/control-room-multi-role-manual-ux-audit-2026-07-16.md docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git add -f output/manual-audits/control-room-multi-role-remediation-2026-07-16
git commit -m "docs(control-room): close live multi-role acceptance"
git fetch origin
git merge-base --is-ancestor origin/main HEAD
if ($LASTEXITCODE -ne 0) { throw 'origin/main advanced during acceptance; reconcile before publishing evidence.' }
git push origin HEAD:main
```

- [ ] **Step 11: Sync the docs-only evidence commit to the server**

```bash
cd /var/www/oblivionfindings
git pull --ff-only origin main
git rev-parse HEAD
```

Expected: the server checkout advances to the final evidence SHA without changing runtime code.

- [ ] **Step 12: Perform final completion audit**

Run locally and on the server:

```powershell
git status --short --branch
git rev-parse HEAD
rg -n "\|\s*(Deferred|Not tested|Partial|Open|P[0-3])\s*\|?\s*$" docs/audits/control-room-multi-role-manual-ux-audit-2026-07-16.md docs/audits/control-room-hs-remediation-ledger-2026-07-16.md
git diff --check HEAD^ HEAD
```

Expected:

- clean local and server `main`;
- identical SHA;
- no unresolved audit status;
- all 19 findings, seven personas, 17 acceptance criteria, and alternate A–F have direct evidence;
- no required work remains.

Only after this evidence is current and internally consistent may the persistent goal be marked complete.
