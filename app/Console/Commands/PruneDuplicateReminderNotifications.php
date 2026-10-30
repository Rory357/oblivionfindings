<?php

namespace App\Console\Commands;

use App\Notifications\AppEventNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off clean-up (30 Oct 2026): repeating reminders (hourly "incident
 * awaiting review", daily "follow-up overdue", "claim pending approval") sent
 * a new copy to every recipient on every run, about 550,000 rows in all. The
 * reminders now skip a person who still has an unread copy. This keeps the
 * newest copy per person per item and removes (or marks read) the older ones.
 */
class PruneDuplicateReminderNotifications extends Command
{
    protected $signature = 'notifications:prune-duplicate-reminders
        {--dry-run : Count what would change; change nothing}
        {--mode=delete : delete the older copies, or "read" to mark them read}
        {--manifest= : Write per-person counts and the kept copy ids (JSON lines) to this file}
        {--batch=2000 : Rows per write}';

    protected $description = 'Keep the newest copy of each repeating reminder per person per item; remove or mark read the older copies';

    /** The repeating reminders this clean-up covers. */
    public const EVENT_KEYS = [
        'incidents.high_unreviewed_reminder',
        'followups.overdue_reminder',
        'shift_replacements.claim_pending_approval',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mode = (string) $this->option('mode');
        $batch = max(100, (int) $this->option('batch'));
        if (! in_array($mode, ['delete', 'read'], true)) {
            $this->error('--mode must be "delete" or "read".');

            return self::FAILURE;
        }
        $manifestPath = $this->option('manifest');
        if (! $dryRun && ! is_string($manifestPath)) {
            $this->error('A --manifest path is required when changing data.');

            return self::FAILURE;
        }
        $manifest = is_string($manifestPath) ? fopen($manifestPath, 'xb') : null;
        if ($manifestPath !== null && $manifest === false) {
            $this->error("Could not create {$manifestPath} (it must not exist yet).");

            return self::FAILURE;
        }

        $placeholders = implode(',', array_fill(0, count(self::EVENT_KEYS), '?'));
        $ranked = "SELECT id, notifiable_type, notifiable_id, read_at, event_key, ROW_NUMBER() OVER (
                PARTITION BY notifiable_type, notifiable_id, event_key, entity, entity_id
                ORDER BY created_at DESC, id DESC) AS copy_rank
            FROM (
                SELECT id, notifiable_type, notifiable_id, read_at, created_at,
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.event_key')) AS event_key,
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.entity')) AS entity,
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.entity_id')) AS entity_id
                FROM notifications
                WHERE type = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.event_key')) IN ({$placeholders})
            ) AS reminders";
        $bindings = [AppEventNotification::class, ...self::EVENT_KEYS];

        $summary = DB::select("SELECT notifiable_type, notifiable_id, event_key,
                SUM(copy_rank = 1) AS kept,
                SUM(copy_rank > 1) AS older,
                SUM(copy_rank > 1 AND read_at IS NULL) AS older_unread
            FROM ({$ranked}) AS ranked
            GROUP BY notifiable_type, notifiable_id, event_key
            ORDER BY older DESC", $bindings);

        $older = 0;
        $olderUnread = 0;
        foreach ($summary as $row) {
            $older += (int) $row->older;
            $olderUnread += (int) $row->older_unread;
            if (is_resource($manifest)) {
                fwrite($manifest, json_encode(['summary' => (array) $row], JSON_UNESCAPED_SLASHES)."\n");
            }
        }
        if (is_resource($manifest)) {
            foreach (DB::cursor("SELECT id FROM ({$ranked}) AS ranked WHERE copy_rank = 1", $bindings) as $row) {
                fwrite($manifest, json_encode(['kept_id' => $row->id])."\n");
            }
        }

        $this->line(sprintf('People and reminder types: %d', count($summary)));
        $this->line(sprintf('Older copies: %d (%d of them unread)', $older, $olderUnread));

        if ($dryRun) {
            $this->info(sprintf('Dry run: would %s %d older copies.', $mode === 'delete' ? 'delete' : 'mark read', $mode === 'delete' ? $older : $olderUnread));
            if (is_resource($manifest)) {
                fclose($manifest);
            }

            return self::SUCCESS;
        }

        // Rank once (it reads every reminder row), then write in small batches
        // by id. Reminders sent meanwhile are not in the list and are kept.
        $targets = collect(DB::select(
            "SELECT id FROM ({$ranked}) AS ranked WHERE copy_rank > 1".($mode === 'read' ? ' AND read_at IS NULL' : ''),
            $bindings,
        ))->pluck('id');

        $changed = 0;
        foreach ($targets->chunk($batch) as $ids) {
            $changed += $mode === 'delete'
                ? DB::table('notifications')->whereIn('id', $ids->values()->all())->delete()
                : DB::table('notifications')->whereIn('id', $ids->values()->all())->whereNull('read_at')->update(['read_at' => now()]);
            if ($changed % ($batch * 25) < $batch) {
                $this->line("  {$changed} done");
            }
        }

        if (is_resource($manifest)) {
            fwrite($manifest, json_encode(['result' => ['mode' => $mode, 'changed' => $changed, 'at' => now()->toIso8601String()]])."\n");
            fclose($manifest);
        }

        $this->info(sprintf('%s %d older reminder copies.', $mode === 'delete' ? 'Deleted' : 'Marked read', $changed));

        return self::SUCCESS;
    }
}
