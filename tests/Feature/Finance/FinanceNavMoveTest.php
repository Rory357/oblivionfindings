<?php

namespace Tests\Feature\Finance;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FinanceNavMoveTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_route_names_replace_operations_billing_route_names(): void
    {
        $this->assertTrue(Route::has('finance.billing.index'));
        $this->assertTrue(Route::has('finance.billing.entries'));
        $this->assertTrue(Route::has('finance.invoices.index'));
        $this->assertTrue(Route::has('finance.price_books.index'));
        $this->assertTrue(Route::has('finance.quotes.index'));
        $this->assertTrue(Route::has('finance.recurring_charges.index'));

        $this->assertFalse(Route::has('operations.billing.index'));
        $this->assertFalse(Route::has('operations.invoices.index'));
        $this->assertFalse(Route::has('operations.price_books.index'));
        $this->assertFalse(Route::has('operations.quotes.index'));
        $this->assertFalse(Route::has('operations.recurring_charges.index'));

        $this->assertTrue(Route::has('operations.funding.index'));
    }

    public function test_finance_ar_view_permission_can_access_moved_read_pages(): void
    {
        $user = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $this->grantPermissions($user, ['finance.ar.view']);

        foreach ([
            '/finance/billing',
            '/finance/billing/entries',
            '/finance/invoices',
            '/finance/price-books',
            '/finance/quotes',
            '/finance/recurring-charges',
        ] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }
    }

    public function test_moved_operations_urls_are_removed_without_redirects(): void
    {
        $user = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $this->grantPermissions($user, ['finance.ar.view', 'finance.ar.manage']);

        foreach ([
            '/operations/billing',
            '/operations/billing/entries',
            '/operations/invoices',
            '/operations/invoices/create',
            '/operations/price-books',
            '/operations/quotes',
            '/operations/recurring-charges',
        ] as $path) {
            $this->actingAs($user)->get($path)->assertNotFound();
        }
    }

    public function test_funding_remains_in_operations(): void
    {
        $user = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $this->grantPermissions($user, ['funding.viewAny']);

        $this->actingAs($user)->get('/operations/funding')->assertOk();
    }

    private function grantPermissions(User $user, array $keys): void
    {
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key],
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }
    }
}
