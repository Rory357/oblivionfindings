<?php

namespace App\Services\Sites;

use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplateItem;
use App\Models\SiteDamage;
use App\Models\SiteHazard;
use App\Models\SiteHazardAction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\References\ReferenceNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class SiteChecklistRunExecutionService
{
    public const AUTHORITY_RUN_ASSIGNEE = 'run_assignee';

    public const AUTHORITY_ASSIGNMENT_ASSIGNEE = 'assignment_assignee';

    public const AUTHORITY_UNASSIGNED_CLAIM = 'unassigned_claim';

    public const AUTHORITY_MANAGER_OVERRIDE = 'manager_override';

    public const MANAGER_OVERRIDE_REASON = 'Authorized using the explicit checklists.schedule manager override.';

    public function __construct(
        private readonly SiteChecklistFailureRiskMapper $failureRiskMapper,
        private readonly ReferenceNumberGenerator $referenceNumbers,
    ) {}

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
            ->values();

        if ($submittedItemIds->contains(fn (int $id): bool => $id <= 0)
            || $submittedItemIds->unique()->count() !== $submittedItemIds->count()) {
            throw ValidationException::withMessages([
                'responses' => 'Each checklist response must identify one distinct item from this run.',
            ]);
        }

        $items = SiteChecklistTemplateItem::query()
            ->where('template_id', $run->template_id)
            ->orderBy('id')
            ->sharedLock()
            ->get()
            ->keyBy(fn (SiteChecklistTemplateItem $item): int => (int) $item->id);

        if ($submittedItemIds->diff($items->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'responses' => 'One or more checklist responses do not belong to this run.',
            ]);
        }

        foreach ($responses as $response) {
            $item = $items->get((int) $response['template_item_id']);
            if (! $item instanceof SiteChecklistTemplateItem) {
                throw new LogicException('A validated checklist item could not be resolved.');
            }

            $responseValue = $response['response_value'] ?? null;
            $run->responses()->updateOrCreate(
                ['template_item_id' => (int) $response['template_item_id']],
                [
                    'response_value' => $responseValue,
                    'notes' => $response['notes'] ?? null,
                    'photo_path' => $response['photo_path'] ?? null,
                    // Failure is a governed consequence of the locked template
                    // item and response value. Never trust the browser flag for
                    // hazard, damage, or escalation projection.
                    'is_failed' => $this->canonicalFailureStatus($item, $responseValue),
                ],
            );
        }
    }

    private function canonicalFailureStatus(SiteChecklistTemplateItem $item, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $value = (string) $value;

        return match ($item->response_type) {
            'yes_no' => $this->choiceFailureStatus($value, ['yes', 'no'], ['no']),
            'yes_no_na' => $this->choiceFailureStatus($value, ['yes', 'no', 'na'], ['no']),
            'pass_fail' => $this->choiceFailureStatus($value, ['pass', 'fail'], ['fail']),
            'numeric' => $this->numericFailureStatus($item, $value),
            'text', 'photo' => false,
            default => throw new LogicException(
                "Unsupported checklist response type [{$item->response_type}].",
            ),
        };
    }

    /**
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $failed
     */
    private function choiceFailureStatus(string $value, array $allowed, array $failed): bool
    {
        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages([
                'responses' => 'One or more checklist responses has an invalid value.',
            ]);
        }

        return in_array($value, $failed, true);
    }

    private function numericFailureStatus(SiteChecklistTemplateItem $item, string $value): bool
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'responses' => 'One or more numeric checklist responses has an invalid value.',
            ]);
        }

        $config = $item->response_config ?? [];
        $number = (float) $value;
        $minimum = $config['min'] ?? null;
        $maximum = $config['max'] ?? null;

        if (($minimum !== null && ! is_numeric($minimum))
            || ($maximum !== null && ! is_numeric($maximum))) {
            throw new LogicException('Checklist numeric response limits are invalid.');
        }

        return ($minimum !== null && $number < (float) $minimum)
            || ($maximum !== null && $number > (float) $maximum);
    }

    private function assertRequiredResponsesComplete(SiteChecklistRun $run): void
    {
        $requiredItemIds = SiteChecklistTemplateItem::query()
            ->where('template_id', $run->template_id)
            ->where('is_required', true)
            ->orderBy('id')
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
            ->with('templateItem')
            ->lockForUpdate()
            ->get();

        foreach ($responses as $response) {
            $item = $response->templateItem;
            if (! $item || (int) $item->template_id !== (int) $run->template_id) {
                throw new LogicException('Checklist response has invalid template-item provenance.');
            }

            $mapping = $this->failureRiskMapper->forItem($item);
            $requiresEscalation = $mapping['requires_hs_escalation'];
            $needsHazard = (bool) $item->failure_creates_hazard || $requiresEscalation;
            $hazard = $this->canonicalCreatedHazard($run, $response->created_hazard_id);

            if ($needsHazard && ! $hazard) {
                $hazard = SiteHazard::query()->create([
                    'site_id' => $run->site_id,
                    'hazard_type' => 'safety',
                    'severity' => $mapping['hazard_severity'],
                    'likelihood' => $mapping['hazard_likelihood'],
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

            if ($hazard && $requiresEscalation) {
                if ($hazard->severity !== $mapping['hazard_severity']
                    || $hazard->likelihood !== $mapping['hazard_likelihood']) {
                    $hazard->update([
                        'severity' => $mapping['hazard_severity'],
                        'likelihood' => $mapping['hazard_likelihood'],
                    ]);
                }

                $this->ensureRequiredEscalationAction($hazard, $item->question);
            }

            $damage = $this->canonicalCreatedDamage($run, $response->created_damage_id);
            if ($item->failure_creates_damage && ! $damage) {
                $damage = $run->site->damages()->create([
                    'reported_by' => $userId,
                    'title' => 'Checklist issue: '.Str::limit($item->question, 200),
                    'description' => $response->notes ?: $item->question,
                    'severity' => $mapping['damage_severity'],
                    'status' => 'reported',
                    'damage_date' => now()->toDateString(),
                    'discovered_date' => now()->toDateString(),
                    'insurance_status' => 'not_applicable',
                    'checklist_run_id' => $run->id,
                ]);
                $response->created_damage_id = $damage->id;
            }

            if ($damage && $requiresEscalation && $damage->severity !== $mapping['damage_severity']) {
                $damage->update(['severity' => $mapping['damage_severity']]);
            }

            if ($response->isDirty()) {
                $response->save();
            }
        }
    }

    private function canonicalCreatedHazard(SiteChecklistRun $run, ?int $hazardId): ?SiteHazard
    {
        if (! $hazardId) {
            return null;
        }

        return SiteHazard::query()
            ->whereKey($hazardId)
            ->where('site_id', $run->site_id)
            ->where('linked_checklist_run_id', $run->id)
            ->lockForUpdate()
            ->first();
    }

    private function canonicalCreatedDamage(SiteChecklistRun $run, ?int $damageId): ?SiteDamage
    {
        if (! $damageId) {
            return null;
        }

        return SiteDamage::query()
            ->whereKey($damageId)
            ->where('site_id', $run->site_id)
            ->where('checklist_run_id', $run->id)
            ->lockForUpdate()
            ->first();
    }

    private function ensureRequiredEscalationAction(SiteHazard $hazard, string $question): void
    {
        $exists = SiteHazardAction::query()
            ->where('hazard_id', $hazard->id)
            ->where('action_type', SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION)
            ->exists();

        if ($exists) {
            return;
        }

        SiteHazardAction::query()->create([
            'hazard_id' => $hazard->id,
            'reference_number' => $this->referenceNumbers->next('HZA'),
            'action_description' => 'H&S review and control required for critical checklist failure: '.Str::limit($question, 200),
            'action_type' => SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION,
            'status' => 'pending',
            'assigned_to_user_id' => $hazard->assigned_to_user_id,
            'due_date' => $hazard->due_date ?? now()->addDay()->toDateString(),
        ]);
    }

    private function followUpDescription(string $prefix, string $question, ?string $notes): string
    {
        $text = "{$prefix}: {$question}";

        return $notes ? "{$text} — {$notes}" : $text;
    }
}
