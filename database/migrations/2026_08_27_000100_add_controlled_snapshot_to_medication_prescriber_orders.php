<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('medication_prescriber_orders', 'controlled_drug_snapshot')) {
            Schema::table('medication_prescriber_orders', function (Blueprint $table): void {
                $table->boolean('controlled_drug_snapshot')
                    ->nullable()
                    ->after('client_medication_id');
            });
        }

        DB::table('medication_prescriber_orders')
            ->whereNotNull('client_medication_id')
            ->whereNull('controlled_drug_snapshot')
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                $medications = DB::table('client_medications')
                    ->whereIn('id', $orders->pluck('client_medication_id')->unique()->values())
                    ->get(['id', 'client_id', 'controlled_drug'])
                    ->keyBy('id');

                foreach ($orders as $order) {
                    $medication = $medications->get($order->client_medication_id);
                    if ($medication === null
                        || (int) $medication->client_id !== (int) $order->client_id
                    ) {
                        continue;
                    }

                    DB::table('medication_prescriber_orders')
                        ->where('id', $order->id)
                        ->whereNull('controlled_drug_snapshot')
                        ->update([
                            'controlled_drug_snapshot' => (bool) $medication->controlled_drug,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Classification is immutable clinical provenance. Retaining this
        // additive column is compatible with the preceding application while
        // allowing a later migration batch to roll back without stopping
        // partway through after newer migrations have already been removed.
    }
};
