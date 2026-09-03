<?php

namespace Tests\Feature\Medication;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ControlledDrugStockTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_administer_controlled_drug_when_stock_insufficient(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        $nurse = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $nurse->id,
            'employee_number' => 'EMP-' . $nurse->id,
            'work_email' => $nurse->email,
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);

        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);

        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => '2.00',
            'unit' => 'tablets',
            'last_counted_at' => now(),
        ]);

        $admin = ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'status' => 'given',
            'administered_at' => now(),
            'administered_by' => $nurse->id,
        ]);

        $service = app(EnhancedMarService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Insufficient controlled drug stock available');

        $service->recordControlledDrugEntry(
            $medication,
            $admin,
            $nurse->id,
            null,
            '5.00'
        );

        $stock->refresh();
        $this->assertSame('2.00', (string) $stock->on_hand);
    }

    public function test_controlled_drug_administration_decrements_stock_truthfully_when_sufficient(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        $nurse = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $nurse->id,
            'employee_number' => 'EMP-' . $nurse->id,
            'work_email' => $nurse->email,
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);

        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);

        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => '10.00',
            'unit' => 'tablets',
            'last_counted_at' => now(),
        ]);

        $admin = ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'status' => 'given',
            'administered_at' => now(),
            'administered_by' => $nurse->id,
        ]);

        $service = app(EnhancedMarService::class);

        $service->recordControlledDrugEntry(
            $medication,
            $admin,
            $nurse->id,
            null,
            '2.00'
        );

        $stock->refresh();
        $this->assertSame('8.00', (string) $stock->on_hand);

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $medication->id,
            'quantity' => '2.00',
            'on_hand_before' => '10.00',
            'on_hand_after' => '8.00',
        ]);
    }
}
