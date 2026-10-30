<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Database\Seeders\MyDayPreShiftBriefingE2ESeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-10-04 10:00:00', 'Pacific/Auckland')->utc());
});

afterEach(function () {
    Carbon::setTestNow();
});

function briefingFixtureReadCounts(): array
{
    return [
        'shifts' => Shift::query()->count(),
        'attendance' => HrAttendanceSession::query()->count(),
        'timesheets' => Timesheet::query()->count(),
        'audit_logs' => AuditLog::query()->count(),
        'permission_overrides' => DB::table('permission_user')->count(),
        'role_users' => DB::table('role_user')->count(),
    ];
}

it('selects the exact marked person and briefing at either edge of the local day without changing sw1', function (string $localTime) {
    Carbon::setTestNow(Carbon::parse($localTime, 'Pacific/Auckland')->utc());
    config(['features.rostering.publish' => true]);
    expect(app(RosteringFeatureFlags::class)->publishEnabled())->toBeTrue();
    $genericWorker = User::factory()->frontlineWorker()->create(['email' => 'sw1@demo.test']);
    $genericSite = Site::factory()->create();
    $genericProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $genericWorker->id,
        'primary_site_id' => $genericSite->id,
        'secondary_site_ids' => [],
    ]);
    $genericPerson = Client::factory()->create(['site_id' => $genericSite->id]);
    $genericShift = Shift::factory()->published()->create([
        'user_id' => $genericWorker->id,
        'client_id' => $genericPerson->id,
        'site_id' => $genericSite->id,
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addHours(4),
        'notes' => 'Older generic briefing must remain unchanged.',
    ]);
    $unrelatedRecords = collect([$genericWorker, $genericProfile, $genericPerson, $genericShift]);
    $unrelatedBefore = $unrelatedRecords->map(fn ($record) => $record->refresh()->getRawOriginal())->all();

    $manifest = app(MyDayPreShiftBriefingE2ESeeder::class)->seedFixture();
    $worker = User::query()->findOrFail($manifest['workerId']);
    $shift = Shift::query()->findOrFail($manifest['shiftId']);
    $person = Client::query()->findOrFail($manifest['personId']);
    expect($worker->id)->not->toBe($genericWorker->id)
        ->and($worker->roles()->pluck('name')->all())->toBe(['support_worker'])
        ->and($worker->permissionOverrides()->count())->toBe(0)
        ->and($worker->canDo('shifts.viewAssigned'))->toBeTrue()
        ->and($worker->canDo('sites.viewAll'))->toBeFalse()
        ->and(app(UserSiteAccessService::class)->accessibleSiteIds($worker))->toBe([$manifest['siteId']])
        ->and($person->supportWorkers()->pluck('users.id')->all())->toBe([$worker->id])
        ->and($shift->starts_at->isFuture())->toBeTrue()
        ->and($shift->starts_at->diffInHours(now(), true))->toBeLessThanOrEqual(36)
        ->and($shift->starts_at->copy()->timezone('Pacific/Auckland')->format('H:i'))->toBe('07:30')
        ->and($shift->published_at)->not->toBeNull();

    $beforeRead = briefingFixtureReadCounts();
    $response = $this->actingAs($worker)->get('/my-day')->assertOk();
    expect($response->inertiaProps('next_shift_briefing.id'))->toBe($manifest['shiftId'])
        ->and($response->inertiaProps('next_shift_briefing.client.id'))->toBe($manifest['personId'])
        ->and($response->inertiaProps('next_shift_briefing.client.name'))->toBe($manifest['personName'])
        ->and($response->inertiaProps('next_shift_briefing.what_to_know'))->toBe($manifest['notes'])
        ->and($response->inertiaProps('next_shift_briefing.location'))->toBe($manifest['siteName'])
        ->and($response->inertiaProps('clock.open_session'))->toBeNull()
        ->and($response->inertiaProps('active_shift'))->toBeNull()
        ->and($response->inertiaProps('previous_shift'))->toBeNull()
        ->and(briefingFixtureReadCounts())->toBe($beforeRead);

    $this->travel(1)->days();
    $replayed = app(MyDayPreShiftBriefingE2ESeeder::class)->seedFixture();
    expect($replayed)->toBe($manifest)
        ->and(Shift::query()->where('notes', MyDayPreShiftBriefingE2ESeeder::NOTES)->count())->toBe(1)
        ->and(User::query()->where('email', MyDayPreShiftBriefingE2ESeeder::WORKER_EMAIL)->count())->toBe(1)
        ->and(Client::query()->where('funding_notes', MyDayPreShiftBriefingE2ESeeder::MARKER)->count())->toBe(1)
        ->and(Shift::query()->findOrFail($manifest['shiftId'])->starts_at->isFuture())->toBeTrue()
        ->and($unrelatedRecords->map(fn ($record) => $record->refresh()->getRawOriginal())->all())->toBe($unrelatedBefore);
})->with([
    'early local day' => '2026-10-04 00:05:00',
    'late local day' => '2026-10-04 23:55:00',
]);

it('keeps the named briefing private to its assigned worker for other current Site workers', function () {
    $manifest = app(MyDayPreShiftBriefingE2ESeeder::class)->seedFixture();
    $fixtureSite = Site::query()->findOrFail($manifest['siteId']);
    $foreignSite = Site::factory()->create();
    $otherWorkers = collect([$fixtureSite, $foreignSite])->map(function (Site $site): User {
        $worker = User::factory()->frontlineWorker()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
        ]);

        return $worker;
    });
    $shiftBefore = Shift::query()->findOrFail($manifest['shiftId'])->getRawOriginal();
    $beforeReads = briefingFixtureReadCounts();

    foreach ($otherWorkers as $other) {
        $response = $this->actingAs($other)->get('/my-day')->assertOk();
        expect($response->inertiaProps('next_shift_briefing'))->toBeNull()
            ->and($response->inertiaProps('active_shift'))->toBeNull()
            ->and($response->inertiaProps('shifts'))->toBe([])
            ->and(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))
            ->not->toContain($manifest['personName'], $manifest['notes']);
    }
    expect(app(UserSiteAccessService::class)->viewableClientIds($otherWorkers->last()))
        ->not->toContain($manifest['personId'])
        ->and(Shift::query()->findOrFail($manifest['shiftId'])->getRawOriginal())->toBe($shiftBefore)
        ->and(briefingFixtureReadCounts())->toBe($beforeReads);
});

it('refuses to reset a marked shift that has begun without changing evidence or authority', function () {
    $manifest = app(MyDayPreShiftBriefingE2ESeeder::class)->seedFixture();
    $shift = Shift::query()->findOrFail($manifest['shiftId']);
    $shift->update(['status' => 'in_progress', 'actual_starts_at' => now(), 'started_by' => $manifest['workerId']]);
    $records = collect([
        $shift,
        User::query()->findOrFail($manifest['workerId']),
        HrEmployeeProfile::query()->where('user_id', $manifest['workerId'])->sole(),
        Client::query()->findOrFail($manifest['personId']),
        Site::query()->findOrFail($manifest['siteId']),
    ]);
    $before = $records->map(fn ($record) => $record->refresh()->getRawOriginal())->all();
    $countsBefore = briefingFixtureReadCounts();

    $this->travel(1)->days();
    expect(fn () => app(MyDayPreShiftBriefingE2ESeeder::class)->seedFixture())
        ->toThrow(DomainException::class, 'The briefing fixture cannot replace attendance, payroll, or unrelated live shift evidence.')
        ->and($records->map(fn ($record) => $record->refresh()->getRawOriginal())->all())->toBe($before)
        ->and(briefingFixtureReadCounts())->toBe($countsBefore);
});
