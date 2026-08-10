<?php

namespace Tests\Feature\Sites;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteCertification;
use App\Models\SiteComplianceCheck;
use App\Models\SiteCoverageRequirement;
use App\Models\SiteFeedback;
use App\Models\SiteStaffRequirement;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SiteComplianceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $siteA;

    private Site $siteB;

    protected function beforeRefreshingDatabase(): void
    {
        if (Schema::hasTable('site_compliance_checks')
            && ! Schema::hasColumn('site_compliance_checks', 'notes')) {
            Schema::table('site_compliance_checks', function (Blueprint $table): void {
                $table->text('notes')->nullable()->after('risk_rating');
            });
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->siteA = Site::factory()->create(['name' => 'Compliance Site A']);
        $this->siteB = Site::factory()->create(['name' => 'Compliance Site B']);
    }

    public function test_dashboard_uses_site_owned_records_and_the_props_rendered_by_the_page(): void
    {
        $certification = SiteCertification::query()->create([
            'site_id' => $this->siteA->id,
            'certification_type' => 'fire_safety',
            'name' => 'Current fire certificate',
            'status' => 'current',
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);
        SiteCertification::query()->create([
            'site_id' => $this->siteB->id,
            'certification_type' => 'food_safety',
            'name' => 'Hidden Site certificate',
            'status' => 'expired',
        ]);

        $check = SiteComplianceCheck::query()->create([
            'site_id' => $this->siteA->id,
            'check_type' => 'fire_drill',
            'scheduled_date' => now()->addWeek()->toDateString(),
            'status' => 'scheduled',
            'notes' => 'Use the east assembly point.',
        ]);
        SiteComplianceCheck::query()->create([
            'site_id' => $this->siteB->id,
            'check_type' => 'vehicle_check',
            'scheduled_date' => now()->addWeek()->toDateString(),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/compliance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/compliance/Index')
                ->has('certifications', 1)
                ->where('certifications.0.id', $certification->id)
                ->has('compliance_checks', 1)
                ->where('compliance_checks.0.id', $check->id)
                ->where('compliance_checks.0.notes', 'Use the east assembly point.')
                ->where('stats.total_certs', 1)
                ->where('stats.checks_scheduled', 1)
                ->where('can.manage_compliance', true));

        $this->assertStringNotContainsString('Hidden Site certificate', $response->getContent());
    }

    public function test_certification_and_check_lifecycles_use_the_real_routes_and_serialized_site_mutations(): void
    {
        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/certifications", [
                'certification_type' => 'building_wof',
                'name' => 'Building warrant of fitness',
                'expiry_date' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect();

        $certification = SiteCertification::query()->sole();
        $this->assertSame('current', $certification->status);

        $this->actingAs($this->admin)
            ->put("/sites/{$this->siteA->id}/certifications/{$certification->id}", [
                'name' => 'Building WoF 2026',
                'status' => 'current',
            ])
            ->assertRedirect();
        $this->assertSame('Building WoF 2026', $certification->fresh()->name);

        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/compliance-checks", [
                'check_type' => 'health_safety_walkthrough',
                'scheduled_date' => now()->addDay()->toDateString(),
                'notes' => 'Inspect the network and medication rooms.',
            ])
            ->assertRedirect();

        $check = SiteComplianceCheck::query()->sole();
        $this->assertSame('Inspect the network and medication rooms.', $check->notes);

        $this->actingAs($this->admin)
            ->patch("/sites/{$this->siteA->id}/compliance-checks/{$check->id}/complete", [
                'findings' => 'No open findings.',
                'risk_rating' => 'low',
            ])
            ->assertRedirect();

        $check->refresh();
        $this->assertSame('completed', $check->status);
        $this->assertSame($this->admin->id, $check->completed_by);
        $this->assertNotNull($check->completed_date);

        $this->actingAs($this->admin)
            ->patchJson("/sites/{$this->siteA->id}/compliance-checks/{$check->id}/complete", [])
            ->assertStatus(409);
        $this->assertSame('completed', $check->fresh()->status);
    }

    public function test_staff_and_coverage_identities_are_site_scoped_and_references_cannot_cross_sites(): void
    {
        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/staff-requirements", [
                'requirement_name' => ' First Aid ',
                'category' => 'mandatory',
                'certification_required' => true,
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/staff-requirements", [
                'requirement_name' => 'First Aid',
                'category' => 'mandatory',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requirement_name');

        $serviceContextA = ServiceContext::query()->create([
            'site_id' => $this->siteA->id,
            'type' => 'residential',
            'name' => 'Site A residential',
            'is_active' => true,
        ]);
        $serviceContextB = ServiceContext::query()->create([
            'site_id' => $this->siteB->id,
            'type' => 'residential',
            'name' => 'Site B residential',
            'is_active' => true,
        ]);

        $base = [
            'name' => 'Overnight support',
            'coverage_type' => 'overnight',
            'day_of_week' => 'mon',
            'starts_time' => '22:00',
            'ends_time' => '07:00',
            'minimum_staff' => 2,
        ];

        $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/coverage-requirements", [
                ...$base,
                'service_context_id' => $serviceContextB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_context_id');

        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/coverage-requirements", [
                ...$base,
                'service_context_id' => $serviceContextA->id,
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/coverage-requirements", [
                ...$base,
                'service_context_id' => $serviceContextA->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('site_staff_requirements', 1);
        $this->assertDatabaseCount('site_coverage_requirements', 1);
    }

    public function test_wrong_site_direct_objects_are_concealed_without_mutation(): void
    {
        config()->set('app.debug', false);
        $certification = SiteCertification::query()->create([
            'site_id' => $this->siteB->id,
            'certification_type' => 'fire_safety',
            'name' => 'Private certification',
            'status' => 'current',
        ]);
        $check = SiteComplianceCheck::query()->create([
            'site_id' => $this->siteB->id,
            'check_type' => 'fire_drill',
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);
        $staffRequirement = SiteStaffRequirement::query()->create([
            'site_id' => $this->siteB->id,
            'requirement_name' => 'Private requirement',
            'category' => 'mandatory',
            'is_active' => true,
        ]);
        $coverage = SiteCoverageRequirement::query()->create([
            'site_id' => $this->siteB->id,
            'name' => 'Private cover',
            'coverage_type' => 'day',
            'day_of_week' => 'tue',
            'starts_time' => '08:00',
            'ends_time' => '16:00',
            'minimum_staff' => 1,
            'is_active' => true,
        ]);
        $feedback = SiteFeedback::query()->create([
            'site_id' => $this->siteB->id,
            'feedback_type' => 'staff',
            'content' => 'Private Site feedback',
            'status' => 'new',
        ]);

        $responses = [
            $this->actingAs($this->admin)->putJson(
                "/sites/{$this->siteA->id}/certifications/{$certification->id}",
                ['name' => 'Mutated'],
            ),
            $this->actingAs($this->admin)->putJson(
                "/sites/{$this->siteA->id}/compliance-checks/{$check->id}",
                ['scheduled_date' => now()->addWeek()->toDateString()],
            ),
            $this->actingAs($this->admin)->putJson(
                "/sites/{$this->siteA->id}/staff-requirements/{$staffRequirement->id}",
                ['requirement_name' => 'Mutated'],
            ),
            $this->actingAs($this->admin)->putJson(
                "/sites/{$this->siteA->id}/coverage-requirements/{$coverage->id}",
                ['name' => 'Mutated'],
            ),
            $this->actingAs($this->admin)->postJson(
                "/sites/{$this->siteA->id}/feedback/{$feedback->id}/respond",
                ['response' => 'Mutated'],
            ),
        ];

        $this->assertSame([404, 404, 404, 404, 404], collect($responses)->map->status()->all());
        $this->assertSame('Private certification', $certification->fresh()->name);
        $this->assertSame('scheduled', $check->fresh()->status);
        $this->assertSame('Private requirement', $staffRequirement->fresh()->requirement_name);
        $this->assertSame('Private cover', $coverage->fresh()->name);
        $this->assertNull($feedback->fresh()->response);
    }

    public function test_anonymous_feedback_is_minimum_necessary_and_closed_feedback_is_immutable(): void
    {
        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/feedback", [
                'feedback_type' => 'compliment',
                'submitted_by_name' => 'Private Submitter',
                'submitted_by_relationship' => 'advocate',
                'content' => 'The team communicated clearly.',
                'rating' => 5,
                'category' => 'communication',
                'is_anonymous' => true,
            ])
            ->assertRedirect();

        $feedback = SiteFeedback::query()->sole();
        $this->assertNull($feedback->submitted_by_name);
        $this->assertNull($feedback->submitted_by_relationship);

        DB::table('site_feedback')->where('id', $feedback->id)->update([
            'submitted_by_name' => 'Legacy Anonymous Name',
            'submitted_by_relationship' => 'staff',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/feedback")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('feedback.data.0.is_anonymous', true)
                ->where('feedback.data.0.submitted_by_name', null)
                ->where('feedback.data.0.submitted_by_relationship', null)
                ->where('can.manage_feedback', true));
        $this->assertStringNotContainsString('Legacy Anonymous Name', $response->getContent());

        $feedback->refresh()->update(['status' => 'closed']);
        $this->actingAs($this->admin)
            ->putJson("/sites/{$this->siteA->id}/feedback/{$feedback->id}/status", [
                'status' => 'in_progress',
            ])
            ->assertStatus(409);
        $this->assertSame('closed', $feedback->fresh()->status);
    }

    public function test_view_only_site_users_can_read_feedback_without_receiving_management_actions(): void
    {
        $viewer = User::factory()->create();
        $viewer->permissionOverrides()->attach(
            Permission::query()->where('key', 'sites.viewAny')->firstOrFail(),
            ['allowed' => true],
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $this->siteA->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        $this->actingAs($viewer)
            ->get("/sites/{$this->siteA->id}/feedback")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/feedback/Index')
                ->where('can.manage_feedback', false));

        $this->actingAs($viewer)
            ->postJson("/sites/{$this->siteA->id}/feedback", [
                'feedback_type' => 'compliment',
                'content' => 'This should not be persisted.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('site_feedback', 0);
    }
}
