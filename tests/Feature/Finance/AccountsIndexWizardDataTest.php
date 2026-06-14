<?php

namespace Tests\Feature\Finance;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountsIndexWizardDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_gets_can_manage_and_reference_data_for_the_new_account_wizard(): void
    {
        app(FinanceSeeder::class)->run(1);

        $user = $this->financeUser(['finance.ledger.view', 'finance.ledger.manage']);

        $this->actingAs($user)
            ->get('/finance/accounts')
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('finance/accounts/Index')
                    ->where('canManage', true)
                    ->has('parentAccounts.0') // non-empty so the picker has options
                    ->etc()
            );
    }

    public function test_viewer_without_manage_gets_no_reference_data(): void
    {
        app(FinanceSeeder::class)->run(1);

        $user = $this->financeUser(['finance.ledger.view']);

        $this->actingAs($user)
            ->get('/finance/accounts')
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('canManage', false)
                    ->where('parentAccounts', [])
                    ->where('taxRates', [])
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
