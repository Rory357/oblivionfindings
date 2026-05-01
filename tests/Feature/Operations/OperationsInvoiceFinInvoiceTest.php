<?php

namespace Tests\Feature\Operations;

use App\Domain\Finance\Models\FinInvoice;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceAgreement;
use App\Models\User;
use App\Services\Operations\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsInvoiceFinInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_service_generates_fin_invoice_without_creating_legacy_invoice_rows(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'first_name' => 'Aroha',
            'last_name' => 'Kingi',
        ]);
        $staff = User::factory()->create(['organization_id' => 1]);
        $creator = User::factory()->create(['organization_id' => 1]);
        $agreement = ServiceAgreement::factory()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'funding_body' => 'Whaikaha',
        ]);

        $entry = BillingEntry::create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'staff_id' => $staff->id,
            'service_agreement_id' => $agreement->id,
            'service_date' => '2026-05-01',
            'hours' => 2,
            'rate' => 75,
            'amount' => 150,
            'rate_type' => 'weekday',
            'status' => 'pending',
        ]);

        $invoice = app(BillingService::class)->generateInvoice([$entry->id], 1, $creator->id);

        $this->assertInstanceOf(FinInvoice::class, $invoice);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseHas('fin_invoices', [
            'id' => $invoice->id,
            'organization_id' => 1,
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

    public function test_operations_invoice_store_creates_fin_invoice_from_page_payload(): void
    {
        $user = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $this->grantPermissions($user, ['invoices.create']);

        $client = Client::factory()->create([
            'organization_id' => 1,
            'first_name' => 'Mere',
            'last_name' => 'Rangi',
        ]);

        $response = $this->actingAs($user)->post('/operations/invoices', [
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

        $invoice = FinInvoice::firstOrFail();

        $response->assertRedirect(route('operations.invoices.show', $invoice));
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
