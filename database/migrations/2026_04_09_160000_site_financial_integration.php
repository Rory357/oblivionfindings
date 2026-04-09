<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────
        // 1. Add rent/lease fields to sites
        // ──────────────────────────────────────────────────────────
        Schema::table('sites', function (Blueprint $table) {
            $table->decimal('rent_amount', 10, 2)->nullable()->after('primary_contact_user_id');
            $table->string('rent_frequency', 20)->nullable()->after('rent_amount'); // weekly, fortnightly, monthly
            $table->date('lease_start_date')->nullable()->after('rent_frequency');
            $table->date('lease_end_date')->nullable()->after('lease_start_date');
            $table->string('landlord_name')->nullable()->after('lease_end_date');
            $table->string('landlord_contact')->nullable()->after('landlord_name');
        });

        // ──────────────────────────────────────────────────────────
        // 2. Site utilities tracking
        // ──────────────────────────────────────────────────────────
        Schema::create('site_utilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('type', 30); // power, water, internet, gas, rates, waste
            $table->string('provider')->nullable();
            $table->string('account_number')->nullable();
            $table->decimal('monthly_estimate', 10, 2)->default(0);
            $table->decimal('last_actual_amount', 10, 2)->nullable();
            $table->date('last_actual_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'type'], 'site_utilities_site_type_unique');
            $table->index(['tenant_id', 'is_active']);
        });

        // ──────────────────────────────────────────────────────────
        // 3. Add journal_id to house_ledger_entries for GL linkage
        // ──────────────────────────────────────────────────────────
        Schema::table('house_ledger_entries', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('attachments')
                ->constrained('fin_journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('house_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });

        Schema::dropIfExists('site_utilities');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'rent_amount',
                'rent_frequency',
                'lease_start_date',
                'lease_end_date',
                'landlord_name',
                'landlord_contact',
            ]);
        });
    }
};
