<?php

namespace Tests\Unit;

use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ComplianceAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ComplianceAlertService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->service = app(ComplianceAlertService::class);
    }

    // ──────────────────────────────────────
    // Training Expiry Alerts
    // ──────────────────────────────────────

    public function test_alert_training_expiry_creates_alert(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $alert = $this->service->alertTrainingExpiry(
            $user->id,
            'First Aid',
            now()->addDays(30)->toDateString(),
            false
        );

        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
        $this->assertEquals('compliance', $alert->source);
        $this->assertStringContainsString('training', strtolower($alert->alert_type));
        $this->assertEquals('open', $alert->status);
    }

    public function test_alert_training_expiry_expired_is_higher_severity(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $expiring = $this->service->alertTrainingExpiry(
            $user->id,
            'First Aid Expiring',
            now()->addDays(30)->toDateString(),
            false
        );

        $expired = $this->service->alertTrainingExpiry(
            $user->id,
            'First Aid Expired',
            now()->subDays(1)->toDateString(),
            true
        );

        // Expired should have higher severity than expiring
        $severityRank = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
        $this->assertGreaterThanOrEqual(
            $severityRank[$expiring->severity],
            $severityRank[$expired->severity]
        );
    }

    // ──────────────────────────────────────
    // DBS Check Alerts
    // ──────────────────────────────────────

    public function test_alert_dbs_check_issue_creates_alert(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $alert = $this->service->alertDbsCheckIssue(
            $user->id,
            'Enhanced DBS',
            'expired',
            now()->subDays(30)->toDateString()
        );

        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
        $this->assertEquals('compliance', $alert->source);
        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'source' => 'compliance',
        ]);
    }

    // ──────────────────────────────────────
    // Consent Expiry Alerts
    // ──────────────────────────────────────

    public function test_alert_consent_expiry_creates_alert(): void
    {
        $alert = $this->service->alertConsentExpiry(
            1,
            'Photo Consent',
            now()->addDays(7)->toDateString(),
            false
        );

        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
        $this->assertEquals('compliance', $alert->source);
    }

    public function test_alert_consent_expired_creates_alert(): void
    {
        $alert = $this->service->alertConsentExpiry(
            1,
            'Data Sharing Consent',
            now()->subDays(1)->toDateString(),
            true
        );

        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
    }

    // ──────────────────────────────────────
    // Care Plan Review Alerts
    // ──────────────────────────────────────

    public function test_alert_care_plan_review_due_creates_alert(): void
    {
        $alert = $this->service->alertCarePlanReviewDue(
            1,
            'Test Client',
            now()->addDays(7)->toDateString(),
            false
        );

        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
        $this->assertEquals('compliance', $alert->source);
    }

    public function test_alert_care_plan_review_overdue_creates_alert(): void
    {
        $alert = $this->service->alertCarePlanReviewDue(
            1,
            'Overdue Client',
            now()->subDays(14)->toDateString(),
            true
        );

        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
    }

    // ──────────────────────────────────────
    // Medication Error Alerts
    // ──────────────────────────────────────

    public function test_alert_medication_error_creates_alert(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $alert = $this->service->alertMedicationError(
            1,
            'wrong_dose',
            'Paracetamol',
            $user->id
        );

        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
        $this->assertEquals('compliance', $alert->source);
    }

    // ──────────────────────────────────────
    // Controlled Drug Discrepancy Alerts
    // ──────────────────────────────────────

    public function test_alert_controlled_drug_discrepancy_creates_alert(): void
    {
        $alert = $this->service->alertControlledDrugDiscrepancy(
            1,
            1,
            'Codeine',
            'stock_mismatch'
        );

        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
        $this->assertEquals('compliance', $alert->source);
    }

    // ──────────────────────────────────────
    // Break Glass Access Alerts
    // ──────────────────────────────────────

    public function test_alert_break_glass_access_creates_alert(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $alert = $this->service->alertBreakGlassAccess(
            $user->id,
            1,
            'Emergency medication required',
            'medications'
        );

        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
        $this->assertEquals('compliance', $alert->source);
    }

    // ──────────────────────────────────────
    // Deduplication
    // ──────────────────────────────────────

    public function test_duplicate_alerts_are_not_created(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $alert1 = $this->service->alertTrainingExpiry(
            $user->id,
            'First Aid',
            now()->addDays(30)->toDateString(),
            false
        );

        $alert2 = $this->service->alertTrainingExpiry(
            $user->id,
            'First Aid',
            now()->addDays(30)->toDateString(),
            false
        );

        // Should be the same alert (deduplication)
        $this->assertEquals($alert1->id, $alert2->id);
    }
}
