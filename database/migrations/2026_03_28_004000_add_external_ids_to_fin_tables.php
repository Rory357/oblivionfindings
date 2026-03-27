<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_accounts', function (Blueprint $table) {
            $table->string('xero_account_id')->nullable()->after('description');
            $table->string('myob_account_id')->nullable()->after('xero_account_id');
        });

        Schema::table('fin_journals', function (Blueprint $table) {
            $table->string('xero_journal_id')->nullable()->after('total_amount');
            $table->string('myob_journal_id')->nullable()->after('xero_journal_id');
        });

        Schema::table('fin_vendors', function (Blueprint $table) {
            $table->string('xero_contact_id')->nullable()->after('notes');
            $table->string('myob_contact_id')->nullable()->after('xero_contact_id');
        });

        Schema::table('fin_bills', function (Blueprint $table) {
            $table->string('xero_invoice_id')->nullable()->after('notes');
            $table->string('myob_invoice_id')->nullable()->after('xero_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('fin_accounts', function (Blueprint $table) {
            $table->dropColumn(['xero_account_id', 'myob_account_id']);
        });

        Schema::table('fin_journals', function (Blueprint $table) {
            $table->dropColumn(['xero_journal_id', 'myob_journal_id']);
        });

        Schema::table('fin_vendors', function (Blueprint $table) {
            $table->dropColumn(['xero_contact_id', 'myob_contact_id']);
        });

        Schema::table('fin_bills', function (Blueprint $table) {
            $table->dropColumn(['xero_invoice_id', 'myob_invoice_id']);
        });
    }
};
