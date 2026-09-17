<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\TeTiritiObligation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Te Tiriti commitments use the five Hauora (Wai 2575) principles, have an
 * owner, and older principle keys map to the new ones.
 */
class GovernanceTeTiritiCommitmentsTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_the_page_lists_the_five_hauora_principles_with_descriptions_and_owners(): void
    {
        $admin = $this->createAdminUser(['name' => 'Mere Owner']);
        $observer = $this->createUserWithRole('board_observer');

        // Saved before the Hauora principles; shown under tino rangatiratanga.
        TeTiritiObligation::create([
            'principle' => 'participation',
            'title' => 'Whānau help plan care',
            'description' => 'Whānau take part in care planning.',
            'status' => 'ongoing',
            'owner_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/governance/te-tiriti')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Governance/TeTiriti/Index')
                ->has('principles', 5)
                ->where('principles', fn ($principles) => collect($principles)->pluck('value')->all() === [
                    'tino_rangatiratanga', 'equity', 'active_protection', 'options', 'partnership',
                ])
                ->where('principles.3.label', 'Options (Kōwhiringa)')
                ->where('principles.3.description', 'Māori can choose kaupapa Māori or culturally safe services.')
                ->where('obligationsByPrinciple.tino_rangatiratanga.0.title', 'Whānau help plan care')
                ->where('obligationsByPrinciple.tino_rangatiratanga.0.principle', 'tino_rangatiratanga')
                ->where('obligationsByPrinciple.tino_rangatiratanga.0.implementation_status', 'embedded')
                ->where('obligationsByPrinciple.tino_rangatiratanga.0.owner.name', 'Mere Owner')
                ->missing('obligationsByPrinciple.tino_rangatiratanga.0.owner.email')
                ->where('owners', fn ($owners) => collect($owners)->contains('name', 'Mere Owner')));

        // People who can only view never receive the staff list.
        $this->actingAs($observer)
            ->get('/governance/te-tiriti')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('owners', 0));
    }

    public function test_a_commitment_is_saved_with_its_owner_and_a_hauora_principle(): void
    {
        $admin = $this->createAdminUser();
        $owner = $this->createUserWithRole('support_worker', ['name' => 'Tama Lead']);
        $client = $this->createUserWithRole('client');

        $payload = [
            'principle' => 'active_protection',
            'title' => 'Cultural safety training',
            'description' => 'Every staff member completes cultural safety training.',
            'implementation_status' => 'implemented',
            'owner_id' => $owner->id,
        ];

        $this->actingAs($admin)
            ->post('/governance/te-tiriti', [...$payload, 'principle' => 'protection'])
            ->assertSessionHasErrors(['principle' => 'Choose the principle from the list.']);

        $this->actingAs($admin)
            ->post('/governance/te-tiriti', [...$payload, 'owner_id' => $client->id])
            ->assertSessionHasErrors(['owner_id' => 'Choose who is responsible from the list.']);

        $this->actingAs($admin)
            ->post('/governance/te-tiriti', $payload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Commitment added.');

        $commitment = TeTiritiObligation::query()->sole();
        $this->assertSame('active_protection', $commitment->principle);
        $this->assertSame('achieved', $commitment->status);
        $this->assertSame($owner->id, (int) $commitment->owner_id);

        $this->actingAs($admin)
            ->put("/governance/te-tiriti/{$commitment->id}", [
                'principle' => 'partnership',
                'owner_id' => $admin->id,
                'implementation_status' => 'embedded',
            ])
            ->assertSessionHasNoErrors();

        $commitment->refresh();
        $this->assertSame('partnership', $commitment->principle);
        $this->assertSame('ongoing', $commitment->status);
        $this->assertSame($admin->id, (int) $commitment->owner_id);
    }

    public function test_the_migration_maps_old_principles_and_can_be_reversed(): void
    {
        $admin = $this->createAdminUser();
        $make = fn (string $principle) => TeTiritiObligation::create([
            'principle' => $principle,
            'title' => "Commitment for {$principle}",
            'description' => 'Description',
            'owner_id' => $admin->id,
        ]);
        $participation = $make('participation');
        $protection = $make('protection');
        $equity = $make('equity');

        $migration = require database_path('migrations/2026_09_15_620200_map_te_tiriti_principles_to_hauora_principles.php');

        $migration->up();
        $this->assertSame('tino_rangatiratanga', $participation->fresh()->principle);
        $this->assertSame('active_protection', $protection->fresh()->principle);
        $this->assertSame('equity', $equity->fresh()->principle);

        $migration->down();
        $this->assertSame('participation', $participation->fresh()->principle);
        $this->assertSame('protection', $protection->fresh()->principle);
        $this->assertSame('equity', $equity->fresh()->principle);
    }
}
