<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Models\ClinicalRiskAssessment;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthClinicalAssessmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
    }

    protected function userWithRole(string $role, ?int $orgId = null): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now(), 'organization_id' => $orgId]);
        $found = Role::where('name', $role)->first();
        if ($found) {
            $user->roles()->attach($found);
        }

        return $user;
    }

    public function test_records_a_must_assessment_with_computed_score(): void
    {
        $user = $this->userWithRole('clinical_lead', 1);
        $client = Client::factory()->create(['organization_id' => 1]);

        $this->actingAs($user)
            ->post('/health-clinical/assessments', [
                'client_id' => $client->id,
                'assessment_type' => 'malnutrition_must',
                'inputs' => ['bmi' => 17, 'weight_loss_percent' => 12, 'acute_disease_effect' => true],
                'notes' => 'Recent chest infection.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('clinical_risk_assessments', [
            'client_id' => $client->id,
            'assessment_type' => 'malnutrition_must',
            'total_score' => 6,
            'risk_band' => 'high',
            'organization_id' => 1,
        ]);

        // The stored breakdown + tool version travel with the record.
        $record = ClinicalRiskAssessment::first();
        $this->assertSame('BAPEN MUST (2003)', $record->tool_version);
        $this->assertNotNull($record->review_due_at);
    }

    public function test_records_a_frat_assessment_high_band(): void
    {
        $user = $this->userWithRole('clinical_lead', 1);
        $client = Client::factory()->create(['organization_id' => 1]);

        $this->actingAs($user)
            ->post('/health-clinical/assessments', [
                'client_id' => $client->id,
                'assessment_type' => 'falls_frat',
                'inputs' => [
                    'recent_falls' => 'one_plus_3mo_resident',
                    'medications' => 'more_than_two',
                    'psychological' => 'severe',
                    'cognitive' => 'severe',
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('clinical_risk_assessments', [
            'assessment_type' => 'falls_frat',
            'total_score' => 20,
            'risk_band' => 'high',
        ]);
    }

    public function test_frat_rejects_an_invalid_option(): void
    {
        $user = $this->userWithRole('clinical_lead', 1);
        $client = Client::factory()->create(['organization_id' => 1]);

        $this->actingAs($user)
            ->post('/health-clinical/assessments', [
                'client_id' => $client->id,
                'assessment_type' => 'falls_frat',
                'inputs' => ['recent_falls' => 'bogus', 'medications' => 'none', 'psychological' => 'none', 'cognitive' => 'intact'],
            ])
            ->assertSessionHasErrors('inputs.recent_falls');

        $this->assertDatabaseCount('clinical_risk_assessments', 0);
    }

    public function test_register_renders_with_records_and_stats(): void
    {
        $user = $this->userWithRole('clinical_lead', 1);
        $client = Client::factory()->create(['organization_id' => 1]);
        ClinicalRiskAssessment::create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'assessed_by' => $user->id,
            'assessment_type' => 'pressure_braden',
            'assessed_at' => now(),
            'inputs' => ['sensory_perception' => 1, 'moisture' => 1, 'activity' => 1, 'mobility' => 1, 'nutrition' => 1, 'friction_shear' => 1],
            'total_score' => 6,
            'risk_band' => 'very_high',
            'breakdown' => [],
            'summary' => 'Braden 6/23 — Very high risk',
            'tool_version' => 'Braden Scale (1988)',
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/assessments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/Assessments')
                ->has('records.data', 1)
                ->where('records.data.0.risk_band', 'very_high')
                ->where('records.data.0.band_tone', 'critical')
                ->has('stats')
                ->where('stats.high_risk', 1)
                ->has('filter_options.types', 4)
                ->has('kpis'));
    }

    public function test_forbidden_without_permission(): void
    {
        $user = $this->userWithRole('support_worker', 1);

        $this->actingAs($user)->get('/health-clinical/assessments')->assertForbidden();
        $this->actingAs($user)->post('/health-clinical/assessments', [
            'client_id' => Client::factory()->create()->id,
            'assessment_type' => 'malnutrition_must',
            'inputs' => ['weight_loss_percent' => 0],
        ])->assertForbidden();
    }
}
