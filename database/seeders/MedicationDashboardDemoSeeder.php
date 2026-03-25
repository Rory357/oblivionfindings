<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationDashboardAlert;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Comprehensive seeder for Medication Dashboard with realistic stats
 */
class MedicationDashboardDemoSeeder extends Seeder
{
    private Carbon $today;
    private ?ServiceContext $serviceContext = null;
    private $workers = [];

    public function run(): void
    {
        $this->command->info('Starting Medication Dashboard Demo Seeder...');
        
        $this->today = now()->startOfDay();
        
        // Get existing data
        $this->serviceContext = ServiceContext::query()->first();
        $this->workers = User::query()->whereIn('role', ['support_worker', 'manager', 'admin'])->limit(5)->get();
        
        if ($this->workers->isEmpty()) {
            $this->command->error('No workers found. Run SystemUsersSeeder first.');
            return;
        }

        $clients = Client::query()->get();
        
        if ($clients->isEmpty()) {
            $this->command->error('No clients found. Run SystemClientsSeeder first.');
            return;
        }

        $this->command->info("Seeding medications for {$clients->count()} clients...");

        foreach ($clients as $index => $client) {
            $this->seedClientMedications($client, $index);
            if (($index + 1) % 5 === 0) {
                $this->command->info('  ✓ ' . ($index + 1) . ' clients done');
            }
        }
        
        // Create dashboard alerts
        $this->createDashboardAlerts($clients);
        
        $this->command->info('✅ Dashboard demo data created successfully!');
        $this->command->info('');
        $this->command->info('Expected dashboard stats:');
        $this->command->info('  - Total Clients: 10');
        $this->command->info('  - Scheduled doses today: ~40-50');
        $this->command->info('  - Completed: ~60-70%');
        $this->command->info('  - Late/Missed: Some for realistic view');
        $this->command->info('  - Controlled Drug Discrepancies: 2-3');
        $this->command->info('  - PRN Near Limits: 1-2');
        $this->command->info('  - Active Alerts: 3-5');
    }

    private function seedClientMedications(Client $client, int $index = 0): void
    {
        $worker = $this->workers->random();
        $witness = $this->workers->where('id', '!=', $worker->id)->first() ?? $worker;

        // Create shift for today
        $shift = Shift::firstOrCreate(
            [
                'client_id' => $client->id,
                'starts_at' => $this->today->copy()->setTime(7, 0),
            ],
            [
                'user_id' => $worker->id,
                'service_context_id' => $this->serviceContext?->id,
                'ends_at' => $this->today->copy()->setTime(19, 0),
                'status' => 'active',
            ]
        );

        // Use index to create variation across clients
        $pattern = $index % 5; // 5 different patterns

        // === REGULAR MEDICATION (BID - twice daily) ===
        $regularMed = ClientMedication::updateOrCreate(
            [
                'client_id' => $client->id,
                'name' => 'Paracetamol',
            ],
            [
                'dosage' => '1g',
                'frequency' => 'Twice daily',
                'dose_times' => ['08:00', '20:00'],
                'is_prn' => false,
                'controlled_drug' => false,
                'route' => 'oral',
                'form' => 'tablet',
                'prescriber' => 'Dr. Smith',
                'pharmacy' => 'Life Pharmacy',
                'start_date' => $this->today->copy()->subMonth(),
                'instructions' => 'Give with food',
                'active' => true,
                'state' => 'active',
            ]
        );

        ClientMedicationStock::updateOrCreate(
            ['client_medication_id' => $regularMed->id],
            ['on_hand' => 60, 'unit' => 'tablets', 'reorder_level' => 10, 'last_counted_at' => now()->subDay()]
        );

        // Create administrations with different patterns per client
        foreach (['08:00', '20:00'] as $timeIndex => $time) {
            $scheduled = $this->today->copy()->setTimeFromTimeString($time);
            $existing = ClientMedicationAdministration::where('client_id', $client->id)
                ->where('client_medication_id', $regularMed->id)
                ->where('scheduled_for', $scheduled)
                ->first();
            
            if ($existing) {
                continue; // Skip if already exists
            }

            // Pattern-based status distribution
            switch ($pattern) {
                case 0: // All given (compliant)
                    $status = 'given';
                    $adminAt = $scheduled->copy()->addMinutes(rand(0, 15));
                    break;
                case 1: // Some late/missed
                    $rand = rand(1, 100);
                    if ($timeIndex === 0) { // Morning
                        $status = 'given';
                        $adminAt = $scheduled->copy()->addMinutes(rand(45, 90)); // Late
                    } else {
                        $status = rand(1, 2) === 1 ? 'missed' : null; // Not given yet (will be due)
                        $adminAt = $status === 'missed' ? null : null;
                    }
                    break;
                case 2: // Missed morning
                    if ($timeIndex === 0) {
                        $status = 'missed';
                        $adminAt = null;
                    } else {
                        $status = null; // Not given yet
                    }
                    break;
                case 3: // Refused
                    $status = 'refused';
                    $adminAt = $scheduled->copy()->addMinutes(rand(0, 10));
                    break;
                case 4: // Mix
                default:
                    $rand = rand(1, 100);
                    if ($rand <= 50) {
                        $status = 'given';
                        $adminAt = $scheduled->copy()->addMinutes(rand(-5, 20));
                    } elseif ($rand <= 75) {
                        $status = 'missed';
                        $adminAt = null;
                    } else {
                        $status = null; // Not recorded yet (due)
                        $adminAt = null;
                    }
                    break;
            }

            if ($status !== null) {
                ClientMedicationAdministration::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $regularMed->id,
                    'shift_id' => $shift->id,
                    'service_context_id' => $this->serviceContext?->id,
                    'scheduled_for' => $scheduled,
                    'administered_by' => $worker->id,
                    'administered_at' => $adminAt,
                    'status' => $status,
                    'dose_given' => $status === 'given' ? '1g' : null,
                    'reason' => $status === 'missed' ? 'Client refused' : ($status === 'refused' ? 'Client declined' : null),
                    'notes' => 'Seeded for dashboard',
                ]);
            }
        }

        // === MORNING MEDICATION (once daily) ===
        $morningMed = ClientMedication::updateOrCreate(
            [
                'client_id' => $client->id,
                'name' => 'Amlodipine',
            ],
            [
                'dosage' => '5mg',
                'frequency' => 'Once daily',
                'dose_times' => ['08:00'],
                'is_prn' => false,
                'controlled_drug' => false,
                'route' => 'oral',
                'form' => 'tablet',
                'prescriber' => 'Dr. Jones',
                'pharmacy' => 'Unichem',
                'start_date' => $this->today->copy()->subMonth(),
                'instructions' => 'Morning only',
                'active' => true,
                'state' => 'active',
            ]
        );

        ClientMedicationStock::updateOrCreate(
            ['client_medication_id' => $morningMed->id],
            ['on_hand' => 30, 'unit' => 'tablets', 'reorder_level' => 5, 'last_counted_at' => now()->subDay()]
        );

        // Administer morning med based on pattern
        $scheduledMorning = $this->today->copy()->setTime(8, 0);
        $existingMorning = ClientMedicationAdministration::where('client_id', $client->id)
            ->where('client_medication_id', $morningMed->id)
            ->where('scheduled_for', $scheduledMorning)
            ->first();

        if (!$existingMorning) {
            $morningStatus = match ($pattern) {
                0 => 'given', // Compliant
                1 => rand(1, 2) === 1 ? 'given' : null, // Not recorded
                2 => 'missed', // Missed
                3 => 'refused', // Refused
                default => rand(1, 100) <= 60 ? 'given' : null,
            };

            if ($morningStatus !== null) {
                ClientMedicationAdministration::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $morningMed->id,
                    'shift_id' => $shift->id,
                    'service_context_id' => $this->serviceContext?->id,
                    'scheduled_for' => $scheduledMorning,
                    'administered_by' => $worker->id,
                    'administered_at' => $morningStatus === 'given' ? $scheduledMorning->copy()->addMinutes(rand(0, 15)) : null,
                    'status' => $morningStatus,
                    'dose_given' => $morningStatus === 'given' ? '5mg' : null,
                    'notes' => 'Seeded for dashboard',
                ]);
            }
        }

        // === AFTERNOON MEDICATION (for variety - will create 'due' entries) ===
        if ($pattern === 1 || $pattern === 4) {
            $afternoonMed = ClientMedication::updateOrCreate(
                [
                    'client_id' => $client->id,
                    'name' => 'Metformin',
                ],
                [
                    'dosage' => '500mg',
                    'frequency' => 'Lunch time',
                    'dose_times' => ['12:00'],
                    'is_prn' => false,
                    'controlled_drug' => false,
                    'route' => 'oral',
                    'form' => 'tablet',
                    'prescriber' => 'Dr. Wilson',
                    'pharmacy' => 'Life Pharmacy',
                    'start_date' => $this->today->copy()->subMonth(),
                    'instructions' => 'With lunch',
                    'active' => true,
                    'state' => 'active',
                ]
            );

            // Don't create administration - will show as 'due' or 'late'
        }

        // === EVENING MEDICATION (may show as due) ===
        $eveningMed = ClientMedication::updateOrCreate(
            [
                'client_id' => $client->id,
                'name' => 'Simvastatin',
            ],
            [
                'dosage' => '20mg',
                'frequency' => 'Once daily evening',
                'dose_times' => ['18:00'],
                'is_prn' => false,
                'controlled_drug' => false,
                'route' => 'oral',
                'form' => 'tablet',
                'prescriber' => 'Dr. Brown',
                'pharmacy' => 'Unichem',
                'start_date' => $this->today->copy()->subMonth(),
                'instructions' => 'Evening dose',
                'active' => true,
                'state' => 'active',
            ]
        );

        // PRN medication
        $prnMed = ClientMedication::updateOrCreate(
            [
                'client_id' => $client->id,
                'name' => 'Lorazepam',
            ],
            [
                'dosage' => '1mg',
                'frequency' => 'PRN',
                'dose_times' => [],
                'is_prn' => true,
                'prn_reason' => 'Anxiety',
                'max_per_day' => '2',
                'controlled_drug' => rand(1, 5) === 1, // 20% are controlled
                'route' => 'oral',
                'form' => 'tablet',
                'prescriber' => 'Dr. Wilson',
                'pharmacy' => 'Life Pharmacy',
                'start_date' => $this->today->copy()->subMonth(),
                'instructions' => 'As needed for anxiety',
                'active' => true,
                'state' => 'active',
            ]
        );

        ClientMedicationStock::updateOrCreate(
            ['client_medication_id' => $prnMed->id],
            ['on_hand' => rand(2, 8), 'unit' => 'tablets', 'reorder_level' => 3, 'last_counted_at' => now()->subDay()]
        );

        // Give PRN for some clients
        if (rand(1, 100) <= 30) {
            ClientMedicationAdministration::create([
                'client_id' => $client->id,
                'client_medication_id' => $prnMed->id,
                'shift_id' => $shift->id,
                'service_context_id' => $this->serviceContext?->id,
                'administered_by' => $worker->id,
                'administered_at' => $this->today->copy()->setTime(rand(9, 17), rand(0, 59)),
                'status' => 'given',
                'dose_given' => '1mg',
                'reason' => 'PRN: escalating anxiety',
                'notes' => 'Seeded PRN administration',
            ]);
        }

        // Create controlled drug entry for controlled meds
        if ($prnMed->controlled_drug) {
            ClientControlledDrugEntry::create([
                'client_id' => $client->id,
                'client_medication_id' => $prnMed->id,
                'shift_id' => $shift->id,
                'service_context_id' => $this->serviceContext?->id,
                'entry_type' => 'administered',
                'quantity' => 1,
                'unit' => 'tablets',
                'on_hand_before' => 10,
                'on_hand_after' => 9,
                'reason' => 'Administration',
                'recorded_at' => now()->subHours(rand(1, 8)),
                'recorded_by' => $worker->id,
                'witnessed_by' => $witness->id,
            ]);
        }
    }

    private function createDashboardAlerts($clients): void
    {
        // Clear old alerts
        MedicationDashboardAlert::query()->delete();

        $alertTypes = [
            ['type' => 'overdue', 'severity' => 'warning', 'message' => 'Medication overdue for administration'],
            ['type' => 'prn_near_limit', 'severity' => 'warning', 'message' => 'PRN medication approaching daily limit'],
            ['type' => 'controlled_discrepancy', 'severity' => 'critical', 'message' => 'Controlled drug stock discrepancy detected'],
            ['type' => 'expiring', 'severity' => 'info', 'message' => 'Medication order expires soon'],
            ['type' => 'high_risk', 'severity' => 'warning', 'message' => 'High-risk medication requires double-check'],
        ];

        // Create 3-5 alerts
        $numAlerts = rand(3, 5);
        $clientsArray = $clients->take(5);

        for ($i = 0; $i < $numAlerts; $i++) {
            $client = $clientsArray->random();
            $alertConfig = $alertTypes[array_rand($alertTypes)];
            
            $medication = ClientMedication::where('client_id', $client->id)->first();

            MedicationDashboardAlert::create([
                'client_id' => $client->id,
                'client_medication_id' => $medication?->id,
                'alert_type' => $alertConfig['type'],
                'severity' => $alertConfig['severity'],
                'message' => $alertConfig['message'] . ' - ' . $client->first_name . ' ' . $client->last_name,
                'status' => 'active',
                'created_at' => now()->subHours(rand(1, 24)),
                'updated_at' => now(),
            ]);
        }

        // Create controlled drug discrepancies
        foreach ($clients->take(3) as $client) {
            $controlledMed = ClientMedication::where('client_id', $client->id)
                ->where('controlled_drug', true)
                ->first();

            if ($controlledMed && rand(1, 2) === 1) {
                ClientControlledDrugDiscrepancy::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $controlledMed->id,
                    'service_context_id' => $this->serviceContext?->id,
                    'on_hand_before' => 10,
                    'on_hand_after' => 8,
                    'difference' => -2,
                    'reason' => 'Stock count discrepancy',
                    'status' => rand(1, 2) === 1 ? 'open' : 'closed',
                    'reported_at' => now()->subHours(rand(1, 12)),
                    'reported_by' => $this->workers->random()->id,
                    'witnessed_by' => $this->workers->random()->id,
                ]);
            }
        }
    }
}
