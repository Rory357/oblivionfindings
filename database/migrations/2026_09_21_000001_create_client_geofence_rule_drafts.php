<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_geofence_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('current_revision');
            $table->enum('status', ['draft', 'archived'])->default('draft');
            $table->timestamps();
            $table->index(['client_id', 'site_id', 'status'], 'client_zone_owner_index');
        });
        Schema::create('client_geofence_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->constrained('client_geofence_rules')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('name', 120);
            $table->text('purpose');
            $table->enum('classification', ['agreed', 'attention']);
            $table->enum('geometry_source', ['custom', 'canonical']);
            $table->json('geometry_proposal');
            $table->foreignId('canonical_geofence_id')->nullable()->constrained('asset_geofences')->restrictOnDelete();
            $table->char('canonical_geometry_hash', 64)->nullable();
            $table->json('schedule_proposal');
            $table->text('response_proposal')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('device_assignments')->restrictOnDelete();
            $table->foreignId('consent_id')->constrained('client_consents')->restrictOnDelete();
            $table->char('access_fingerprint', 64);
            $table->char('operation_key', 64)->unique();
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['rule_id', 'revision'], 'client_zone_revision_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('client_geofence_rule_versions')->exists() || DB::table('client_geofence_rules')->exists()) {
            throw new RuntimeException('Client zone evidence exists. Disable the surface and retain its records.');
        }
        Schema::dropIfExists('client_geofence_rule_versions');
        Schema::dropIfExists('client_geofence_rules');
    }
};
