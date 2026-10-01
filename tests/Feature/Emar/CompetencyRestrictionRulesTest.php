<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCovertAuthorisation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationCompetencyRestrictionRules;
use App\Services\Medication\MedicationSafetyPolicySettings;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Database\Factories\UserFactory;

/**
 * NF-03: the organisation's competency rules (restricted assessments, task
 * areas) — each off by default — applied when a dose is signed as given.
 * Refused and withheld doses stay recordable.
 */
class CompetencyRestrictionRulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected User $assessor;

    protected Client $client;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->site = Site::factory()->create(['is_active' => true]);
        $this->assessor = User::factory()->create(['role' => 'manager', 'approved_at' => now()]);
        $this->worker = $this->siteStaff(['medications.administer.record']);

        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Competency rules',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $this->onShift($this->worker);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_restricted_worker_is_unaffected_while_the_rule_is_off(): void
    {
        $this->assess($this->worker, ['restricted' => true, 'restriction_notes' => 'Patches not yet observed']);
        $order = $this->order('Metformin 500mg');

        $this->recordGiven($order)->assertSessionHas('success');

        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_block_mode_refuses_given_with_the_reason_but_still_records_a_refusal(): void
    {
        $this->setPolicy(MedicationSafetyPolicySettings::RESTRICTED_COMPETENCY, 'block');
        $this->assess($this->worker, ['restricted' => true, 'restriction_notes' => 'Patches not yet observed']);
        $order = $this->order('Metformin 500mg');

        $this->recordGiven($order)->assertSessionHasErrors('status');
        $message = session('errors')->first('status');
        $this->assertStringContainsString('restricted', $message);
        $this->assertStringContainsString('Patches not yet observed', $message);
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $order->id,
                'scheduled_for' => now()->toIso8601String(),
                'status' => 'refused',
                'reason_code' => 'refused',
                'reason' => 'Declined the tablet.',
            ])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $order->id,
            'status' => 'refused',
        ]);

        // The PRN wizard path is refused with a blocking JSON error (EM-26).
        $prn = $this->order('Paracetamol 500mg', ['is_prn' => true, 'dose_times' => [], 'prn_reason' => 'Pain', 'max_per_day' => 4]);
        $this->actingAs($this->worker)
            ->postJson('/meds/today/prn', [
                'client_medication_id' => $prn->id,
                'reason' => 'Pain (mild)',
                'client_request_uuid' => 'c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f',
                'queued_offline' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('sync.status', 'rejected')
            ->assertJsonPath('competency_state', 'restricted');
    }

    public function test_cosigner_mode_needs_a_present_qualified_unrestricted_cosigner(): void
    {
        $this->setPolicy(MedicationSafetyPolicySettings::RESTRICTED_COMPETENCY, 'cosigner');
        $this->assess($this->worker, ['restricted' => true]);
        $first = $this->order('Metformin 500mg');
        $second = $this->order('Sertraline 50mg');

        $this->recordGiven($first)->assertSessionHasErrors('witnessed_by');
        $this->assertStringContainsString('co-signer', session('errors')->first('witnessed_by'));
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $restrictedColleague = $this->qualifiedWitness(['restricted' => true]);
        $this->recordGiven($first, [
            'witnessed_by' => $restrictedColleague->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ])->assertSessionHasErrors('witnessed_by');
        $this->assertStringContainsString('restricted', session('errors')->first('witnessed_by'));
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $cosigner = $this->qualifiedWitness();
        $this->recordGiven($second, [
            'witnessed_by' => $cosigner->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $second->id,
            'status' => 'given',
            'witnessed_by' => $cosigner->id,
        ]);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('board_user.competency_notice.requires_cosigner', true)
                ->where('board_user.competency_notice.blocked', false));
    }

    public function test_area_rule_blocks_a_covert_dose_the_assessment_did_not_pass(): void
    {
        $this->setPolicy(MedicationSafetyPolicySettings::COMPETENCY_AREAS, 'failed');
        $this->assess($this->worker, ['covert_admin_knowledge' => false]);
        $covert = $this->covertOrder('Risperidone liquid');
        $ordinary = $this->order('Metformin 500mg');

        $this->recordGiven($covert)->assertSessionHasErrors('status');
        $this->assertStringContainsString('Covert administration', session('errors')->first('status'));
        $this->recordGiven($ordinary)->assertSessionHas('success');

        $this->assertSame(0, ClientMedicationAdministration::query()->where('client_medication_id', $covert->id)->count());
        $this->assertSame(1, ClientMedicationAdministration::query()->where('client_medication_id', $ordinary->id)->count());
    }

    public function test_a_not_seen_area_blocks_only_in_failed_or_not_seen_mode(): void
    {
        $this->assess($this->worker, [
            'covert_admin_knowledge' => false,
            'not_seen_areas' => ['covert_admin_knowledge'],
        ]);
        $first = $this->covertOrder('Risperidone liquid');
        $second = $this->covertOrder('Quetiapine liquid');

        $this->setPolicy(MedicationSafetyPolicySettings::COMPETENCY_AREAS, 'failed');
        $this->recordGiven($first)->assertSessionHas('success');

        $this->setPolicy(MedicationSafetyPolicySettings::COMPETENCY_AREAS, 'failed_or_not_seen');
        $this->recordGiven($second)->assertSessionHasErrors('status');
        $this->assertStringContainsString('not seen at assessment', session('errors')->first('status'));
    }

    public function test_area_rule_covers_controlled_drug_orders(): void
    {
        $this->setPolicy(MedicationSafetyPolicySettings::COMPETENCY_AREAS, 'failed');
        $assessment = $this->assess($this->worker, ['controlled_drugs' => false]);
        $controlled = $this->order('Oxycodone 5mg', ['controlled_drug' => true]);
        $rules = app(MedicationCompetencyRestrictionRules::class);

        $violation = $rules->violation(
            ['state' => 'valid', 'assessment_id' => $assessment->id],
            $controlled,
            $this->site->id,
            now(),
            null,
        );
        $this->assertSame('area_not_passed', $violation['state']);
        $this->assertStringContainsString('Controlled drugs', $violation['message']);

        // An exemption (not a valid assessment) remains the governing decision.
        $this->assertNull($rules->violation(
            ['state' => 'exempt', 'assessment_id' => $assessment->id],
            $controlled,
            $this->site->id,
            now(),
            null,
        ));
    }

    public function test_only_an_organisation_wide_settings_manager_can_change_the_rules(): void
    {
        $values = [
            'profile_allergy_match' => 'block',
            'restricted_competency' => 'cosigner',
            'competency_areas' => 'failed_or_not_seen',
        ];
        $defaults = ['profile_allergy_match' => 'warn', 'restricted_competency' => 'off', 'competency_areas' => 'off'];
        $payload = fn (array $values): array => [
            'view' => 'rules',
            'changes' => collect($values)->map(fn (string $value, string $key): array => [
                'group' => 'safety',
                'key' => $key,
                'value' => $value,
                'from' => $defaults[$key],
            ])->values()->all(),
        ];

        $siteManager = $this->siteStaff(['medications.settings.manage']);
        $this->actingAs($siteManager)
            ->put('/emar/settings/changes', $payload($values))
            ->assertForbidden();

        $orgManager = $this->siteStaff(['medications.settings.manage', 'sites.viewAll']);
        $this->actingAs($orgManager)
            ->get('/emar/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.reviewed.safety', [
                    'profile_allergy_match' => null,
                    'restricted_competency' => null,
                    'competency_areas' => null,
                ]));

        $this->actingAs($orgManager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $payload([...$values, 'competency_areas' => 'always']))
            ->assertSessionHasErrors('changes.2.value');

        $this->actingAs($orgManager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $payload($values))
            ->assertRedirect('/emar/settings')
            ->assertSessionHas('medication_settings_saved', '3 changes saved. From the next dose signed, at every house.');

        $settings = app(MedicationSafetyPolicySettings::class);
        $this->assertSame('block', $settings->profileAllergyMatch());
        $this->assertSame('cosigner', $settings->restrictedCompetencyMode());
        $this->assertSame('failed_or_not_seen', $settings->competencyAreaEnforcement());

        $audit = AuditLog::query()->where('action', 'medications.safety_policy.updated')->sole();
        $this->assertSame($orgManager->id, (int) $audit->user_id);
        $this->assertSame('off', $audit->meta['before']['restricted_competency']);
        $this->assertSame('cosigner', $audit->meta['after']['restricted_competency']);

        $this->actingAs($orgManager)
            ->get('/emar/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Settings')
                ->where('settings.values.safety.profile_allergy_match', 'block')
                ->where('settings.values.safety.restricted_competency', 'cosigner')
                ->where('settings.values.safety.competency_areas', 'failed_or_not_seen')
                ->where('settings.reviewed.safety.restricted_competency.by', $orgManager->name)
                ->where('settings.can_manage_organisation', true));
    }

    // ─── Fixtures ────────────────────────────────────────────

    private function siteStaff(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('key', $permissions)
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    private function onShift(User $user): void
    {
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);
    }

    private function assess(User $user, array $overrides = []): MedicationCompetencyAssessment
    {
        return MedicationCompetencyAssessment::query()->create(array_merge([
            'user_id' => $user->id,
            'assessor_id' => $this->assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'controlled_drugs' => true,
            'covert_admin_knowledge' => true,
            'restricted' => false,
        ], $overrides));
    }

    /** A present colleague who passes the controlled-medication witness checks. */
    private function qualifiedWitness(array $assessmentOverrides = []): User
    {
        $witness = $this->siteStaff(['medications.controlled.witness', 'medications.administer.record']);
        $this->assess($witness, ['can_witness_controlled' => true, ...$assessmentOverrides]);
        $this->onShift($witness);

        return $witness;
    }

    private function order(string $name, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['09:30'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ], $overrides));
    }

    private function covertOrder(string $name): ClientMedication
    {
        $order = $this->order($name, ['dosage' => '1 mL']);
        MedicationCovertAuthorisation::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $order->id,
            'authorised_by_name' => 'Dr Aroha Rangi',
            'clinical_justification' => 'Best-interests decision recorded by the MDT.',
            'authorised_date' => now()->subMonth()->toDateString(),
            'review_date' => now()->addMonths(2)->toDateString(),
            'status' => 'active',
            'recorded_by' => $this->assessor->id,
        ]);

        return $order;
    }

    private function recordGiven(ClientMedication $order, array $extra = [])
    {
        return $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $order->id,
                'scheduled_for' => now()->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
                ...$extra,
            ]);
    }

    private function setPolicy(string $key, string $value): void
    {
        app(MedicationSafetyPolicySettings::class)->save([$key => $value]);
    }
}
