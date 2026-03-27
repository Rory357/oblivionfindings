<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_donor_funds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('fund_code');
            $table->string('fund_name');
            $table->string('donor_name')->nullable();
            $table->string('donor_contact')->nullable();
            $table->enum('fund_type', ['grant', 'donation', 'bequest', 'trust', 'government', 'sponsorship']);
            $table->foreignId('gl_account_id')->nullable()->constrained('fin_accounts')->nullOnDelete();
            $table->foreignId('funding_stream_id')->nullable()->constrained('fin_funding_streams')->nullOnDelete();
            $table->decimal('total_received', 14, 2)->default(0);
            $table->decimal('total_spent', 14, 2)->default(0);
            $table->decimal('total_committed', 14, 2)->default(0);
            $table->decimal('available_balance', 14, 2)->default(0);
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('restrictions')->nullable();
            $table->text('reporting_requirements')->nullable();
            $table->date('next_report_due')->nullable();
            $table->enum('status', ['active', 'fully_spent', 'expired', 'returned'])->default('active');
            $table->boolean('is_restricted')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'fund_code'], 'fin_donor_funds_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_donor_funds');
    }
};
