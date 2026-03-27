<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('code', 20);
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->enum('sub_type', [
                'bank', 'accounts_receivable', 'accounts_payable', 'fixed_asset',
                'accumulated_depreciation', 'current_asset', 'current_liability',
                'long_term_liability', 'equity', 'revenue', 'cost_of_sales',
                'expense', 'other',
            ])->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('fin_accounts')->nullOnDelete();
            $table->unsignedBigInteger('funding_stream_id')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->text('description')->nullable();
            $table->boolean('gst_applicable')->default(false);
            $table->unsignedBigInteger('default_tax_rate_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_accounts');
    }
};
