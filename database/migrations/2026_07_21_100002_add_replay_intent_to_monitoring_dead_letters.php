<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_outbox', function (Blueprint $table): void {
            $table->uuid('dispatch_token')->nullable()->after('last_error');
            $table->timestamp('dispatch_lease_until')->nullable()->after('dispatch_token');
            $table->index(
                ['published_at', 'available_at', 'dispatch_lease_until'],
                'monitoring_outbox_recovery_idx',
            );
        });

        Schema::table('monitoring_dead_letters', function (Blueprint $table): void {
            $table->char('evidence_fingerprint', 64)->nullable()->after('envelope_bytes');
            $table->char('dedupe_key', 64)->nullable()->after('evidence_fingerprint');
            $table->timestamp('replay_requested_at')->nullable()->after('replay_count');
            $table->foreignId('replay_requested_by_user_id')
                ->nullable()
                ->after('replay_requested_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('replay_request_reason', 500)->nullable()->after('replay_requested_by_user_id');
            $table->uuid('replay_intent_token')->nullable()->after('replay_request_reason');
            $table->timestamp('replay_dispatch_lease_until')->nullable()->after('replay_intent_token');
            $table->index('replay_requested_at', 'monitoring_dead_letters_replay_requested_idx');
            $table->index(
                ['resolved_at', 'replay_requested_at', 'replay_dispatch_lease_until'],
                'monitoring_dead_letters_recovery_idx',
            );
        });

        $usedDedupeKeys = [];

        DB::table('monitoring_dead_letters')
            ->select(['id', 'consumer', 'message_id', 'reason_code', 'site_id', 'envelope_bytes'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$usedDedupeKeys): void {
                foreach ($rows as $row) {
                    $fingerprint = hash('sha256', (string) $row->envelope_bytes);
                    $naturalKey = $this->dedupeKey(
                        (string) $row->consumer,
                        (string) $row->message_id,
                        (string) $row->reason_code,
                        $row->site_id === null ? null : (int) $row->site_id,
                        $fingerprint,
                    );
                    $dedupeKey = $naturalKey;
                    $salt = 0;

                    while (isset($usedDedupeKeys[$dedupeKey])) {
                        $dedupeKey = hash('sha256', "legacy-duplicate\0{$naturalKey}\0{$row->id}\0{$salt}");
                        $salt++;
                    }

                    $usedDedupeKeys[$dedupeKey] = true;

                    DB::table('monitoring_dead_letters')->where('id', $row->id)->update([
                        'evidence_fingerprint' => $fingerprint,
                        'dedupe_key' => $dedupeKey,
                    ]);
                }
            }, 'id');

        Schema::table('monitoring_dead_letters', function (Blueprint $table): void {
            $table->char('evidence_fingerprint', 64)->nullable(false)->change();
            $table->char('dedupe_key', 64)->nullable(false)->change();
            $table->unique('dedupe_key', 'monitoring_dead_letters_dedupe_key_uq');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_dead_letters', function (Blueprint $table): void {
            $table->dropIndex('monitoring_dead_letters_replay_requested_idx');
            $table->dropIndex('monitoring_dead_letters_recovery_idx');
            $table->dropUnique('monitoring_dead_letters_dedupe_key_uq');
            $table->dropConstrainedForeignId('replay_requested_by_user_id');
            $table->dropColumn([
                'evidence_fingerprint',
                'dedupe_key',
                'replay_requested_at',
                'replay_request_reason',
                'replay_intent_token',
                'replay_dispatch_lease_until',
            ]);
        });

        Schema::table('monitoring_outbox', function (Blueprint $table): void {
            $table->dropIndex('monitoring_outbox_recovery_idx');
            $table->dropColumn(['dispatch_token', 'dispatch_lease_until']);
        });
    }

    private function dedupeKey(
        string $consumer,
        string $messageId,
        string $reasonCode,
        ?int $siteId,
        string $evidenceFingerprint,
    ): string {
        return hash('sha256', json_encode([
            $consumer,
            $messageId,
            $reasonCode,
            $siteId === null ? 'site:null' : "site:{$siteId}",
            $evidenceFingerprint,
        ], JSON_THROW_ON_ERROR));
    }
};
