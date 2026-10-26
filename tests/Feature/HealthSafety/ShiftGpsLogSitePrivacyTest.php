<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftGpsLog;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\ShiftGpsAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

function shiftGpsSiteUser(Site $site, array $permissionKeys = []): User
{
    $user = User::factory()->create([
        'approved_at' => now(),
        'role' => 'support_worker',
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-SHIFT-GPS-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'care_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $user;
}

/** @return array{Shift, Client} */
function shiftGpsActiveShift(Site $site, User $worker): array
{
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = Shift::query()->create([
        'site_id' => $site->id,
        'client_id' => $client->id,
        'user_id' => $worker->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(3),
        'actual_starts_at' => now()->subHour(),
        'started_by' => $worker->id,
        'status' => 'in_progress',
        'is_lone_worker' => true,
        'created_by' => $worker->id,
    ]);

    return [$shift, $client];
}

function shiftGpsLiveSession(Shift $shift): LoneWorkerSession
{
    return LoneWorkerSession::query()->create([
        'user_id' => $shift->user_id,
        'site_id' => $shift->site_id,
        'client_id' => $shift->client_id,
        'shift_id' => $shift->id,
        'started_at' => now()->subMinutes(10),
        'expected_end_at' => now()->addHours(2),
        'last_check_in_at' => now()->subMinutes(5),
        'check_in_interval_minutes' => 30,
        'status' => 'active',
        'created_by' => $shift->user_id,
        'updated_by' => $shift->user_id,
    ]);
}

function assertShiftGpsForbidden(Closure $callback): void
{
    try {
        $callback();
        test()->fail('Expected Shift GPS access to be denied.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

it('derives GPS evidence from the canonical Shift worker and Site', function () {
    $site = Site::factory()->create();
    $worker = shiftGpsSiteUser($site);
    $otherWorker = shiftGpsSiteUser($site);
    [$shift] = shiftGpsActiveShift($site, $worker);

    $log = ShiftGpsLog::query()->create([
        'shift_id' => $shift->id,
        'user_id' => $worker->id,
        'event_type' => 'ping',
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'captured_at' => now()->subMinutes(5),
    ]);

    expect($log->shift_id)->toBe($shift->id)
        ->and($log->user_id)->toBe($worker->id);

    expect(fn () => ShiftGpsLog::query()->create([
        'shift_id' => $shift->id,
        'user_id' => $otherWorker->id,
        'event_type' => 'ping',
        'latitude' => -36.85,
        'longitude' => 174.76,
        'captured_at' => now(),
    ]))->toThrow(ValidationException::class);

    expect(ShiftGpsLog::query()->count())->toBe(1);
});

it('denies direct GPS access outside the Shift Site or assigned-worker manager boundary', function () {
    $allowedSite = Site::factory()->create();
    $shiftSite = Site::factory()->create();
    $restrictedManager = shiftGpsSiteUser($allowedSite, ['hazards.manage', 'assets.telemetry.view']);
    $worker = shiftGpsSiteUser($shiftSite, ['assets.telemetry.view']);
    $bystander = shiftGpsSiteUser($shiftSite, ['assets.telemetry.view']);
    $managerWithoutTelemetry = shiftGpsSiteUser($shiftSite, ['hazards.manage']);
    [$shift] = shiftGpsActiveShift($shiftSite, $worker);
    $session = shiftGpsLiveSession($shift);
    ShiftGpsLog::query()->create([
        'shift_id' => $shift->id,
        'user_id' => $worker->id,
        'event_type' => 'ping',
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'captured_at' => now()->subMinutes(5),
    ]);
    $access = app(ShiftGpsAccessService::class);

    assertShiftGpsForbidden(fn () => $access->latestForLiveSession(
        $restrictedManager,
        $shift,
        $session,
    ));
    assertShiftGpsForbidden(fn () => $access->latestForLiveSession(
        $bystander,
        $shift,
        $session,
    ));
    assertShiftGpsForbidden(fn () => $access->latestForLiveSession(
        $managerWithoutTelemetry,
        $shift,
        $session,
    ));

    expect($access->latestForLiveSession($worker, $shift, $session)?->shift_id)
        ->toBe($shift->id);
});

it('uses only a fresh ping while the safety session is live', function () {
    $site = Site::factory()->create();
    $manager = shiftGpsSiteUser($site, ['hazards.manage', 'assets.telemetry.view']);
    $worker = shiftGpsSiteUser($site);
    [$shift] = shiftGpsActiveShift($site, $worker);
    $session = shiftGpsLiveSession($shift);
    ShiftGpsLog::query()->create([
        'shift_id' => $shift->id,
        'user_id' => $worker->id,
        'event_type' => 'ping',
        'latitude' => -36.80,
        'longitude' => 174.70,
        'captured_at' => now()->subMinutes(30),
    ]);
    $fresh = ShiftGpsLog::query()->create([
        'shift_id' => $shift->id,
        'user_id' => $worker->id,
        'event_type' => 'ping',
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'captured_at' => now()->subMinutes(5),
    ]);
    $access = app(ShiftGpsAccessService::class);

    expect($access->latestForLiveSession($manager, $shift, $session)?->id)
        ->toBe($fresh->id);

    $session->update(['status' => 'completed', 'ended_at' => now()]);
    assertShiftGpsForbidden(fn () => $access->latestForLiveSession(
        $manager,
        $shift,
        $session,
    ));
});

it('redacts monitorable Shift GPS until the safety session is activated', function () {
    $site = Site::factory()->create();
    $manager = shiftGpsSiteUser($site, ['hazards.view', 'hazards.manage', 'assets.telemetry.view']);
    $worker = shiftGpsSiteUser($site);
    [$shift, $client] = shiftGpsActiveShift($site, $worker);
    ShiftGpsLog::query()->create([
        'shift_id' => $shift->id,
        'user_id' => $worker->id,
        'event_type' => 'ping',
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'captured_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($manager)
        ->get(route('health-safety.lone-workers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('options.shifts.0.id', $shift->id)
            ->where('options.shifts.0.location_lat', null)
            ->where('options.shifts.0.location_lng', null));

    $this->actingAs($manager)
        ->post(route('health-safety.lone-workers.sessions.store'), [
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            // The start command takes an exact instant with its offset, as the drawn start form sends.
            'expected_end_at' => now()->addHours(2)->toIso8601String(),
            // Every lone-worker command carries a one-time key, as the drawn start form sends.
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Stephan, 25 Oct ("show it, with time, to GPS users"): the Shift's ping is never copied into the session record;
    // the session page reads it live, with its time, for telemetry viewers.
    $session = LoneWorkerSession::query()->latest('id')->firstOrFail();
    expect($session->location_lat)->toBeNull()
        ->and($session->location_lng)->toBeNull();
    $gps = $this->getJson(route('health-safety.lone-workers.sessions.show', $session))->assertOk()->json('detail.shift_gps');
    expect($gps['latitude'])->toBe(-36.8485)
        ->and($gps['longitude'])->toBe(174.7633)
        ->and($gps['is_old'])->toBeFalse();
});

it('shows the linked Shift ping with its time to a telemetry viewer and marks an old ping old', function () {
    $this->freezeTime();
    $site = Site::factory()->create();
    $viewer = shiftGpsSiteUser($site, ['hazards.view', 'assets.telemetry.view']);
    $worker = shiftGpsSiteUser($site);
    [$shift] = shiftGpsActiveShift($site, $worker);
    $session = shiftGpsLiveSession($shift);
    ShiftGpsLog::query()->create(['shift_id' => $shift->id, 'user_id' => $worker->id, 'event_type' => 'ping',
        'latitude' => -36.8485, 'longitude' => 174.7633, 'captured_at' => now()->subMinutes(40)]);

    $gps = $this->actingAs($viewer)->getJson(route('health-safety.lone-workers.sessions.show', $session))
        ->assertOk()->json('detail.shift_gps');
    expect($gps['latitude'])->toBe(-36.8485)
        ->and($gps['longitude'])->toBe(174.7633)
        ->and($gps['captured_at'])->toBe(now()->subMinutes(40)->toIso8601String())
        ->and($gps['is_old'])->toBeTrue()
        ->and($gps['fresh_minutes'])->toBe(15);

    ShiftGpsLog::query()->create(['shift_id' => $shift->id, 'user_id' => $worker->id, 'event_type' => 'ping',
        'latitude' => -36.8501, 'longitude' => 174.7650, 'captured_at' => now()->subMinutes(3)]);
    $gps = $this->getJson(route('health-safety.lone-workers.sessions.show', $session))->assertOk()->json('detail.shift_gps');
    expect($gps['latitude'])->toBe(-36.8501)->and($gps['is_old'])->toBeFalse();

    // Nothing is copied: the session record still has no position.
    expect($session->fresh()->location_lat)->toBeNull()->and($session->fresh()->location_lng)->toBeNull();
});

it('gives a viewer without telemetry access no Shift position on the session page', function () {
    $site = Site::factory()->create();
    $viewer = shiftGpsSiteUser($site, ['hazards.view']);
    $worker = shiftGpsSiteUser($site);
    [$shift] = shiftGpsActiveShift($site, $worker);
    $session = shiftGpsLiveSession($shift);
    ShiftGpsLog::query()->create(['shift_id' => $shift->id, 'user_id' => $worker->id, 'event_type' => 'ping',
        'latitude' => -36.8485, 'longitude' => 174.7633, 'captured_at' => now()->subMinutes(5)]);

    $response = $this->actingAs($viewer)->getJson(route('health-safety.lone-workers.sessions.show', $session))->assertOk();
    expect($response->json('detail.shift_gps'))->toBeNull()
        ->and($response->getContent())->not->toContain('-36.8485')
        ->and($response->getContent())->not->toContain('174.7633');
});

it('shows no Shift position once the session has ended', function () {
    $site = Site::factory()->create();
    $viewer = shiftGpsSiteUser($site, ['hazards.view', 'assets.telemetry.view']);
    $worker = shiftGpsSiteUser($site);
    [$shift] = shiftGpsActiveShift($site, $worker);
    $session = shiftGpsLiveSession($shift);
    ShiftGpsLog::query()->create(['shift_id' => $shift->id, 'user_id' => $worker->id, 'event_type' => 'ping',
        'latitude' => -36.8485, 'longitude' => 174.7633, 'captured_at' => now()->subMinutes(5)]);
    LoneWorkerSession::query()->whereKey($session->id)->update(['status' => 'completed', 'ended_at' => now()]);

    $response = $this->actingAs($viewer)->getJson(route('health-safety.lone-workers.sessions.show', $session))->assertOk();
    expect($response->json('detail.shift_gps'))->toBeNull()
        ->and($response->getContent())->not->toContain('-36.8485');
});
