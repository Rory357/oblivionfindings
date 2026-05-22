<?php

namespace App\Console\Commands;

use App\Models\ClientNote;
use App\Models\ProgressNote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 data migration: copy every legacy `ProgressNote` row into the
 * canonical `ClientNote` table so the new Daily Notes surfaces become the
 * single source of truth. Idempotent: re-running only inserts rows that
 * are not yet linked by `meta.legacy_progress_note_id`.
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

        $alreadySet = ClientNote::query()
            ->whereNotNull('attachments')
            ->whereRaw("JSON_EXTRACT(attachments, '$.legacy_progress_note_id') IS NOT NULL")
            ->get(['attachments'])
            ->map(function ($row) {
                $value = ($row->attachments ?? [])['legacy_progress_note_id']
                    ?? null;

                return $value !== null ? (int) $value : null;
            })
            ->filter()
            ->flip()
            ->all();

        $migrated = 0;
        $skipped = 0;

        ProgressNote::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($notes) use (&$migrated, &$skipped, $dryRun, $alreadySet) {
                foreach ($notes as $note) {
                    if (isset($alreadySet[$note->id])) {
                        $skipped++;

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

                    ClientNote::create($payload);
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
            'client_id' => $note->client_id,
            'shift_id' => $note->shift_id,
            'user_id' => $note->author_id,
            'organization_id' => $note->organization_id,
            'type' => 'daily_note',
            'category' => match ($note->note_type) {
                'shift_change', 'handover' => 'routine',
                'medication' => 'health',
                'incident', 'concern' => 'concern',
                'activity', 'general' => 'activity',
                'communication' => 'communication',
                'goal_progress' => 'goal_progress',
                default => 'other',
            },
            'subject' => ucfirst(str_replace('_', ' ', (string) $note->note_type))
                .($emotionText !== '' ? ' ('.$emotionText.')' : ''),
            'body' => $note->content,
            'occurred_at' => $note->created_at,
            'visibility' => $visibility,
            'is_flagged' => (bool) $note->is_flagged,
            'flagged_reason' => $note->flagged_reason,
            'mood_rating' => $note->mood_rating,
            'behaviour_tags' => $note->emotions,
            'appears_on_timeline' => true,
            'is_draft' => false,
            'attachments' => [
                'legacy_progress_note_id' => $note->id,
                'migration_source' => 'progress_notes',
                'migrated_at' => now()->toISOString(),
            ],
        ];
    }
}
