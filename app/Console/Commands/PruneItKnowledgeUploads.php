<?php

namespace App\Console\Commands;

use App\Models\ItAttachment;
use App\Models\ItKbFile;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * W22 retention: an unfinished Knowledge upload the author never resumed or
 * dismissed does not keep its private bytes forever. Saved (ready) files are
 * never touched; every pruned reservation keeps its row as history with an
 * audit record, and quarantined bytes are held longer before disposal.
 */
class PruneItKnowledgeUploads extends Command
{
    protected $signature = 'it:prune-knowledge-uploads
        {--days=14 : Days an unfinished upload may sit before it is abandoned}
        {--quarantine-days=30 : Days quarantined bytes are retained as evidence before disposal}';

    protected $description = 'Abandon stale unfinished Knowledge uploads and dispose of their private bytes';

    public function handle(): int
    {
        if (! Schema::hasTable('it_kb_files')) {
            $this->info('Knowledge media storage is not migrated yet.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $quarantineDays = max($days, (int) $this->option('quarantine-days'));
        $disk = Storage::disk(ItAttachment::DISK);
        $abandoned = 0;
        $disposed = 0;

        // Stale unfinished reservations become abandoned; their bytes go.
        ItKbFile::query()
            ->whereIn('state', ['reserved', 'scan_unavailable', 'integrity_failed'])
            ->where('created_at', '<=', now()->subDays($days))
            ->orderBy('id')
            ->chunkById(100, function ($files) use ($disk, &$abandoned): void {
                foreach ($files as $file) {
                    DB::transaction(function () use ($disk, $file, &$abandoned): void {
                        $locked = ItKbFile::query()->lockForUpdate()->find($file->id);
                        if (! $locked || ! in_array($locked->state, ['reserved', 'scan_unavailable', 'integrity_failed'], true)) {
                            return;
                        }
                        $locked->update(['state' => 'abandoned']);
                        if ($disk->exists($locked->path)) {
                            $disk->delete($locked->path);
                        }
                        AuditLogger::logOrFail('it.knowledge.file.retention_pruned', $locked, [
                            'reason' => 'stale_unfinished_upload', 'previous_state' => $file->state,
                        ], systemActor: true);
                        $abandoned++;
                    });
                }
            });

        // Bytes behind already-abandoned rows, and quarantine evidence past
        // its retention window, are disposed of; the rows remain as history.
        foreach ([
            ['state' => 'abandoned', 'cutoff' => now()->subDays($days), 'reason' => 'abandoned_bytes_disposed'],
            ['state' => 'quarantined', 'cutoff' => now()->subDays($quarantineDays), 'reason' => 'quarantine_retention_elapsed'],
        ] as $pass) {
            ItKbFile::query()->where('state', $pass['state'])
                ->where('created_at', '<=', $pass['cutoff'])
                ->orderBy('id')
                ->chunkById(100, function ($files) use ($disk, $pass, &$disposed): void {
                    foreach ($files as $file) {
                        if (! $disk->exists($file->path)) {
                            continue;
                        }
                        $disk->delete($file->path);
                        AuditLogger::logOrFail('it.knowledge.file.retention_pruned', $file, [
                            'reason' => $pass['reason'], 'previous_state' => $file->state,
                        ], systemActor: true);
                        $disposed++;
                    }
                });
        }

        $this->info(sprintf('Knowledge upload retention: %d stale uploads abandoned, %d stored files disposed.', $abandoned, $disposed));

        return self::SUCCESS;
    }
}
