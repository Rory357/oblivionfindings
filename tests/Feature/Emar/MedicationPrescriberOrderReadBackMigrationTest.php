<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\MedicationPrescriberOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MedicationPrescriberOrderReadBackMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_refuses_legacy_pending_countersign_before_any_ddl(): void
    {
        $this->assertMigrationRefusesLegacyPendingRow([
            'order_type' => 'telephone',
            'requires_countersign' => true,
            'read_back_confirmed' => true,
            'countersigned_at' => null,
        ]);
    }

    public function test_migration_refuses_pending_verbal_order_with_inconsistent_countersign_flag_before_any_ddl(): void
    {
        $this->assertMigrationRefusesLegacyPendingRow([
            'order_type' => 'verbal',
            'requires_countersign' => false,
            'countersigned_at' => null,
        ]);
    }

    public function test_migration_refuses_inconsistent_pending_countersigned_row_before_any_ddl(): void
    {
        $this->assertMigrationRefusesLegacyPendingRow([
            'order_type' => 'new',
            'requires_countersign' => false,
            'countersigned_at' => now(),
            'countersign_method' => 'electronic',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function assertMigrationRefusesLegacyPendingRow(array $overrides): void
    {
        $client = Client::factory()->create();
        $witness = User::factory()->create();
        $order = MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'order_type' => 'telephone',
            'status' => 'pending',
            'prescriber_name' => 'Dr Legacy',
            'medication_name' => 'Legacy read-back order',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
            'requires_countersign' => true,
            'read_back_confirmed' => true,
            'read_back_witnessed_by' => $witness->id,
            'countersigned_at' => null,
            ...$overrides,
        ]);
        $migration = require database_path(
            'migrations/2026_08_28_000300_add_read_back_verification_provenance_to_prescriber_orders.php',
        );

        $migration->down();

        try {
            try {
                $migration->up();
                $this->fail('The migration must not strand a legacy pending countersign order.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'Resolve or cancel every affected legacy pending order before deploying this migration.',
                    $exception->getMessage(),
                );
                $this->assertFalse(Schema::hasColumn(
                    'medication_prescriber_orders',
                    'read_back_verified_at',
                ));
                $this->assertFalse(Schema::hasColumn(
                    'medication_prescriber_orders',
                    'read_back_verification_method',
                ));
            }
        } finally {
            DB::table('medication_prescriber_orders')
                ->where('id', $order->id)
                ->delete();

            if (! Schema::hasColumn('medication_prescriber_orders', 'read_back_verified_at')) {
                $migration->up();
            }
        }
    }
}
