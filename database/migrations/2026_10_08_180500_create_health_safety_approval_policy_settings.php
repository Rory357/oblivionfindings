<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_safety_setting_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key', 120);
            $table->unsignedBigInteger('revision');
            $table->unsignedSmallInteger('schema_version');
            $table->string('action', 24);
            $table->string('origin', 40);
            $table->json('before_value')->nullable();
            $table->json('after_value');
            $table->char('before_digest', 64)->nullable();
            $table->char('after_digest', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('actor_snapshot')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->text('reason')->nullable();
            $table->foreignId('audit_id')->nullable()->constrained('audit_logs')->restrictOnDelete();
            $table->uuid('command_key')->nullable()->unique();
            $table->char('fingerprint', 64)->nullable();
            $table->json('accepted_result')->nullable();
            $table->unique(['setting_key', 'revision'], 'hs_setting_revision_unique');
        });
    }

    public function down(): void
    {
        // Even revision zero is retained provenance once used by a source command.
        if (Schema::hasTable('health_safety_setting_changes') && DB::table('health_safety_setting_changes')->exists()) {
            throw new RuntimeException('Health and Safety policy history must be retained; rollback is refused.');
        }
        if (DB::table('app_settings')->where('key', 'healthSafety.restraints.plan_approval_policy')->exists()) {
            throw new RuntimeException('A configured approval policy must be retained; rollback is refused.');
        }
        $revision = DB::table('app_settings')->where('key', 'healthSafety.restraints.plan_approval_policy_revision')->first(['value']);
        if ($revision !== null && json_decode((string) $revision->value, true) !== 0) {
            throw new RuntimeException('Approval policy revision evidence must be retained; rollback is refused.');
        }
        Schema::dropIfExists('health_safety_setting_changes');
        DB::table('app_settings')->where('key', 'healthSafety.restraints.plan_approval_policy_revision')->delete();
    }
};
