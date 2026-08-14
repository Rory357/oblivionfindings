<?php

namespace App\Services\Sites;

use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplateItem;
use App\Models\SiteHazard;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteChecklistRunExecutionService
{
    public const AUTHORITY_RUN_ASSIGNEE = 'run_assignee';

    public const AUTHORITY_ASSIGNMENT_ASSIGNEE = 'assignment_assignee';

    public const AUTHORITY_UNASSIGNED_CLAIM = 'unassigned_claim';

    public const AUTHORITY_MANAGER_OVERRIDE = 'manager_override';

    public const MANAGER_OVERRIDE_REASON = 'Authorized using the explicit checklists.schedule manager override.';

    public function assertVisible(SiteChecklistRun $requestedRun, User $actor): void
    {
        $run = SiteChecklistRun::query()
            ->with(['site', 'assignment'])
            ->find($requestedRun->getKey());
        $canonicalActor = User::query()->find($actor->getKey());

        abort_unless(
            $run
                && $canonicalActor
                && $run->hasCanonicalExecutionProvenance()
                && Gate::forUser($canonicalActor)->allows('view', $run),
            404,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     */
    public function saveResponses(
        SiteChecklistRun $requestedRun,
        User $actor,
        array $responses,
    ): SiteChecklistRun {
        return DB::transaction(function () use ($requestedRun, $actor, $responses): SiteChecklistRun {
            [$run, $canonicalActor] = $this->lockedContext($requestedRun, $actor);
            $authority = $this->authorizeExecution($run, $canonicalActor);
            $this->assertMutable($run);

            $this->persistResponses($run, $responses);
            if ($run->status === 'scheduled') {
                $run->status = 'in_progress';
                $run->started_at ??= now();
            }
            if ($run->isDirty()) {
                $run->save();
            }

            $run->calculateCompletion();
            $this->raiseFollowUpsForFailures($run, (int) $canonicalActor->id);
            AuditLogger::logOrFail('checklist.responses.saved', $run, [
                'actor_id' => (int) $canonicalActor->id,
                'site_id' => (int) $run->site_id,
                'template_id' => (int) $run->template_id,
                'execution_authority' => $authority,
                'execution_authority_reason' => $authority === self::AUTHORITY_MANAGER_OVERRIDE
                    ? self::MANAGER_OVERRIDE_REASON
                    : null,
                'template_item_ids' => collect($responses)
                    ->pluck('template_item_id')
                    ->map(fn ($id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all(),
            ]);

            return $run->fresh() ?? $run;
        }, attempts: 3);
    }

    public function reassign(
        SiteChecklistRun $requestedRun,
        User $actor,
        ?int $recipientId,
    ): SiteChecklistRun {
        return DB::transaction(function () use ($requestedRun, $actor, $recipientId): SiteChecklistRun {
            [$run, $canonicalActor] = $this->lockedContext($requestedRun, $actor);
            $this->authorizeManagement($run, $canonicalActor, 'checklists.schedule');
            abort_if($run->status === 'completed', 409, 'A completed run cannot be reassigned.');

            $previousRecipientId = $run->effectiveAssigneeUserId();
            $effectiveRecipientId = $recipientId ?? $run->assignment?->assigned_to_user_id;
            if ($effectiveRecipientId !== null) {
                $recipient = User::query()->find($effectiveRecipientId);
                if (! $recipient
                    || ! $recipient->canDo('checklists.view')
                    || ! $recipient->canDo('checklists.run')
                    || Gate::forUser($recipient)->denies('view', $run->site)) {
                    throw ValidationException::withMessages([
                        'assigned_to_user_id' => 'The selected person cannot run checklists at this site.',
                    ]);
                }
            }

            $currentRecipientId = $run->assigned_to_user_id !== null
                ? (int) $run->assigned_to_user_id
                : null;
            if ($currentRecipientId === $recipientId) {
                return $run;
            }

            $run->assigned_to_user_id = $recipientId;
            $run->save();
            AuditLogger::logOrFail('checklist.reassigned', $run, [
                'actor_id' => (int) $canonicalActor->id,
                'site_id' => (int) $run->site_id,
                'template_id' => (int) $run->template_id,
                'from_user_id' => $previousRecipientId,
                'to_user_id' => $effectiveRecipientId,
                'uses_assignment_fallback' => $recipientId === null,
            ]);

            return $run->fresh() ?? $run;
        }, attempts: 3);
    }

    public function reschedule(
        SiteChecklistRun $requestedRun,
        User $actor,
        string $scheduledDate,
    ): SiteChecklistRun {
        return DB::transaction(function () use ($requestedRun, $actor, $scheduledDate): SiteChecklistRun {
            [$run, $canonicalActor] = $this->lockedContext($requestedRun, $actor);
            $this->authorizeManagement($run, $canonicalActor, 'checklists.schedule');
            abort_if($run->status === 'completed', 409, 'A completed run cannot be rescheduled.');

            $run->scheduled_date = $scheduledDate;
            if ($run->isDirty()) {
                $run->save();
            }

            return $run->fresh() ?? $run;
        }, attempts: 3);
    }

    public function skip(SiteChecklistRun $requestedRun, User $actor): SiteChecklistRun
    {
        return DB::transaction(function () use ($requestedRun, $actor): SiteChecklistRun {
            [$run, $canonicalActor] = $this->lockedContext($requestedRun, $actor);
            $this->authorizeManagement($run, $canonicalActor, 'checklists.run');

            if ($run->status === 'completed') {
                return $run;
            }

            if ($run->status !== 'skipped') {
                $run->status = 'skipped';
                $run->save();
            }

            return $run->fresh() ?? $run;
        }, attempts: 3);
    }

    public function restore(SiteChecklistRun $requestedRun, User $actor): SiteChecklistRun
    {
        return DB::transaction(function () use ($requestedRun, $actor): SiteChecklistRun {
            [$run, $canonicalActor] = $this->lockedContext($requestedRun, $actor);
            $this->authorizeManagement($run, $canonicalActor, 'checklists.schedule');

            if ($run->status === 'skipped') {
                $run->status = 'scheduled';
                $run->save();
            }

            return $run->fresh() ?? $run;
        }, attempts: 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     * @return array{run: SiteChecklistRun, replayed: bool}
     */
    public function complete(
        SiteChecklistRun $requestedRun,
        User $actor,
        array $responses,
        string $signatureName,
        ?string $overallNotes,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        if (trim($signatureName) === '') {
            throw ValidationException::withMessages([
                'signature_name' => 'Enter your name to complete this checklist.',
            ]);
        }

        return DB::transaction(function () use (
            $requestedRun,
            $actor,
            $responses,
            $signatureName,
            $overallNotes,
            $ipAddress,
            $userAgent,
        ): array {
            [$run, $canonicalActor] = $this->lockedContext($requestedRun, $actor);
            $authority = $this->authorizeExecution($run, $canonicalActor);

            if ($run->status === 'completed') {
                return ['run' => $run, 'replayed' => true];
            }
            $this->assertMutable($run);

            $this->persistResponses($run, $responses);
            $this->assertRequiredResponsesComplete($run);
            $run->calculateCompletion();
            $this->raiseFollowUpsForFailures($run, (int) $canonicalActor->id);

            $signedAt = now();
            $run->forceFill([
                'status' => 'completed',
                'completed_at' => $signedAt,
                'completed_by_user_id' => $canonicalActor->id,
                'overall_notes' => $overallNotes,
                'signature_name' => trim($signatureName),
                'signature_signed_at' => $signedAt,
                'signature_ip_address' => $ipAddress ? Str::limit($ipAddress, 45, '') : null,
                'signature_user_agent' => $userAgent ? Str::limit($userAgent, 255, '') : null,
                'completion_authority' => $authority,
                'completion_authority_reason' => $authority === self::AUTHORITY_MANAGER_OVERRIDE
                    ? self::MANAGER_OVERRIDE_REASON
                    : null,
            ]);
            $run->setRelation(
                'responses',
                $run->responses()->orderBy('template_item_id')->get(),
            );
            $run->signature_payload_hash = $run->computedSignaturePayloadHash();
            $run->save();

            return ['run' => $run->fresh() ?? $run, 'replayed' => false];
        }, attempts: 3);
    }

    /**
     * @return array{0: SiteChecklistRun, 1: User}
     */
    private function lockedContext(SiteChecklistRun $requestedRun, User $actor): array
    {
        $run = SiteChecklistRun::query()
            ->whereKey($requestedRun->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $run->load('site');
        $run->setRelation(
            'assignment',
            SiteChecklistAssignment::query()
                ->whereKey($run->assignment_id)
                ->lockForUpdate()
                ->first(),
        );

        $canonicalActor = User::query()->find($actor->getKey());
        abort_unless($canonicalActor, 403);

        abort_unless(
            $run->hasCanonicalExecutionProvenance()
                && Gate::forUser($canonicalActor)->allows('view', $run),
            404,
        );

        return [$run, $canonicalActor];
    }

    private function authorizeExecution(SiteChecklistRun $run, User $actor): string
    {
        abort_unless(Gate::forUser($actor)->allows('execute', $run), 403);

        if ($run->assigned_to_user_id !== null) {
            return (int) $run->assigned_to_user_id === (int) $actor->id
                ? self::AUTHORITY_RUN_ASSIGNEE
                : self::AUTHORITY_MANAGER_OVERRIDE;
        }

        if ($run->assignment?->assigned_to_user_id !== null) {
            $assignmentAssigneeId = (int) $run->assignment->assigned_to_user_id;
            $run->assigned_to_user_id = $assignmentAssigneeId;

            return $assignmentAssigneeId === (int) $actor->id
                ? self::AUTHORITY_ASSIGNMENT_ASSIGNEE
                : self::AUTHORITY_MANAGER_OVERRIDE;
        }

        $run->assigned_to_user_id = $actor->id;

        return self::AUTHORITY_UNASSIGNED_CLAIM;
    }

    private function authorizeManagement(SiteChecklistRun $run, User $actor, string $permission): void
    {
        abort_unless(
            $actor->canDo($permission)
                && Gate::forUser($actor)->allows('update', $run->site),
            403,
        );
    }

    private function assertMutable(SiteChecklistRun $run): void
    {
        if (! in_array($run->status, ['scheduled', 'in_progress', 'overdue'], true)) {
            abort(409, 'This checklist run can no longer be changed.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     */
    private function persistResponses(SiteChecklistRun $run, array $responses): void
    {
        $submittedItemIds = collect($responses)
            ->map(fn (array $response): int => (int) ($response['template_item_id'] ?? 0))
            ->unique()
            ->values();
        $validItemIds = SiteChecklistTemplateItem::query()
            ->where('template_id', $run->template_id)
            ->whereIn('id', $submittedItemIds->all())
            ->sharedLock()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        if ($submittedItemIds->diff($validItemIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'responses' => 'One or more checklist responses do not belong to this run.',
            ]);
        }

        foreach ($responses as $response) {
            $run->responses()->updateOrCreate(
                ['template_item_id' => (int) $response['template_item_id']],
                [
                    'response_value' => $response['response_value'] ?? null,
                    'notes' => $response['notes'] ?? null,
                    'photo_path' => $response['photo_path'] ?? null,
                    'is_failed' => (bool) ($response['is_failed'] ?? false),
                ],
            );
        }
    }

    private function assertRequiredResponsesComplete(SiteChecklistRun $run): void
    {
        $requiredItemIds = SiteChecklistTemplateItem::query()
            ->where('template_id', $run->template_id)
            ->where('is_required', true)
            ->sharedLock()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        if ($requiredItemIds->isEmpty()) {
            return;
        }

        $answeredItemIds = $run->responses()
            ->whereIn('template_item_id', $requiredItemIds->all())
            ->lockForUpdate()
            ->get(['template_item_id', 'response_value'])
            ->filter(fn ($response): bool => trim((string) $response->response_value) !== '')
            ->pluck('template_item_id')
            ->map(fn ($id): int => (int) $id);

        if ($requiredItemIds->diff($answeredItemIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'responses' => 'Complete all required checklist items before signing.',
            ]);
        }
    }

    private function raiseFollowUpsForFailures(SiteChecklistRun $run, int $userId): void
    {
        $responses = $run->responses()
            ->where('is_failed', true)
            ->where(fn ($query) => $query
                ->whereNull('created_hazard_id')
                ->orWhereNull('created_damage_id'))
            ->with('templateItem')
            ->get();

        foreach ($responses as $response) {
            $item = $response->templateItem;
            if (! $item) {
                continue;
            }

            if ($item->failure_creates_hazard && ! $response->created_hazard_id) {
                $hazard = SiteHazard::create([
                    'site_id' => $run->site_id,
                    'hazard_type' => 'safety',
                    'severity' => 'medium',
                    'likelihood' => 'possible',
                    'description' => $this->followUpDescription(
                        'Checklist check failed',
                        $item->question,
                        $response->notes,
                    ),
                    'reported_by_user_id' => $userId,
                    'status' => 'open',
                    'linked_checklist_run_id' => $run->id,
                ]);
                $response->created_hazard_id = $hazard->id;
            }

            if ($item->failure_creates_damage && ! $response->created_damage_id) {
                $damage = $run->site->damages()->create([
                    'reported_by' => $userId,
                    'title' => 'Checklist issue: '.Str::limit($item->question, 200),
                    'description' => $response->notes ?: $item->question,
                    'severity' => 'minor',
                    'status' => 'reported',
                    'damage_date' => now()->toDateString(),
                    'discovered_date' => now()->toDateString(),
                    'insurance_status' => 'not_applicable',
                    'checklist_run_id' => $run->id,
                ]);
                $response->created_damage_id = $damage->id;
            }

            if ($response->isDirty()) {
                $response->save();
            }
        }
    }

    private function followUpDescription(string $prefix, string $question, ?string $notes): string
    {
        $text = "{$prefix}: {$question}";

        return $notes ? "{$text} — {$notes}" : $text;
    }
}
