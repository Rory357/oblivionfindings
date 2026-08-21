<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPharmacyOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MedicationStockPrecisionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_down_migration_fails_before_narrowing_any_fractional_provenance_column(): void
    {
        $client = Client::factory()->create();
        $medication = ClientMedication::factory()->create(['client_id' => $client->id]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => '9.50',
            'unit' => 'tablets',
        ]);
        $migration = require database_path(
            'migrations/2026_08_20_000100_preserve_fractional_controlled_drug_balances.php',
        );

        try {
            $migration->down();
            $this->fail('The migration must not narrow fractional medication-stock provenance.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('fractional medication-stock provenance exists', $exception->getMessage());
        }

        $this->assertSame('decimal', Schema::getColumnType('client_medication_stocks', 'on_hand'));
        $this->assertSame('decimal', Schema::getColumnType('client_controlled_drug_entries', 'on_hand_before'));
        $this->assertSame('decimal', Schema::getColumnType('client_controlled_drug_discrepancies', 'difference'));
        $this->assertSame('decimal', Schema::getColumnType('medication_scheduled_stock_counts', 'actual_quantity'));
        $this->assertSame(9.5, (float) $stock->refresh()->on_hand);
    }

    public function test_controlled_delivery_migration_down_refuses_to_remove_linked_provenance(): void
    {
        [$client, $medication, $stock, $user, $order] = $this->controlledDeliveryRecords();
        ClientControlledDrugEntry::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'pharmacy_order_id' => $order->id,
            'entry_type' => 'receipt',
            'quantity' => '0.50',
            'unit' => $stock->unit,
            'on_hand_before' => '9.50',
            'on_hand_after' => '10.00',
            'recorded_at' => now(),
            'recorded_by' => $user->id,
            'witnessed_by' => $user->id,
        ]);
        $migration = require database_path(
            'migrations/2026_08_21_000100_link_controlled_pharmacy_deliveries.php',
        );

        try {
            $migration->down();
            $this->fail('The migration must not discard linked controlled-delivery provenance.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('linked register entries exist', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('client_controlled_drug_entries', 'pharmacy_order_id'));
        $this->assertSame('decimal', Schema::getColumnType('medication_pharmacy_orders', 'quantity_received'));
    }

    public function test_controlled_delivery_migration_down_refuses_to_truncate_fractional_received_quantity(): void
    {
        [, , , , $order] = $this->controlledDeliveryRecords();
        $order->update(['quantity_received' => '0.50']);
        $migration = require database_path(
            'migrations/2026_08_21_000100_link_controlled_pharmacy_deliveries.php',
        );

        try {
            $migration->down();
            $this->fail('The migration must not truncate a fractional received quantity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('fractional values exist', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('client_controlled_drug_entries', 'pharmacy_order_id'));
        $this->assertSame('decimal', Schema::getColumnType('medication_pharmacy_orders', 'quantity_received'));
        $this->assertSame('0.50', (string) $order->refresh()->quantity_received);
    }

    /** @return array{Client, ClientMedication, ClientMedicationStock, User, MedicationPharmacyOrder} */
    private function controlledDeliveryRecords(): array
    {
        $client = Client::factory()->create();
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => '9.50',
            'unit' => 'tablets',
        ]);
        $user = User::factory()->create();
        $order = MedicationPharmacyOrder::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'pharmacy_name' => 'Local Pharmacy',
            'quantity_ordered' => 1,
            'status' => 'dispensed',
            'ordered_by' => $user->id,
        ]);

        return [$client, $medication, $stock, $user, $order];
    }
}
