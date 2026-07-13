<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLLBACK_META_KEY = '_client_note_consolidation';

    public function up(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_progress_note_id')
                ->nullable()
                ->after('id');
            $table->foreignId('care_plan_goal_id')
                ->nullable()
                ->after('shift_id')
                ->constrained('care_plan_goals')
                ->nullOnDelete();
            $table->text('ai_summary')->nullable()->after('flagged_reason');
            $table->softDeletes();
        });

        // Adopt both the earlier JSON marker and metadata preserved by a safe
        // down/up cycle. Goal links are restored only while the target still
        // exists, leaving the preserved attachment metadata as evidence when
        // a goal was removed during the rollback window.
        DB::table('client_notes')
            ->whereNotNull('attachments')
            ->orderBy('id')
            ->chunkById(200, function ($notes): void {
                foreach ($notes as $note) {
                    $attachments = $this->decodeAttachments($note->attachments);
                    $rollback = (array) ($attachments[self::ROLLBACK_META_KEY] ?? []);
                    $legacyId = $attachments['legacy_progress_note_id']
                        ?? $rollback['legacy_progress_note_id']
                        ?? null;
                    $goalId = $rollback['care_plan_goal_id'] ?? null;
                    $updates = [];

                    if ($legacyId !== null) {
                        $updates['legacy_progress_note_id'] = (int) $legacyId;
                    }
                    if (
                        $goalId !== null
                        && DB::table('care_plan_goals')->where('id', (int) $goalId)->exists()
                    ) {
                        $updates['care_plan_goal_id'] = (int) $goalId;
                    }
                    if (array_key_exists('ai_summary', $rollback)) {
                        $updates['ai_summary'] = $rollback['ai_summary'];
                    }

                    if ($updates !== []) {
                        DB::table('client_notes')->where('id', $note->id)->update($updates);
                    }
                }
            });

        $duplicateLegacyId = DB::table('client_notes')
            ->whereNotNull('legacy_progress_note_id')
            ->select('legacy_progress_note_id')
            ->groupBy('legacy_progress_note_id')
            ->havingRaw('COUNT(*) > 1')
            ->value('legacy_progress_note_id');
        if ($duplicateLegacyId !== null) {
            throw new RuntimeException(
                "Cannot enforce client-note consolidation uniqueness: legacy progress note #{$duplicateLegacyId} is linked more than once.",
            );
        }

        Schema::table('client_notes', function (Blueprint $table) {
            $table->unique('legacy_progress_note_id');
        });
    }

    public function down(): void
    {
        if (DB::table('client_notes')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException(
                'Cannot roll back client-note consolidation while soft-deleted client notes exist; doing so would resurrect them. Restore or permanently archive those tombstones first.',
            );
        }

        // Preserve every new non-tombstone field in the pre-existing JSON
        // column before dropping schema. The next up() restores these values.
        DB::table('client_notes')
            ->where(function ($query): void {
                $query->whereNotNull('legacy_progress_note_id')
                    ->orWhereNotNull('care_plan_goal_id')
                    ->orWhereNotNull('ai_summary');
            })
            ->orderBy('id')
            ->chunkById(200, function ($notes): void {
                foreach ($notes as $note) {
                    $attachments = $this->decodeAttachments($note->attachments ?? null);
                    $rollback = (array) ($attachments[self::ROLLBACK_META_KEY] ?? []);

                    if ($note->legacy_progress_note_id !== null) {
                        // Keep the historical root marker compatible with the
                        // earlier migration command as well as our metadata.
                        $attachments['legacy_progress_note_id'] = (int) $note->legacy_progress_note_id;
                        $rollback['legacy_progress_note_id'] = (int) $note->legacy_progress_note_id;
                    }
                    if ($note->care_plan_goal_id !== null) {
                        $rollback['care_plan_goal_id'] = (int) $note->care_plan_goal_id;
                    }
                    if ($note->ai_summary !== null) {
                        $rollback['ai_summary'] = $note->ai_summary;
                    }

                    $attachments[self::ROLLBACK_META_KEY] = $rollback;

                    DB::table('client_notes')->where('id', $note->id)->update([
                        'attachments' => json_encode($attachments, JSON_THROW_ON_ERROR),
                    ]);
                }
            });

        Schema::table('client_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('care_plan_goal_id');
            $table->dropUnique(['legacy_progress_note_id']);
            $table->dropColumn([
                'legacy_progress_note_id',
                'ai_summary',
                'deleted_at',
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function decodeAttachments(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
