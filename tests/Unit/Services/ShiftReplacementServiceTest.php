<?php

namespace Tests\Unit\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class ShiftReplacementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftReplacementService $service;

    protected User $manager;

    protected User $currentStaff;

    protected User $replacementStaff;

    protected Shift $shift;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        $this->manager = User::factory()->create(['role' => 'admin']);
        $this->currentStaff = User::factory()->frontlineWorker()->create();
        $this->replacementStaff = User::factory()->frontlineWorker()->create();
        foreach ([$this->manager, $this->currentStaff, $this->replacementStaff] as $staff) {
            $this->assignToSite($staff, $this->site);
        }
        $this->grantPermissions($this->manager, ['shifts.manageAny', 'job_board.approve']);
        $this->grantPermissions($this->currentStaff, ['shifts.update']);

        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('evaluate')->andReturn(new EligibilityResult(
                is_allowed: true,
                blocking_reasons: [],
                warnings: [],
                checked_rules: [],
                overrideable_warnings: [],
            ));
        });
        $this->service = app(ShiftReplacementService::class);

        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $serviceContext = ServiceContext::factory()->create();

        $this->shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $this->currentStaff->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(17, 0),
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]);
    }

    public function test_request_replacement_creates_request_open_position_and_timeline_event(): void
    {
        $replacement = $this->service->request($this->shift, $this->currentStaff, [
            'reason' => 'Unavailable for shift',
            'notes' => 'Need cover',
            'required_skills' => ['lifting'],
            'publish_to_job_board' => true,
        ]);

        $this->assertSame(ShiftReplacementService::REQUESTED, $replacement->status);
        $this->assertNotNull($replacement->openPosition);
        $this->assertDatabaseHas('timeline_events', [
            'source_type' => ShiftReplacementRequest::class,
            'source_id' => $replacement->id,
            'type' => 'shift_replacement_requested',
        ]);
    }

    public function test_claim_and_approve_replacement_flow_updates_request_and_cancels_other_positions(): void
    {
        Notification::fake();

        $replacement = $this->service->request($this->shift, $this->currentStaff, [
            'reason' => 'Unavailable for shift',
            'publish_to_job_board' => true,
        ]);

        $primaryPosition = $replacement->openPosition()->firstOrFail();
        $primaryPosition->update([
            'status' => 'claimed',
            'claimed_by' => $this->replacementStaff->id,
            'claimed_at' => now(),
        ]);

        $otherPosition = ShiftOpenPosition::query()->create([
            'shift_id' => $this->shift->id,
            'replacement_request_id' => $replacement->id,
            'status' => 'open',
        ]);

        $this->service->syncClaimFromOpenPosition($primaryPosition->fresh(['replacementRequest', 'claimer']));
        $replacement->refresh();
        $this->assertSame(ShiftReplacementService::CLAIMED, $replacement->status);
        $this->assertSame($this->replacementStaff->id, $replacement->replacement_user_id);
        Notification::assertSentTo($this->currentStaff, AppEventNotification::class, function (AppEventNotification $notification) {
            return $notification->payload['title'] === 'Shift replacement claim submitted';
        });
        $this->service->syncClaimFromOpenPosition($primaryPosition->fresh(['replacementRequest', 'claimer']));
        Notification::assertSentToTimes($this->currentStaff, AppEventNotification::class, 1);

        $this->service->approveFromOpenPosition($primaryPosition->fresh(['replacementRequest', 'claimer']), $this->manager);
        $this->service->approveFromOpenPosition($primaryPosition->fresh(['replacementRequest', 'claimer']), $this->manager);

        $replacement->refresh();
        $otherPosition->refresh();

        $this->assertSame(ShiftReplacementService::APPROVED, $replacement->status);
        $this->assertSame($this->manager->id, $replacement->approved_by);
        $this->assertSame('cancelled', $otherPosition->status);
        Notification::assertSentToTimes($this->replacementStaff, AppEventNotification::class, 1);
    }

    public function test_cancel_replacement_updates_active_request_and_open_position(): void
    {
        Notification::fake();

        $replacement = $this->service->request($this->shift, $this->currentStaff, [
            'reason' => 'Unavailable for shift',
            'publish_to_job_board' => true,
        ]);

        $cancelled = $this->service->cancel($replacement->fresh(['openPosition', 'shift.client']), $this->manager);

        $this->assertSame(ShiftReplacementService::CANCELLED, $cancelled->status);
        $this->assertSame('cancelled', $cancelled->openPosition?->status);
        Notification::assertSentTo($this->currentStaff, AppEventNotification::class, function (AppEventNotification $notification) {
            return $notification->payload['title'] === 'Shift replacement cancelled';
        });
    }

    public function test_manual_assignment_resolves_replacement_and_fills_open_position(): void
    {
        $replacement = $this->service->request($this->shift, $this->currentStaff, [
            'reason' => 'Unavailable for shift',
            'publish_to_job_board' => true,
        ]);

        $this->service->resolveFromManualAssignment($this->shift->fresh(), $this->replacementStaff->id, $this->manager);

        $replacement->refresh();
        $openPosition = $replacement->openPosition()->firstOrFail()->fresh();

        $this->assertSame(ShiftReplacementService::APPROVED, $replacement->status);
        $this->assertSame($this->replacementStaff->id, $replacement->replacement_user_id);
        $this->assertSame('filled', $openPosition->status);
    }

    public function test_cancelled_shift_cannot_be_approved_mid_replacement_workflow(): void
    {
        $replacement = $this->service->request($this->shift, $this->currentStaff, [
            'reason' => 'Unavailable for shift',
            'publish_to_job_board' => true,
        ]);

        $position = $replacement->openPosition()->firstOrFail();
        $position->update([
            'status' => 'claimed',
            'claimed_by' => $this->replacementStaff->id,
            'claimed_at' => now(),
        ]);
        $this->service->syncClaimFromOpenPosition($position->fresh(['replacementRequest', 'claimer']));

        $this->shift->update(['status' => 'cancelled']);

        $this->expectException(ValidationException::class);

        $this->service->approveFromOpenPosition($position->fresh(['replacementRequest', 'shift']), $this->manager);
    }

    public function test_claimant_must_still_be_currently_eligible_for_the_shift_site(): void
    {
        $replacement = $this->service->request($this->shift, $this->currentStaff, [
            'reason' => 'Unavailable for shift',
            'publish_to_job_board' => true,
        ]);
        $position = $replacement->openPosition()->firstOrFail();
        $position->update([
            'status' => 'claimed',
            'claimed_by' => $this->replacementStaff->id,
            'claimed_at' => now(),
        ]);
        $foreignSite = Site::factory()->create();
        $this->replacementStaff->hrEmployeeProfile()->update([
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
        ]);

        try {
            $this->service->syncClaimFromOpenPosition($position->fresh(['replacementRequest', 'claimer']));
            $this->fail('A claimant outside the Shift Site should not enter the replacement lifecycle.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'The selected staff member is not currently eligible to work at this Shift Site.',
                $exception->errors()['replacement'][0] ?? null,
            );
        }

        $this->assertDatabaseHas('shift_replacement_requests', [
            'id' => $replacement->id,
            'status' => ShiftReplacementService::REQUESTED,
            'replacement_user_id' => null,
        ]);
    }

    private function assignToSite(User $user, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-REPLACE-'.$user->id,
            'work_email' => $user->email,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    /** @param array<int, string> $keys */
    private function grantPermissions(User $user, array $keys): void
    {
        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key],
            );
            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }
    }
}
