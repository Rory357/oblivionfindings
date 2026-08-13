<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_ALERT_STATUSES = ['open', 'ack', 'triaging', 'confirmed'];

    public function up(): void
    {
        Schema::table('control_room_alerts', function (Blueprint $table): void {
            $table->unsignedBigInteger('origin_signal_id')
                ->nullable()
                ->after('fleet_signal_id');
        });

        Schema::create('control_room_signal_alert_provenance_reviews', function (Blueprint $table): void {
            $table->id();
            // Intentionally retained as evidence IDs rather than foreign keys:
            // review history must survive later record retention operations.
            $table->unsignedBigInteger('signal_id')->nullable();
            $table->unsignedBigInteger('alert_id')->nullable();
            $table->unsignedBigInteger('selected_alert_id')->nullable();
            $table->string('reason', 80);
            $table->string('status', 24)->default('pending');
            $table->json('evidence')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'reason'], 'cr_signal_provenance_review_status_idx');
            $table->index('signal_id', 'cr_signal_provenance_review_signal_idx');
            $table->index('alert_id', 'cr_signal_provenance_review_alert_idx');
        });

        $this->backfillTypedProvenance();

        Schema::table('control_room_alerts', function (Blueprint $table): void {
            $table->unique('origin_signal_id', 'cr_alerts_origin_signal_uq');
            $table->foreign('origin_signal_id', 'cr_alerts_origin_signal_fk')
                ->references('id')
                ->on('control_room_signals')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('control_room_alerts', function (Blueprint $table): void {
            $table->dropForeign('cr_alerts_origin_signal_fk');
            $table->dropUnique('cr_alerts_origin_signal_uq');
            $table->dropColumn('origin_signal_id');
        });

        Schema::dropIfExists('control_room_signal_alert_provenance_reviews');
    }

    /**
     * Prefer the existing typed signal.alert_id link. Historical context.signal_id
     * is mutable because correlated signals replace the display snapshot, so it is
     * only used when it is not already proven to be a correlation. Ambiguities are
     * retained for operator review; no operational alert is deleted or closed here.
     */
    private function backfillTypedProvenance(): void
    {
        DB::table('control_room_signals')
            ->whereNotNull('alert_id')
            ->orderBy('id')
            ->chunkById(500, function ($signals): void {
                foreach ($signals as $signal) {
                    $signalId = $this->positiveId($signal->id);
                    $alertId = $this->positiveId($signal->alert_id);
                    if ($signalId === null || $alertId === null) {
                        continue;
                    }

                    $alert = DB::table('control_room_alerts')
                        ->where('id', $alertId)
                        ->first(['id', 'origin_signal_id']);
                    if ($alert === null) {
                        $this->recordReview(
                            $signalId,
                            $alertId,
                            null,
                            'typed_link_missing_alert',
                            ['signal_alert_id' => $alertId],
                        );

                        continue;
                    }

                    $claimedSignalId = $this->positiveId($alert->origin_signal_id);
                    if ($claimedSignalId === null) {
                        DB::table('control_room_alerts')
                            ->where('id', $alertId)
                            ->whereNull('origin_signal_id')
                            ->update(['origin_signal_id' => $signalId]);

                        continue;
                    }

                    if ($claimedSignalId !== $signalId) {
                        $this->recordReview(
                            $signalId,
                            $alertId,
                            $alertId,
                            'alert_claims_multiple_origin_signals',
                            [
                                'selected_signal_id' => $claimedSignalId,
                                'conflicting_signal_id' => $signalId,
                            ],
                        );
                    }
                }
            }, 'id');

        $contextClaims = [];
        DB::table('control_room_alerts')
            ->whereNotNull('context')
            ->orderBy('id')
            ->chunkById(500, function ($alerts) use (&$contextClaims): void {
                foreach ($alerts as $alert) {
                    $context = $this->decodeContext($alert->context);
                    if (! array_key_exists('signal_id', $context)) {
                        continue;
                    }

                    $signalId = $this->positiveId($context['signal_id']);
                    if ($signalId === null) {
                        if ($context['signal_id'] !== null && $context['signal_id'] !== '') {
                            $this->recordReview(
                                null,
                                (int) $alert->id,
                                null,
                                'malformed_context_signal_claim',
                                ['context_signal_id' => $context['signal_id']],
                            );
                        }

                        continue;
                    }

                    $contextClaims[$signalId][] = [
                        'alert_id' => (int) $alert->id,
                        'status' => (string) $alert->status,
                        'origin_signal_id' => $this->positiveId($alert->origin_signal_id),
                    ];
                }
            }, 'id');

        foreach ($contextClaims as $signalId => $claims) {
            $this->reconcileContextClaims((int) $signalId, $claims);
        }
    }

    /**
     * @param  list<array{alert_id: int, status: string, origin_signal_id: ?int}>  $claims
     */
    private function reconcileContextClaims(int $signalId, array $claims): void
    {
        $signal = DB::table('control_room_signals')
            ->where('id', $signalId)
            ->first([
                'id',
                'status',
                'alert_id',
                'correlated_alert_id',
                'processed_at',
                'processing_notes',
            ]);
        if ($signal === null) {
            foreach ($claims as $claim) {
                $this->recordReview(
                    $signalId,
                    $claim['alert_id'],
                    null,
                    'context_claim_missing_signal',
                    ['alert_status' => $claim['status']],
                );
            }

            return;
        }

        $directAlertId = $this->positiveId($signal->alert_id);
        $correlatedAlertId = $this->positiveId($signal->correlated_alert_id);
        $existingOrigin = DB::table('control_room_alerts')
            ->where('origin_signal_id', $signalId)
            ->orderBy('id')
            ->first(['id']);
        $selectedAlertId = $directAlertId
            ?? $this->positiveId($existingOrigin?->id);

        $eligible = [];
        foreach ($claims as $claim) {
            $alertId = $claim['alert_id'];

            // This context is the mutable snapshot of a known grouped signal,
            // not evidence that the signal originated the alert.
            if ($correlatedAlertId === $alertId) {
                continue;
            }

            if ($claim['origin_signal_id'] !== null
                && $claim['origin_signal_id'] !== $signalId
            ) {
                $this->recordReview(
                    $signalId,
                    $alertId,
                    $selectedAlertId,
                    'alert_has_different_typed_origin',
                    ['typed_origin_signal_id' => $claim['origin_signal_id']],
                );

                continue;
            }

            if ($directAlertId !== null && $directAlertId !== $alertId) {
                $this->recordReview(
                    $signalId,
                    $alertId,
                    $directAlertId,
                    'context_conflicts_with_typed_link',
                    ['signal_alert_id' => $directAlertId],
                );

                continue;
            }

            $eligible[] = $claim;
        }

        if ($selectedAlertId !== null || $eligible === []) {
            return;
        }

        if ($signal->status === 'suppressed') {
            foreach ($eligible as $claim) {
                $this->recordReview(
                    $signalId,
                    $claim['alert_id'],
                    null,
                    'suppressed_signal_has_alert_claim',
                    ['signal_status' => $signal->status],
                );
            }

            return;
        }

        usort($eligible, function (array $left, array $right): int {
            $leftActive = in_array($left['status'], self::ACTIVE_ALERT_STATUSES, true) ? 0 : 1;
            $rightActive = in_array($right['status'], self::ACTIVE_ALERT_STATUSES, true) ? 0 : 1;

            return ($leftActive <=> $rightActive)
                ?: ($left['alert_id'] <=> $right['alert_id']);
        });

        $selectedAlertId = $eligible[0]['alert_id'];
        DB::table('control_room_alerts')
            ->where('id', $selectedAlertId)
            ->whereNull('origin_signal_id')
            ->update(['origin_signal_id' => $signalId]);

        DB::table('control_room_signals')
            ->where('id', $signalId)
            ->update([
                'status' => 'processed',
                'alert_id' => $selectedAlertId,
                'correlated_alert_id' => null,
                'processed_at' => $signal->processed_at ?? now(),
                'processing_notes' => $signal->status === 'processed'
                    ? $signal->processing_notes
                    : 'Reconciled from durable alert provenance during migration.',
            ]);

        if (count($eligible) === 1) {
            return;
        }

        foreach ($eligible as $claim) {
            $this->recordReview(
                $signalId,
                $claim['alert_id'],
                $selectedAlertId,
                $claim['alert_id'] === $selectedAlertId
                    ? 'ambiguous_context_origin_selected'
                    : 'duplicate_context_origin_claim',
                [
                    'selection_rule' => 'active_alert_then_lowest_alert_id',
                    'candidate_alert_ids' => array_column($eligible, 'alert_id'),
                ],
            );
        }
    }

    private function recordReview(
        ?int $signalId,
        ?int $alertId,
        ?int $selectedAlertId,
        string $reason,
        array $evidence,
    ): void {
        DB::table('control_room_signal_alert_provenance_reviews')->insert([
            'signal_id' => $signalId,
            'alert_id' => $alertId,
            'selected_alert_id' => $selectedAlertId,
            'reason' => $reason,
            'status' => 'pending',
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function decodeContext(mixed $context): array
    {
        if (is_array($context)) {
            return $context;
        }

        if (is_object($context)) {
            return (array) $context;
        }

        if (! is_string($context) || $context === '') {
            return [];
        }

        $decoded = json_decode($context, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value)
            && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        return null;
    }
};
