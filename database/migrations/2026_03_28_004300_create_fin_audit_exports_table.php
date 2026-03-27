<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_audit_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('export_name');
            $table->date('period_from');
            $table->date('period_to');
            $table->boolean('include_journals')->default(true);
            $table->boolean('include_bank_reconciliations')->default(true);
            $table->boolean('include_ap')->default(true);
            $table->boolean('include_ar')->default(true);
            $table->boolean('include_gst')->default(true);
            $table->boolean('include_fixed_assets')->default(true);
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->enum('status', ['pending', 'generating', 'completed', 'failed'])->default('pending');
            $table->datetime('generated_at')->nullable();
            $table->datetime('downloaded_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_audit_exports');
    }
};
