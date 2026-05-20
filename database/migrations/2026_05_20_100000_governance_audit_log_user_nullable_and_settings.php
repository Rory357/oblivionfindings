<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make governance_audit_log.user_id and governance_change_log.user_id nullable
        // so writes by system jobs / unauthenticated paths still produce an audit entry.
        if (Schema::hasTable('governance_audit_log')) {
            Schema::table('governance_audit_log', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        }

        if (Schema::hasTable('governance_change_log')) {
            Schema::table('governance_change_log', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        }

        // Governance settings — replaces hardcoded escalation levels / user IDs
        // and stores configurable thresholds (spend approval thresholds etc).
        if (! Schema::hasTable('governance_settings')) {
            Schema::create('governance_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('category')->index(); // escalation, spend_approval, etc
                $table->string('description')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_settings');

        if (Schema::hasTable('governance_audit_log')) {
            Schema::table('governance_audit_log', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });
        }

        if (Schema::hasTable('governance_change_log')) {
            Schema::table('governance_change_log', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });
        }
    }
};
