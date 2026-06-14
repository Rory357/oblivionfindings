<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes can now convert to a FinInvoice (in addition to a ServiceAgreement);
 * link the resulting invoice back to the quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('converted_to_invoice_id')->nullable()->after('converted_to_agreement_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('converted_to_invoice_id');
        });
    }
};
