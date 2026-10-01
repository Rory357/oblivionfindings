<?php

namespace Tests\Feature\Emar;

use App\Models\AuditLog;
use App\Models\MedicationInteraction;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NF-09: drug-interaction rules feed every resident's safety checks, so only
 * the medication settings (clinical governance) capability may author one.
 * Dose-correction authority, which support workers hold, is not enough.
 */
class DrugInteractionRuleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_a_support_worker_with_correction_authority_cannot_create_an_interaction_rule(): void
    {
        $worker = $this->userWithPermissions([
            'medications.view',
            'medications.administer.record',
            'medications.administer.correct',
        ]);

        $this->actingAs($worker)
            ->postJson(route('api.medications.interactions.store'), $this->rule())
            ->assertForbidden();

        $this->assertSame(0, MedicationInteraction::query()->count());
        $this->assertFalse(
            AuditLog::query()->where('action', 'medications.interaction.created')->exists(),
        );
    }

    public function test_a_settings_manager_creates_an_attributed_and_audited_interaction_rule(): void
    {
        $manager = $this->userWithPermissions([
            'medications.view',
            'medications.settings.manage',
        ]);

        $response = $this->actingAs($manager)
            ->postJson(route('api.medications.interactions.store'), $this->rule())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('interaction.severity', 'major');

        $interaction = MedicationInteraction::query()->findOrFail($response->json('interaction.id'));
        $this->assertSame('Warfarin', $interaction->medication_a);
        $this->assertSame('Aspirin', $interaction->medication_b);
        $this->assertSame((int) $manager->id, (int) $interaction->created_by);
        $this->assertTrue($interaction->createdBy->is($manager));

        $audit = AuditLog::query()
            ->where('action', 'medications.interaction.created')
            ->where('auditable_type', $interaction->getMorphClass())
            ->where('auditable_id', $interaction->id)
            ->sole();
        $this->assertSame((int) $manager->id, (int) $audit->user_id);
    }

    public function test_reading_interaction_rules_still_needs_only_medication_view(): void
    {
        $reader = $this->userWithPermissions(['medications.view']);

        $this->actingAs($reader)
            ->getJson(route('api.medications.interactions.index'))
            ->assertOk();

        $this->actingAs($reader)
            ->postJson(route('api.medications.interactions.store'), $this->rule())
            ->assertForbidden();
        $this->assertSame(0, MedicationInteraction::query()->count());
    }

    /** @return array<string, string> */
    private function rule(): array
    {
        return [
            'medication_a' => 'Warfarin',
            'medication_b' => 'Aspirin',
            'severity' => 'major',
            'description' => 'Increased bleeding risk.',
            'clinical_effects' => 'Bleeding',
            'management' => 'Avoid unless the prescriber confirms.',
        ];
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds);
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
        );

        return $user->fresh();
    }
}
