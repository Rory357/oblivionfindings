<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Jobs\SendComplianceReminder;
use App\Domain\Governance\Models\ComplianceEvidence;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\ComplianceReminder;
use App\Domain\Governance\Models\GovernanceSetting;
use App\Models\User;
use Carbon\Carbon;

class ComplianceEngineService
{
    /**
     * Create a new compliance obligation
     */
    public function createObligation(
        string $framework,
        string $title,
        string $description,
        string $frequency,
        User $owner,
        ?Carbon $dueDate = null,
        ?string $obligationCode = null,
        ?array $reminderDays = null,
        string $priority = 'medium',
        ?string $requirements = null,
        bool $evidenceRequired = true
    ): ComplianceObligation {
        $dueDate = $dueDate ?? $this->calculateNextDueDate($frequency);

        return ComplianceObligation::create([
            'framework' => $framework,
            'obligation_code' => $obligationCode,
            'obligation_title' => $title,
            'description' => $description,
            'requirements' => $requirements,
            'priority' => $priority,
            'frequency' => $frequency,
            'due_date' => $dueDate,
            'next_due_date' => $dueDate,
            'reminder_days' => $reminderDays ?? [30, 14, 7],
            'owner_id' => $owner->id,
            'status' => 'not_due',
            'evidence_required' => $evidenceRequired,
            'version_number' => 1,
        ]);
    }

    /**
     * Calculate next due date based on frequency.
     * Advances strictly past $from with end-of-month and leap-year boundaries:
     * - Monthly: advances 1 month. If starting on month-end (e.g. Jan 31), lands on next month-end (e.g. Feb 28/29).
     * - Quarterly: advances 3 months. If starting on quarter-end (e.g. Mar 31), lands on next quarter-end.
     * - Annual: advances 1 year. If starting on year-end (e.g. Dec 31), lands on next Dec 31. If starting on Feb 29 (leap day), lands on Feb 28.
     */
    public function calculateNextDueDate(string $frequency, ?Carbon $from = null): Carbon
    {
        $from = $from ? $from->copy()->startOfDay() : now()->startOfDay();

        $next = match ($frequency) {
            'monthly' => $this->advanceMonthly($from),
            'quarterly' => $this->advanceQuarterly($from),
            'annual' => $this->advanceAnnual($from),
            default => $from->copy()->addMonthsNoOverflow(1),
        };

        // Guarantee strictly greater than $from
        if ($next->lte($from)) {
            $next = $from->copy()->addDay();
        }

        return $next->startOfDay();
    }

    protected function advanceMonthly(Carbon $from): Carbon
    {
        $isEndOfMonth = $from->isLastOfMonth();
        $next = $from->copy()->addMonthsNoOverflow(1);

        if ($isEndOfMonth) {
            return $next->endOfMonth();
        }

        return $next;
    }

    protected function advanceQuarterly(Carbon $from): Carbon
    {
        $isEndOfMonth = $from->isLastOfMonth();
        $next = $from->copy()->addMonthsNoOverflow(3);

        if ($isEndOfMonth) {
            return $next->endOfMonth();
        }

        return $next;
    }

    protected function advanceAnnual(Carbon $from): Carbon
    {
        $isEndOfMonth = $from->isLastOfMonth();
        $next = $from->copy()->addYearsNoOverflow(1);

        if ($isEndOfMonth) {
            return $next->endOfMonth();
        }

        return $next;
    }

    /**
     * Complete an obligation under database transaction with optimistic concurrency
     * and strict evidence validation.
     */
    public function completeObligation(
        ComplianceObligation $obligation,
        User $completedBy,
        ?array $evidenceIds = null,
        ?string $notes = null,
        ?int $expectedVersion = null
    ): void {
        \Illuminate\Support\Facades\DB::transaction(function () use (
            $obligation,
            $completedBy,
            $evidenceIds,
            $notes,
            $expectedVersion
        ) {
            /** @var ComplianceObligation $locked */
            $locked = ComplianceObligation::whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            // Idempotent completion replay: already complete is a no-op
            if ($locked->status === 'complete') {
                return;
            }

            // Optimistic concurrency check
            if ($expectedVersion !== null && (int) $locked->version_number !== (int) $expectedVersion) {
                abort(409, 'The compliance obligation has been updated by another user. Please refresh and try again.');
            }

            // Validate provided evidence IDs
            if ($evidenceIds !== null && count($evidenceIds) > 0) {
                $evidences = ComplianceEvidence::whereIn('id', $evidenceIds)->get();

                if ($evidences->count() !== count($evidenceIds)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'evidence_ids' => 'One or more selected evidence records do not exist.',
                    ]);
                }

                foreach ($evidences as $ev) {
                    // Check for foreign evidence (borrowed / reparenting attempt forbidden)
                    if ((int) $ev->compliance_obligation_id !== (int) $locked->id) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'evidence_ids' => "Evidence '{$ev->title}' belongs to another obligation and cannot be reassigned.",
                        ]);
                    }

                    // Check for expired evidence
                    if ($ev->valid_until && $ev->valid_until->lt(today())) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'evidence_ids' => "Evidence '{$ev->title}' expired on {$ev->valid_until->toDateString()} and cannot satisfy compliance.",
                        ]);
                    }

                    // Check that document evidence has real file bytes on disk
                    if ($ev->evidence_type === 'document') {
                        $filePath = $ev->file_path ?? '';
                        $exists = ! empty($filePath) && (
                            \Illuminate\Support\Facades\Storage::disk('local')->exists($filePath)
                            || \Illuminate\Support\Facades\Storage::disk('public')->exists($filePath)
                            || \Illuminate\Support\Facades\Storage::exists($filePath)
                            || file_exists(storage_path('app/' . $filePath))
                            || file_exists(storage_path('app/public/' . $filePath))
                        );

                        if (! $exists) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'evidence_ids' => "Evidence file '{$ev->title}' does not exist on disk and cannot satisfy compliance.",
                            ]);
                        }
                    }
                }
            }

            // Check if valid, unexpired evidence with existing file bytes is linked
            $evidenceQuery = $locked->evidence();
            if ($evidenceIds !== null) {
                $evidenceQuery->whereIn('id', $evidenceIds);
            }

            $hasValidEvidence = false;
            foreach ($evidenceQuery->get() as $ev) {
                if ($ev->valid_until && $ev->valid_until->lt(today())) {
                    continue;
                }
                if ($ev->evidence_type === 'document') {
                    $filePath = $ev->file_path ?? '';
                    $exists = ! empty($filePath) && (
                        \Illuminate\Support\Facades\Storage::disk('local')->exists($filePath)
                        || \Illuminate\Support\Facades\Storage::disk('public')->exists($filePath)
                        || \Illuminate\Support\Facades\Storage::exists($filePath)
                        || file_exists(storage_path('app/' . $filePath))
                        || file_exists(storage_path('app/public/' . $filePath))
                    );
                    if (! $exists) {
                        continue;
                    }
                }
                $hasValidEvidence = true;
                break;
            }

            if ($locked->evidence_required && ! $hasValidEvidence) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'evidence' => 'Valid, unexpired evidence with verified files is required to complete this compliance obligation.',
                ]);
            }

            // Derive evidence_provided directly from valid linked evidence
            $locked->evidence_provided = $hasValidEvidence;

            $locked->markComplete($completedBy->id, $notes, $expectedVersion);

            // Schedule next occurrence if recurring
            if ($locked->frequency !== 'ad_hoc' && $locked->frequency !== 'event_driven') {
                $this->scheduleNextOccurrence($locked);
            }
        });
    }

    /**
     * Schedule next occurrence of a recurring obligation.
     * Idempotently creates exactly one next occurrence with lineage and reminder set.
     */
    protected function scheduleNextOccurrence(ComplianceObligation $completed): ?ComplianceObligation
    {
        $nextDueDate = $this->calculateNextDueDate($completed->frequency, $completed->due_date);

        if ($nextDueDate->lte($completed->due_date)) {
            $nextDueDate = $completed->due_date->copy()->addDay();
        }

        $code = $completed->obligation_code ?: "OBL-{$completed->id}";
        $cycleKey = "CYCLE-{$completed->framework}-{$code}-{$nextDueDate->toDateString()}";

        // Idempotency: series/cycle key unique
        $existing = ComplianceObligation::where('recurrence_cycle_key', $cycleKey)->first();

        if (! $existing) {
            $existing = ComplianceObligation::where('framework', $completed->framework)
                ->where('obligation_code', $completed->obligation_code)
                ->whereDate('due_date', $nextDueDate)
                ->first();
        }

        if ($existing) {
            return $existing;
        }

        $nextObligation = ComplianceObligation::create([
            'framework' => $completed->framework,
            'obligation_code' => $completed->obligation_code,
            'obligation_title' => $completed->obligation_title,
            'description' => $completed->description,
            'requirements' => $completed->requirements,
            'priority' => $completed->priority ?? 'medium',
            'frequency' => $completed->frequency,
            'due_date' => $nextDueDate,
            'next_due_date' => $nextDueDate,
            'reminder_days' => $completed->reminder_days ?? [30, 14, 7],
            'owner_id' => $completed->owner_id,
            'backup_owner_id' => $completed->backup_owner_id,
            'status' => 'not_due',
            'evidence_required' => $completed->evidence_required,
            'evidence_provided' => false,
            'sign_off_required' => $completed->sign_off_required,
            'sign_off_role' => $completed->sign_off_role,
            'parent_obligation_id' => $completed->id,
            'recurrence_cycle_key' => $cycleKey,
            'version_number' => 1,
        ]);

        $this->scheduleReminders($nextObligation);

        return $nextObligation;
    }

    /**
     * Upload evidence for an obligation
     */
    public function uploadEvidence(
        ComplianceObligation $obligation,
        string $type,
        string $title,
        $file,
        User $uploadedBy,
        ?Carbon $validUntil = null
    ): ComplianceEvidence {
        $path = $file->store('compliance-evidence/'.$obligation->framework);

        $evidence = ComplianceEvidence::create([
            'compliance_obligation_id' => $obligation->id,
            'evidence_type' => $type,
            'title' => $title,
            'file_path' => $path,
            'valid_until' => $validUntil,
            'uploaded_by' => $uploadedBy->id,
            'uploaded_at' => now(),
        ]);

        // Derive evidence_provided from valid unexpired evidence
        $hasValidEvidence = $obligation->evidence()
            ->where(function ($q) {
                $q->whereNull('valid_until')
                  ->orWhereDate('valid_until', '>=', today());
            })
            ->exists();

        $obligation->update(['evidence_provided' => $hasValidEvidence]);

        return $evidence;
    }

    /**
     * Schedule reminders for an obligation
     */
    public function scheduleReminders(ComplianceObligation $obligation): void
    {
        // Clear existing pending reminders
        $obligation->reminders()->where('status', 'pending')->delete();

        foreach ($obligation->reminder_days as $daysBefore) {
            $scheduledAt = $obligation->due_date->copy()->subDays($daysBefore);

            // Don't schedule if already passed
            if ($scheduledAt->isPast()) {
                continue;
            }

            ComplianceReminder::create([
                'compliance_obligation_id' => $obligation->id,
                'days_before_due' => $daysBefore,
                'scheduled_at' => $scheduledAt,
                'notified_users' => [$obligation->owner_id],
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Process due reminders
     */
    public function processDueReminders(): int
    {
        $reminders = ComplianceReminder::due()->pending()->get();
        $count = 0;

        foreach ($reminders as $reminder) {
            $obligation = $reminder->obligation;

            // Reminders resolve to specific users (owner / backup owner /
            // final escalation recipient). Drop leavers — revoked login
            // (approved_at null) or an inactive employee profile — so a
            // departed owner never receives (or crashes) the send.
            $recipientIds = array_values(array_filter(array_map(
                'intval',
                (array) ($reminder->notified_users ?? []),
            )));
            $eligibleIds = $recipientIds === [] ? [] : User::query()
                ->whereIn('id', $recipientIds)
                ->whereNotNull('approved_at')
                ->whereDoesntHave('hrEmployeeProfile', fn ($q) => $q->where('is_active', false))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($eligibleIds === []) {
                // Nobody left to notify — mark handled (no send, no crash) but
                // still fall through to escalation so the backup / final
                // recipient picks the obligation up.
                $reminder->markSent();
            } else {
                if ($eligibleIds !== $recipientIds) {
                    $reminder->update(['notified_users' => $eligibleIds]);
                }

                try {
                    // Send notification to owner
                    SendComplianceReminder::dispatch($reminder);
                    $reminder->markSent();
                    $count++;
                } catch (\Throwable $e) {
                    $reminder->markFailed($e->getMessage());
                }
            }

            // Escalate if overdue
            $maxLevel = GovernanceSetting::getInt('compliance.escalation.max_level', 3);
            if ($obligation->isOverdue() && $reminder->escalation_level < $maxLevel) {
                $this->escalateReminder($reminder);
            }
        }

        return $count;
    }

    /**
     * Escalate a reminder to higher levels. Max level + final notification
     * recipient configurable via GovernanceSetting.
     */
    protected function escalateReminder(ComplianceReminder $reminder): void
    {
        $maxLevel = GovernanceSetting::getInt('compliance.escalation.max_level', 3);
        if ($reminder->escalation_level >= $maxLevel) {
            return; // Maximum escalation level reached
        }

        $obligation = $reminder->obligation;
        $nextLevel = $reminder->escalation_level + 1;

        // Final-level recipient configurable (default falls back to chair / admin).
        $finalRecipient = GovernanceSetting::getInt('compliance.escalation.final_notify_user_id', 0)
            ?: $this->resolveFinalEscalationRecipient();

        // Determine who to notify based on escalation level
        $notifyUsers = match (true) {
            $nextLevel === 1 => [$obligation->owner_id],
            $nextLevel === 2 => [$obligation->backup_owner_id ?? $obligation->owner_id],
            $nextLevel >= $maxLevel => [$finalRecipient],
            default => [$obligation->backup_owner_id ?? $obligation->owner_id],
        };

        ComplianceReminder::create([
            'compliance_obligation_id' => $obligation->id,
            'days_before_due' => 0,
            'scheduled_at' => now(),
            'notified_users' => array_filter($notifyUsers),
            'status' => 'pending',
            'is_escalation' => true,
            'escalation_level' => $nextLevel,
        ]);
    }

    /**
     * Resolve a final-level escalation recipient when no governance setting
     * has been explicitly configured. Prefers an active user with the
     * board_chair role, then admin, then user id 1 as a last resort.
     */
    private function resolveFinalEscalationRecipient(): int
    {
        $chair = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['board_chair', 'admin']))
            ->orderBy('id')
            ->value('id');

        return $chair ?: 1;
    }

    /**
     * Get compliance status summary
     */
    public function getComplianceStatus(): array
    {
        $frameworks = [
            'charities', 'nga_paerewa', 'hdsa_safety', 'privacy_act',
            'hip_code', 'hswa', 'employment', 'funding_moh',
        ];

        $summary = [];
        foreach ($frameworks as $framework) {
            $obligations = ComplianceObligation::byFramework($framework);

            $summary[$framework] = [
                'total' => $obligations->count(),
                'complete' => (clone $obligations)->where('status', 'complete')->count(),
                'overdue' => (clone $obligations)->overdue()->count(),
                'due_soon' => (clone $obligations)->dueSoon(30)->count(),
                'not_due' => (clone $obligations)->where('status', 'not_due')->count(),
            ];
        }

        return [
            'by_framework' => $summary,
            'total_overdue' => ComplianceObligation::overdue()->count(),
            'total_due_soon' => ComplianceObligation::dueSoon(30)->count(),
            'next_30_days' => $this->getUpcomingObligations(30),
        ];
    }

    /**
     * Get upcoming obligations
     */
    public function getUpcomingObligations(int $days = 30): array
    {
        return ComplianceObligation::where('due_date', '<=', now()->addDays($days))
            ->where('status', '!=', 'complete')
            ->orderBy('due_date')
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'framework' => $o->getFrameworkLabel(),
                'title' => $o->obligation_title,
                'due_date' => $o->due_date->toDateString(),
                'days_remaining' => now()->diffInDays($o->due_date, false),
                'status' => $o->status,
                'owner' => $o->owner?->name,
                'evidence_provided' => $o->evidence_provided,
            ])
            ->toArray();
    }

    /**
     * Generate audit evidence pack
     */
    public function generateEvidencePack(
        string $auditType,
        Carbon $startDate,
        Carbon $endDate,
        User $generatedBy
    ): array {
        $obligations = ComplianceObligation::byFramework($auditType)
            ->whereBetween('completed_at', [$startDate, $endDate])
            ->with('evidence')
            ->get();

        $manifest = [];
        foreach ($obligations as $obligation) {
            $manifest[] = [
                'obligation_id' => $obligation->id,
                'title' => $obligation->obligation_title,
                'completed_at' => $obligation->completed_at?->toDateString(),
                'evidence_count' => $obligation->evidence->count(),
                'evidence_files' => $obligation->evidence->pluck('file_path')->toArray(),
            ];
        }

        return [
            'audit_type' => $auditType,
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'obligations_count' => $obligations->count(),
            'manifest' => $manifest,
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $generatedBy->name,
        ];
    }

    /**
     * Seed default NZ compliance obligations
     */
    public function seedDefaultObligations(): void
    {
        $defaults = [
            [
                'framework' => 'charities',
                'code' => 'CHAR-001',
                'title' => 'Annual Return Filing',
                'description' => 'File annual return with Charities Services',
                'frequency' => 'annual',
                'due_month' => 6, // June
            ],
            [
                'framework' => 'charities',
                'code' => 'CHAR-002',
                'title' => 'Serious Incident Reporting',
                'description' => 'Report serious incidents to Charities Services within relevant timeframes',
                'frequency' => 'event_driven',
            ],
            [
                'framework' => 'nga_paerewa',
                'code' => 'NP-SELF',
                'title' => 'Self-Assessment',
                'description' => 'Complete Ngā Paerewa self-assessment',
                'frequency' => 'annual',
            ],
            [
                'framework' => 'privacy_act',
                'code' => 'PRIV-OFF',
                'title' => 'Privacy Officer Appointment',
                'description' => 'Ensure Privacy Officer role is filled and trained',
                'frequency' => 'annual',
            ],
            [
                'framework' => 'hswa',
                'code' => 'HS-OFF',
                'title' => 'Officer Due Diligence Review',
                'description' => 'Board review of health and safety governance',
                'frequency' => 'annual',
            ],
        ];

        $admin = User::first(); // Default to first user

        foreach ($defaults as $obligation) {
            $exists = ComplianceObligation::where('framework', $obligation['framework'])
                ->where('obligation_code', $obligation['code'])
                ->exists();

            if (! $exists && $admin) {
                $dueDate = isset($obligation['due_month'])
                    ? now()->month($obligation['due_month'])->endOfMonth()
                    : now()->addMonth();

                $this->createObligation(
                    $obligation['framework'],
                    $obligation['title'],
                    $obligation['description'],
                    $obligation['frequency'],
                    $admin,
                    $dueDate,
                    $obligation['code']
                );
            }
        }
    }
}
