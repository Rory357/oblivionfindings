<?php

namespace App\Console\Commands;

use App\Models\ClientNote;
use App\Models\ProgressNote;
use App\Models\TimelineEvent;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copy the read-only ProgressNote archive into the canonical ClientNote
 * table. The explicit legacy_progress_note_id column makes the operation
 * idempotent without relying on mutable JSON metadata.
 */
class MigrateProgressNotesToClientNotes extends Command
{
    protected $signature = 'oblivion:migrate-progress-notes-to-client-notes
        {--dry-run : Show what would be migrated without writing}
        {--chunk=200 : Number of rows per chunk}';

    protected $description = 'Copy legacy progress_notes rows into client_notes (idempotent, Phase 2 deprecation step).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $migrated = 0;
        $skipped = 0;

        ProgressNote::withTrashed()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($notes) use (&$migrated, &$skipped, $dryRun) {
                foreach ($notes as $note) {
                    $existing = $this->findExistingCanonical($note);

                    if ($existing) {
                        $skipped++;

                        if (! $dryRun) {
                            if ($existing->legacy_progress_note_id === null) {
                                ClientNote::withoutEvents(function () use ($existing, $note): void {
                                    $existing->timestamps = false;
                                    $existing->forceFill([
                                        'legacy_progress_note_id' => $note->id,
                                    ])->save();
                                });
                            }
                            $this->upgradeJsonMarkerMigration($note, $existing);
                            $this->synchronizeTimeline($note, $existing);
                        }

                        continue;
                    }

                    $payload = $this->mapProgressNoteToClientNote($note);

                    if ($dryRun) {
                        $this->line(sprintf(
                            ' would migrate progress_note #%d (client %s) → client_notes',
                            $note->id,
                            $payload['client_id'],
                        ));
                        $migrated++;

                        continue;
                    }

                    DB::transaction(function () use ($payload, $note): void {
                        $canonical = ClientNote::withoutEvents(function () use ($payload) {
                            $canonical = new ClientNote;
                            $canonical->timestamps = false;
                            $canonical->forceFill($payload);
                            $canonical->save();

                            return $canonical;
                        });

                        $this->synchronizeTimeline($note, $canonical);
                    });
                    $migrated++;
                }
            });

        $this->info(sprintf(
            '%s. Migrated %d, skipped (already linked) %d.',
            $dryRun ? 'Dry run complete' : 'Migration complete',
            $migrated,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProgressNoteToClientNote(ProgressNote $note): array
    {
        $emotionText = collect($note->emotions ?? [])->join(', ');
        $visibility = $note->visibility === 'include_family' ? 'portal' : 'internal';

        return [
            'legacy_progress_note_id' => $note->id,
            'client_id' => $note->client_id,
            'shift_id' => $note->shift_id,
            'care_plan_goal_id' => $note->care_plan_goal_id,
            'user_id' => $note->author_id,
            'type' => 'progress_note',
            'category' => $note->note_type,
            'subject' => ucfirst(str_replace('_', ' ', (string) $note->note_type))
                .($emotionText !== '' ? ' ('.$emotionText.')' : ''),
            'body' => $note->content,
            'occurred_at' => $note->created_at,
            'visibility' => $visibility,
            'is_private' => $note->visibility === 'private',
            'is_flagged' => (bool) $note->is_flagged,
            'flagged_reason' => $note->flagged_reason,
            'ai_summary' => $note->ai_summary,
            'mood_rating' => $note->mood_rating,
            'behaviour_tags' => $note->emotions,
            'appears_on_timeline' => true,
            'is_draft' => false,
            'created_at' => $note->created_at,
            'updated_at' => $note->updated_at,
            'deleted_at' => $note->deleted_at,
        ];
    }

    private function synchronizeTimeline(ProgressNote $legacy, ClientNote $canonical): void
    {
        TimelineEvent::query()
            ->where('source_type', ProgressNote::class)
            ->where('source_id', $legacy->id)
            ->update([
                'source_type' => ClientNote::class,
                'source_id' => $canonical->id,
            ]);

        if ($canonical->trashed()) {
            app(TimelineEmitter::class)->retract($canonical);

            return;
        }

        $projectedEventIds = TimelineEvent::query()
            ->where('source_type', ClientNote::class)
            ->where('source_id', $canonical->id)
            ->where('meta->'.TimelineEmitter::PROJECTED_META_KEY, true)
            ->orderBy('id')
            ->pluck('id');
        if ($projectedEventIds->count() > 1) {
            TimelineEvent::query()
                ->whereIn('id', $projectedEventIds->slice(1)->all())
                ->delete();
        }

        app(TimelineEmitter::class)->project($canonical);
    }

    private function findExistingCanonical(ProgressNote $legacy): ?ClientNote
    {
        $canonical = ClientNote::withTrashed()
            ->where('legacy_progress_note_id', $legacy->id)
            ->first();

        if ($canonical) {
            return $canonical;
        }

        return ClientNote::withTrashed()
            ->whereNotNull('attachments')
            ->whereRaw(
                "CAST(JSON_UNQUOTE(JSON_EXTRACT(attachments, '$.legacy_progress_note_id')) AS UNSIGNED) = ?",
                [$legacy->id],
            )
            ->first();
    }

    private function upgradeJsonMarkerMigration(ProgressNote $legacy, ClientNote $canonical): void
    {
        if (($canonical->attachments['migration_source'] ?? null) !== 'progress_notes') {
            return;
        }

        $payload = $this->mapProgressNoteToClientNote($legacy);
        $payload['attachments'] = $canonical->attachments;

        ClientNote::withoutEvents(function () use ($canonical, $payload): void {
            $canonical->timestamps = false;
            $canonical->forceFill($payload);
            $canonical->save();
        });
    }
}
