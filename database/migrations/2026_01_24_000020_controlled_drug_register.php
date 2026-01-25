<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medications', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medications', 'controlled_drug')) {
                $table->boolean('controlled_drug')->default(false)->after('is_prn');
            }
        });

        Schema::create('client_controlled_drug_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('service_context_id')->nullable()->constrained('service_contexts')->nullOnDelete();

            // administered | received | wasted | stock_count | adjustment
            $table->string('entry_type');
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit')->nullable();
            $table->integer('on_hand_before')->nullable();
            $table->integer('on_hand_after')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('witnessed_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['client_id', 'recorded_at'], 'ccde_client_rec_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_controlled_drug_entries');

        Schema::table('client_medications', function (Blueprint $table) {
            if (Schema::hasColumn('client_medications', 'controlled_drug')) {
                $table->dropColumn('controlled_drug');
            }
        });
    }
};
