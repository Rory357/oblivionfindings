<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hs_corrective_actions', function (Blueprint $table): void {
            $table->unsignedInteger('owner_revision')->default(0);
        });
        Schema::create('hs_corrective_action_owner_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained('hs_corrective_actions', indexName: 'hs_action_owner_action_fk')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('previous_owner_user_id')->nullable()->constrained('users', indexName: 'hs_action_owner_previous_fk')->nullOnDelete();
            $table->string('previous_owner_name')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users', indexName: 'hs_action_owner_current_fk')->nullOnDelete();
            $table->string('owner_name');
            $table->foreignId('actor_user_id')->nullable()->constrained('users', indexName: 'hs_action_owner_actor_fk')->nullOnDelete();
            $table->string('actor_name');
            $table->text('reason');
            $table->uuid('request_uuid')->unique();
            $table->char('request_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['action_id', 'revision'], 'hs_action_owner_revision_unique');
        });
        // Keep existing owners and dates unchanged; do not invent historic assignments.
    }

    public function down(): void
    {
        if (DB::table('hs_corrective_action_owner_changes')->exists()) {
            throw new RuntimeException('Corrective action ownership history exists. Retain this additive schema when rolling application code back.');
        }
        Schema::dropIfExists('hs_corrective_action_owner_changes');
        Schema::table('hs_corrective_actions', fn (Blueprint $table) => $table->dropColumn('owner_revision'));
    }
};
