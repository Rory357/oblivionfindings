<?php

namespace App\Services\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\HsCorrectiveActionPresenter;

/** An addressable view of the existing action, with independent source readers. */
final class HsCorrectiveActionWorkspace
{
    public function __construct(
        private readonly HsEventAccessService $events,
        private readonly UserSiteAccessService $sites,
        private readonly HsCorrectiveActionPresenter $presenter,
        private readonly ControlRoomAlertAccessService $responses,
        private readonly ControlRoomAlertProvenanceService $provenance,
        private readonly HsCorrectiveActionOwnershipService $ownership,
    ) {}

    public function forViewer(HsCorrectiveAction $record, User $viewer): array
    {
        $permissions = array_values(array_unique([...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
            ...InjuryAccessService::PERMISSIONS, ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS]));
        return HealthSafetyReadContext::within($viewer, $permissions, fn ($context) =>
            // H&S data needs current staff and an approved Site (Stephan, 25 Oct), as on the event page.
            $context->consumeViewer($permissions, function ($viewer) use ($record, $context) {
                abort_unless($viewer->canDo('hazards.view'), 403);
                $sourceCandidates = $context->applyEventSiteScope(HsEvent::query()->whereIn('id', HsCorrectiveAction::query()
                    ->whereKey($record->id)->select('hs_event_id')), UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
                $action = HsCorrectiveAction::query()
                    ->whereHas('hsEvent', fn ($query) => $this->events->applyReadableScope($query, $viewer, $context, $sourceCandidates))
                    ->with(['hsEvent.site:id,name', 'assignedTo:id,name', 'completedBy:id,name', 'verifiedBy:id,name',
                        'hsInvestigation', 'sourceControlRoomTask.alert'])
                    ->findOrFail($record->id);
                $event = $action->hsEvent;
                $strict = $context->applyEventSiteScope(HsEvent::query()->whereKey($event->id), [])->exists();
                $manager = $viewer->canDo('hazards.manage');
                $participant = $strict && ($manager || (int) $action->assigned_to_user_id === (int) $viewer->id);
                $evidenceState = HsCorrectiveActionPresenter::EVIDENCE_LOADED;
                if ($participant) {
                    try {
                        $action->load(['attachments.uploader:id,name', 'auditLogs' => fn ($query) => $query->with('user:id,name')->orderBy('id')]);
                    } catch (\Throwable $error) {
                        report($error);
                        $evidenceState = HsCorrectiveActionPresenter::EVIDENCE_UNAVAILABLE;
                    }
                }

                // A corrupted investigation link must not reveal another review's finding.
                $validInvestigation = $action->hsInvestigation && (int) $action->hsInvestigation->hs_event_id === (int) $event->id;
                if (! $validInvestigation) {
                    $action->setRelation('hsInvestigation', null);
                }
                $task = $action->sourceControlRoomTask;
                $hasSource = $action->source_control_room_task_id !== null;
                $sourceConsistent = $task?->alert && (int) $task->alert_id === (int) $event->control_room_alert_id;
                if ($sourceConsistent) {
                    try {
                        $this->provenance->assertHealthSafetyEventTuple($task->alert, $event);
                    } catch (\DomainException) {
                        $sourceConsistent = false;
                    }
                }
                $sourceReadable = $sourceConsistent && $this->responses->canView($task->alert, $viewer);
                if ($hasSource && ! $sourceReadable) {
                    $action->setRelation('sourceControlRoomTask', null);
                }
                $detail = $this->presenter->present($action, $viewer, $manager, $strict, $evidenceState);
                if (! $validInvestigation) {
                    $detail['hs_investigation_id'] = null;
                    $detail['recommendation_index'] = null;
                }
                if ($hasSource && ! $sourceReadable) {
                    $detail['source'] = ['type' => $sourceConsistent ? 'restricted' : 'unavailable'];
                }

                $ownership = $this->ownership->present($viewer, $action, $participant);
                // K1a (B1.5/B1.6): an owner who can no longer own the action blocks Start until a new owner is chosen.
                $replacementNeeded = ($ownership['state'] ?? null) === 'replacement_needed';
                $mayStart = $manager && $action->assigned_to_user_id !== null && $action->status === HsCorrectiveAction::STATUS_OPEN;

                return [
                    'ownership' => $ownership,
                    'action' => [...$detail,
                        'description' => $hasSource && ! $sourceReadable ? null : $action->description,
                        'closed_at' => $action->closed_at?->toIso8601String(),
                    ],
                    'review' => [
                        'id' => $event->id,
                        'reference_number' => $event->reference_number,
                        'status' => $event->status,
                        'site_name' => $event->site?->name,
                        'url' => "/health-safety/events/{$event->id}?section=actions",
                    ],
                    'source_task_url' => $sourceReadable ? "/control-room/alerts/{$task->alert_id}?section=tasks&return_to=".rawurlencode("/health-safety/corrective-actions/{$action->id}") : null,
                    'can' => [
                        // Review closure preserves independently owned action work, including
                        // a verified action or work covered by a controlled closure exception.
                        'start' => $mayStart && ! $replacementNeeded,
                        'complete' => $manager && $strict && $action->status === HsCorrectiveAction::STATUS_IN_PROGRESS,
                        'verify' => $detail['can_verify'],
                        'return' => $manager && $strict && $action->status === HsCorrectiveAction::STATUS_COMPLETED,
                        'close' => $manager && $strict && $action->status === HsCorrectiveAction::STATUS_VERIFIED,
                    ],
                    // Blocked for now, with the reason and who unblocks it; only for viewers who could otherwise start (IR3a).
                    'blocked' => $mayStart && $replacementNeeded
                        ? ['start' => ['reason' => 'Choose a new owner first', 'unblock' => 'Someone who manages health and safety at this site chooses a new owner.']]
                        : [],
                    'next_step' => $replacementNeeded && $action->status !== HsCorrectiveAction::STATUS_CLOSED
                        ? ['key' => 'choose_owner', 'can' => (bool) ($ownership['can_assign'] ?? false)]
                        : null,
                ];
            }));
    }
}
