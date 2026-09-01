<?php

namespace Tests\Unit\Jobs;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Jobs\EscalateUnresolvedEligibilityJob;
use App\Jobs\RecalculateFutureShiftEligibility;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\Site;
use App\Models\User;
use App\Notifications\EligibilityEscalationNotification;
use App\Services\ShiftSignalService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EscalateUnresolvedEligibilityJobTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected Client $client;

    protected ServiceContext $serviceContext;

    protected User $staff;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        $this->serviceContext = ServiceContext::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->staff = User::factory()->create(['approved_at' => now()]);
        $this->manager = User::factory()->create(['approved_at' => now()]);

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'employee_number' => 'ESC-'.$this->staff->id,
            'work_email' => $this->staff->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subYear()->toDateString(),
            'is_active' => true,
            'manager_user_id' => $this->manager->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->manager->id,
            'employee_number' => 'ESC-MGR-'.$this->manager->id,
            'work_email' => $this->manager->email,
            'position_title' => 'Service Manager',
            'position_role' => 'service_manager',
            'employment_type' => 'full_time',
            'start_date' => now()->subYear()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
    }

    // ── Under threshold → no escalation ─────────────────────────────────

    public function test_recent_signal_does_not_escalate(): void
    {
        Notification::fake();

        $shift = $this->makeFutureBlockedShift();
        $this->createEligibilitySignal($shift, now()->subHours(12)); // 12h ago, threshold is 24h

        (new EscalateUnresolvedEligibilityJob(thresholdHours: 24))
            ->handle(app(ShiftStaffEligibilityService::class), app(ShiftSignalService::class));

        Notification::assertNothingSent();
    }

    // ── Over threshold + still blocked → escalation sent ────────────────

    public function test_old_signal_with_still_blocked_shift_escalates(): void
    {
        Notification::fake();

        $shift = $this->makeFutureBlockedShift();
        $this->createEligibilitySignal($shift, now()->subHours(30)); // 30h ago

        (new EscalateUnresolvedEligibilityJob(thresholdHours: 24))
            ->handle(app(ShiftStaffEligibilityService::class), app(ShiftSignalService::class));

        Notification::assertSentTo($this->manager, EligibilityEscalationNotification::class);
    }

    // ── Shift becomes valid before threshold → no escalation ────────────

    public function test_resolved_shift_does_not_escalate(): void
    {
        Notification::fake();

        $shift = $this->makeFutureShift(); // valid shift, no compliance block
        $this->createEligibilitySignal($shift, now()->subHours(30)); // signal is old

        (new EscalateUnresolvedEligibilityJob(thresholdHours: 24))
            ->handle(app(ShiftStaffEligibilityService::class), app(ShiftSignalService::class));

        // Shift is now valid — re-evaluation should pass, no escalation.
        Notification::assertNothingSent();
    }

    // ── Shift unassigned before threshold → no escalation ───────────────

    public function test_unassigned_shift_does_not_escalate(): void
    {
        Notification::fake();

        $shift = $this->makeFutureBlockedShift();
        $this->createEligibilitySignal($shift, now()->subHours(30));

        // Unassign the staff.
        $shift->update(['user_id' => null, 'status' => 'draft']);

        (new EscalateUnresolvedEligibilityJob(thresholdHours: 24))
            ->handle(app(ShiftStaffEligibilityService::class), app(ShiftSignalService::class));

        Notification::assertNothingSent();
    }

    // ── Idempotency: repeated runs don't duplicate ──────────────────────

    public function test_escalation_is_idempotent(): void
    {
        Notification::fake();

        $shift = $this->makeFutureBlockedShift();
        $this->createEligibilitySignal($shift, now()->subHours(30));

        $service = app(ShiftStaffEligibilityService::class);
        $signals = app(ShiftSignalService::class);

        // First run — should escalate.
        (new EscalateUnresolvedEligibilityJob(thresholdHours: 24))->handle($service, $signals);

        $escalationCount = ShiftSignal::where('signal_type', EscalateUnresolvedEligibilityJob::ESCALATION_SIGNAL_TYPE)->count();
        $this->assertEquals(1, $escalationCount);

        // Second run — should NOT create another escalation signal.
        Notification::fake(); // reset
        (new EscalateUnresolvedEligibilityJob(thresholdHours: 24))->handle($service, $signals);

        $escalationCountAfter = ShiftSignal::where('signal_type', EscalateUnresolvedEligibilityJob::ESCALATION_SIGNAL_TYPE)->count();
        $this->assertEquals(1, $escalationCountAfter);

        Notification::assertNothingSent();
    }

    // ── Fallback recipient when no manager hierarchy ────────────────────

    public function test_fallback_to_provider_manager_role(): void
    {
        Notification::fake();

        // Remove manager from staff profile.
        HrEmployeeProfile::where('user_id', $this->staff->id)->update(['manager_user_id' => null]);

        // Create a provider_manager.
        $providerManager = User::factory()->create(['approved_at' => now()]);
        $role = Role::firstOrCreate(['name' => 'provider_manager']);
        $providerManager->roles()->attach($role);
        HrEmployeeProfile::factory()->create([
            'user_id' => $providerManager->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $shift = $this->makeFutureBlockedShift();
        $this->createEligibilitySignal($shift, now()->subHours(30));

        (new EscalateUnresolvedEligibilityJob(thresholdHours: 24))
            ->handle(app(ShiftStaffEligibilityService::class), app(ShiftSignalService::class));

        Notification::assertSentTo($providerManager, EligibilityEscalationNotification::class);
    }

    // ── Escalation message includes age and reason ──────────────────────

    public function test_escalation_notification_contains_context(): void
    {
        Notification::fake();

        $shift = $this->makeFutureBlockedShift();
        $signalTime = now()->subHours(36);
        $this->createEligibilitySignal($shift, $signalTime);

        (new EscalateUnresolvedEligibilityJob(thresholdHours: 24))
            ->handle(app(ShiftStaffEligibilityService::class), app(ShiftSignalService::class));

        Notification::assertSentTo($this->manager, function (EligibilityEscalationNotification $n) {
            $this->assertEquals($this->staff->name, $n->staffName);
            $this->assertGreaterThanOrEqual(36, $n->hoursUnresolved);
            $this->assertNotEmpty($n->blockingReason);
            $this->assertNotEmpty($n->unresolvedSince);

            return true;
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Create a future scheduled shift with a compliance hard-stop that will block eligibility.
     */
    protected function makeFutureBlockedShift(): Shift
    {
        $role = Role::firstOrCreate(['name' => 'support_worker']);
        if (! $this->staff->roles()->where('name', 'support_worker')->exists()) {
            $this->staff->roles()->attach($role);
        }

        $requirement = HrComplianceRequirement::query()->firstOrCreate(
            ['code' => 'esc_test_req', 'tenant_id' => 1],
            [
                'name' => 'Escalation Test Requirement',
                'category' => 'compliance',
                'check_type' => 'manual',
                'hard_stop' => true,
                'is_active' => true,
            ],
        );

        HrComplianceMatrix::query()->firstOrCreate([
            'tenant_id' => 1,
            'requirement_id' => $requirement->id,
            'role' => 'support_worker',
        ], ['is_mandatory' => true]);

        HrStaffComplianceStatus::query()->updateOrCreate(
            [
                'tenant_id' => 1,
                'user_id' => $this->staff->id,
                'requirement_id' => $requirement->id,
            ],
            [
                'status' => 'expired',
                'evidence_type' => 'manual',
                'last_checked_at' => now(),
                'next_check_at' => now()->addDay(),
            ],
        );

        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(8),
            'status' => 'scheduled',
            'created_by' => $this->staff->id,
        ]);
    }

    /**
     * Create a future scheduled shift that is eligible (no blocks).
     */
    protected function makeFutureShift(): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(8),
            'status' => 'scheduled',
            'created_by' => $this->staff->id,
        ]);
    }

    protected function createEligibilitySignal(Shift $shift, $occurredAt): ShiftSignal
    {
        return ShiftSignal::query()->create([
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'client_id' => $shift->client_id,
            'user_id' => $shift->user_id,
            'signal_type' => RecalculateFutureShiftEligibility::SIGNAL_TYPE,
            'severity_hint' => 'high',
            'occurred_at' => $occurredAt,
            'idempotency_key' => hash('sha256', 'test-signal-'.$shift->id.'-'.$occurredAt->toDateString()),
            'payload' => [
                'staff_name' => $shift->staff?->name ?? 'Test',
                'blocking_reasons' => ['Test compliance block'],
                'checked_at' => $occurredAt->toIso8601String(),
            ],
        ]);
    }
}
