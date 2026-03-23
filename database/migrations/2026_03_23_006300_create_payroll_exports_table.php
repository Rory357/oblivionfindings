<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('export_type')->default('xero'); // xero/myob/quickbooks/keypay/csv
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft'); // draft/exported/confirmed
            $table->unsignedInteger('timesheet_count')->default(0);
            $table->decimal('total_hours', 10, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('file_path')->nullable(); // exported file
            $table->dateTime('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_exports');
    }
};
