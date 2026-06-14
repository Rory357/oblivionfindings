<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
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
}
