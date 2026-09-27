<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('name', 120);
            $table->string('source', 60);
            $table->unsignedInteger('version')->default(1);
            $table->json('definition');
            $table->json('shared_with')->nullable();
            $table->string('folder', 80)->nullable();
            $table->boolean('favourite')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'archived_at']);
        });
        Schema::create('operational_report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('operational_reports')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
            $table->unique(['report_id', 'version']);
        });
        Schema::create('operational_report_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('report_id')->nullable()->constrained('operational_reports')->nullOnDelete();
            $table->json('definition');
            $table->string('status', 20)->default('queued');
            $table->string('failure_code', 40)->nullable();
            $table->longText('payload')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
        Schema::create('operational_report_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('operational_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('frequency', 12);
            $table->boolean('active')->default(false);
            $table->timestamp('next_run_at');
            $table->text('reason');
            $table->timestamps();
            $table->unique(['report_id', 'user_id']);
            $table->index(['active', 'next_run_at']);
        });
        // Separate historical location authority. No role is granted it implicitly.
        Permission::firstOrCreate(['key' => 'assets.telemetry.history'], [
            'description' => 'View session-bound historical staff location evidence', 'group' => 'assets', 'module' => 'Fleet & Assets',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_report_subscriptions');
        Schema::dropIfExists('operational_report_runs');
        Schema::dropIfExists('operational_report_versions');
        Schema::dropIfExists('operational_reports');
    }
};
