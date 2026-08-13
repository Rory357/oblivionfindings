<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_retention_policies', function (Blueprint $table): void {
            $table->string('execution_state', 32)->default('draft')->after('active');
            $table->char('preview_fingerprint', 64)->nullable()->after('execution_state');
            $table->json('preview_snapshot')->nullable()->after('preview_fingerprint');
            $table->timestamp('previewed_at')->nullable()->after('preview_snapshot');
            $table->foreignId('previewed_by_user_id')->nullable()->after('previewed_at')
                ->constrained('users')->nullOnDelete();
            $table->char('approved_fingerprint', 64)->nullable()->after('previewed_by_user_id');
            $table->timestamp('approved_at')->nullable()->after('approved_fingerprint');
            $table->foreignId('approved_by_user_id')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['active', 'execution_state'], 'retention_policy_execution_state_idx');
        });

        // Legal holds are an unconditional execution boundary. Existing rows
        // are made safe before any policy can enter the new approval lifecycle.
        DB::table('data_retention_policies')->update([
            'legal_hold_exemption' => true,
            'execution_state' => 'draft',
        ]);

        Schema::create('data_retention_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('data_retention_policy_id')
                ->constrained('data_retention_policies')->restrictOnDelete();
            $table->string('source', 16);
            $table->char('idempotency_key', 64)->unique();
            $table->char('contract_fingerprint', 64)->nullable();
            $table->string('status', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('previewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('preview_snapshot')->nullable();
            $table->json('result')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['data_retention_policy_id', 'started_at'],
                'retention_execution_policy_started_idx',
            );
        });

        Schema::create('data_retention_execution_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('data_retention_execution_id');
            $table->unsignedBigInteger('data_retention_policy_id');
            $table->string('owner_key');
            $table->unsignedBigInteger('record_id');
            $table->string('action', 40);
            $table->string('outcome', 32);
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->foreign('data_retention_execution_id', 'retention_item_execution_fk')
                ->references('id')->on('data_retention_executions')->restrictOnDelete();
            $table->foreign('data_retention_policy_id', 'retention_item_policy_fk')
                ->references('id')->on('data_retention_policies')->restrictOnDelete();

            $table->unique(
                ['data_retention_policy_id', 'owner_key', 'record_id', 'action'],
                'retention_execution_item_idempotency_uq',
            );
            $table->index(
                ['data_retention_execution_id', 'outcome'],
                'retention_execution_item_outcome_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_retention_execution_items');
        Schema::dropIfExists('data_retention_executions');

        Schema::table('data_retention_policies', function (Blueprint $table): void {
            $table->dropIndex('retention_policy_execution_state_idx');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('previewed_by_user_id');
            $table->dropColumn([
                'execution_state',
                'preview_fingerprint',
                'preview_snapshot',
                'previewed_at',
                'approved_fingerprint',
                'approved_at',
            ]);
        });
    }
};
