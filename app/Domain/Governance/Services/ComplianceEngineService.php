<?php

namespace App\Domain\Governance\Services;

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
        ?array $reminderDays = null
    ): ComplianceObligation {
        $dueDate = $dueDate ?? $this->calculateNextDueDate($frequency);

        return ComplianceObligation::create([
            'framework' => $framework,
            'obligation_code' => $obligationCode,
            'obligation_title' => $title,
            'description' => $description,
            'frequency' => $frequency,
            'due_date' => $dueDate,
            'next_due_date' => $dueDate,
            'reminder_days' => $reminderDays ?? [30, 14, 7],
            'owner_id' => $owner->id,
            'status' => 'not_due',
            'evidence_required' => true,
        ]);
    }

    /**
     * Calculate next due date based on frequency
     */
    public function calculateNextDueDate(string $frequency, ?Carbon $from = null): Carbon
    {
        $from = $from ?? now();

        return match($frequency) {
            'monthly' => $from->copy()->endOfMonth(),
            'quarterly' => $from->copy()->endOfQuarter(),
            'annual' => $from->copy()->endOfYear(),
            default => $from->copy()->addMonth(),
        };
    }

    /**
     * Complete an obligation
     */
    public function completeObligation(
        ComplianceObligation $obligation,
        User $completedBy,
        ?array $evidenceIds = null
    ): void {
        $obligation->markComplete($completedBy->id);

        // Update evidence links if provided
        if ($evidenceIds) {
            ComplianceEvidence::whereIn('id', $evidenceIds)
                ->update(['compliance_obligation_id' => $obligation->id]);
            
            $obligation->update(['evidence_provided' => true]);
        }

        // Schedule next occurrence if recurring
        if ($obligation->frequency !== 'ad_hoc' && $obligation->frequency !== 'event_driven') {
            $this->scheduleNextOccurrence($obligation);
        }
    }

    /**
     * Schedule next occurrence of a recurring obligation
     */
    protected function scheduleNextOccurrence(ComplianceObligation $completed): void
    {
        $nextDueDate = $this->calculateNextDueDate($completed->frequency, $completed->due_date);
        
        // Check if obligation already exists for this date
        $existing = ComplianceObligation::where('framework', $completed->framework)
            ->where('obligation_code', $completed->obligation_code)
            ->whereDate('due_date', $nextDueDate)
            ->first();

        if (!$existing) {
            $this->createObligation(
                $completed->framework,
                $completed->obligation_title,
                $completed->description,
                $completed->frequency,
                $completed->owner,
                $nextDueDate,
                $completed->obligation_code,
                $completed->reminder_days
            );
        }
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
        $path = $file->store('compliance-evidence/' . $obligation->framework);

        $evidence = ComplianceEvidence::create([
            'compliance_obligation_id' => $obligation->id,
            'evidence_type' => $type,
            'title' => $title,
            'file_path' => $path,
            'valid_until' => $validUntil,
            'uploaded_by' => $uploadedBy->id,
            'uploaded_at' => now(),
        ]);

        $obligation->update(['evidence_provided' => true]);

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
            
            // Send notification to owner
            \App\Domain\Governance\Jobs\SendComplianceReminder::dispatch($reminder);
            
            $reminder->markSent();
            $count++;

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
            'hip_code', 'hswa', 'employment', 'funding_moh'
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
            ->map(fn($o) => [
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

            if (!$exists && $admin) {
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
