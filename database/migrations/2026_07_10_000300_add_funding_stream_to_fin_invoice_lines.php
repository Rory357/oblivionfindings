<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funding-stream attribution on AR invoice lines. Bill lines already carry
 * funding_stream_id into their journal lines; invoice (revenue) lines could
 * not, so funder-billed income (e.g. respite → Whaikaha) was invisible to the
 * funding-stream summary report. With this, the invoice send-journal's revenue
 * lines carry the stream — the GL-level drawdown attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('funding_stream_id')->nullable()->after('account_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fin_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('funding_stream_id');
        });
    }
};
