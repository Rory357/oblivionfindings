<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_medication_stock_id')->constrained(indexName: 'med_stock_lots_stock_fk')->restrictOnDelete();
            $table->string('batch_number', 100)->nullable();
            $table->boolean('batch_not_printed')->default(false);
            $table->date('expiry_date')->nullable();
            $table->boolean('expiry_not_printed')->default(false);
            $table->decimal('quantity_received', 12, 2);
            $table->decimal('quantity_remaining', 12, 2);
            $table->string('state', 24)->default('open')->index();
            $table->foreignId('controlled_entry_id')->nullable()->unique('med_stock_lots_register_unique')->constrained('client_controlled_drug_entries', indexName: 'med_stock_lots_register_fk')->restrictOnDelete();
            $table->string('source', 40);
            $table->string('source_reference', 255)->nullable();
            $table->foreignId('pharmacy_order_id')->nullable()->constrained('medication_pharmacy_orders', indexName: 'med_stock_lots_order_fk')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users', indexName: 'med_stock_lots_receiver_fk')->nullOnDelete();
            $table->timestamp('received_at');
            $table->text('short_expiry_reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->index(['client_medication_stock_id', 'state', 'expiry_date'], 'med_stock_lots_fefo');
        });
        Schema::create('medication_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medication_stock_lot_id')->constrained(indexName: 'med_stock_moves_lot_fk')->restrictOnDelete();
            $table->uuid('operation_uuid');
            $table->string('kind', 40)->index();
            $table->decimal('quantity', 12, 2);
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users', indexName: 'med_stock_moves_actor_fk')->nullOnDelete();
            $table->timestamp('recorded_at')->index();
            $table->foreignId('administration_id')->nullable()->constrained('client_medication_administrations', indexName: 'med_stock_moves_dose_fk')->restrictOnDelete();
            $table->foreignId('return_of_id')->nullable()->constrained('medication_stock_movements', indexName: 'med_stock_moves_return_fk')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['operation_uuid', 'medication_stock_lot_id'], 'med_stock_movement_replay');
        });
        Schema::create('medication_stock_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medication_stock_lot_id')->constrained(indexName: 'med_stock_photos_lot_fk')->restrictOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 80);
            $table->unsignedBigInteger('bytes');
            $table->string('sha256', 64);
            $table->text('description')->nullable();
            $table->foreignId('taken_by')->nullable()->constrained('users', indexName: 'med_stock_photos_actor_fk')->nullOnDelete();
            $table->timestamp('taken_at');
            $table->timestamps();
        });
        Schema::create('medication_stock_count_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_medication_stock_id')->constrained('client_medication_stocks', indexName: 'med_stock_counts_stock_fk')->restrictOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->string('state', 24)->default('counted')->index();
            $table->json('lines');
            $table->text('reason')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users', indexName: 'med_stock_counts_actor_fk')->nullOnDelete();
            $table->timestamp('counted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'med_stock_counts_reviewer_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
        });
        Schema::table('client_medication_stocks', function (Blueprint $table): void {
            // Opt-in only after every writer uses the P06 service. Existing balances
            // and all audit/controlled ledger history are left untouched.
            $table->timestamp('lots_started_at')->nullable();
        });
        Schema::table('medication_pharmacy_orders', function (Blueprint $table): void {
            $table->date('needed_by')->nullable();
            $table->date('expected_delivery')->nullable();
            $table->string('communication_method', 30)->nullable();
            $table->text('communication_reference')->nullable();
            $table->foreignId('communication_recorded_by')->nullable()->constrained('users', indexName: 'med_pharmacy_contact_actor_fk')->nullOnDelete();
            $table->timestamp('communication_recorded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users', indexName: 'med_pharmacy_closer_fk')->nullOnDelete();
            $table->text('closure_reason')->nullable();
            $table->decimal('quantity_dispensed', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        // A rollback that deletes medication evidence requires a separately reviewed
        // retention/export plan; this additive migration is deliberately forward-only.
        throw new RuntimeException('Stock lot and movement evidence must be retained. Use a reviewed forward migration.');
    }
};
