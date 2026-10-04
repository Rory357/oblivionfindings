<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ConsentValidationService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Database\Seeders\TrackingWorkspaceE2EConsentSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthoritativeConsentFixture;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->trackingAdmin = User::factory()->create([
        'email' => 'admin@demo.test', 'role' => 'admin', 'approved_at' => now(),
    ]);
    $this->trackingAdmin->roles()->attach(Role::query()->where('name', 'admin')->sole());
    $this->trackingSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->trackingType = ConsentType::factory()->create([
        'name' => 'Asset Location Tracking (Safety)',
        'purpose' => 'Personal safety location tracking',
        'legal_basis' => 'consent', 'active' => true, 'version' => 1,
    ]);
    $this->trackingActive = Client::factory()->create([
        'site_id' => $this->trackingSite->id, 'status' => 'active', 'user_id' => null,
        'first_name' => 'Playwright Active', 'last_name' => 'Tracking', 'preferred_name' => 'Mere Active',
    ]);
    $this->trackingWithdrawn = Client::factory()->create([
        'site_id' => $this->trackingSite->id, 'status' => 'active', 'user_id' => null,
        'first_name' => 'Playwright Withdrawn', 'last_name' => 'Tracking', 'preferred_name' => 'Ria Withdrawn',
    ]);
});

function trackingE2EConsentCounts(): array
{
    return [
        'consents' => ClientConsent::withTrashed()->count(),
        'versions' => ConsentTypeVersion::query()->count(),
        'assignments' => DeviceAssignment::query()->count(),
        'audit' => AuditLog::query()->count(),
        'roles' => DB::table('role_user')->count(),
        'overrides' => DB::table('permission_user')->count(),
    ];
}

function trackingE2EAssignment(Client $client, ClientConsent $consent, string $name): DeviceAssignment
{
    $device = Device::factory()->tracking()->create([
        'name' => $name, 'category' => 'personal_tracker',
        'latitude' => -36.8485, 'longitude' => 174.7633,
        'meta' => ['private_location_envelope' => 'PW-RAW-TRACKING-MUST-NOT-RENDER'],
    ]);

    return DeviceAssignment::query()->create([
        'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id, 'consent_id' => $consent->id,
        'assignment_type' => 'permanent', 'assigned_at' => now(),
    ]);
}

it('repairs the exact legacy synthetic self-decisions while preserving withdrawn and stopped collection', function () {
    $legacy = ClientConsent::query()->create([
        'client_id' => $this->trackingActive->id, 'consent_type_id' => $this->trackingType->id,
        'status' => 'given', 'given_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        'given_by_user_id' => $this->trackingAdmin->id, 'given_method' => 'written',
        'created_by' => $this->trackingAdmin->id,
    ]);
    expect(ConsentValidationService::isValidTrackingConsent($legacy, $this->trackingActive))->toBeFalse();
    $stopped = trackingE2EAssignment($this->trackingActive, $legacy, 'Already stopped legacy pendant');
    expect($stopped->collection_stop_reason)->toBe('consent_not_active');
    $stoppedBefore = $stopped->refresh()->getRawOriginal();
    $unchanged = collect([$this->trackingActive, $this->trackingWithdrawn, $this->trackingAdmin, $this->trackingType]);
    $before = $unchanged->map(fn ($record) => $record->refresh()->getRawOriginal())->all();
    $roleCount = DB::table('role_user')->count();
    $overrideCount = DB::table('permission_user')->count();

    $seeder = app(TrackingWorkspaceE2EConsentSeeder::class);
    $active = $seeder->seedConsent($this->trackingActive, $this->trackingType, $this->trackingAdmin, 'given');
    $withdrawn = $seeder->seedConsent($this->trackingWithdrawn, $this->trackingType, $this->trackingAdmin, 'withdrawn');
    $current = trackingE2EAssignment($this->trackingActive, $active, 'Playwright active safety pendant');
    $withdrawnAssignment = trackingE2EAssignment($this->trackingWithdrawn, $withdrawn, 'Playwright withdrawn safety pendant');

    expect($active->id)->toBe($legacy->id)
        ->and($active->site_id)->toBe($this->trackingSite->id)
        ->and($active->decision_basis)->toBe(ClientConsent::BASIS_SELF)
        ->and($active->decision_actor_user_id)->toBeNull()
        ->and($active->decision_evidence['decision_actor_kind'])->toBe('identified_client_self')
        ->and($active->decision_evidence['recorder_user_id'])->toBe($this->trackingAdmin->id)
        ->and(ConsentValidationService::isValidTrackingConsent($active, $this->trackingActive))->toBeTrue()
        ->and(ConsentValidationService::isValidTrackingConsent($withdrawn, $this->trackingWithdrawn))->toBeFalse()
        ->and($current->isCollectionActive())->toBeTrue()
        ->and($withdrawnAssignment->collection_stop_reason)->toBe('consent_withdrawn')
        ->and($withdrawnAssignment->isCollectionActive())->toBeFalse()
        ->and($stopped->refresh()->getRawOriginal())->toBe($stoppedBefore)
        ->and(app(PersonalTrackingPrivacyService::class)->assignmentAuthorisesClient($stopped, $this->trackingActive))->toBeFalse()
        ->and($unchanged->map(fn ($record) => $record->refresh()->getRawOriginal())->all())->toBe($before)
        ->and(DB::table('role_user')->count())->toBe($roleCount)
        ->and(DB::table('permission_user')->count())->toBe($overrideCount);

    $this->actingAs($this->trackingAdmin)
        ->get('/security-devices/tracking?tab=personal-safety')
        ->assertOk()->assertInertia(function ($page) use ($current, $withdrawnAssignment, $stopped): void {
            $props = $page->toArray()['props'];
            $rows = collect($props['trackingWorkspace']['activeTab']['devices']);
            $activeRow = $rows->firstWhere('id', $current->device_id);
            $withdrawnRow = $rows->firstWhere('id', $withdrawnAssignment->device_id);
            $stoppedRow = $rows->firstWhere('id', $stopped->device_id);

            expect($activeRow['privacy']['state'])->toBe('active')
                ->and($activeRow['privacy']['locationAllowed'])->toBeTrue()
                ->and($activeRow['location']['latitude'])->toBe(-36.8485)
                ->and($activeRow['location']['longitude'])->toBe(174.7633)
                ->and($activeRow['canonicalHref'])->toBe("/operations/clients/{$this->trackingActive->id}?tab=location")
                ->and($withdrawnRow['privacy']['state'])->toBe('withdrawn')
                ->and($withdrawnRow['privacy']['locationAllowed'])->toBeFalse()
                ->and($withdrawnRow['location'])->toBeNull()
                ->and($withdrawnRow['canonicalHref'])->toBeNull()
                ->and($withdrawnRow['historyHref'])->toBeNull()
                ->and($stoppedRow['privacy']['locationAllowed'])->toBeFalse()
                ->and($stoppedRow['location'])->toBeNull()
                ->and(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain('PW-RAW-TRACKING-MUST-NOT-RENDER');
        });
});

it('replays the marked decisions without replacing unrelated evidence or writing permissions', function () {
    $otherSite = Site::factory()->create();
    $other = Client::factory()->create(['site_id' => $otherSite->id]);
    $unrelated = AuthoritativeConsentFixture::manualSelf($other, $this->trackingType, $this->trackingAdmin);
    $unrelatedBefore = $unrelated->refresh()->getRawOriginal();
    $seeder = app(TrackingWorkspaceE2EConsentSeeder::class);
    $active = $seeder->seedConsent($this->trackingActive, $this->trackingType, $this->trackingAdmin, 'given');
    $withdrawn = $seeder->seedConsent($this->trackingWithdrawn, $this->trackingType, $this->trackingAdmin, 'withdrawn');
    $records = collect([$active, $withdrawn]);
    $before = $records->map(fn ($record) => $record->refresh()->getRawOriginal())->all();
    $counts = trackingE2EConsentCounts();

    expect($seeder->seedConsent($this->trackingActive, $this->trackingType, $this->trackingAdmin, 'given')->id)->toBe($active->id)
        ->and($seeder->seedConsent($this->trackingWithdrawn, $this->trackingType, $this->trackingAdmin, 'withdrawn')->id)->toBe($withdrawn->id)
        ->and($records->map(fn ($record) => $record->refresh()->getRawOriginal())->all())->toBe($before)
        ->and($unrelated->refresh()->getRawOriginal())->toBe($unrelatedBefore)
        ->and(ConsentValidationService::isValidTrackingConsent($unrelated, $other))->toBeTrue()
        ->and(trackingE2EConsentCounts())->toBe($counts);
});

it('rejects foreign identities purposes and decision status without any evidence effects', function (string $invalid) {
    $client = $this->trackingActive;
    $type = $this->trackingType;
    $recorder = $this->trackingAdmin;
    $status = 'given';
    if ($invalid === 'foreign client') {
        $client = Client::factory()->create(['site_id' => $this->trackingSite->id]);
    } elseif ($invalid === 'wrong purpose') {
        $type->update(['purpose' => 'Unrelated location purpose']);
    } elseif ($invalid === 'wrong status') {
        $status = 'withdrawn';
    } elseif ($invalid === 'withdrawn cannot be active') {
        $client = $this->trackingWithdrawn;
    } elseif ($invalid === 'foreign recorder') {
        $recorder = User::factory()->create();
    } elseif ($invalid === 'archived site') {
        $this->trackingSite->update(['archived' => true]);
    }
    $records = collect([$client, $type, $recorder, $this->trackingSite]);
    $before = $records->map(fn ($record) => $record->refresh()->getRawOriginal())->all();
    $counts = trackingE2EConsentCounts();

    expect(fn () => app(TrackingWorkspaceE2EConsentSeeder::class)->seedConsent($client, $type, $recorder, $status))
        ->toThrow(InvalidArgumentException::class)
        ->and($records->map(fn ($record) => $record->refresh()->getRawOriginal())->all())->toBe($before)
        ->and(trackingE2EConsentCounts())->toBe($counts);
})->with(['foreign client', 'wrong purpose', 'wrong status', 'withdrawn cannot be active', 'foreign recorder', 'archived site']);

it('refuses to overwrite an existing substitute decision', function () {
    $existing = ClientConsent::query()->create([
        'client_id' => $this->trackingActive->id, 'consent_type_id' => $this->trackingType->id,
        'status' => 'given', 'decision_basis' => ClientConsent::BASIS_SUBSTITUTE,
        'given_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
    ]);
    $before = $existing->refresh()->getRawOriginal();
    $counts = trackingE2EConsentCounts();

    expect(fn () => app(TrackingWorkspaceE2EConsentSeeder::class)->seedConsent(
        $this->trackingActive, $this->trackingType, $this->trackingAdmin, 'given',
    ))->toThrow(InvalidArgumentException::class)
        ->and($existing->refresh()->getRawOriginal())->toBe($before)
        ->and(trackingE2EConsentCounts())->toBe($counts);
});
