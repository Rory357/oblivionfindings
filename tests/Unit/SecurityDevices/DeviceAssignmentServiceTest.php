<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeviceAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceAssignmentService();
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

    public function test_transfer_releases_old_and_creates_new(): void
    {
        $device = Device::factory()->create();
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = User::factory()->create();

        $this->service->assign($device, DeviceAssignment::TARGET_SITE, $siteA->id, $user->id);
        $newAssignment = $this->service->transfer($device, DeviceAssignment::TARGET_SITE, $siteB->id, $user->id);

        $this->assertEquals($siteB->id, $newAssignment->assignable_id);
        $this->assertEquals(1, $device->assignments()->active()->count());
        $this->assertEquals(2, $device->assignments()->count()); // history preserved
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
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('consent_id');

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
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'status' => 'active',
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
