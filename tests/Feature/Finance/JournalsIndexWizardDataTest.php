<?php

namespace Tests\Feature\Finance;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class JournalsIndexWizardDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_gets_can_manage_and_reference_data_for_the_new_journal_wizard(): void
    {
        app(FinanceSeeder::class)->run(1); // chart for the user's org

        $user = $this->financeUser(['finance.ledger.view', 'finance.ledger.manage']);

        $this->actingAs($user)
            ->get('/finance/journals')
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('finance/journals/Index')
                    ->where('canManage', true)
                    // accounts must be non-empty so the wizard's account picker has options
                    ->has('accounts.0')
                    ->etc()
            );
    }

    public function test_viewer_without_manage_gets_no_reference_data(): void
    {
        app(FinanceSeeder::class)->run(1);

        $user = $this->financeUser(['finance.ledger.view']);

        $this->actingAs($user)
            ->get('/finance/journals')
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('canManage', false)
                    ->where('accounts', [])
                    ->where('costCentres', [])
                    ->where('fundingStreams', [])
            );
    }

    private function financeUser(array $keys): User
    {
        $user = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);

        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }
}
