<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\Site;
use App\Models\User;
use App\Services\ConsentValidationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeviceAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceAssignmentService;
    }

    public function test_assign_device_to_site(): void
    {
        $device = Device::factory()->create();
        $site = Site::factory()->create();
        $user = User::factory()->create();

        $assignment = $this->service->assign(
            $device,
            DeviceAssignment::TARGET_SITE,
            $site->id,
            $user->id,
        );

        $this->assertNotNull($assignment->id);
        $this->assertEquals($device->id, $assignment->device_id);
        $this->assertEquals('site', $assignment->assignable_type);
        $this->assertEquals($site->id, $assignment->assignable_id);
        $this->assertNull($assignment->released_at);
        $this->assertEquals(AssignmentType::Permanent, $assignment->assignment_type);
    }

    public function test_assign_releases_previous_active_assignment(): void
    {
        $device = Device::factory()->create();
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = User::factory()->create();

        $first = $this->service->assign($device, DeviceAssignment::TARGET_SITE, $siteA->id, $user->id);
        $second = $this->service->assign($device, DeviceAssignment::TARGET_SITE, $siteB->id, $user->id);

        $first->refresh();

        $this->assertNotNull($first->released_at);
        $this->assertNull($second->released_at);
        $this->assertEquals(1, $device->assignments()->active()->count());
    }

    public function test_assign_locks_the_canonical_device_and_accepts_a_historical_assignment_time(): void
    {
        $device = Device::factory()->create();
        $site = Site::factory()->create();
        $assignedAt = now()->subYears(2)->startOfSecond();
        $queries = collect();
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });

        $assignment = $this->service->assign(
            device: $device,
            assignableType: DeviceAssignment::TARGET_SITE,
            assignableId: $site->id,
            assignedByUserId: null,
            assignedAt: $assignedAt,
        );

        $this->assertSame($assignedAt->toDateTimeString(), $assignment->assigned_at->toDateTimeString());
        $this->assertTrue($queries->contains(
            fn (string $sql): bool => str_contains($sql, 'from `devices`')
                && str_contains($sql, 'for update'),
        ), 'The canonical Device row must be locked before replacing an assignment.');
    }

    public function test_release_sets_released_at(): void
    {
        $device = Device::factory()->create();
        $site = Site::factory()->create();
        $user = User::factory()->create();

        $this->service->assign($device, DeviceAssignment::TARGET_SITE, $site->id, $user->id);
        $released = $this->service->release($device, $user->id);

        $this->assertNotNull($released->released_at);
        $this->assertEquals(0, $device->assignments()->active()->count());
    }

    public function test_release_returns_null_when_no_active_assignment(): void
    {
        $device = Device::factory()->create();
        $user = User::factory()->create();

        $result = $this->service->release($device, $user->id);

        $this->assertNull($result);
    }

    public function test_target_scoped_release_does_not_release_a_device_that_has_moved_elsewhere(): void
    {
        $device = Device::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $assignment = $this->service->assign(
            $device,
            DeviceAssignment::TARGET_SITE,
            $site->id,
            $user->id,
        );

        $released = $this->service->releaseForTarget(
            $device,
            DeviceAssignment::TARGET_CLIENT,
            $client->id,
            $user->id,
        );

        $this->assertNull($released);
        $this->assertNull($assignment->fresh()->released_at);
        $this->assertSame(1, $device->assignments()->active()->count());
    }

    public function test_transfer_releases_old_and_creates_new(): void
    {
        $device = Device::factory()->create();
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = User::factory()->create();

        $this->service->assign($device, DeviceAssignment::TARGET_SITE, $siteA->id, $user->id);
        $newAssignment = $this->service->transfer($device, DeviceAssignment::TARGET_SITE, $siteB->id, $user->id);

        $this->assertEquals($siteB->id, $newAssignment->assignable_id);
        $this->assertEquals($siteB->id, $newAssignment->custody_site_id);
        $this->assertEquals(1, $device->assignments()->active()->count());
        $this->assertEquals(2, $device->assignments()->count()); // history preserved
        $this->assertEquals($siteA->id, $device->assignments()->released()->sole()->custody_site_id);
    }

    public function test_stale_transfer_race_serializes_on_device_and_preserves_the_winner_and_history(): void
    {
        $device = Device::factory()->create();
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $siteC = Site::factory()->create();
        $user = User::factory()->create();
        $initial = $this->service->assign($device, DeviceAssignment::TARGET_SITE, $siteA->id, $user->id);
        $staleDevice = Device::query()->findOrFail($device->id);

        $winner = $this->service->transfer(
            $device,
            DeviceAssignment::TARGET_SITE,
            $siteB->id,
            $user->id,
        );

        try {
            $this->service->transfer(
                $staleDevice,
                DeviceAssignment::TARGET_SITE,
                $siteC->id,
                $user->id,
                authorizeLockedDevice: function (Device $lockedDevice) use ($initial): void {
                    $active = $lockedDevice->assignments()
                        ->whereNull('released_at')
                        ->lockForUpdate()
                        ->firstOrFail();
                    if ((int) $active->id !== (int) $initial->id) {
                        throw new \RuntimeException('Stale transfer lost the custody race.');
                    }
                },
            );
            $this->fail('The stale transfer should not replace the winning custody row.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Stale transfer lost the custody race.', $exception->getMessage());
        }

        $active = $device->assignments()->whereNull('released_at')->sole();
        $this->assertSame($winner->id, $active->id);
        $this->assertSame($siteB->id, (int) $active->custody_site_id);
        $this->assertSame(2, $device->assignments()->count());
        $this->assertSame($siteA->id, (int) $initial->fresh()->custody_site_id);
        $this->assertNotNull($initial->fresh()->released_at);
    }

    public function test_purpose_validation_failure_rolls_back_without_releasing_current_custody(): void
    {
        $device = Device::factory()->tracking()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $user = User::factory()->create();
        $current = $this->service->assign(
            $device,
            DeviceAssignment::TARGET_SITE,
            $site->id,
            $user->id,
        );
        $genericConsent = ClientConsent::query()->create([
            'client_id' => $client->id,
            'consent_type_id' => ConsentType::factory()->create([
                'name' => 'Fleet Tracking',
                'active' => true,
            ])->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        try {
            $this->service->transfer(
                $device,
                DeviceAssignment::TARGET_CLIENT,
                $client->id,
                $user->id,
                consentId: $genericConsent->id,
                validateLockedConsent: function (?ClientConsent $lockedConsent): void {
                    if (! $lockedConsent
                        || ! ConsentValidationService::isValidResidentLocationConsent($lockedConsent)) {
                        throw new \InvalidArgumentException('Consent purpose rejected under lock.');
                    }
                },
            );
            $this->fail('A generic consent must not replace current custody for resident tracking.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Consent purpose rejected under lock.', $exception->getMessage());
        }

        $this->assertNull($current->fresh()->released_at);
        $this->assertSame(1, $device->assignments()->whereNull('released_at')->count());
        $this->assertSame(1, $device->assignments()->count());
    }

    public function test_loan_assignment_with_expected_return_date(): void
    {
        $device = Device::factory()->create();
        $site = Site::factory()->create();
        $user = User::factory()->create();
        $returnDate = now()->addDays(14);

        $assignment = $this->service->assign(
            $device,
            DeviceAssignment::TARGET_SITE,
            $site->id,
            $user->id,
            AssignmentType::Loan,
            $returnDate,
        );

        $this->assertEquals(AssignmentType::Loan, $assignment->assignment_type);
        $this->assertEquals($returnDate->toDateTimeString(), $assignment->expected_return_at->toDateTimeString());
    }

    public function test_client_assignment_requires_consent(): void
    {
        $device = Device::factory()->tracking()->create();
        $client = Client::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('assignment-linked location-tracking consent');

        $this->service->assign(
            $device,
            DeviceAssignment::TARGET_CLIENT,
            $client->id,
            $user->id,
        );
    }

    public function test_client_assignment_succeeds_with_consent(): void
    {
        $device = Device::factory()->tracking()->create();
        $client = Client::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => ConsentType::factory()->create([
                'name' => 'Personal Tracker (Wandering Risk)',
                'purpose' => 'Client personal safety tracking',
            ])->id,
            'status' => 'given',
            'given_at' => now(),
            'given_by_user_id' => $user->id,
            'given_method' => 'verbal',
        ]);

        $assignment = $this->service->assign(
            $device,
            DeviceAssignment::TARGET_CLIENT,
            $client->id,
            $user->id,
            consentId: $consent->id,
        );

        $this->assertNotNull($assignment->id);
        $this->assertEquals($consent->id, $assignment->consent_id);
    }

    public function test_invalid_target_type_throws(): void
    {
        $device = Device::factory()->create();
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid assignable type');

        $this->service->assign($device, 'invalid_type', 1, $user->id);
    }

    public function test_overdue_loans_scope(): void
    {
        $device = Device::factory()->create();
        $site = Site::factory()->create();
        $user = User::factory()->create();

        // Overdue loan
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Loan,
            'assigned_at' => now()->subDays(30),
            'expected_return_at' => now()->subDays(5),
            'assigned_by_user_id' => $user->id,
        ]);

        // Not overdue (future return)
        $device2 = Device::factory()->create();
        DeviceAssignment::create([
            'device_id' => $device2->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Loan,
            'assigned_at' => now(),
            'expected_return_at' => now()->addDays(14),
            'assigned_by_user_id' => $user->id,
        ]);

        $this->assertCount(1, DeviceAssignment::overdueLoans()->get());
    }
}
