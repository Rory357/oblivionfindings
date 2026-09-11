<?php

namespace App\Domain\It\Services;

use App\Models\ItAutomationRun;
use App\Models\ItInboundEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/** Global mechanical evidence; never an authorization to inspect or delete a file. */
final class ItAttachmentCleanupReadService
{
    public const KEY = 'it.retry-attachment-cleanup';

    public function canViewCounts(User $actor): bool
    {
        return $actor->canDo('it.manage') && $actor->canDo('audit.viewAny');
    }

    public function health(User $actor): array
    {
        $result = ['viewer_user_id' => (int) $actor->id, 'can_view_counts' => $this->canViewCounts($actor),
            'readiness' => 'unavailable', 'counts' => null, 'checked_at' => now()->toIso8601String()];
        try {
            $ready = Schema::hasTable('it_attachment_storage_intents') && Schema::hasColumns('it_attachment_storage_intents', [
                'intent_uuid', 'path', 'actor_user_id', 'parent_type', 'parent_id', 'content_sha256', 'original_name_sha256', 'size',
                'state', 'attachment_id', 'revision', 'cleanup_attempts', 'cleanup_requested_at', 'last_cleanup_attempt_at', 'deleted_at', 'cleanup_error_code',
            ]) && Schema::hasTable('it_automation_runs') && Schema::hasColumns('it_automation_runs', [
                'automation_key', 'schedule_expression', 'status', 'started_at', 'finished_at', 'runtime_ms', 'error_summary', 'result_summary',
            ]);
            $result['readiness'] = $ready ? 'ready' : 'not_ready';
            if ($ready && $result['can_view_counts']) {
                $result['counts'] = $this->currentCounts();
            }
        } catch (Throwable) {
            $result['readiness'] = 'unavailable';
            $result['counts'] = null;
        }

        return $result;
    }

    /** Shared CLI/authorized UI observation. It conveys no authority to inspect or remove a file. */
    public function currentCounts(): array
    {
        $counts = ['cleanup_pending' => 0, 'reserved_unclassified' => 0, 'reconciliation_required' => 0];
        $rows = DB::table('it_attachment_storage_intents')->useWritePdo()->select('state')
            ->selectRaw('COUNT(*) AS total')->whereIn('state', ['cleanup_pending', 'reserved', 'reconciliation_required'])
            ->groupBy('state')->get();
        foreach ($rows as $row) {
            $key = $row->state === 'reserved' ? 'reserved_unclassified' : $row->state;
            $total = filter_var($row->total, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (! array_key_exists($key, $counts) || $total === false) {
                throw new LogicException('Remaining cleanup evidence is invalid.');
            }
            $counts[$key] = $total;
        }
        if (Schema::hasColumn('it_attachments', 'inbound_storage_state')) {
            // A single aggregate observes eligible and orphaned/invalid ownership together.
            // Quarantine is never treated as deletion authority, even with a malformed cleanup flag.
            $inbound = DB::table('it_attachments as files')->useWritePdo()
                ->leftJoin('it_inbound_emails as receipts', 'receipts.id', '=', 'files.attachable_id')
                ->where('files.attachable_type', (new ItInboundEmail)->getMorphClass())
                ->where('files.inbound_storage_state', 'cleanup_pending')
                ->selectRaw("SUM(CASE WHEN receipts.status IN ('processed', 'duplicate') THEN 1 ELSE 0 END) AS cleanup_pending")
                ->selectRaw("SUM(CASE WHEN receipts.status IN ('processed', 'duplicate') THEN 0 ELSE 1 END) AS reconciliation_required")->first();
            foreach (['cleanup_pending', 'reconciliation_required'] as $key) {
                $total = filter_var($inbound->{$key} ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($total === false) {
                    throw new LogicException('Inbound cleanup evidence is invalid.');
                }
                $counts[$key] += $total;
            }
        }

        return $counts;
    }

    /** No arbitrary message, path, identity or extra result property reaches the DTO. */
    public function runSummary(User $actor, ItAutomationRun $run): ?array
    {
        if ($run->automation_key !== self::KEY || ! $this->canViewCounts($actor) || ! is_array($run->result_summary)) {
            return null;
        }
        $source = $run->result_summary;
        $limit = $source['limit'] ?? null;
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            return null;
        }
        $counts = $source['counts'] ?? null;
        if ($counts !== null) {
            if (! is_array($counts)) {
                return null;
            }
            $keys = ['requested', 'deleted', 'failed', 'deferred', 'reconciliation_required'];
            foreach ($keys as $key) {
                if (! is_int($counts[$key] ?? null) || $counts[$key] < 0 || $counts[$key] > $limit) {
                    return null;
                }
            }
            if ($counts['requested'] !== $counts['deleted'] + $counts['failed'] + $counts['deferred'] + $counts['reconciliation_required']) {
                return null;
            }
            $counts = array_intersect_key($counts, array_flip($keys));
        }

        return ['limit' => $limit, 'counts' => $counts];
    }

    public function safeError(ItAutomationRun $run): ?string
    {
        return ItAutomationRunDiagnostics::safeError($run);
    }
}
