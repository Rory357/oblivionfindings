<?php

namespace App\Services\HealthSafety;

use App\Models\RestraintEvent;
use App\Models\RestraintFollowup;
use App\Models\User;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Decision 4 (Stephan, 1 Nov 2026, board G4-5): a support worker can own a restraint follow-up such as "Tell Aroha's
 * family/whānau what happened" and record its outcome. restraints.followups.own shows them ONLY the follow-ups they own
 * — the follow-up's own words, when it's due and its outcome — never the restraint record, its plan, its people or its
 * other follow-ups. Every row still needs current Site and person access (RestraintAccessService::assertEvent with
 * 'complete_followup'); a follow-up at a Site they no longer work at drops out of the list and can't be finished.
 */
final class RestraintOwnFollowupService
{
    /** Done follow-ups stay on the list this long, so the person sees what they recorded. */
    private const DONE_DAYS = 30;

    public function __construct(private readonly RestraintAccessService $access, private readonly RestraintService $commands) {}

    public function forViewer(User $viewer): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites): array {
            $this->access->assertOperation($current, 'own_followups');
            $rows = $reads->query(RestraintFollowup::query())
                ->where('assigned_to_user_id', $current->id)
                ->whereNull('cancelled_at')
                ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', now()->utc()->subDays(self::DONE_DAYS)))
                ->orderByRaw('completed_at is null desc')->orderBy('due_at')->orderBy('id')
                ->limit(100)
                ->get(['id', 'restraint_event_id', 'notes', 'due_at', 'completed_at', 'completion_outcome', 'assigned_to_user_id']);
            $items = [];
            foreach ($rows as $child) {
                $event = $reads->query(RestraintEvent::query())->find($child->restraint_event_id);
                if (! $event || ! $this->allowed(fn () => $this->access->assertEvent($event, $current, $reads, $sites, 'complete_followup'))) {
                    continue;
                }
                $outcome = is_array($child->completion_outcome) ? $child->completion_outcome : null;
                $items[] = [
                    'id' => (int) $child->id,
                    'title' => (string) $child->notes,
                    'due_at' => $child->due_at?->toIso8601String(),
                    'completed_at' => $child->completed_at?->toIso8601String(),
                    'outcome' => is_string($outcome['outcome'] ?? null) ? $outcome['outcome'] : null,
                    'outcome_notes' => is_string($outcome['notes'] ?? null) ? $outcome['notes'] : null,
                    // The restraint's optimistic-lock version: a number the save needs, no restraint fact.
                    'expected_version' => (int) $event->lock_version,
                    'can_complete' => $child->completed_at === null,
                ];
            }

            return ['followups' => $items];
        });
    }

    /** Records the outcome on the viewer's own follow-up through the one restraint command (owner check included). */
    public function complete(User $viewer, int $followupId, array $input): array
    {
        $child = RestraintFollowup::query()->find($followupId, ['id', 'restraint_event_id', 'assigned_to_user_id']);
        // Someone else's follow-up, or one that doesn't exist, reads the same: not found.
        abort_unless($child && (int) $child->assigned_to_user_id === (int) $viewer->id, 404);
        $this->access->withCurrentViewer($viewer, fn ($current) => $this->access->assertOperation($current, 'own_followups'));
        $event = RestraintEvent::query()->find($child->restraint_event_id);
        abort_unless($event, 404);

        return $this->commands->completeFollowup($event, $viewer, [
            ...Arr::only($input, ['completed_at', 'outcome', 'notes', 'idempotency_key', 'expected_version']),
            'followup_id' => (int) $child->id,
        ]);
    }

    private function allowed(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return false;
            }
            throw $error;
        }
    }
}
