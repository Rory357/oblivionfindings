<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('report_type');
            $table->date('period_start');
            $table->date('period_end');
            $table->json('data');
            $table->datetime('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'report_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_report_snapshots');
    }
};
