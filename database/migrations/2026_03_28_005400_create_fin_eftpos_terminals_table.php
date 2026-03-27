<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_eftpos_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('terminal_id');
            $table->string('name');
            $table->string('location')->nullable();
            $table->enum('provider', ['paymark', 'worldline', 'eftpos_nz', 'windcave']);
            $table->text('merchant_id');
            $table->foreignId('bank_account_id')->nullable()->constrained('fin_bank_accounts')->nullOnDelete();
            $table->foreignId('gl_account_id')->nullable()->constrained('fin_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'terminal_id'], 'fin_eftpos_term_org_tid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_eftpos_terminals');
    }
};
