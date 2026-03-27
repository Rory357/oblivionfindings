<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_journals', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('total_amount')->constrained('fin_currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 14, 6)->nullable()->after('currency_id');
            $table->decimal('base_currency_amount', 14, 2)->nullable()->after('exchange_rate');
        });

        Schema::table('fin_journal_lines', function (Blueprint $table) {
            $table->decimal('currency_amount', 14, 2)->nullable()->after('tax_amount');
        });

        Schema::table('fin_bills', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('notes')->constrained('fin_currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 14, 6)->nullable()->after('currency_id');
        });

        Schema::table('fin_bank_accounts', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('is_active')->constrained('fin_currencies')->nullOnDelete();
        });

        Schema::table('fin_bank_transactions', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('status')->constrained('fin_currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 14, 6)->nullable()->after('currency_id');
            $table->decimal('base_amount', 14, 2)->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('fin_bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['exchange_rate', 'base_amount']);
        });

        Schema::table('fin_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
        });

        Schema::table('fin_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn('exchange_rate');
        });

        Schema::table('fin_journal_lines', function (Blueprint $table) {
            $table->dropColumn('currency_amount');
        });

        Schema::table('fin_journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['exchange_rate', 'base_currency_amount']);
        });
    }
};
