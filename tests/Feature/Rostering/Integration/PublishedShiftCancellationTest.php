<?php

use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

function publishedCancellationFixture(): array
{
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $role = Role::query()->create([
        'name' => 'published-cancellation-'.str()->uuid(),
        'label' => 'Published shift cancellation test',
        'level' => 10,
        'type' => 'custom',
    ]);
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'shifts.manageAny'],
        ['description' => 'Manage shifts', 'group' => 'shifts', 'module' => 'Operations'],
    );
    $role->permissions()->attach($permission);
    $actor = User::factory()->create(['role' => $role->name, 'approved_at' => now()]);
    $actor->roles()->attach($role);
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($actor, $site);
    ensureCanonicalHrStaffProfile($worker, $site);
    $period = RosterPeriod::factory()->published()->create([
        'site_id' => $site->id,
        'week_start' => '2026-05-04',
        'week_end' => '2026-05-11',
    ]);
    $shift = Shift::factory()->scheduled()->published($period)->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'roster_period_id' => $period->id,
        'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
    ]);

    return [$shift, $period, $actor];
}

it('marks a published period changed when a published shift is cancelled', function () {
    [$shift, $period, $actor] = publishedCancellationFixture();
    $this->actingAs($actor);

    expect($actor->canDo('shifts.manageAny'))->toBeTrue();
    expect($actor->canDo('reports.viewAny'))->toBeFalse();
    app(ShiftLifecycleService::class)->cancel($shift, $actor, 'Worker unavailable');

    expect($shift->fresh()->status)->toBe('cancelled');
    expect($shift->fresh()->publish_dirty_at)->not->toBeNull();
    expect($period->fresh()->status)->toBe(RosterPeriod::STATUS_CHANGED_AFTER_PUBLISH);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'shift.cancel.cascade',
        'auditable_type' => $shift->getMorphClass(),
        'auditable_id' => $shift->id,
        'user_id' => $actor->id,
    ]);
});

it('preserves the published shift and period when cancellation authority is absent', function (string $missingAuthority, int $status) {
    [$shift, $period, $actor] = publishedCancellationFixture();
    if ($missingAuthority === 'capability') {
        $actor->roles()->firstOrFail()->permissions()->detach();
    } elseif ($missingAuthority === 'site') {
        ensureCanonicalHrStaffProfile($actor, Site::factory()->create());
    } else {
        $actor->hrEmployeeProfile()->update(['is_active' => false]);
    }
    $actor = $actor->fresh();
    $this->actingAs($actor);
    $shiftBefore = $shift->fresh()->getRawOriginal();
    $periodBefore = $period->fresh()->getRawOriginal();
    $timelineCount = TimelineEvent::query()->count();
    $auditCount = AuditLog::query()->count();

    try {
        app(ShiftLifecycleService::class)->cancel($shift, $actor, 'Worker unavailable');
        $this->fail('Cancellation without current Site and capability authority must be denied.');
    } catch (HttpExceptionInterface $exception) {
        expect($exception->getStatusCode())->toBe($status);
    }

    expect($shift->fresh()->getRawOriginal())->toBe($shiftBefore);
    expect($period->fresh()->getRawOriginal())->toBe($periodBefore);
    expect(TimelineEvent::query()->count())->toBe($timelineCount);
    expect(AuditLog::query()->count())->toBe($auditCount);
})->with([
    'missing capability' => ['capability', 403],
    'different approved Site' => ['site', 403],
    'inactive employment' => ['employment', 404],
]);
