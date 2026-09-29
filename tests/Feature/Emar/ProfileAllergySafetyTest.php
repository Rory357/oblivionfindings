<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\MedicationAllergy;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * EM-07: allergies staff record on the health profile must reach the
 * medication safety check and the recording wizards, and an empty record
 * must never read as "no known allergies".
 */
class ProfileAllergySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected Client $client;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $this->worker->roles()->syncWithoutDetaching([$role->id]);
        }
        $this->worker->permissionOverrides()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('key', ['medications.administer.record'])
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );

        $this->site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $assessor = User::factory()->create(['role' => 'manager', 'approved_at' => now()]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);

        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Profile allergy',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = $this->clientOnShift('Mere', 'Tawhiri');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_profile_penicillin_allergy_flags_amoxicillin_without_blocking_by_default(): void
    {
        ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin'],
        ]);
        $amoxicillin = $this->order('Amoxicillin 500mg capsule', ['09:30']);
        $paracetamol = $this->order('Paracetamol 500mg', ['09:30']);
        $safety = app(MedicationSafetyService::class);

        $check = $safety->performSafetyCheck($this->client, $amoxicillin);
        $allergyWarnings = collect($check['warnings'])->where('type', 'allergy')->values();

        $this->assertFalse($check['blocked']);
        $this->assertCount(1, $allergyWarnings);
        $this->assertSame('Penicillin', $allergyWarnings[0]['details']['allergen']);
        $this->assertSame('health_profile', $allergyWarnings[0]['details']['source']);
        $this->assertNull($allergyWarnings[0]['details']['severity']);
        $this->assertSame(
            'Possible allergy match — Amoxicillin 500mg capsule matches recorded Penicillin allergy (health profile)',
            $allergyWarnings[0]['message'],
        );

        $this->assertCount(0, collect(
            $safety->performSafetyCheck($this->client, $paracetamol)['warnings'],
        )->where('type', 'allergy'));
    }

    public function test_block_setting_refuses_giving_a_medicine_that_matches_a_profile_allergy(): void
    {
        ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin'],
        ]);
        $amoxicillin = $this->order('Amoxicillin 500mg capsule', ['09:30']);
        app(MedicationSafetyPolicySettings::class)->save([
            MedicationSafetyPolicySettings::PROFILE_ALLERGY_MATCH => 'block',
        ]);

        $check = app(MedicationSafetyService::class)->performSafetyCheck($this->client, $amoxicillin);
        $this->assertTrue($check['blocked']);
        $this->assertStringStartsWith(
            'Possible allergy match — Amoxicillin 500mg capsule matches recorded Penicillin allergy (health profile).',
            (string) $check['block_reason'],
        );

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $amoxicillin->id,
                'scheduled_for' => now()->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_warn_setting_still_records_the_dose(): void
    {
        ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin'],
        ]);
        $amoxicillin = $this->order('Amoxicillin 500mg capsule', ['09:30']);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $amoxicillin->id,
                'scheduled_for' => now()->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_meds_today_lists_profile_and_register_allergies_and_never_implies_none_known(): void
    {
        ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin', 'Kiwifruit'],
        ]);
        MedicationAllergy::query()->create([
            'client_id' => $this->client->id,
            'allergen' => 'Codeine',
            'severity' => 'moderate',
            'recorded_by' => $this->worker->id,
        ]);
        $withoutAllergies = $this->clientOnShift('Hemi', 'Parata');
        $this->order('Amoxicillin 500mg capsule', ['09:30']);
        $this->order('Paracetamol 500mg', ['09:30'], $withoutAllergies);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->where('clients', function ($clients) use ($withoutAllergies): bool {
                    $byId = collect($clients)->keyBy('id');
                    $recorded = $byId[$this->client->id];
                    $none = $byId[$withoutAllergies->id];

                    return $recorded['allergies'] === ['Codeine', 'Penicillin', 'Kiwifruit']
                        && $recorded['allergy_status'] === 'recorded'
                        && $none['allergies'] === []
                        && $none['allergy_status'] === 'none_recorded';
                }));
    }

    private function clientOnShift(string $first, string $last): Client
    {
        $client = Client::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);

        return $client;
    }

    private function order(string $name, array $doseTimes, ?Client $client = null): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => ($client ?? $this->client)->id,
            'name' => $name,
            'dosage' => '1 capsule',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
    }
}
