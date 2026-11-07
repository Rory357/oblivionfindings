<?php

namespace App\Services\Safeguarding;

use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\SafeguardingConcern;

/**
 * What must be closed before a concern can end as "No further action" (readiness audit B5, Stephan 6 Nov 2026:
 * "Keep checks, say what to close"). A read-only, plain-words copy of the checks SafeguardingTerminalTransitionService
 * enforces when it locks the concern (safeguardingBlockers + assertOwningDomainsReady); the service stays the
 * authority, and this list never lets anything through. Each item links to where the work is done.
 *
 * @phpstan-type Item array{key: string, label: string, detail: string, done: bool, href: ?string}
 */
final class SafeguardingCloseChecklist
{
    /** @return list<Item> */
    public function forConcern(SafeguardingConcern $concern): array
    {
        $items = [];

        $incident = $concern->concern_type === 'incident_escalation' && $concern->related_incident_id
            ? ClientIncident::query()->find($concern->related_incident_id) : null;
        $event = $this->event($concern, $incident);
        $alertId = collect([$event?->control_room_alert_id, $incident?->control_room_alert_id])
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)->map(fn ($id): int => (int) $id)->unique()->values();
        $alert = $alertId->count() === 1 ? ControlRoomAlert::query()->find($alertId->first()) : null;

        if ($event === null || $alert === null) {
            $items[] = ['key' => 'links', 'label' => 'Its linked H&S safety review or Control Room alert can’t be found',
                'detail' => 'Ask an administrator to check the links. Nothing can close it until they match.', 'done' => false, 'href' => null];
        }

        if ($event !== null) {
            $reviewClosed = $event->status === HsEvent::STATUS_CLOSED && $event->closed_at !== null
                && $event->closed_by !== null && filled($event->closure_summary);
            $openInvestigation = HsInvestigation::query()->where('hs_event_id', $event->id)
                ->where('status', '!=', HsInvestigation::STATUS_COMPLETED)->exists();
            $openActions = HsCorrectiveAction::query()->where('hs_event_id', $event->id)
                ->whereNotIn('status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED])->count();
            $missing = array_values(array_filter([
                $reviewClosed ? null : 'close it with its WorkSafe decision and closing summary',
                $openInvestigation ? 'complete its investigation' : null,
                $openActions > 0 ? 'check or close its '.$openActions.' '.($openActions === 1 ? 'action' : 'actions') : null,
            ]));
            $items[] = ['key' => 'hs_event', 'label' => 'Close its H&S safety review '.$event->reference_number,
                'detail' => $missing === [] ? 'Closed' : ucfirst(implode(', then ', $missing)).'.',
                'done' => $missing === [], 'href' => '/health-safety/events/'.$event->id];
        }

        if ($alert !== null) {
            $resolved = in_array($alert->status, [ControlRoomAlert::STATUS_RESOLVED, ControlRoomAlert::STATUS_CLOSED], true)
                && $alert->resolved_at !== null && $alert->resolved_by_user_id !== null && filled($alert->resolution_code);
            $openTasks = AlertTask::query()->where('alert_id', $alert->id)->whereNotIn('status', AlertTask::TERMINAL_STATUSES)->count();
            $missing = array_values(array_filter([
                $resolved ? null : 'resolve it with a resolution',
                $openTasks > 0 ? 'finish, cancel or hand over its '.$openTasks.' open '.($openTasks === 1 ? 'task' : 'tasks') : null,
            ]));
            $items[] = ['key' => 'control_room', 'label' => 'Resolve its Control Room alert '.($alert->reference_number ?: '#'.$alert->id),
                'detail' => $missing === [] ? 'Resolved' : ucfirst(implode(', then ', $missing)).'.',
                'done' => $missing === [], 'href' => '/control-room/alerts/'.$alert->id];
        }

        if ($concern->requires_external_referral) {
            $logged = $concern->externalReports()->exists();
            $items[] = ['key' => 'external_referral', 'label' => 'Log the external referral',
                'detail' => $logged ? 'Logged' : 'An external referral is flagged but not logged yet. Log it on this concern.',
                'done' => $logged, 'href' => null];
        }

        $openInvestigations = $concern->investigations()->whereNotIn('status', ['completed', 'abandoned'])->count();
        if ($openInvestigations > 0) {
            $items[] = ['key' => 'safeguarding_investigation', 'label' => 'Finish its safeguarding investigation',
                'detail' => $openInvestigations === 1 ? 'One investigation is still open on this concern.' : $openInvestigations.' investigations are still open on this concern.',
                'done' => false, 'href' => null];
        }
        $openPlans = $concern->actionPlans()->whereNotIn('status', ['completed', 'cancelled'])->count();
        if ($openPlans > 0) {
            $items[] = ['key' => 'safeguarding_actions', 'label' => 'Finish or cancel its open actions',
                'detail' => $openPlans === 1 ? 'One action on this concern is still open.' : $openPlans.' actions on this concern are still open.',
                'done' => false, 'href' => null];
        }

        return $items;
    }

    private function event(SafeguardingConcern $concern, ?ClientIncident $incident): ?HsEvent
    {
        if ($incident !== null) {
            $events = HsEvent::query()->where(function ($query) use ($incident): void {
                $query->whereIn('source_type', [ClientIncident::class, '\\'.ClientIncident::class])->where('source_id', $incident->id);
                if ($incident->hs_event_id) {
                    $query->orWhere('id', $incident->hs_event_id);
                }
            })->limit(2)->get();
        } else {
            $events = HsEvent::query()->whereIn('source_type', [SafeguardingConcern::class, '\\'.SafeguardingConcern::class])
                ->where('source_id', $concern->id)->where('event_category', HsEvent::CATEGORY_SAFEGUARDING)->limit(2)->get();
        }

        return $events->count() === 1 ? $events->first() : null;
    }
}
