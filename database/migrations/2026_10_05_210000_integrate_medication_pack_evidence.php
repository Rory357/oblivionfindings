<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_stock_lots', function (Blueprint $table): void {
            $table->index('controlled_entry_id', 'med_stock_lots_receipt_index');
            $table->dropUnique('med_stock_lots_register_unique');
        });
        Schema::table('medication_stock_count_records', function (Blueprint $table): void {
            $table->foreignId('controlled_entry_id')->nullable()->constrained('client_controlled_drug_entries', indexName: 'med_stock_count_register_fk')->restrictOnDelete();
            $table->foreignId('witnessed_by')->nullable()->constrained('users', indexName: 'med_stock_count_witness_fk')->restrictOnDelete();
            $table->timestamp('coverage_start')->nullable();
            $table->timestamp('coverage_end')->nullable();
            $table->json('covered_paper_entry_ids')->nullable();
        });
        Schema::table('client_controlled_drug_entries', function (Blueprint $table): void {
            $table->string('stock_balance_scope', 20)->default('house');
            $table->foreignId('transit_log_id')->nullable()->constrained('fleet_medication_transit_logs', indexName: 'cd_entry_transit_fk')->restrictOnDelete();
            $table->foreignId('stock_count_record_id')->nullable()->constrained('medication_stock_count_records', indexName: 'cd_entry_stock_count_fk')->restrictOnDelete();
        });
        Schema::table('medication_stock_movements', function (Blueprint $table): void {
            $table->foreignId('controlled_entry_id')->nullable()->constrained('client_controlled_drug_entries', indexName: 'med_stock_move_register_fk')->restrictOnDelete();
            $table->foreignId('destruction_id')->nullable()->constrained('medication_destructions', indexName: 'med_stock_move_destruction_fk')->restrictOnDelete();
            $table->foreignId('transit_log_id')->nullable()->constrained('fleet_medication_transit_logs', indexName: 'med_stock_move_transit_fk')->restrictOnDelete();
            $table->foreignId('stock_count_record_id')->nullable()->constrained('medication_stock_count_records', indexName: 'med_stock_move_count_fk')->restrictOnDelete();
            $table->foreignId('reverses_movement_id')->nullable()->unique('med_stock_move_reverse_unique')->constrained('medication_stock_movements', indexName: 'med_stock_move_reverse_fk')->restrictOnDelete();
            $table->json('evidence')->nullable();
        });
        Schema::table('fleet_medication_transit_logs', function (Blueprint $table): void {
            $table->string('stock_reconciliation_status', 20)->nullable();
            $table->text('stock_reconciliation_reason')->nullable();
            $table->foreignId('stock_loss_report_id')->nullable()->constrained('controlled_drug_loss_reports', indexName: 'transit_stock_loss_fk')->restrictOnDelete();
        });
        Schema::create('medication_stock_transit_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transit_log_id')->constrained('fleet_medication_transit_logs', indexName: 'med_stock_transit_log_fk')->restrictOnDelete();
            $table->foreignId('medication_stock_lot_id')->constrained('medication_stock_lots', indexName: 'med_stock_transit_lot_fk')->restrictOnDelete();
            $table->foreignId('outward_movement_id')->unique()->constrained('medication_stock_movements', indexName: 'med_stock_transit_out_fk')->restrictOnDelete();
            $table->decimal('quantity_out', 12, 2);
            $table->decimal('quantity_used', 12, 2)->default(0);
            $table->decimal('quantity_returned', 12, 2)->default(0);
            $table->decimal('quantity_missing', 12, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['transit_log_id', 'medication_stock_lot_id'], 'med_stock_transit_pack_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Pack, register and transit evidence must be retained. Use a reviewed forward migration.');
    }
};
