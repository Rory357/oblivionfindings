<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('timesheet_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_agreement_id')->nullable()->constrained('service_agreements')->nullOnDelete();
            $table->unsignedBigInteger('line_item_id')->nullable();
            $table->date('service_date');
            $table->decimal('hours', 6, 2);
            $table->decimal('rate', 8, 2);
            $table->decimal('amount', 12, 2);
            $table->string('rate_type')->default('standard');
            $table->string('status')->default('pending');
            $table->date('billing_period_start')->nullable();
            $table->date('billing_period_end')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_entries');
    }
};
