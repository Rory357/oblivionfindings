<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\ShiftReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftReplacementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftReplacementService $service;

    protected User $manager;

    protected User $currentStaff;

    protected User $replacementStaff;

    protected Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShiftReplacementService::class);
        $this->manager = User::factory()->create();
        $this->currentStaff = User::factory()->create();
        $this->replacementStaff = User::factory()->create();
        $client = Client::factory()->create();
        $serviceContext = ServiceContext::factory()->create();

        $this->shift = Shift::factory()->create([
            'client_id' => $client->id,
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
            'organization_id' => $this->manager->organization_id,
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

        $this->service->approveFromOpenPosition($primaryPosition->fresh(['replacementRequest', 'claimer']), $this->manager);

        $replacement->refresh();
        $otherPosition->refresh();

        $this->assertSame(ShiftReplacementService::APPROVED, $replacement->status);
        $this->assertSame($this->manager->id, $replacement->approved_by);
        $this->assertSame('cancelled', $otherPosition->status);
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
}
