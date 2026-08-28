<?php

namespace Tests\Feature\Console;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\Site;
use App\Services\Medication\MedicationSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CheckMedicationStockCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_distinguishes_fractional_low_stock_from_zero_stock_operational_delivery(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $fractionalMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Fractional low stock medication',
            'active' => true,
            'state' => 'active',
        ]);
        $zeroMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Zero stock medication',
            'active' => true,
            'state' => 'active',
        ]);
        $fractionalStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $fractionalMedication->id,
            'on_hand' => '0.50',
            'unit' => 'tablets',
            'reorder_level' => '1.00',
            'expiry_date' => null,
        ]);
        $zeroStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $zeroMedication->id,
            'on_hand' => '0.00',
            'unit' => 'tablets',
            'reorder_level' => '1.00',
            'expiry_date' => null,
        ]);

        $signals = Mockery::mock(MedicationSignalService::class);
        $signals->shouldReceive('emit')
            ->once()
            ->withArgs(fn (
                string $signalType,
                int $clientId,
                string $severity,
                string $message,
                array $context,
            ): bool => $signalType === MedicationSignalService::TYPE_STOCK_OUT
                && $clientId === $client->id
                && $severity === 'high'
                && str_contains($message, 'Zero stock medication')
                && (int) ($context['client_medication_id'] ?? 0) === $zeroMedication->id
                && (int) ($context['site_id'] ?? 0) === $site->id);
        $this->app->instance(MedicationSignalService::class, $signals);

        $this->artisan('emar:check-medication-stock')
            ->expectsOutputToContain('Stock check complete. 2 new alerts created.')
            ->assertSuccessful();

        $this->assertDatabaseHas('medication_dashboard_alerts', [
            'client_id' => $client->id,
            'client_medication_id' => $fractionalMedication->id,
            'alert_type' => 'stock_low',
            'severity' => 'warning',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('medication_dashboard_alerts', [
            'client_id' => $client->id,
            'client_medication_id' => $zeroMedication->id,
            'alert_type' => 'stock_low',
            'severity' => 'critical',
            'status' => 'active',
        ]);
        $this->assertNotNull($fractionalStock->fresh()->last_reorder_alert_at);
        $this->assertNotNull($zeroStock->fresh()->last_reorder_alert_at);
    }
}
