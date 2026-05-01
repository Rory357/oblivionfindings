<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('clients')
                ->nullOnDelete();
            $table->string('funding_body')->nullable()->after('client_address');
            $table->string('source')->nullable()->after('bill_id')->index('fin_inv_source_name_idx');
            $table->string('source_type')->nullable()->after('source');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');

            $table->index(['organization_id', 'client_id'], 'fin_inv_org_client_idx');
            $table->index(['source_type', 'source_id'], 'fin_inv_source_idx');
        });

        Schema::table('fin_invoice_lines', function (Blueprint $table) {
            $table->foreignId('billing_entry_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('billing_entries')
                ->nullOnDelete();
            $table->date('service_date')->nullable()->after('line_total');
            $table->string('category')->nullable()->after('service_date');
        });
    }

    public function down(): void
    {
        Schema::table('fin_invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_entry_id');
            $table->dropColumn(['service_date', 'category']);
        });

        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->dropIndex('fin_inv_org_client_idx');
            $table->dropIndex('fin_inv_source_idx');
            $table->dropIndex('fin_inv_source_name_idx');
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['funding_body', 'source', 'source_type', 'source_id']);
        });
    }
};
