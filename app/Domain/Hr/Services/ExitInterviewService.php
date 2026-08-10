<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrExitInterview;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Notifications\ExitInterviewScheduledNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ExitInterviewService
{
    /**
     * Create a new exit interview record.
     */
    public function createExitInterview(array $data): HrExitInterview
    {
        $interview = DB::transaction(function () use ($data) {
            $task = $this->resolveOffboardingTask($data);

            $interview = HrExitInterview::create([
                'employee_profile_id' => $data['employee_profile_id'],
                'interviewer_user_id' => $data['interviewer_user_id'],
                'interview_date' => $data['interview_date'],
                'departure_reason' => $data['departure_reason'],
                'would_recommend' => $data['would_recommend'] ?? null,
                'overall_satisfaction' => $data['overall_satisfaction'] ?? null,
                'what_went_well' => $data['what_went_well'] ?? null,
                'what_could_improve' => $data['what_could_improve'] ?? null,
                'management_feedback' => $data['management_feedback'] ?? null,
                'culture_feedback' => $data['culture_feedback'] ?? null,
                'additional_comments' => $data['additional_comments'] ?? null,
                'is_confidential' => $data['is_confidential'] ?? true,
                'created_by' => $data['created_by'],
            ]);

            if ($task) {
                $task->update(['exit_interview_id' => $interview->id]);

                try {
                    app(OnboardingService::class)->completeOffboardingTask($task, (int) $data['created_by'], [
                        'notes' => trim(($task->notes ? $task->notes."\n" : '').'Auto-completed: exit interview recorded.'),
                    ]);
                } catch (\LogicException) {
                    // Keep the explicit relationship even when dependencies or
                    // sign-off rules intentionally require manual completion.
                }
            }

            return $interview->load('offboardingTask');
        });

        $this->notifyScheduledInterviewer($interview);

        return $interview;
    }

    /**
     * Change scheduling metadata only; submitted answers remain immutable.
     */
    public function rescheduleInterview(HrExitInterview $interview, array $data): HrExitInterview
    {
        $materiallyChanged = false;
        $interview = DB::transaction(function () use ($interview, $data, &$materiallyChanged): HrExitInterview {
            $lockedInterview = HrExitInterview::query()
                ->lockForUpdate()
                ->findOrFail($interview->getKey());
            $nextInterviewer = (int) $data['interviewer_user_id'];
            $nextDate = (string) $data['interview_date'];
            $materiallyChanged = $lockedInterview->interviewer_user_id !== $nextInterviewer
                || $lockedInterview->interview_date?->toDateString() !== $nextDate;

            if (! $materiallyChanged) {
                return $lockedInterview;
            }

            $lockedInterview->update([
                'interviewer_user_id' => $nextInterviewer,
                'interview_date' => $nextDate,
            ]);

            return $lockedInterview->fresh(['employeeProfile.user']);
        });
        if ($materiallyChanged) {
            $this->notifyScheduledInterviewer($interview);
        }

        return $interview;
    }

    /**
     * Resolve only durable identity seams. Historical title matching is
     * confined to the migration and never participates in new writes.
     */
    private function resolveOffboardingTask(array $data): ?HrOffboardingTask
    {
        $query = HrOffboardingTask::query()
            ->whereNull('exit_interview_id')
            ->where('status', '!=', 'completed')
            ->whereHas('checklist', fn ($checklists) => $checklists
                ->where('employee_profile_id', $data['employee_profile_id'])
                ->whereIn('status', ['pending', 'in_progress']));

        if (! empty($data['offboarding_task_id'])) {
            $task = (clone $query)
                ->whereKey((int) $data['offboarding_task_id'])
                ->where('notes', 'like', '%workflow_key=exit_interview%')
                ->first();

            if (! $task) {
                throw ValidationException::withMessages([
                    'offboarding_task_id' => 'The selected exit-interview task is not open for this employee.',
                ]);
            }

            return $task;
        }

        $candidates = $query
            ->where('notes', 'like', '%workflow_key=exit_interview%')
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function notifyScheduledInterviewer(HrExitInterview $interview): void
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $date = $interview->interview_date?->copy()->timezone($timezone)->startOfDay();

        if (! $date || ! $date->isAfter(now($timezone)->startOfDay())) {
            return;
        }

        $interviewer = User::find($interview->interviewer_user_id);
        if (! $interviewer) {
            return;
        }

        try {
            $interviewer->notify(new ExitInterviewScheduledNotification(
                $interview->loadMissing('employeeProfile.user'),
            ));
        } catch (\Throwable $exception) {
            Log::warning('Failed to notify scheduled exit-interview owner', [
                'exit_interview_id' => $interview->id,
                'interviewer_user_id' => $interviewer->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Append a correction without rewriting the submitted interview answers.
     */
    public function appendAddendum(HrExitInterview $exitInterview, string $note, User $actor): HrExitInterview
    {
        return DB::transaction(function () use ($exitInterview, $note, $actor) {
            $lockedInterview = HrExitInterview::query()
                ->lockForUpdate()
                ->findOrFail($exitInterview->getKey());
            $submittedComments = trim((string) $lockedInterview->additional_comments);
            $recordedAt = now()
                ->timezone(config('app.worker_timezone', 'Pacific/Auckland'))
                ->format('d M Y H:i');
            $addendum = "[Addendum — {$recordedAt} — {$actor->name}]\n".trim($note);

            $lockedInterview->update([
                'additional_comments' => $submittedComments === ''
                    ? $addendum
                    : $submittedComments."\n\n".$addendum,
            ]);

            return $lockedInterview->refresh();
        });
    }

    /**
     * Get aggregated exit trends from an already-authorized interview query.
     *
     * Returns departure reason counts and average satisfaction scores
     * over a configurable period.
     */
    public function getExitTrends(Builder $query, ?string $fromDate = null, ?string $toDate = null): array
    {
        if ($fromDate) {
            $query->where('interview_date', '>=', $fromDate);
        }
        if ($toDate) {
            $query->where('interview_date', '<=', $toDate);
        }

        // Departure reasons breakdown
        $departureReasons = (clone $query)
            ->select('departure_reason', DB::raw('COUNT(*) as count'))
            ->groupBy('departure_reason')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'reason' => $row->departure_reason,
                'count' => $row->count,
            ])
            ->toArray();

        // Average satisfaction over time (monthly)
        $satisfactionOverTime = (clone $query)
            ->whereNotNull('overall_satisfaction')
            ->select(
                DB::raw("DATE_FORMAT(interview_date, '%Y-%m') as month"),
                DB::raw('AVG(overall_satisfaction) as avg_satisfaction'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            // Privacy floor: with fewer than 3 respondents a monthly average
            // effectively discloses individual ratings (NZ Privacy Act IPPs) —
            // suppress the value but keep the month + count for the chart axis.
            ->map(fn ($row) => [
                'month' => $row->month,
                'avg_satisfaction' => $row->count >= 3 ? round($row->avg_satisfaction, 2) : null,
                'count' => $row->count,
                'suppressed' => $row->count < 3,
            ])
            ->toArray();

        // Would recommend percentage
        $recommendStats = (clone $query)
            ->whereNotNull('would_recommend')
            ->select(
                DB::raw('SUM(CASE WHEN would_recommend = 1 THEN 1 ELSE 0 END) as would_recommend'),
                DB::raw('SUM(CASE WHEN would_recommend = 0 THEN 1 ELSE 0 END) as would_not_recommend'),
                DB::raw('COUNT(*) as total'),
            )
            ->first();

        // Overall averages
        $overallStats = (clone $query)
            ->select(
                DB::raw('AVG(overall_satisfaction) as avg_satisfaction'),
                DB::raw('COUNT(*) as total_interviews'),
            )
            ->first();

        return [
            'departure_reasons' => $departureReasons,
            'satisfaction_over_time' => $satisfactionOverTime,
            'recommend_stats' => [
                'would_recommend' => (int) ($recommendStats->would_recommend ?? 0),
                'would_not_recommend' => (int) ($recommendStats->would_not_recommend ?? 0),
                'total' => (int) ($recommendStats->total ?? 0),
            ],
            'overall' => [
                'avg_satisfaction' => round($overallStats->avg_satisfaction ?? 0, 2),
                'total_interviews' => (int) ($overallStats->total_interviews ?? 0),
            ],
        ];
    }
}
