<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsRiskAssessment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * G4-14 (board approved by Stephan 1 Nov 2026): the Risk assessments header counts and the one lead block — overdue
 * reviews first (oldest first), then drafts waiting to be approved, then reviews due in the next 7 days — over the same
 * register as the counts. "Reviewed · last 30 days" counts each assessment reviewed in that time once.
 */
class RiskAssessmentRegisterSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31T01:00:00Z'));
        $this->seed(RbacSeeder::class);
    }

    public function test_the_lead_block_and_counts(): void
    {
        $officer = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        $officer->roles()->attach(Role::where('name', 'health_safety_officer')->first());
        $overdue = HsRiskAssessment::factory()->active()->create(['title' => 'Manual handling — hoist transfers', 'review_due_at' => '2026-10-20']);
        $draft = HsRiskAssessment::factory()->create(['title' => 'Kitchen knives']);
        $soon = HsRiskAssessment::factory()->active()->create(['title' => 'Falls — Aroha Kingi', 'review_due_at' => '2026-11-06']);
        HsRiskAssessment::factory()->active()->create(['review_due_at' => '2026-11-25']);
        HsRiskAssessment::factory()->active()->create(['review_due_at' => '2027-06-01']);
        foreach ([now()->subDays(3), now()->subDays(2)] as $at) {
            AuditLog::query()->forceCreate(['action' => 'risk_assessment.review_completed', 'auditable_type' => $soon->getMorphClass(),
                'auditable_id' => $soon->id, 'user_id' => $officer->id, 'meta' => [], 'created_at' => $at, 'updated_at' => $at]);
        }

        $this->actingAs($officer)->get('/health-safety/risk-assessments')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/risk-assessments/index')
                ->where('register_summary.boxes.reviews_overdue', 1)
                ->where('register_summary.boxes.reviews_due_30d', 2)
                ->where('register_summary.boxes.awaiting_approval', 1)
                ->where('register_summary.boxes.reviewed_30d', 1)
                ->where('register_summary.lead_total', 3)
                ->where('register_summary.lead.0.kind', 'review_overdue')
                ->where('register_summary.lead.0.row.id', $overdue->id)
                ->where('register_summary.lead.0.review_due_at', '2026-10-20')
                ->where('register_summary.lead.1.kind', 'draft')
                ->where('register_summary.lead.1.row.id', $draft->id)
                ->where('register_summary.lead.2.kind', 'review_soon')
                ->where('register_summary.lead.2.row.id', $soon->id));
    }
}
