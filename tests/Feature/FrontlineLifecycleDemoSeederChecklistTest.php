<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use App\Services\HandoverWorkerNotes;
use Database\Seeders\FrontlineLifecycleDemoSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemUsersSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-05 10:30:00', 'Pacific/Auckland')->utc());
    config(['features.rostering.publish' => true]);
    $this->seed(RbacSeeder::class);
    $this->seed(SystemUsersSeeder::class);
    $this->seed(FrontlineLifecycleDemoSeeder::class);
    $this->attendancePerson = Client::query()
        ->where('first_name', 'Playwright')->where('last_name', 'Attendance')->sole();
});

it('shows the assigned checklist person and task while denying another person at the same Site', function (string $email) {
    $worker = User::query()->where('email', $email)->sole();
    $shift = Shift::query()->where('notes', 'PW:active-checklist:'.$email)->sole();
    $session = HrAttendanceSession::query()->where('shift_id', $shift->id)->where('user_id', $worker->id)->sole();
    $task = $shift->tasks()->sole();
    $privatePerson = Client::factory()->create([
        'first_name' => 'Private checklist',
        'last_name' => 'Co-resident',
        'site_id' => $this->attendancePerson->site_id,
    ]);
    $privateTask = $shift->tasks()->create([
        'label' => 'Private co-resident work',
        'task_scope' => 'client',
        'client_id' => $privatePerson->id,
        'is_completed' => false,
        'sort_order' => 2,
    ]);
    $privateBefore = $privateTask->refresh()->getRawOriginal();
    $auditBefore = AuditLog::query()->count();

    expect($worker->roles()->pluck('name')->all())->toBe(['support_worker'])
        ->and($worker->permissionOverrides()->count())->toBe(0)
        ->and($worker->canDo('clients.viewAssigned'))->toBeTrue()
        ->and($worker->canDo('clients.viewAny'))->toBeFalse()
        ->and(Gate::forUser($worker)->allows('view', $this->attendancePerson))->toBeTrue()
        ->and(Gate::forUser($worker)->allows('view', $privatePerson))->toBeFalse()
        ->and((int) $shift->client_id)->toBe($this->attendancePerson->id)
        ->and((int) $shift->site_id)->toBe((int) $this->attendancePerson->site_id)
        ->and($shift->published_at)->not->toBeNull()
        ->and($shift->status)->toBe('in_progress')
        ->and((int) $session->site_id)->toBe((int) $shift->site_id)
        ->and($session->clock_out_at)->toBeNull()
        ->and($task->is_completed)->toBeFalse();

    $response = $this->actingAs($worker)->get('/my-day')->assertOk();
    expect($response->inertiaProps('active_shift.id'))->toBe($shift->id)
        ->and($response->inertiaProps('active_shift.client.id'))->toBe($this->attendancePerson->id)
        ->and($response->inertiaProps('active_shift.site.name'))->toBe('Playwright Attendance House')
        ->and(collect($response->inertiaProps('active_shift.site.residents'))->pluck('id')->all())
        ->toBe([$this->attendancePerson->id])
        ->and($response->inertiaProps('clock.open_session.id'))->toBe($session->id);
    foreach (['active_shift.tasks', 'clock.open_session.tasks'] as $path) {
        $tasks = collect($response->inertiaProps($path));
        expect($tasks->pluck('id')->all())->toBe([$task->id])
            ->and($tasks->first()['label'])->toBe('Playwright checklist task')
            ->and($tasks->first()['can_complete'])->toBeTrue();
    }
    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))
        ->not->toContain('Private checklist', 'Private co-resident work');
    expect(app(HandoverWorkerNotes::class)->editor($shift, $worker)['people'])->toBe([
        ['id' => $this->attendancePerson->id, 'name' => 'Playwright Attendance'],
    ]);

    $this->putJson('/my-day/tasks/'.$privateTask->id.'/completion', [
        'is_completed' => true,
        'expected_version' => (int) $privateTask->version,
    ])->assertForbidden();
    expect($privateTask->refresh()->getRawOriginal())->toBe($privateBefore)
        ->and(AuditLog::query()->count())->toBe($auditBefore);
})->with(['desktop' => 'sw4@demo.test', 'mobile' => 'sw7@demo.test']);

it('replays only the two checklist assignments and preserves unrelated person pivots and authority', function () {
    $workers = User::query()->whereIn('email', ['sw4@demo.test', 'sw7@demo.test'])->orderBy('id')->get();
    $unrelatedPerson = Client::factory()->create();
    $unrelatedPerson->supportWorkers()->syncWithoutDetaching($workers->modelKeys());
    $unrelatedBefore = $unrelatedPerson->refresh()->getRawOriginal();
    $otherWorker = User::query()->where('email', 'sw2@demo.test')->sole();
    $this->attendancePerson->supportWorkers()->syncWithoutDetaching([$otherWorker->id]);
    $pivots = fn () => DB::table('client_user')->orderBy('client_id')->orderBy('user_id')
        ->get(['client_id', 'user_id'])->map(fn ($row) => (array) $row)->all();
    $before = $pivots();
    $rolesBefore = DB::table('role_user')->count();
    $overridesBefore = DB::table('permission_user')->count();

    $this->seed(FrontlineLifecycleDemoSeeder::class);

    expect($pivots())->toBe($before)
        ->and($unrelatedPerson->refresh()->getRawOriginal())->toBe($unrelatedBefore)
        ->and(DB::table('role_user')->count())->toBe($rolesBefore)
        ->and(DB::table('permission_user')->count())->toBe($overridesBefore)
        ->and($unrelatedPerson->supportWorkers()->orderBy('users.id')->pluck('users.id')->all())
        ->toBe($workers->modelKeys());
    foreach ($workers as $worker) {
        expect($this->attendancePerson->supportWorkers()->whereKey($worker->id)->count())->toBe(1)
            ->and(Shift::query()->where('notes', 'PW:active-checklist:'.$worker->email)->count())->toBe(1)
            ->and($worker->fresh()->permissionOverrides()->count())->toBe(0);
    }
});
