<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Approved P03 register, retained reassessment, and canonical privacy regressions. */
class SelfAdminTest extends TestCase
{
    use RefreshDatabase;

    private function seedSelfAdmin(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return compact('user', 'site', 'client');
    }

    private function basePayload(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'people_involved' => ['The person'], 'storage_location' => 'own_drawer',
            'reassessment_interval_months' => 12, 'confirmed_with_person' => true,
            'wishes_to_self_administer' => true,
            'cognitive_capacity' => 5, 'physical_dexterity' => 5, 'vision_ability' => 5, 'swallowing_ability' => 5, 'understanding_score' => 5,
            'can_identify_medications' => true, 'can_read_labels' => true, 'can_open_packaging' => true, 'can_manage_timing' => true, 'can_store_safely' => true,
            'willing_to_self_admin' => true,
        ];
    }

    public function test_page_serves_brand_colour_and_payload(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedSelfAdmin();
        MedicationSelfAdminAssessment::query()->create([
            'client_id' => $client->id, 'status' => 'completed', 'outcome' => 'independent', 'assessment_date' => now()->toDateString(),
            'cognitive_capacity' => 5, 'physical_dexterity' => 5, 'vision_ability' => 5, 'swallowing_ability' => 5, 'understanding_score' => 5,
            'willing_to_self_admin' => true, 'wishes_to_self_administer' => true,
        ]);

        $this->actingAs($user)
            ->get('/emar/self-admin?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/SelfAdmin')
                ->has('register', 1)
                ->where('register.0.assessment.outcome', 'independent')
                ->where('register.0.cap', 'self_managed')
                ->where('counts.people', 1)
            );
    }

    public function test_payload_carries_detail_enrichment_fields(): void
    {
        // The enriched detail modal (Options bar + read-only body) needs the
        // capacity sub-scores, capability checks, agreement status and per-med
        // scope — confirm serializeSelfAdmin() returns the whole contract.
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedSelfAdmin();
        MedicationSelfAdminAssessment::query()->create(array_merge($this->basePayload($client), [
            'status' => 'completed', 'outcome' => 'independent', 'assessment_date' => now()->toDateString(),
            'people_involved' => ['Person', 'Pharmacist'], 'support_adjustments' => ['Large-print labels'],
            'storage_location' => 'lockable_drawer', 'safe_storage_notes' => 'Lockable bedside drawer',
            'agreement_signed_at' => now(), 'ordering_responsibility' => 'self',
        ]));

        $this->actingAs($user)->get('/emar/self-admin/clients/'.$client->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('emar/SupportRecord')
                ->where('support.assessment.cognitive_capacity', 5)
                ->where('support.assessment.physical_dexterity', 5)
                ->where('support.assessment.can_identify_medications', true)
                ->where('support.assessment.storage_location', 'lockable_drawer')
                ->where('support.assessment.safe_storage_notes', 'Lockable bedside drawer')
                ->where('support.agreement', null)); // Legacy signature columns are not a formal agreement.
    }

    public function test_consent_first_forces_administered(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedSelfAdmin();

        $this->actingAs($user)
            ->from('/emar/self-admin')
            ->post('/emar/self-admin', array_merge($this->basePayload($client), ['wishes_to_self_administer' => false]))
            ->assertSessionHasNoErrors();

        // Full marks, but the person declined → Category 4.
        $this->assertSame('administered', MedicationSelfAdminAssessment::query()->firstOrFail()->outcome);
    }

    public function test_high_score_with_consent_is_independent(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedSelfAdmin();

        $this->actingAs($user)->from('/emar/self-admin')->post('/emar/self-admin', $this->basePayload($client))->assertSessionHasNoErrors();

        $this->assertSame('independent', MedicationSelfAdminAssessment::query()->firstOrFail()->outcome);
    }

    public function test_score_changes_require_a_new_retained_assessment(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedSelfAdmin();
        $assessment = MedicationSelfAdminAssessment::query()->create(array_merge($this->basePayload($client), ['status' => 'completed', 'outcome' => 'independent', 'assessment_date' => now()->toDateString()]));
        $lower = ['cognitive_capacity' => 1, 'physical_dexterity' => 1, 'vision_ability' => 1, 'swallowing_ability' => 1, 'understanding_score' => 1];
        $this->actingAs($user)->put('/emar/self-admin/'.$assessment->id, $lower)->assertSessionHasErrors('med_scope');
        $this->assertSame('independent', $assessment->fresh()->outcome);
        $this->actingAs($user)->post('/emar/self-admin', [...$this->basePayload($client), ...$lower, 'supersedes_id' => $assessment->id])->assertSessionHasNoErrors();
        $this->assertSame('administered', MedicationSelfAdminAssessment::query()->where('supersedes_id', $assessment->id)->sole()->outcome);
        $this->assertSame('independent', $assessment->fresh()->outcome);
    }

    public function test_destroy_soft_deletes(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedSelfAdmin();
        $assessment = MedicationSelfAdminAssessment::query()->create(array_merge($this->basePayload($client), ['status' => 'completed', 'outcome' => 'independent', 'assessment_date' => now()->toDateString()]));

        $this->actingAs($user)->from('/emar/self-admin')->delete("/emar/self-admin/{$assessment->id}")->assertSessionHasNoErrors();

        $this->assertSoftDeleted('medication_self_admin_assessments', ['id' => $assessment->id]);
    }

    public function test_reassessment_supersedes_excludes_prior_from_register(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedSelfAdmin();
        $prior = MedicationSelfAdminAssessment::query()->create(array_merge($this->basePayload($client), ['status' => 'completed', 'outcome' => 'independent', 'assessment_date' => now()->subMonths(6)->toDateString()]));

        $this->actingAs($user)->from('/emar/self-admin')->post('/emar/self-admin', array_merge($this->basePayload($client), ['supersedes_id' => $prior->id]))->assertSessionHasNoErrors();

        $this->assertSame(2, MedicationSelfAdminAssessment::query()->count());
        $this->actingAs($user)
            ->get('/emar/self-admin')
            ->assertInertia(fn (Assert $page) => $page->has('register', 1)->where('register.0.assessment.supersedes_id', $prior->id)); // prior superseded → excluded from the live register
    }

    public function test_reassessment_must_extend_the_single_current_leaf(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedSelfAdmin();
        $prior = MedicationSelfAdminAssessment::query()->create(array_merge($this->basePayload($client), [
            'status' => 'completed',
            'outcome' => 'independent',
            'assessment_date' => today()->subMonth(),
        ]));

        $this->actingAs($user)
            ->post(route('emar.self_admin.store'), $this->basePayload($client))
            ->assertSessionHasErrors('supersedes_id');
        $this->assertDatabaseCount('medication_self_admin_assessments', 1);

        $this->actingAs($user)
            ->post(route('emar.self_admin.store'), array_merge($this->basePayload($client), [
                'supersedes_id' => $prior->id,
            ]))
            ->assertRedirect();
        $current = MedicationSelfAdminAssessment::query()
            ->where('supersedes_id', $prior->id)
            ->sole();

        $this->actingAs($user)
            ->post(route('emar.self_admin.store'), array_merge($this->basePayload($client), [
                'supersedes_id' => $prior->id,
            ]))
            ->assertSessionHasErrors('supersedes_id');
        $this->assertDatabaseCount('medication_self_admin_assessments', 2);

        $this->actingAs($user)
            ->post(route('emar.self_admin.store'), array_merge($this->basePayload($client), [
                'supersedes_id' => $current->id,
            ]))
            ->assertRedirect();
        $this->assertDatabaseCount('medication_self_admin_assessments', 3);
        $this->assertSame(1, MedicationSelfAdminAssessment::query()
            ->whereNotIn('id', MedicationSelfAdminAssessment::query()
                ->whereNotNull('supersedes_id')
                ->select('supersedes_id'))
            ->count());
    }

    public function test_clinical_lead_can_assess_with_hidden_controlled_medicines_but_cannot_target_them(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedSelfAdmin();
        $this->denyPermissions($user, ['medications.controlled.view', 'medications.controlled.record']);
        $ordinary = ClientMedication::factory()->create(['client_id' => $client->id, 'name' => 'Canonical ordinary medicine', 'controlled_drug' => false, 'active' => true, 'state' => 'active']);
        $controlled = ClientMedication::factory()->create(['client_id' => $client->id, 'name' => 'Restricted controlled medicine', 'controlled_drug' => true, 'active' => true, 'state' => 'active']);
        $foreignPerson = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreign = ClientMedication::factory()->create(['client_id' => $foreignPerson->id, 'name' => 'Foreign medicine', 'controlled_drug' => false, 'active' => true, 'state' => 'active']);
        $assessment = MedicationSelfAdminAssessment::query()->create([...$this->basePayload($client), 'status' => 'completed', 'outcome' => 'independent', 'assessment_date' => today(),
            'med_scope' => [['med_id' => $ordinary->id, 'scope' => 'staff_given'], ['med_id' => $controlled->id, 'scope' => 'assisted']]]);
        $this->actingAs($user)->get('/emar/self-admin/clients/'.$client->id)->assertInertia(fn (Assert $p) => $p
            ->where('support.concealed_count', 1)->where('support.medicines', fn ($rows) => collect($rows)->pluck('id')->all() === [$ordinary->id]));
        foreach ([$controlled, $foreign] as $denied) {
            $this->actingAs($user)->put('/emar/self-admin/'.$assessment->id, ['med_scope' => [['med_id' => $denied->id, 'scope' => 'staff_given']]])->assertNotFound();
        }
        $this->actingAs($user)->delete('/emar/self-admin/'.$assessment->id)->assertNotFound();
        $this->actingAs($user)->post('/emar/self-admin', [...$this->basePayload($client), 'supersedes_id' => $assessment->id,
            'med_scope' => [['med_id' => $ordinary->id, 'scope' => 'staff_given']]])->assertSessionHasNoErrors();
        $current = MedicationSelfAdminAssessment::query()->where('supersedes_id', $assessment->id)->sole();
        $this->assertSame('assisted', collect($current->med_scope)->firstWhere('med_id', $controlled->id)['scope']);
        $this->grantPermissions($user, ['medications.controlled.view', 'medications.controlled.record']);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)->post('/emar/self-admin', [...$this->basePayload($client), 'supersedes_id' => $current->id, 'confirm_loosening' => true,
            'med_scope' => [['med_id' => $controlled->id, 'scope' => 'prompted']]])->assertSessionHasErrors('med_scope');
        $this->assertDatabaseCount('medication_self_admin_assessments', 2);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function denyPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
