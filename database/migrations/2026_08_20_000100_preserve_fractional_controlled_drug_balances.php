<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medication_stocks', function (Blueprint $table): void {
            $table->decimal('on_hand', 12, 2)->nullable()->change();
        });

        Schema::table('client_controlled_drug_entries', function (Blueprint $table): void {
            $table->decimal('on_hand_before', 12, 2)->nullable()->change();
            $table->decimal('on_hand_after', 12, 2)->nullable()->change();
        });

        Schema::table('client_controlled_drug_discrepancies', function (Blueprint $table): void {
            $table->decimal('on_hand_before', 12, 2)->nullable()->change();
            $table->decimal('on_hand_after', 12, 2)->nullable()->change();
            $table->decimal('difference', 12, 2)->nullable()->change();
        });

        Schema::table('medication_scheduled_stock_counts', function (Blueprint $table): void {
            $table->decimal('expected_quantity', 12, 2)->nullable()->change();
            $table->decimal('actual_quantity', 12, 2)->nullable()->change();
            $table->decimal('discrepancy', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        $this->assertBalancesFitSignedIntegers();

        Schema::table('medication_scheduled_stock_counts', function (Blueprint $table): void {
            $table->integer('expected_quantity')->nullable()->change();
            $table->integer('actual_quantity')->nullable()->change();
            $table->integer('discrepancy')->nullable()->change();
        });

        Schema::table('client_controlled_drug_discrepancies', function (Blueprint $table): void {
            $table->integer('on_hand_before')->nullable()->change();
            $table->integer('on_hand_after')->nullable()->change();
            $table->integer('difference')->nullable()->change();
        });

        Schema::table('client_controlled_drug_entries', function (Blueprint $table): void {
            $table->integer('on_hand_before')->nullable()->change();
            $table->integer('on_hand_after')->nullable()->change();
        });

        Schema::table('client_medication_stocks', function (Blueprint $table): void {
            $table->integer('on_hand')->nullable()->change();
        });
    }

    private function assertBalancesFitSignedIntegers(): void
    {
        foreach ([
            'client_medication_stocks' => ['on_hand'],
            'client_controlled_drug_entries' => ['on_hand_before', 'on_hand_after'],
            'client_controlled_drug_discrepancies' => ['on_hand_before', 'on_hand_after', 'difference'],
            'medication_scheduled_stock_counts' => ['expected_quantity', 'actual_quantity', 'discrepancy'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $wrappedColumn = DB::connection()->getQueryGrammar()->wrap($column);
                $hasUnsafeValue = DB::table($table)
                    ->whereNotNull($column)
                    ->where(function ($query) use ($wrappedColumn): void {
                        $query->whereRaw("{$wrappedColumn} <> ROUND({$wrappedColumn}, 0)")
                            ->orWhereRaw("{$wrappedColumn} < -2147483648")
                            ->orWhereRaw("{$wrappedColumn} > 2147483647");
                    })
                    ->exists();

                if ($hasUnsafeValue) {
                    throw new RuntimeException(
                        "Cannot restore {$table}.{$column} to signed integer storage while fractional or out-of-range medication-stock provenance exists.",
                    );
                }
            }
        }
    }
};
