<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientCondition;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Comprehensive seeder to demonstrate the complete medication management workflow.
 * 
 * Run with: php artisan db:seed --class=MedicationWorkflowDemoSeeder
 */
class MedicationWorkflowDemoSeeder extends Seeder
{
    private ServiceContext $serviceContext;
    private array $workers = [];
    private array $managers = [];
    private Carbon $today;

    public function run(): void
    {
        $this->command->info('Starting Medication Workflow Demo Seeder...');
        
        $this->today = now()->startOfDay();
        $this->serviceContext = ServiceContext::query()->firstOrCreate(
            ['name' => 'Demo Residential Home'],
            ['type' => 'residential', 'is_active' => true]
        );

        // Get or create users for the workflow
        $this->setupUsers();
        
        // Create demo clients with complete medical profiles
        $clients = $this->createDemoClients();
        
        foreach ($clients as $client) {
            $this->seedMedicalProfile($client);
            $this->seedConditions($client);
            $this->seedEmergencyContacts($client);
            $this->seedMedicationsAndWorkflow($client);
        }
        
        $this->command->info('Medication Workflow Demo Seeder completed successfully!');
        $this->command->info('');
        $this->command->info('Demo data includes:');
        $this->command->info('  - Medical profiles with allergies, history, disabilities');
        $this->command->info('  - Medical conditions (various severities)');
        $this->command->info('  - Emergency contacts');
        $this->command->info('  - Regular medications with scheduled doses');
        $this->command->info('  - PRN medications with reason tracking');
        $this->command->info('  - Controlled drugs with double-sign witness');
        $this->command->info('  - Medication administrations (given, missed, refused, withheld)');
        $this->command->info('  - Stock tracking with low stock alerts');
        $this->command->info('  - Controlled drug register entries');
        $this->command->info('  - Controlled drug discrepancies (open and closed)');
        $this->command->info('');
        $this->command->info('Access the Medical Profile page to see the redesigned interface!');
    }

    private function setupUsers(): void
    {
        // Ensure we have support workers
        $this->workers = User::query()->whereIn('role', ['support_worker', 'admin'])->limit(5)->get()->toArray();
        
        if (empty($this->workers)) {
            // Create demo workers if none exist
            for ($i = 1; $i <= 3; $i++) {
                $user = User::firstOrCreate(
                    ['email' => "worker{$i}@demo.local"],
                    [
                        'name' => "Support Worker {$i}",
                        'role' => 'support_worker',
                        'password' => bcrypt('password'),
                    ]
                );
                $this->workers[] = $user->toArray();
            }
        }

        // Ensure we have managers for witnessing
        $this->managers = User::query()->whereIn('role', ['manager', 'clinical_lead', 'admin'])->limit(3)->get()->toArray();
        
        if (empty($this->managers)) {
            for ($i = 1; $i <= 2; $i++) {
                $user = User::firstOrCreate(
                    ['email' => "manager{$i}@demo.local"],
                    [
                        'name' => "Manager {$i}",
                        'role' => 'manager',
                        'password' => bcrypt('password'),
                    ]
                );
                $this->managers[] = $user->toArray();
            }
        }

        $this->workers = array_map(fn($u) => (object)$u, $this->workers);
        $this->managers = array_map(fn($u) => (object)$u, $this->managers);
    }

    private function createDemoClients(): array
    {
        $demoClients = [
            [
                'first_name' => 'Sarah',
                'last_name' => 'Johnson',
                'date_of_birth' => '1955-03-15',
                'gender' => 'female',
                'email' => 'sarah.j@demo.local',
            ],
            [
                'first_name' => 'Michael',
                'last_name' => 'Chen',
                'date_of_birth' => '1962-07-22',
                'gender' => 'male',
                'email' => 'michael.c@demo.local',
            ],
            [
                'first_name' => 'Emma',
                'last_name' => 'Williams',
                'date_of_birth' => '1948-11-08',
                'gender' => 'female',
                'email' => 'emma.w@demo.local',
            ],
            [
                'first_name' => 'James',
                'last_name' => 'Anderson',
                'date_of_birth' => '1958-05-30',
                'gender' => 'male',
                'email' => 'james.a@demo.local',
            ],
        ];

        $clients = [];
        foreach ($demoClients as $data) {
            $client = Client::firstOrCreate(
                ['email' => $data['email']],
                array_merge($data, [
                    'status' => 'active',
                ])
            );
            $clients[] = $client;
        }

        return $clients;
    }

    private function seedMedicalProfile(Client $client): void
    {
        $profiles = [
            [
                'medical_history' => "Type 2 diabetes diagnosed 2015. Hypertension since 2010. History of falls in 2019. Mild cognitive impairment noted in 2021. Previous hip replacement surgery 2018.",
                'disabilities' => "Reduced mobility - uses walking frame. Visual impairment (cataracts). Some short-term memory difficulties.",
                'allergies' => "Penicillin - severe rash and breathing difficulty. Shellfish - mild stomach upset.",
                'notes' => "Prefers morning medications with breakfast. Needs reminders for PRN medications. Family very involved in care planning.",
            ],
            [
                'medical_history' => "Chronic back pain (degenerative disc disease). Asthma since childhood. Depression and anxiety. Insomnia.",
                'disabilities' => "Chronic pain limits mobility. Difficulty with stairs. Requires grab rails in bathroom.",
                'allergies' => "NSAIDs - stomach bleeding risk. Latex contact dermatitis.",
                'notes' => "Uses PRN pain medication regularly. Benefits from non-pharm interventions (heat packs, massage).",
            ],
            [
                'medical_history' => "Dementia (Alzheimer's type) diagnosed 2020. Osteoarthritis. High cholesterol. History of TIAs.",
                'disabilities' => "Significant memory impairment. Wandering behavior. Difficulty with ADLs. Requires supervision.",
                'allergies' => "Sulfa drugs. Aspirin.",
                'notes' => "Full medication administration required - cannot self-medicate. Daughter is primary contact and medical POA.",
            ],
            [
                'medical_history' => "COPD (Chronic Obstructive Pulmonary Disease). Heart failure. Chronic kidney disease stage 3. Gout.",
                'disabilities' => "Oxygen dependent (2L/min). Severe mobility limitations. Wheelchair for distances.",
                'allergies' => "Codeine - severe nausea. Contrast dye.",
                'notes' => "Complex medication regime. Requires nebulizer medications. Monitor for drug interactions with renal impairment.",
            ],
        ];

        $profileData = $profiles[$client->id % count($profiles)];
        
        ClientMedicalProfile::updateOrCreate(
            ['client_id' => $client->id],
            $profileData
        );
    }

    private function seedConditions(Client $client): void
    {
        $conditions = [
            ['label' => 'Type 2 Diabetes', 'severity' => 'moderate', 'notes' => 'HbA1c monitored quarterly'],
            ['label' => 'Hypertension', 'severity' => 'mild', 'notes' => 'Well controlled on current regimen'],
            ['label' => 'Osteoarthritis', 'severity' => 'moderate', 'notes' => 'Affects knees and hips'],
            ['label' => 'Chronic Pain', 'severity' => 'severe', 'notes' => 'Multimodal management approach'],
            ['label' => 'Anxiety Disorder', 'severity' => 'moderate', 'notes' => 'PRN medication available'],
            ['label' => 'Dementia', 'severity' => 'severe', 'notes' => 'Progressive cognitive decline'],
            ['label' => 'COPD', 'severity' => 'severe', 'notes' => 'Oxygen dependent'],
            ['label' => 'Heart Failure', 'severity' => 'severe', 'notes' => 'Fluid restriction in place'],
        ];

        // Assign 2-4 conditions per client
        $numConditions = rand(2, 4);
        $selectedConditions = array_slice($conditions, ($client->id * 2) % count($conditions), $numConditions);
        
        foreach ($selectedConditions as $condition) {
            ClientCondition::firstOrCreate(
                [
                    'client_id' => $client->id,
                    'label' => $condition['label'],
                ],
                [
                    'severity' => $condition['severity'],
                    'notes' => $condition['notes'],
                ]
            );
        }
    }

    private function seedEmergencyContacts(Client $client): void
    {
        $contacts = [
            [
                'name' => 'Margaret Johnson',
                'relationship' => 'Daughter',
                'phone' => '021-123-4567',
                'email' => 'margaret.j@email.com',
                'notes' => 'Primary contact, medical POA',
            ],
            [
                'name' => 'Robert Chen',
                'relationship' => 'Son',
                'phone' => '022-987-6543',
                'email' => 'robert.chen@email.com',
                'notes' => 'Visits weekly',
            ],
            [
                'name' => 'Dr. Sarah Williams',
                'relationship' => 'GP',
                'phone' => '09-555-1234',
                'email' => 'dr.williams@clinic.co.nz',
                'notes' => 'Available for medication queries',
            ],
            [
                'name' => 'Pharmacy Direct',
                'relationship' => 'Pharmacy',
                'phone' => '09-555-5678',
                'email' => 'orders@pharmacydirect.co.nz',
                'notes' => 'Delivery Tuesdays and Fridays',
            ],
        ];

        // Add 2-3 contacts per client
        $numContacts = rand(2, 3);
        for ($i = 0; $i < $numContacts; $i++) {
            $contact = $contacts[($client->id + $i) % count($contacts)];
            ClientEmergencyContact::firstOrCreate(
                [
                    'client_id' => $client->id,
                    'name' => $contact['name'],
                ],
                $contact
            );
        }
    }

    private function seedMedicationsAndWorkflow(Client $client): void
    {
        $worker = $this->workers[array_rand($this->workers)];
        $witness = $this->managers[array_rand($this->managers)];
        
        $shift = Shift::query()
            ->where('client_id', $client->id)
            ->whereDate('starts_at', $this->today->toDateString())
            ->first();

        if (!$shift) {
            $shift = Shift::create([
                'client_id' => $client->id,
                'user_id' => $worker->id,
                'service_context_id' => $this->serviceContext->id,
                'starts_at' => $this->today->copy()->setTime(7, 0),
                'ends_at' => $this->today->copy()->setTime(19, 0),
                'status' => 'active',
            ]);
        }

        // 1. Regular Medication (BID - twice daily)
        $this->createRegularMedication($client, $worker, $witness, $shift);
        
        // 2. PRN Medication
        $this->createPrnMedication($client, $worker, $witness, $shift);
        
        // 3. Controlled Drug (if applicable)
        if (rand(1, 3) === 1) {
            $this->createControlledDrug($client, $worker, $witness, $shift);
        }
        
        // 4. Ceased medication (for history)
        $this->createCeasedMedication($client);
    }

    private function createRegularMedication(Client $client, object $worker, object $witness, Shift $shift): void
    {
        $medNames = [
            ['name' => 'Metformin', 'dosage' => '500mg', 'frequency' => 'Twice daily', 'route' => 'oral', 'form' => 'tablet'],
            ['name' => 'Amlodipine', 'dosage' => '5mg', 'frequency' => 'Once daily', 'route' => 'oral', 'form' => 'tablet'],
            ['name' => 'Paracetamol', 'dosage' => '1g', 'frequency' => 'Four times daily', 'route' => 'oral', 'form' => 'tablet'],
            ['name' => 'Atorvastatin', 'dosage' => '20mg', 'frequency' => 'Once daily at night', 'route' => 'oral', 'form' => 'tablet'],
        ];
        
        $medData = $medNames[$client->id % count($medNames)];
        
        $medication = ClientMedication::updateOrCreate(
            [
                'client_id' => $client->id,
                'name' => $medData['name'],
            ],
            [
                'dosage' => $medData['dosage'],
                'frequency' => $medData['frequency'],
                'dose_times' => $medData['frequency'] === 'Once daily' ? ['08:00'] : 
                               ($medData['frequency'] === 'Once daily at night' ? ['20:00'] :
                               ($medData['frequency'] === 'Twice daily' ? ['08:00', '20:00'] : ['08:00', '12:00', '16:00', '20:00'])),
                'is_prn' => false,
                'controlled_drug' => false,
                'route' => $medData['route'],
                'form' => $medData['form'],
                'prescriber' => 'Dr. ' . ['Smith', 'Jones', 'Taylor', 'Brown'][$client->id % 4],
                'pharmacy' => ['Countdown Pharmacy', 'Life Pharmacy', 'Unichem', 'Chemist Warehouse'][$client->id % 4],
                'start_date' => $this->today->copy()->subMonths(6)->toDateString(),
                'instructions' => 'Give with food. Monitor for side effects.',
                'active' => true,
                'state' => 'active',
            ]
        );

        // Set up stock (some with low stock for demo)
        $onHand = rand(1, 100);
        ClientMedicationStock::updateOrCreate(
            ['client_medication_id' => $medication->id],
            [
                'on_hand' => $onHand,
                'unit' => $medData['form'] . 's',
                'reorder_level' => 10,
                'last_counted_at' => $this->today->copy()->subDays(rand(1, 7)),
            ]
        );

        // Create administrations with various statuses
        foreach ($medication->dose_times as $time) {
            $scheduled = $this->today->copy()->setTimeFromTimeString($time);
            
            // Random status: 70% given, 10% missed, 10% refused, 10% withheld
            $rand = rand(1, 100);
            $status = match(true) {
                $rand <= 70 => 'given',
                $rand <= 80 => 'missed',
                $rand <= 90 => 'refused',
                default => 'withheld',
            };
            
            $adminAt = $status === 'given' ? $scheduled->copy()->addMinutes(rand(-5, 15)) : null;
            
            $reason = match($status) {
                'missed' => ['Client at appointment', 'Medication unavailable', 'Staff shortage'][rand(0, 2)],
                'refused' => ['Client declined', 'Feeling unwell', 'Preference'][rand(0, 2)],
                'withheld' => ['Clinical hold - awaiting review', 'NPO for procedure', 'Contraindicated'][rand(0, 2)],
                default => null,
            };

            ClientMedicationAdministration::create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'shift_id' => $shift->id,
                'service_context_id' => $this->serviceContext->id,
                'administered_by' => $worker->id,
                'scheduled_for' => $scheduled,
                'administered_at' => $adminAt,
                'status' => $status,
                'dose_given' => $status === 'given' ? $medData['dosage'] : null,
                'reason' => $reason,
                'notes' => $status === 'given' ? 'Taken well with water' : null,
            ]);
        }
    }

    private function createPrnMedication(Client $client, object $worker, object $witness, Shift $shift): void
    {
        $prnMeds = [
            ['name' => 'Lorazepam', 'dosage' => '1mg', 'prn_reason' => 'Anxiety / agitation', 'max_per_day' => '2'],
            ['name' => 'Paracetamol', 'dosage' => '1g', 'prn_reason' => 'Pain / fever', 'max_per_day' => '4'],
            ['name' => 'Ondansetron', 'dosage' => '4mg', 'prn_reason' => 'Nausea', 'max_per_day' => '3'],
        ];
        
        $medData = $prnMeds[$client->id % count($prnMeds)];
        
        $medication = ClientMedication::updateOrCreate(
            [
                'client_id' => $client->id,
                'name' => $medData['name'] . ' (PRN)',
            ],
            [
                'dosage' => $medData['dosage'],
                'frequency' => 'PRN (as needed)',
                'dose_times' => [],
                'is_prn' => true,
                'prn_reason' => $medData['prn_reason'],
                'max_per_day' => $medData['max_per_day'],
                'controlled_drug' => str_contains($medData['name'], 'Lorazepam'),
                'route' => 'oral',
                'form' => 'tablet',
                'prescriber' => 'Dr. Wilson',
                'pharmacy' => 'Life Pharmacy',
                'start_date' => $this->today->copy()->subMonths(3)->toDateString(),
                'instructions' => 'Use least restrictive practice. Document reason and effectiveness.',
                'active' => true,
                'state' => 'active',
            ]
        );

        ClientMedicationStock::updateOrCreate(
            ['client_medication_id' => $medication->id],
            [
                'on_hand' => rand(5, 20),
                'unit' => 'tablets',
                'reorder_level' => 5,
                'last_counted_at' => $this->today->copy()->subDays(2),
            ]
        );

        // Create 0-2 PRN administrations per client
        $numAdmin = rand(0, 2);
        for ($i = 0; $i < $numAdmin; $i++) {
            $adminTime = $this->today->copy()->setTime(rand(9, 17), rand(0, 59));
            
            $admin = ClientMedicationAdministration::create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'shift_id' => $shift->id,
                'service_context_id' => $this->serviceContext->id,
                'administered_by' => $worker->id,
                'scheduled_for' => $adminTime,
                'administered_at' => $adminTime->copy()->addMinutes(5),
                'status' => 'given',
                'dose_given' => $medData['dosage'],
                'reason' => 'PRN: ' . $medData['prn_reason'] . ' - escalating symptoms',
                'notes' => 'Effective relief observed within 30 minutes',
            ]);

            // If controlled drug, create register entry
            if ($medication->controlled_drug) {
                $this->createControlledDrugEntry($client, $medication, $worker, $witness, $shift, 'administered');
            }
        }
    }

    private function createControlledDrug(Client $client, object $worker, object $witness, Shift $shift): void
    {
        $controlledMed = ClientMedication::updateOrCreate(
            [
                'client_id' => $client->id,
                'name' => 'Morphine Sulfate',
            ],
            [
                'dosage' => '5mg',
                'frequency' => 'PRN (breakthrough pain)',
                'dose_times' => [],
                'is_prn' => true,
                'prn_reason' => 'Breakthrough cancer pain',
                'max_per_day' => '4',
                'controlled_drug' => true,
                'route' => 'oral',
                'form' => 'liquid',
                'prescriber' => 'Dr. Pain Specialist',
                'pharmacy' => 'Hospital Pharmacy',
                'start_date' => $this->today->copy()->subMonths(1)->toDateString(),
                'instructions' => 'CONTROLLED DRUG - Double sign required. Lock in CD cabinet.',
                'active' => true,
                'state' => 'active',
            ]
        );

        // Set stock
        ClientMedicationStock::updateOrCreate(
            ['client_medication_id' => $controlledMed->id],
            [
                'on_hand' => 200, // ml
                'unit' => 'ml',
                'reorder_level' => 50,
                'last_counted_at' => $this->today->copy()->subDay(),
            ]
        );

        // Create controlled drug register entries
        $this->createControlledDrugEntry($client, $controlledMed, $worker, $witness, $shift, 'received', 500);
        $this->createControlledDrugEntry($client, $controlledMed, $worker, $witness, $shift, 'administered', 5);
        $this->createControlledDrugEntry($client, $controlledMed, $worker, $witness, $shift, 'disposed', 2);

        // Create a discrepancy (some open, some closed)
        $this->createControlledDrugDiscrepancy($client, $controlledMed, $worker, $witness);
    }

    private function createControlledDrugEntry(
        Client $client, 
        ClientMedication $medication, 
        object $worker, 
        object $witness, 
        Shift $shift,
        string $entryType,
        ?int $quantity = null
    ): void {
        $stock = ClientMedicationStock::where('client_medication_id', $medication->id)->first();
        $before = $stock?->on_hand ?? 0;
        
        $qty = $quantity ?? rand(1, 3);
        $after = match($entryType) {
            'received' => $before + $qty,
            'administered', 'disposed', 'wasted' => max(0, $before - $qty),
            default => $before,
        };
        
        if ($stock) {
            $stock->update(['on_hand' => $after]);
        }

        ClientControlledDrugEntry::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'shift_id' => $shift->id,
            'service_context_id' => $this->serviceContext->id,
            'entry_type' => $entryType,
            'quantity' => $qty,
            'unit' => $stock?->unit ?? 'units',
            'on_hand_before' => $before,
            'on_hand_after' => $after,
            'reason' => match($entryType) {
                'received' => 'Pharmacy delivery',
                'administered' => 'Pain management',
                'disposed' => 'Expired medication',
                'wasted' => 'Spillage during administration',
                default => 'Stock adjustment',
            },
            'notes' => "Seeded controlled drug register entry: {$entryType}",
            'recorded_at' => $this->today->copy()->subHours(rand(1, 24)),
            'recorded_by' => $worker->id,
            'witnessed_by' => $witness->id,
        ]);
    }

    private function createControlledDrugDiscrepancy(
        Client $client, 
        ClientMedication $medication, 
        object $worker, 
        object $witness
    ): void {
        $stock = ClientMedicationStock::where('client_medication_id', $medication->id)->first();
        $before = $stock?->on_hand ?? 100;
        $difference = rand(-5, 5);
        $after = max(0, $before + $difference);
        
        $isOpen = rand(1, 3) === 1; // 1 in 3 chance of being open
        
        ClientControlledDrugDiscrepancy::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $this->serviceContext->id,
            'on_hand_before' => $before,
            'on_hand_after' => $after,
            'difference' => $difference,
            'reason' => 'Stock count discrepancy during routine audit',
            'status' => $isOpen ? 'open' : 'closed',
            'reported_at' => $this->today->copy()->subDays(rand(1, 7)),
            'reported_by' => $worker->id,
            'witnessed_by' => $witness->id,
            'resolved_at' => $isOpen ? null : $this->today->copy()->subDays(rand(1, 3)),
            'resolved_by' => $isOpen ? null : $this->managers[0]->id,
            'resolution_notes' => $isOpen ? null : 'Investigation complete. Adjustment made to stock records.',
        ]);
    }

    private function createCeasedMedication(Client $client): void
    {
        // Add a medication that was ceased (for history demonstration)
        ClientMedication::updateOrCreate(
            [
                'client_id' => $client->id,
                'name' => 'Aspirin (Ceased)',
            ],
            [
                'dosage' => '100mg',
                'frequency' => 'Once daily',
                'dose_times' => ['08:00'],
                'is_prn' => false,
                'controlled_drug' => false,
                'route' => 'oral',
                'form' => 'tablet',
                'prescriber' => 'Dr. Smith',
                'pharmacy' => 'Life Pharmacy',
                'start_date' => $this->today->copy()->subMonths(12)->toDateString(),
                'end_date' => $this->today->copy()->subMonths(2)->toDateString(),
                'ceased_at' => $this->today->copy()->subMonths(2)->toDateString(),
                'ceased_reason' => 'Changed to Clopidogrel - better GI tolerance',
                'instructions' => 'Discontinued - see ceased reason',
                'active' => false,
                'state' => 'ceased',
            ]
        );
    }
}
