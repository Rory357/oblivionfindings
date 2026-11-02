<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\SafeWorkProcedureService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * G4-14 (board approved by Stephan 1 Nov 2026): the Safe work procedures lead block — published procedures past their
 * review date with their owner, and the areas that have no published procedure yet.
 */
class ProcedureRegisterSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_overdue_reviews_lead_and_the_areas_without_a_procedure_are_named(): void
    {
        $officer = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        $officer->roles()->attach(Role::where('name', 'health_safety_officer')->first());
        HrEmployeeProfile::factory()->create(['user_id' => $officer->id, 'primary_site_id' => Site::factory()->create()->id]);
        $overdue = SafeWorkProcedure::factory()->reviewOverdue()->create(['category' => 'manual_handling']);
        SafeWorkProcedure::factory()->approved()->create(['category' => 'medication', 'review_date' => now()->addYear()]);
        SafeWorkProcedure::factory()->draft()->create(['category' => 'fire_safety']);

        $this->actingAs($officer)->get('/health-safety/procedures')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/procedures/index')
                ->where('register_summary.review_overdue', 1)
                ->has('register_summary.lead', 1)
                ->where('register_summary.lead.0.id', $overdue->id)
                ->has('register_summary.lead.0.owner')
                // A draft isn't a published procedure, so its area still counts as missing.
                ->where('register_summary.gap_categories', array_values(array_diff(SafeWorkProcedureService::CATEGORIES, ['manual_handling', 'medication']))));
    }
}
