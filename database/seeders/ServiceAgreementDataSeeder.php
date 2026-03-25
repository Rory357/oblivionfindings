<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceAgreement;
use Carbon\Carbon;

class ServiceAgreementDataSeeder extends Seeder
{
    public function run(): void
    {
        $agreements = ServiceAgreement::all();

        foreach ($agreements as $a) {
            // Seed milestone dates
            $startsAt = $a->starts_at ? Carbon::parse($a->starts_at) : now()->subMonths(3);
            $endsAt = $a->ends_at ? Carbon::parse($a->ends_at) : now()->addMonths(9);

            $a->update([
                'nasc_assessment_date' => $startsAt->copy()->subDays(30),
                'funding_approved_date' => $startsAt->copy()->subDays(14),
                'signed_date' => $startsAt->copy()->subDays(7),
                'first_service_date' => $startsAt->copy()->addDays(1),
                'review_due_date' => $endsAt->copy()->subMonths(2),
                'renewal_date' => $endsAt->copy()->subMonths(1),
            ]);

            $this->command->info("Updated milestones: {$a->title}");

            // Seed line items if none exist
            if ($a->lineItems()->count() === 0) {
                $serviceItems = [
                    ['description' => 'Personal Care Support', 'unit' => 'hour', 'rate' => 65.50, 'quantity' => 520],
                    ['description' => 'Community Access Support', 'unit' => 'hour', 'rate' => 58.00, 'quantity' => 260],
                    ['description' => 'Overnight Support (Active)', 'unit' => 'night', 'rate' => 185.00, 'quantity' => 52],
                    ['description' => 'Respite Care', 'unit' => 'day', 'rate' => 320.00, 'quantity' => 14],
                    ['description' => 'Transport Assistance', 'unit' => 'trip', 'rate' => 25.00, 'quantity' => 104],
                ];

                $count = rand(2, 5);
                $items = array_slice($serviceItems, 0, $count);

                foreach ($items as $item) {
                    $a->lineItems()->create([
                        'description' => $item['description'],
                        'unit' => $item['unit'],
                        'unit_price' => $item['rate'],
                        'quantity' => $item['quantity'],
                        'budget_allocated' => $item['rate'] * $item['quantity'],
                        'budget_used' => round($item['rate'] * $item['quantity'] * rand(10, 60) / 100, 2),
                    ]);
                }

                $this->command->info("  Added {$count} line items");
            }
        }
    }
}
