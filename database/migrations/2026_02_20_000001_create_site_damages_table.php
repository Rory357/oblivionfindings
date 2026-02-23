<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_damages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('reported_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->string('title');
            $table->text('description');
            $table->string('location_in_site')->nullable();
            $table->enum('severity', ['minor', 'moderate', 'major', 'critical']);
            $table->enum('status', ['reported', 'assessed', 'repair_scheduled', 'repair_in_progress', 'repaired', 'closed'])->default('reported');
            $table->date('damage_date');
            $table->date('discovered_date');
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->decimal('actual_cost', 10, 2)->nullable();
            $table->string('insurance_claim_ref')->nullable();
            $table->enum('insurance_status', ['not_applicable', 'pending', 'submitted', 'approved', 'declined'])->default('not_applicable');
            $table->text('repair_notes')->nullable();
            $table->datetime('repaired_at')->nullable();
            $table->foreignId('repaired_by')->nullable()->constrained('users');
            $table->json('photos')->nullable();
            $table->foreignId('checklist_run_id')->nullable()->constrained('site_checklist_runs')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_damages');
    }
};
