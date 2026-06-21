<?php

namespace App\Console\Commands;

use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\User;
use App\Notifications\PpeComplianceDueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * PPE & Equipment — daily compliance reminder.
 *
 * Two digests (in-app bell, database channel):
 *  - WORKER: their own active allocations still unacknowledged (after a 1-day grace
 *    so the My Day card handles day-zero) or an RPE item missing a fit-test (AS/NZS
 *    1715). Notifies the allocation's own worker — no hazards.* permission required.
 *  - MANAGER: org-level inspections overdue / items expiring (<=60d) / condemned
 *    awaiting disposal. Notifies users holding hazards.manage.
 */
class PpeComplianceReminders extends Command
{
    protected $signature = 'ppe:compliance-reminders {--grace=1 : Days before an unacknowledged allocation nudges the worker}';

    protected $description = 'Remind workers of unacknowledged/fit-test-due PPE and H&S leads of overdue inspections / expiring / condemned stock.';

    public function handle(): int
    {
        $grace = max(0, (int) $this->option('grace'));
        $today = now()->toDateString();
        $in60 = now()->addDays(60)->toDateString();

        // ── Worker digest ──────────────────────────────────────────────
        $allocations = PpeAllocation::query()
            ->whereNull('returned_at')
            ->where(function ($q) use ($grace) {
                $q->where(fn ($a) => $a->where('acknowledged', false)->whereDate('allocated_at', '<=', now()->subDays($grace)->toDateString()))
                    ->orWhere(fn ($a) => $a->where('fit_test_completed', false)
                        ->whereHas('ppeInventory.ppeType', fn ($t) => $t->where('category', 'respiratory')));
            })
            ->with('ppeInventory.ppeType:id,category')
            ->get();

        $perWorker = []; // user_id => ['unacknowledged' => int, 'fit_test_due' => int]
        foreach ($allocations as $a) {
            if (! $a->acknowledged) {
                $perWorker[$a->user_id]['unacknowledged'] = ($perWorker[$a->user_id]['unacknowledged'] ?? 0) + 1;
            }
            if (! $a->fit_test_completed && $a->ppeInventory?->ppeType?->category === 'respiratory') {
                $perWorker[$a->user_id]['fit_test_due'] = ($perWorker[$a->user_id]['fit_test_due'] ?? 0) + 1;
            }
        }

        $workersNotified = 0;
        if ($perWorker !== []) {
            foreach (User::query()->whereIn('id', array_keys($perWorker))->get() as $worker) {
                $worker->notify(new PpeComplianceDueNotification('worker', $perWorker[$worker->id]));
                $workersNotified++;
            }
        }

        // ── Manager digest ─────────────────────────────────────────────
        $inspectionsOverdue = PpeInventory::query()->whereNotIn('status', ['condemned', 'disposed'])
            ->whereNotNull('next_inspection_due')->whereDate('next_inspection_due', '<', $today)->count();
        $expiring = PpeInventory::query()->whereNotIn('status', ['condemned', 'disposed'])
            ->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $in60)->count();
        $condemned = PpeInventory::query()->where('status', 'condemned')->count();

        $managersNotified = 0;
        if ($inspectionsOverdue + $expiring + $condemned > 0) {
            $counts = ['inspections_overdue' => $inspectionsOverdue, 'expiring' => $expiring, 'condemned' => $condemned];
            $managers = User::query()
                ->whereHas('roles', fn ($q) => $q->whereHas('permissions', fn ($p) => $p->where('key', 'hazards.manage')))
                ->get();
            foreach ($managers as $manager) {
                $manager->notify(new PpeComplianceDueNotification('manager', $counts));
                $managersNotified++;
            }
        }

        $this->info("PPE reminders: notified {$workersNotified} worker(s); {$inspectionsOverdue} inspection(s) overdue, {$expiring} expiring, {$condemned} condemned → notified {$managersNotified} H&S lead(s).");

        Log::info('ppe.compliance_reminders', [
            'workers_notified' => $workersNotified,
            'inspections_overdue' => $inspectionsOverdue,
            'expiring' => $expiring,
            'condemned' => $condemned,
            'managers_notified' => $managersNotified,
        ]);

        return self::SUCCESS;
    }
}
