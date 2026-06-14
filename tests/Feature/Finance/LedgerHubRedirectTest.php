<?php

namespace Tests\Feature\Finance;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerHubRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_hub_redirects_a_ledger_viewer_to_chart_of_accounts(): void
    {
        $user = $this->financeUser(['finance.ledger.view']);

        $this->actingAs($user)
            ->get('/finance/ledger')
            ->assertRedirect('/finance/accounts');
    }

    public function test_ledger_hub_redirects_an_admin_only_user_to_the_first_admin_tab(): void
    {
        // No ledger.view, but finance.admin → cost centres is the first openable tab.
        $user = $this->financeUser(['finance.admin']);

        $this->actingAs($user)
            ->get('/finance/ledger')
            ->assertRedirect('/finance/cost-centres');
    }

    public function test_ledger_hub_forbids_a_user_with_no_ledger_permissions(): void
    {
        $user = $this->financeUser([]);

        $this->actingAs($user)
            ->get('/finance/ledger')
            ->assertForbidden();
    }

    private function financeUser(array $keys): User
    {
        $user = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);

        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key],
            );
            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }
}
