<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ServiceContext;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Test command to verify medication workflow functionality.
 * 
 * Usage: php artisan medication:workflow-test
 */
class TestMedicationWorkflow extends Command
{
    protected $signature = 'medication:workflow-test 
                            {--client= : Specific client ID to test with}
                            {--seed : Run the demo seeder first}';

    protected $description = 'Test the complete medication management workflow';

    public function handle(): int
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║     Medication Management Workflow Test Suite              ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->info('');

        if ($this->option('seed')) {
            $this->info('Running demo seeder...');
            $this->call('db:seed', ['--class' => 'MedicationWorkflowDemoSeeder']);
            $this->info('');
        }

        // Get test client
        $clientId = $this->option('client');
        if ($clientId) {
            $client = Client::find($clientId);
            if (!$client) {
                $this->error("Client ID {$clientId} not found.");
                return 1;
            }
        } else {
            $client = Client::query()->first();
            if (!$client) {
                $this->error('No clients found. Run with --seed option first.');
                return 1;
            }
        }

        $this->info("Testing with client: {$client->first_name} {$client->last_name} (ID: {$client->id})");
        $this->info(str_repeat('-', 60));
        $this->info('');

        // Run tests
        $this->testMedicalProfile($client);
        $this->testConditions($client);
        $this->testEmergencyContacts($client);
        $this->testMedications($client);
        $this->testStockManagement($client);
        $this->testAdministrations($client);
        $this->testControlledDrugs($client);
        $this->testAuditTrail($client);

        $this->info('');
        $this->info(str_repeat('=', 60));
        $this->info('Workflow test suite completed successfully!');
        $this->info('');
        $this->info('Next steps:');
        $this->info('  1. Visit /clients/' . $client->id . '/medical in your browser');
        $this->info('  2. Check the redesigned Medical Profile page');
        $this->info('  3. Visit /medications/audit to see audit logs');
        $this->info('  4. Test the collapsible forms and table views');
        $this->info('');

        return 0;
    }

    private function testMedicalProfile(Client $client): void
    {
        $this->info('📋 MEDICAL PROFILE');
        
        $profile = $client->medicalProfile;
        if ($profile) {
            $this->info("  ✓ Medical profile found");
            $this->info("    - Allergies: " . ($profile->allergies ? 'Yes' : 'No'));
            $this->info("    - Medical history: " . ($profile->medical_history ? 'Yes' : 'No'));
            $this->info("    - Disabilities: " . ($profile->disabilities ? 'Yes' : 'No'));
        } else {
            $this->warn("  ⚠ No medical profile found");
        }
        $this->info('');
    }

    private function testConditions(Client $client): void
    {
        $this->info('🏥 MEDICAL CONDITIONS');
        
        $conditions = \App\Models\ClientCondition::where('client_id', $client->id)->get();
        if ($conditions && $conditions->isNotEmpty()) {
            $this->info("  ✓ {$conditions->count()} condition(s) found");
            foreach ($conditions->take(3) as $condition) {
                $this->info("    - {$condition->label} ({$condition->severity})");
            }
        } else {
            $this->warn("  ⚠ No conditions found");
        }
        $this->info('');
    }

    private function testEmergencyContacts(Client $client): void
    {
        $this->info('📞 EMERGENCY CONTACTS');
        
        $contacts = $client->emergencyContacts;
        if ($contacts->isNotEmpty()) {
            $this->info("  ✓ {$contacts->count()} contact(s) found");
            foreach ($contacts->take(3) as $contact) {
                $this->info("    - {$contact->name} ({$contact->relationship})");
            }
        } else {
            $this->warn("  ⚠ No emergency contacts found");
        }
        $this->info('');
    }

    private function testMedications(Client $client): void
    {
        $this->info('💊 MEDICATIONS');
        
        $medications = ClientMedication::where('client_id', $client->id)->get();
        
        if ($medications->isEmpty()) {
            $this->warn("  ⚠ No medications found");
            return;
        }

        $this->info("  ✓ {$medications->count()} medication(s) found");
        
        $active = $medications->where('state', 'active')->count();
        $prn = $medications->where('is_prn', true)->count();
        $controlled = $medications->where('controlled_drug', true)->count();
        $ceased = $medications->where('state', 'ceased')->count();
        
        $this->info("    - Active: {$active}");
        $this->info("    - PRN (as needed): {$prn}");
        $this->info("    - Controlled drugs: {$controlled}");
        $this->info("    - Ceased: {$ceased}");
        $this->info('');
    }

    private function testStockManagement(Client $client): void
    {
        $this->info('📦 STOCK MANAGEMENT');
        
        $medications = ClientMedication::where('client_id', $client->id)
            ->where('state', 'active')
            ->pluck('id');
            
        $stocks = ClientMedicationStock::whereIn('client_medication_id', $medications)->get();
        
        if ($stocks->isEmpty()) {
            $this->warn("  ⚠ No stock records found");
            return;
        }

        $this->info("  ✓ {$stocks->count()} stock record(s) found");
        
        $lowStock = $stocks->filter(function ($stock) {
            return $stock->on_hand !== null && 
                   $stock->reorder_level !== null && 
                   $stock->on_hand <= $stock->reorder_level;
        });
        
        if ($lowStock->isNotEmpty()) {
            $this->warn("    ⚠ {$lowStock->count()} medication(s) with low stock");
        }
        $this->info('');
    }

    private function testAdministrations(Client $client): void
    {
        $this->info('💉 MEDICATION ADMINISTRATIONS (MAR)');
        
        $administrations = ClientMedicationAdministration::where('client_id', $client->id)
            ->with('medication')
            ->get();
        
        if ($administrations->isEmpty()) {
            $this->warn("  ⚠ No administration records found");
            return;
        }

        $this->info("  ✓ {$administrations->count()} administration record(s) found");
        
        $statuses = $administrations->groupBy('status')->map->count();
        foreach ($statuses as $status => $count) {
            $icon = match($status) {
                'given' => '✓',
                'missed' => '✗',
                'refused' => '⊘',
                'withheld' => '⊗',
                default => '-',
            };
            $this->info("    {$icon} {$status}: {$count}");
        }
        $this->info('');
    }

    private function testControlledDrugs(Client $client): void
    {
        $this->info('🔒 CONTROLLED DRUGS');
        
        $controlledMeds = ClientMedication::where('client_id', $client->id)
            ->where('controlled_drug', true)
            ->pluck('id');
        
        if ($controlledMeds->isEmpty()) {
            $this->info("  ℹ No controlled drugs for this client");
            $this->info('');
            return;
        }

        $this->info("  ✓ {$controlledMeds->count()} controlled medication(s)");
        
        // Check register entries
        $entries = ClientControlledDrugEntry::where('client_id', $client->id)->get();
        $this->info("    - Register entries: {$entries->count()}");
        
        // Check for double-sign (witnessed)
        $witnessed = $entries->whereNotNull('witnessed_by')->count();
        if ($witnessed > 0) {
            $this->info("    - Witnessed entries: {$witnessed}");
        }
        
        // Check discrepancies
        $discrepancies = \App\Models\ClientControlledDrugDiscrepancy::where('client_id', $client->id)->get();
        if ($discrepancies->isNotEmpty()) {
            $open = $discrepancies->where('status', 'open')->count();
            $closed = $discrepancies->where('status', 'closed')->count();
            $this->info("    - Discrepancies: {$discrepancies->count()} ({$open} open, {$closed} closed)");
        }
        $this->info('');
    }

    private function testAuditTrail(Client $client): void
    {
        $this->info('📊 AUDIT TRAIL');
        
        // Check if audit records exist in the database
        $auditCount = DB::table('client_medication_administrations')
            ->where('client_id', $client->id)
            ->count();
        
        if ($auditCount > 0) {
            $this->info("  ✓ {$auditCount} audit record(s) in MAR");
        }
        
        $cdAuditCount = DB::table('client_controlled_drug_entries')
            ->where('client_id', $client->id)
            ->count();
        
        if ($cdAuditCount > 0) {
            $this->info("  ✓ {$cdAuditCount} controlled drug register record(s)");
        }
        
        $this->info('');
    }
}
