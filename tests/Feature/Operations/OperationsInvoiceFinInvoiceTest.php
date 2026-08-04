<?php

namespace Tests\Feature\Operations;

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;
use App\Services\Operations\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsInvoiceFinInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_service_generates_fin_invoice_without_creating_legacy_invoice_rows(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'first_name' => 'Aroha',
            'last_name' => 'Kingi',
        ]);
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $creator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->scopeUserToSite($staff, $site, 'support_worker');
        $this->scopeUserToSite($creator, $site, 'coordinator');
        $agreement = ServiceAgreement::factory()->create([
            'client_id' => $client->id,
            'funding_body' => 'Whaikaha',
        ]);

        $entry = BillingEntry::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'staff_id' => $staff->id,
            'service_agreement_id' => $agreement->id,
            'service_date' => '2026-05-01',
            'hours' => 2,
            'rate' => 75,
            'amount' => 150,
            'rate_type' => 'weekday',
            'status' => 'pending',
        ]);

        $invoice = app(BillingService::class)->generateInvoice([$entry->id], $creator->id);

        $this->assertInstanceOf(FinInvoice::class, $invoice);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseHas('fin_invoices', [
            'id' => $invoice->id,
            'client_id' => $client->id,
            'funding_body' => 'Whaikaha',
            'source' => 'operations',
            'source_type' => BillingEntry::class,
            'source_id' => $entry->id,
            'subtotal' => '150.00',
            'tax_amount' => '22.50',
            'total_amount' => '172.50',
        ]);
        $this->assertDatabaseHas('fin_invoice_lines', [
            'invoice_id' => $invoice->id,
            'billing_entry_id' => $entry->id,
            'quantity' => '2.00',
            'unit_price' => '75.00',
            'tax_amount' => '22.50',
            'line_total' => '172.50',
            'service_date' => '2026-05-01',
            'category' => 'weekday',
        ]);
        $this->assertSame('invoiced', $entry->refresh()->status);
    }

    public function test_finance_invoice_store_creates_fin_invoice_from_operations_billing_payload(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'role' => 'finance',
            'approved_at' => now(),
        ]);
        $this->grantPermissions($user, ['finance.ar.manage']);
        $this->scopeUserToSite($user, $site, 'finance');

        $client = Client::factory()->create([
            'site_id' => $site->id,
            'first_name' => 'Mere',
            'last_name' => 'Rangi',
        ]);

        $response = $this->actingAs($user)->post('/finance/invoices', [
            'client_id' => $client->id,
            'funding_body' => 'ACC',
            'issue_date' => '2026-05-02',
            'due_date' => '2026-05-22',
            'payment_terms' => 'net_20',
            'notes' => 'Operations invoice',
            'line_items' => [
                [
                    'description' => 'Community support',
                    'quantity' => '2',
                    'unit_price' => '120',
                    'service_date' => '2026-05-01',
                    'category' => 'weekday',
                ],
            ],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $invoice = FinInvoice::firstOrFail();

        $response->assertRedirect(route('finance.invoices.show', $invoice));
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseHas('fin_invoices', [
            'id' => $invoice->id,
            'client_id' => $client->id,
            'funding_body' => 'ACC',
            'client_name' => 'Mere Rangi',
            'invoice_date' => '2026-05-02',
            'due_date' => '2026-05-22',
            'subtotal' => '240.00',
            'tax_amount' => '36.00',
            'total_amount' => '276.00',
            'source' => 'operations',
        ]);
        $this->assertDatabaseHas('fin_invoice_lines', [
            'invoice_id' => $invoice->id,
            'description' => 'Community support',
            'quantity' => '2.00',
            'unit_price' => '120.00',
            'tax_amount' => '36.00',
            'line_total' => '276.00',
            'service_date' => '2026-05-01',
            'category' => 'weekday',
        ]);
    }

    private function scopeUserToSite(User $user, Site $site, string $positionRole): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'position_role' => $positionRole,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
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
