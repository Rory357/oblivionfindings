<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Models\ComplianceEvidence;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Services\ComplianceEngineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class ComplianceEngineServiceTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    public function test_create_obligation_defaults(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Privacy review',
            'Review privacy controls',
            'annual',
            $admin,
            now()->addMonths(2),
            'PRIV-100',
            [30, 7]
        );

        $this->assertInstanceOf(ComplianceObligation::class, $obligation);
        $this->assertEquals('privacy_act', $obligation->framework);
        $this->assertEquals('PRIV-100', $obligation->obligation_code);
        $this->assertEquals($admin->id, $obligation->owner_id);
        $this->assertEquals([30, 7], $obligation->reminder_days);
    }

    public function test_complete_obligation_schedules_next_occurrence(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Privacy review',
            'Review privacy controls',
            'annual',
            $admin,
            now()->addMonths(2),
            'PRIV-101',
            [30, 7],
            'medium',
            null,
            false // evidence not required for this test
        );

        $service->completeObligation($obligation, $admin);

        $obligation->refresh();
        $this->assertEquals('complete', $obligation->status);
        $this->assertEquals($admin->id, $obligation->completed_by);

        $this->assertEquals(2, ComplianceObligation::where('obligation_code', 'PRIV-101')->count());

        $next = ComplianceObligation::where('obligation_code', 'PRIV-101')
            ->where('id', '!=', $obligation->id)
            ->first();
        $this->assertNotNull($next);
        $this->assertEquals($obligation->id, $next->parent_obligation_id);
        $this->assertNotNull($next->recurrence_cycle_key);
        $this->assertTrue($next->due_date->gt($obligation->due_date));
    }

    public function test_schedule_reminders_creates_pending_entries(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Privacy review',
            'Review privacy controls',
            'annual',
            $admin,
            now()->addDays(10),
            'PRIV-102',
            [7, 1]
        );

        $service->scheduleReminders($obligation);

        $this->assertDatabaseCount('compliance_reminders', 2);
    }

    public function test_calculate_next_due_date_annual_31_dec_advances_strictly_to_next_year(): void
    {
        $service = new ComplianceEngineService();
        $from = Carbon::parse('2026-12-31');

        $next = $service->calculateNextDueDate('annual', $from);

        $this->assertEquals('2027-12-31', $next->toDateString());
        $this->assertTrue($next->gt($from));
    }

    public function test_calculate_next_due_date_annual_leap_day_advances_to_feb_28(): void
    {
        $service = new ComplianceEngineService();
        $from = Carbon::parse('2024-02-29');

        $next = $service->calculateNextDueDate('annual', $from);

        $this->assertEquals('2025-02-28', $next->toDateString());
        $this->assertTrue($next->gt($from));
    }

    public function test_calculate_next_due_date_monthly_month_end_advances_to_next_month_end(): void
    {
        $service = new ComplianceEngineService();

        // Jan 31 -> Feb 28 in non-leap year
        $fromJan = Carbon::parse('2026-01-31');
        $nextFeb = $service->calculateNextDueDate('monthly', $fromJan);
        $this->assertEquals('2026-02-28', $nextFeb->toDateString());
        $this->assertTrue($nextFeb->gt($fromJan));

        // Feb 28 -> Mar 31
        $fromFeb = Carbon::parse('2026-02-28');
        $nextMar = $service->calculateNextDueDate('monthly', $fromFeb);
        $this->assertEquals('2026-03-31', $nextMar->toDateString());
        $this->assertTrue($nextMar->gt($fromFeb));
    }

    public function test_calculate_next_due_date_quarterly_quarter_end_advances_to_next_quarter_end(): void
    {
        $service = new ComplianceEngineService();
        $from = Carbon::parse('2026-03-31');

        $next = $service->calculateNextDueDate('quarterly', $from);

        $this->assertEquals('2026-06-30', $next->toDateString());
        $this->assertTrue($next->gt($from));
    }

    public function test_complete_obligation_blocks_when_evidence_required_and_none_attached(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Mandatory Evidence Obligation',
            'Must have evidence',
            'annual',
            $admin,
            now()->addMonths(1),
            'PRIV-REQ',
            null,
            'high',
            null,
            true // evidence required
        );

        $this->expectException(ValidationException::class);
        $service->completeObligation($obligation, $admin);
    }

    public function test_complete_obligation_blocks_expired_evidence(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Expired Evidence Obligation',
            'Has expired cert',
            'annual',
            $admin,
            now()->addMonths(1),
            'PRIV-EXP',
            null,
            'high',
            null,
            true
        );

        $expired = ComplianceEvidence::create([
            'compliance_obligation_id' => $obligation->id,
            'evidence_type' => 'certification',
            'title' => 'Expired ISO Certificate',
            'file_path' => 'certs/old.pdf',
            'valid_until' => today()->subDays(5),
            'uploaded_by' => $admin->id,
            'uploaded_at' => now()->subYear(),
        ]);

        $this->expectException(ValidationException::class);
        $service->completeObligation($obligation, $admin, [$expired->id]);
    }

    public function test_complete_obligation_forbids_borrowing_foreign_evidence(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligationA = $service->createObligation(
            'privacy_act',
            'Obligation A',
            'Obligation A desc',
            'annual',
            $admin,
            now()->addMonths(1),
            'PRIV-A',
            null,
            'medium',
            null,
            true
        );

        $obligationB = $service->createObligation(
            'privacy_act',
            'Obligation B',
            'Obligation B desc',
            'annual',
            $admin,
            now()->addMonths(1),
            'PRIV-B',
            null,
            'medium',
            null,
            true
        );

        $evidenceB = ComplianceEvidence::create([
            'compliance_obligation_id' => $obligationB->id,
            'evidence_type' => 'document',
            'title' => 'Evidence of B',
            'file_path' => 'docs/b.pdf',
            'valid_until' => today()->addMonths(6),
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        try {
            $service->completeObligation($obligationA, $admin, [$evidenceB->id]);
            $this->fail('Expected foreign evidence to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('evidence_ids', $e->errors());
        }

        // Assert evidence B was NOT reparented to obligation A
        $evidenceB->refresh();
        $this->assertEquals($obligationB->id, $evidenceB->compliance_obligation_id);
        $obligationA->refresh();
        $this->assertNotEquals('complete', $obligationA->status);
    }

    public function test_complete_obligation_idempotent_replay_creates_only_one_next_cycle(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Recurring Obligation',
            'Annual recurring obligation',
            'annual',
            $admin,
            Carbon::parse('2026-12-31'),
            'PRIV-REC-1',
            [30, 7],
            'medium',
            null,
            false
        );

        // First completion
        $service->completeObligation($obligation, $admin);
        $obligation->refresh();
        $this->assertEquals('complete', $obligation->status);
        $this->assertEquals(2, ComplianceObligation::where('obligation_code', 'PRIV-REC-1')->count());

        // Replay completion
        $service->completeObligation($obligation, $admin);
        $this->assertEquals(2, ComplianceObligation::where('obligation_code', 'PRIV-REC-1')->count());
    }

    public function test_complete_obligation_enforces_optimistic_concurrency_version(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Versioned Obligation',
            'Version check',
            'annual',
            $admin,
            now()->addMonth(),
            'PRIV-VER',
            null,
            'medium',
            null,
            false
        );

        $this->expectException(HttpException::class);
        // Pass stale expected version 99 when actual version is 1
        $service->completeObligation($obligation, $admin, null, 'Notes', 99);
    }
}
