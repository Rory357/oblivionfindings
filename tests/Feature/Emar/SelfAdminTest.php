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

/**
 * The redesigned Self-Administration page resolves the active site's brand
 * colour and fixes three server bugs: consent-first outcome (a person who
 * declines is Category 4 regardless of score), outcome recomputed on update,
 * and soft-delete instead of hard-delete. Reassessment supersedes the prior
 * record (kept, excluded from the live register).
 */
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
                ->where('site_brand_colour', '#5E35B1')
                ->has('assessments', 1)
                ->where('kpis.self_managing', 1)
                ->has('activity')
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

        $this->actingAs($user)
            ->get('/emar/self-admin?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/SelfAdmin')
                ->has('assessments.0', fn (Assert $a) => $a
                    ->where('cognitive_capacity', 5)
                    ->where('physical_dexterity', 5)
                    ->where('total_score', 25)
                    ->where('can_identify_medications', true)
                    ->where('willing_to_self_admin', true)
                    ->where('storage_location', 'lockable_drawer')
                    ->where('safe_storage_notes', 'Lockable bedside drawer')
                    ->where('ordering_responsibility', 'self')
                    ->whereNot('agreement_signed_at', null)
                    ->has('people_involved')
                    ->has('support_adjustments')
                    ->has('med_scope')
                    ->has('client_medications')
                    ->etc()
                )
            );
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

    public function test_update_recomputes_outcome(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedSelfAdmin();
        $assessment = MedicationSelfAdminAssessment::query()->create(array_merge($this->basePayload($client), ['status' => 'completed', 'outcome' => 'independent', 'assessment_date' => now()->toDateString()]));

        // Drop all scores to the floor — the server must recompute to Cat 4.
        $this->actingAs($user)
            ->from('/emar/self-admin')
            ->put("/emar/self-admin/{$assessment->id}", ['cognitive_capacity' => 1, 'physical_dexterity' => 1, 'vision_ability' => 1, 'swallowing_ability' => 1, 'understanding_score' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame('administered', $assessment->refresh()->outcome);
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
            ->assertInertia(fn (Assert $page) => $page->has('assessments', 1)); // prior superseded → excluded from the live register
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

    public function test_controlled_and_forged_medication_scope_is_concealed_and_canonicalized(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedSelfAdmin();
        $this->denyPermissions($user, [
            'medications.controlled.view',
            'medications.controlled.record',
        ]);
        $ordinary = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Canonical ordinary self-admin medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ]);
        $controlled = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Restricted controlled self-admin medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
        ]);
        $foreignClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreign = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'name' => 'FORGED foreign self-admin medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ]);
        $assessment = MedicationSelfAdminAssessment::query()->create(array_merge(
            $this->basePayload($client),
            [
                'status' => 'completed',
                'outcome' => 'independent',
                'assessment_date' => now()->toDateString(),
                'med_scope' => [
                    ['med_id' => $ordinary->id, 'med_name' => 'Forged ordinary name', 'scope' => 'self_managed'],
                    ['med_id' => $controlled->id, 'med_name' => 'Restricted controlled self-admin medication', 'scope' => 'staff_given'],
                    ['med_id' => $foreign->id, 'med_name' => 'FORGED foreign self-admin medication', 'scope' => 'self_managed'],
                ],
            ],
        ));

        $this->actingAs($user)
            ->get('/emar/self-admin?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('assessments.0.med_scope', fn ($scope): bool => collect($scope)->pluck('med_id')->all() === [$ordinary->id]
                    && collect($scope)->pluck('med_name')->all() === [$ordinary->name])
                ->where('assessments.0.client_medications', fn ($medications): bool => collect($medications)->pluck('id')->all() === [$ordinary->id]));

        foreach ([$controlled, $foreign] as $hiddenMedication) {
            $this->actingAs($user)
                ->put(route('emar.self_admin.update', $assessment), [
                    'med_scope' => [[
                        'med_id' => $hiddenMedication->id,
                        'med_name' => 'Untrusted name',
                        'scope' => 'self_managed',
                    ]],
                ])
                ->assertNotFound();
        }
        $this->assertCount(3, $assessment->fresh()->med_scope);

        $this->actingAs($user)
            ->put(route('emar.self_admin.update', $assessment), [
                'assessor_notes' => 'Must not edit a record containing hidden or malformed medication scope.',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->put(route('emar.self_admin.update', $assessment), ['sign_agreement' => true])
            ->assertNotFound();
        $this->actingAs($user)
            ->delete(route('emar.self_admin.destroy', $assessment))
            ->assertNotFound();
        $this->assertNull($assessment->fresh()->agreement_signed_at);
        $this->assertNull($assessment->fresh()->deleted_at);

        $this->grantPermissions($user, ['medications.controlled.record']);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->put(route('emar.self_admin.update', $assessment), ['assessor_notes' => 'Record-only remains concealed.'])
            ->assertNotFound();

        $cleanClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $cleanControlled = ClientMedication::factory()->create([
            'client_id' => $cleanClient->id,
            'name' => 'Canonical controlled assessment medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
        ]);
        $controlledAssessment = MedicationSelfAdminAssessment::query()->create(array_merge(
            $this->basePayload($cleanClient),
            [
                'status' => 'completed',
                'outcome' => 'independent',
                'assessment_date' => today(),
                'med_scope' => [[
                    'med_id' => $cleanControlled->id,
                    'med_name' => $cleanControlled->name,
                    'scope' => 'staff_given',
                ]],
            ],
        ));

        $this->actingAs($user)
            ->put(route('emar.self_admin.update', $controlledAssessment), ['assessor_notes' => 'Record-only remains insufficient.'])
            ->assertNotFound();

        $this->grantPermissions($user, ['medications.controlled.view']);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->put(route('emar.self_admin.update', $controlledAssessment), [
                'med_scope' => [[
                    'med_id' => $cleanControlled->id,
                    'med_name' => 'Still untrusted',
                    'scope' => 'prompted',
                ]],
                'sign_agreement' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(
            $cleanControlled->name,
            collect($controlledAssessment->fresh()->med_scope)->sole()['med_name'],
        );
        $this->assertNotNull($controlledAssessment->fresh()->agreement_signed_at);
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
