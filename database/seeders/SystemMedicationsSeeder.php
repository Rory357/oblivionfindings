<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Seeder;

class SystemMedicationsSeeder extends Seeder
{
    public function run(): void
    {
        $serviceContext = ServiceContext::query()->first();
        if (!$serviceContext) {
            return;
        }

        $workers = User::query()->where('role', 'support_worker')->get();
        if ($workers->isEmpty()) {
            return;
        }

        $today = now()->startOfDay();
        $clients = Client::query()->with('supportWorkers')->get();

        foreach ($clients as $i => $client) {
            $worker = $client->supportWorkers->first() ?: $workers->random();
            $witness = $client->supportWorkers->skip(1)->first() ?: $workers->random();

            $shift = Shift::query()
                ->where('client_id', $client->id)
                ->whereDate('starts_at', $today->toDateString())
                ->orderBy('starts_at')
                ->first();

            // Regular med (BID)
            $regular = ClientMedication::updateOrCreate(
                ['client_id' => $client->id, 'name' => 'Paracetamol'],
                [
                    'dosage' => '1g',
                    'frequency' => 'Twice daily',
                    'dose_times' => ['08:00', '20:00'],
                    'is_prn' => false,
                    'controlled_drug' => false,
                    'route' => 'oral',
                    'form' => 'tablet',
                    'prescriber' => 'Dr Green',
                    'pharmacy' => 'Demo Pharmacy',
                    'start_date' => $today->toDateString(),
                    'instructions' => 'Give with food if possible.',
                    'active' => true,
                    'state' => 'active',
                ]
            );

            ClientMedicationStock::updateOrCreate(
                ['client_medication_id' => $regular->id],
                ['on_hand' => 60, 'unit' => 'tablets', 'reorder_level' => 10, 'last_counted_at' => now()->subDays(2)]
            );

            // PRN med
            $prn = ClientMedication::updateOrCreate(
                ['client_id' => $client->id, 'name' => 'Lorazepam'],
                [
                    'dosage' => '1mg',
                    'frequency' => 'PRN',
                    'dose_times' => [],
                    'is_prn' => true,
                    'prn_reason' => 'Anxiety / acute distress',
                    'max_per_day' => 2,
                    'controlled_drug' => ($i % 3 === 0),
                    'route' => 'oral',
                    'form' => 'tablet',
                    'prescriber' => 'Dr Kaur',
                    'pharmacy' => 'Demo Pharmacy',
                    'start_date' => $today->toDateString(),
                    'instructions' => 'Use least restrictive practice; document reason.',
                    'active' => true,
                    'state' => 'active',
                ]
            );

            ClientMedicationStock::updateOrCreate(
                ['client_medication_id' => $prn->id],
                ['on_hand' => 14, 'unit' => 'tablets', 'reorder_level' => 4, 'last_counted_at' => now()->subDays(1)]
            );

            // Seed administrations for regular med (today)
            foreach (['08:00', '20:00'] as $k => $time) {
                $scheduled = (clone $today)->setTimeFromTimeString($time);
                $status = ($k === 0) ? 'given' : (($i % 5 === 0) ? 'missed' : 'given');
                $adminAt = $status === 'given' ? (clone $scheduled)->addMinutes(rand(-10, 20)) : null;

                ClientMedicationAdministration::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $regular->id,
                    'shift_id' => $shift?->id,
                    'service_context_id' => $serviceContext->id,
                    'administered_by' => $worker->id,
                    'scheduled_for' => $scheduled,
                    'administered_at' => $adminAt,
                    'status' => $status,
                    'dose_given' => '1g',
                    'reason' => $status === 'missed' ? 'Client unavailable (out on appointment)' : null,
                    'notes' => 'Seeded MAR entry.',
                ]);
            }

            // Seed one PRN entry for ~half clients
            if ($i % 2 === 0) {
                $scheduled = (clone $today)->setTime(14, 30);
                $isControlled = (bool)$prn->controlled_drug;

                $admin = ClientMedicationAdministration::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $prn->id,
                    'shift_id' => $shift?->id,
                    'service_context_id' => $serviceContext->id,
                    'administered_by' => $worker->id,
                    'witnessed_by' => $isControlled ? $witness->id : null,
                    'scheduled_for' => $scheduled,
                    'administered_at' => (clone $scheduled)->addMinutes(5),
                    'status' => 'given',
                    'dose_given' => '1mg',
                    'reason' => 'PRN used: escalating anxiety',
                    'notes' => $isControlled ? 'Controlled drug: witnessed.' : 'PRN given.',
                ]);

                // If controlled, seed the controlled drug register entry too
                if ($isControlled) {
                    $stock = $prn->stock;
                    $before = (int)($stock?->on_hand ?? 14);
                    $after = max(0, $before - 1);
                    if ($stock) {
                        $stock->update(['on_hand' => $after]);
                    }

                    ClientControlledDrugEntry::create([
                        'client_id' => $client->id,
                        'client_medication_id' => $prn->id,
                        'shift_id' => $shift?->id,
                        'service_context_id' => $serviceContext->id,
                        'entry_type' => 'administered',
                        'quantity' => 1,
                        'unit' => 'tablets',
                        'on_hand_before' => $before,
                        'on_hand_after' => $after,
                        'reason' => 'Administration (seed)',
                        'notes' => 'Seeded controlled drug register entry linked to MAR.',
                        'recorded_at' => now()->subMinutes(30),
                        'recorded_by' => $worker->id,
                        'witnessed_by' => $witness->id,
                    ]);
                }
            }
        }
    }
}
