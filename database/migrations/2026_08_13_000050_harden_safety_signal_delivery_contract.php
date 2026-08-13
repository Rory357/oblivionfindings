<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->consolidateDuplicateIntents('fleet_signal_outbox', 'fleet_signal_id');
        $this->consolidateDuplicateIntents('shift_signal_outbox', 'shift_signal_id');

        Schema::table('fleet_signal_outbox', function (Blueprint $table): void {
            $table->unique('fleet_signal_id', 'fleet_signal_outbox_signal_uq');
        });

        Schema::table('shift_signal_outbox', function (Blueprint $table): void {
            $table->unique('shift_signal_id', 'shift_signal_outbox_signal_uq');
        });

        Schema::create('device_event_signal_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_event_id')
                ->unique('device_event_signal_outbox_event_uq')
                ->constrained('device_events')
                ->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_attempt_at'], 'device_event_signal_outbox_recovery_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_event_signal_outbox');

        Schema::table('shift_signal_outbox', function (Blueprint $table): void {
            $table->dropUnique('shift_signal_outbox_signal_uq');
        });

        Schema::table('fleet_signal_outbox', function (Blueprint $table): void {
            $table->dropUnique('fleet_signal_outbox_signal_uq');
        });
    }

    /**
     * Earlier queue races could leave more than one intent for one source row.
     * Preserve the strongest terminal/recoverable state before enforcing the
     * one-source/one-intent invariant so a production migration cannot fail on
     * historical duplicates or silently re-deliver an already-sent signal.
     */
    private function consolidateDuplicateIntents(string $table, string $sourceColumn): void
    {
        $duplicateSourceIds = DB::table($table)
            ->select($sourceColumn)
            ->groupBy($sourceColumn)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($sourceColumn);

        foreach ($duplicateSourceIds as $sourceId) {
            $rows = DB::table($table)
                ->where($sourceColumn, $sourceId)
                ->orderByDesc('id')
                ->get();

            $priority = [
                'sent' => 0,
                'pending' => 1,
                'processing' => 2,
                'failed' => 3,
                'dead_letter' => 4,
                'unroutable' => 5,
            ];
            $survivor = $rows->sort(function (object $left, object $right) use ($priority): int {
                $statusOrder = ($priority[$left->status] ?? 99) <=> ($priority[$right->status] ?? 99);

                return $statusOrder !== 0 ? $statusOrder : $right->id <=> $left->id;
            })->first();

            $lastFailure = $rows->first(fn (object $row): bool => filled($row->last_error));
            DB::table($table)->where('id', $survivor->id)->update([
                'attempts' => (int) $rows->max('attempts'),
                'last_attempt_at' => $rows->max('last_attempt_at'),
                'last_error' => $survivor->status === 'sent' ? null : $lastFailure?->last_error,
                'updated_at' => $rows->max('updated_at'),
            ]);

            DB::table($table)
                ->where($sourceColumn, $sourceId)
                ->where('id', '<>', $survivor->id)
                ->delete();
        }
    }
};
