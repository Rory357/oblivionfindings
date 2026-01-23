<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medications', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medications', 'is_prn')) {
                $table->boolean('is_prn')->default(false)->after('frequency');
            }
            if (!Schema::hasColumn('client_medications', 'prn_reason')) {
                $table->string('prn_reason')->nullable()->after('is_prn');
            }
            if (!Schema::hasColumn('client_medications', 'max_per_day')) {
                $table->string('max_per_day')->nullable()->after('prn_reason');
            }
            if (!Schema::hasColumn('client_medications', 'active')) {
                $table->boolean('active')->default(true)->after('instructions');
            }
        });

        Schema::create('client_medication_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            $table->integer('on_hand')->nullable();
            $table->string('unit')->nullable();
            $table->integer('reorder_level')->nullable();
            $table->timestamp('last_counted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('client_medication_id');
        });

        Schema::create('client_medication_administrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('administered_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('administered_at')->nullable();
            $table->string('status')->default('given'); // given | refused | missed
            $table->string('dose_given')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

		    // MySQL has a 64-char identifier limit; use a short, explicit index name.
		    $table->index(['client_id', 'administered_at'], 'cma_client_admin_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_medication_administrations');
        Schema::dropIfExists('client_medication_stocks');

        Schema::table('client_medications', function (Blueprint $table) {
            foreach (['is_prn', 'prn_reason', 'max_per_day', 'active'] as $col) {
                if (Schema::hasColumn('client_medications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
