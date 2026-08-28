<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationPrescriberOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MedicationPrescriberOrderClassificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_only_canonical_links_and_rollback_preserves_classification(): void
    {
        $client = Client::factory()->create();
        $foreignClient = Client::factory()->create();
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        $foreignMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'controlled_drug' => false,
        ]);
        $ordinaryOrder = $this->order($client, $ordinaryMedication);
        $controlledOrder = $this->order($client, $controlledMedication);
        $unlinkedOrder = $this->order($client);
        $forgedOrder = $this->order($client, $foreignMedication);
        $migration = require database_path(
            'migrations/2026_08_27_000100_add_controlled_snapshot_to_medication_prescriber_orders.php',
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumn(
            'medication_prescriber_orders',
            'controlled_drug_snapshot',
        ));
        $this->assertFalse((bool) $ordinaryOrder->fresh()->controlled_drug_snapshot);
        $this->assertTrue((bool) $controlledOrder->fresh()->controlled_drug_snapshot);
        $this->assertNull($unlinkedOrder->fresh()->controlled_drug_snapshot);
        $this->assertNull($forgedOrder->fresh()->controlled_drug_snapshot);

        $migration->down();

        $this->assertTrue(Schema::hasColumn(
            'medication_prescriber_orders',
            'controlled_drug_snapshot',
        ));
        $this->assertFalse((bool) $ordinaryOrder->fresh()->controlled_drug_snapshot);
        $this->assertTrue((bool) $controlledOrder->fresh()->controlled_drug_snapshot);
        $this->assertNull($unlinkedOrder->fresh()->controlled_drug_snapshot);
        $this->assertNull($forgedOrder->fresh()->controlled_drug_snapshot);
    }

    private function order(
        Client $client,
        ?ClientMedication $medication = null,
    ): MedicationPrescriberOrder {
        return MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication?->id,
            'controlled_drug_snapshot' => null,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Migration',
            'medication_name' => 'Migration classification proof',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
        ]);
    }
}
