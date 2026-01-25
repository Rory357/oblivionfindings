<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 9: medication order completeness + clear state handling
        Schema::table('client_medications', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medications', 'form')) {
                $table->string('form')->nullable()->after('route');
            }
            if (!Schema::hasColumn('client_medications', 'pharmacy')) {
                $table->string('pharmacy')->nullable()->after('prescriber');
            }

            // Replace ambiguous boolean `active` with explicit state (keep `active` for backwards compatibility)
            if (!Schema::hasColumn('client_medications', 'state')) {
                $table->string('state')->default('active')->after('active'); // active|paused|ceased
            }
            if (!Schema::hasColumn('client_medications', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('state');
            }
            if (!Schema::hasColumn('client_medications', 'ceased_at')) {
                $table->date('ceased_at')->nullable()->after('end_date');
            }
            if (!Schema::hasColumn('client_medications', 'ceased_reason')) {
                $table->string('ceased_reason')->nullable()->after('ceased_at');
            }

            // Step 8: dose times drive Daily MAR (fallback still works when null)
            if (!Schema::hasColumn('client_medications', 'dose_times')) {
                $table->json('dose_times')->nullable()->after('frequency');
            }
        });

        // Step 10: safe correction rules for MAR entries (corrections are new entries)
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medication_administrations', 'corrected_of_id')) {
                $table->foreignId('corrected_of_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('client_medication_administrations')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('client_medication_administrations', 'is_correction')) {
                $table->boolean('is_correction')->default(false)->after('corrected_of_id');
            }
            if (!Schema::hasColumn('client_medication_administrations', 'correction_reason')) {
                $table->string('correction_reason')->nullable()->after('reason');
            }
        });

        // Step 16: Break-glass emergency access
        Schema::create('client_break_glass_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_break_glass_accesses');

        Schema::table('client_medication_administrations', function (Blueprint $table) {
            if (Schema::hasColumn('client_medication_administrations', 'corrected_of_id')) {
                $table->dropConstrainedForeignId('corrected_of_id');
            }
            foreach (['is_correction', 'correction_reason'] as $col) {
                if (Schema::hasColumn('client_medication_administrations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('client_medications', function (Blueprint $table) {
            foreach (['form', 'pharmacy', 'state', 'paused_at', 'ceased_at', 'ceased_reason', 'dose_times'] as $col) {
                if (Schema::hasColumn('client_medications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
