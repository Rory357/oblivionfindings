<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_pharmacy_orders', function (Blueprint $table): void {
            $table->decimal('quantity_received', 12, 2)->nullable()->change();
        });

        Schema::table('client_controlled_drug_entries', function (Blueprint $table): void {
            $table->foreignId('pharmacy_order_id')
                ->nullable()
                ->after('client_medication_id')
                ->constrained('medication_pharmacy_orders')
                ->restrictOnDelete();
            $table->unique('pharmacy_order_id');
        });
    }

    public function down(): void
    {
        if (DB::table('client_controlled_drug_entries')->whereNotNull('pharmacy_order_id')->exists()) {
            throw new RuntimeException(
                'Cannot remove controlled pharmacy-delivery provenance while linked register entries exist.',
            );
        }

        $quantity = DB::connection()->getQueryGrammar()->wrap('quantity_received');
        if (DB::table('medication_pharmacy_orders')
            ->whereNotNull('quantity_received')
            ->whereRaw(
                "({$quantity} <> ROUND({$quantity}, 0) OR {$quantity} < ? OR {$quantity} > ?)",
                [-2147483648, 2147483647],
            )
            ->exists()) {
            throw new RuntimeException(
                'Cannot downgrade medication_pharmacy_orders.quantity_received while fractional values exist or values fall outside the signed INT range.',
            );
        }

        Schema::table('client_controlled_drug_entries', function (Blueprint $table): void {
            $table->dropForeign(['pharmacy_order_id']);
            $table->dropUnique(['pharmacy_order_id']);
            $table->dropColumn('pharmacy_order_id');
        });

        Schema::table('medication_pharmacy_orders', function (Blueprint $table): void {
            $table->integer('quantity_received')->nullable()->change();
        });
    }
};
