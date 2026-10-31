<?php

namespace App\Services\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Support\HealthSafety\FirstAidPresenter;
use App\Support\HealthSafety\FirstAidReturn;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Source-owned scalar entries, independently reauthorized at each destination. */
final class FirstAidSourceProjectionService
{
    public function __construct(private readonly FirstAidAccessService $access, private readonly FirstAidPresenter $presenter, private readonly IncidentAccessService $incidents) {}

    public function forSite(User $viewer, Site $site): array
    {
        return $this->available($viewer, function ($current, $reads, $sites) use ($site) {
            return $this->access->readableQuery($current, $reads, $sites)->where('site_id', $site->id)
                ->withCount(['followups as open_followups_count' => fn ($q) => $q->whereNull('completed_at')])->orderByDesc('treatment_date')->orderByDesc('id')->get()
                ->map(function ($record) use ($current, $reads) {
                    $row = $this->presenter->row($record, $current, '/sites/'.$record->site_id.'?tab=first_aid');
                    $incident = $this->readableIncident($record, $current, $reads);

                    return ['id' => $row['id'], 'reference' => $row['reference'], 'treatment_date' => $row['treatment_date'], 'person' => $row['treated_person_name'],
                        'injury' => $row['injury_illness_type'], 'outcome' => $row['treatment_outcome'], 'ambulance_called' => $row['ambulance_called'],
                        'first_aider' => $row['first_aider_name'], 'incident_reported' => $row['incident_reported'], 'related_incident_id' => $incident?->id,
                        'open_followups_count' => (int) $record->open_followups_count, 'href' => $row['href']];
                })->all();
        });
    }

    public function forClient(User $viewer, Client $client): array
    {
        return $this->available($viewer, function ($current, $reads, $sites) use ($client) {
            $subject = $reads->query(Client::query())->find($client->id);
            if (! $subject || ! app(ClientPolicy::class)->viewFromCurrentEvidence($current, $subject, $reads)) {
                return [];
            }

            return $this->access->readableQuery($current, $reads, $sites)->where('treated_person_type', 'client')->where('client_id', $subject->id)->where('site_id', $subject->site_id)
                ->orderByDesc('treatment_date')->orderByDesc('id')->get()->map(function ($record) use ($current, $subject) {
                    $row = $this->presenter->row($record, $current, '/operations/clients/'.$subject->id.'?tab=first_aid');

                    // Existing Client treatment panel retains these authorized factual fields.
                    return [...$row, 'reference_number' => $row['reference'], 'body_part' => $record->body_part, 'treatment_given' => $record->treatment_given,
                        'firstAider' => $row['first_aider']];
                })->all();
        });
    }

    public function forIncident(User $viewer, ClientIncident $incident): array
    {
        return $this->available($viewer, function ($current, $reads, $sites) use ($incident) {
            $origin = $this->incidents->apply(ClientIncident::query(), $current, $reads)->find($incident->id);
            if (! $origin) {
                return [];
            }

            return $this->access->readableQuery($current, $reads, $sites, true)->where('related_incident_id', $origin->id)->where('treated_person_type', 'client')
                ->where('client_id', $origin->client_id)->where('site_id', $origin->site_id)->orderByDesc('treatment_date')->orderByDesc('id')->get()
                ->map(function ($record) use ($current, $origin) {
                    $return = FirstAidReturn::sanitize(request()->getRequestUri());
                    if (parse_url($return ?? '', PHP_URL_PATH) !== '/incidents/'.$origin->id) {
                        $return = '/incidents/'.$origin->id;
                    }
                    $row = $this->presenter->row($record, $current, $return);

                    return ['id' => $row['id'], 'reference' => $row['reference'], 'person' => $row['treated_person_name'], 'injury' => $row['injury_illness_type'],
                        'treatment_date' => $row['treatment_date'], 'ambulance_called' => $row['ambulance_called'], 'status' => $row['status'], 'href' => $row['href']];
                })->all();
        });
    }

    /**
     * First-aid records linked to this incident that the incident page doesn't show this viewer (approved drawing D3).
     * Only when each was added is returned: no kind, person, injury or reference.
     *
     * @param  array<int, int>  $shownIds
     * @return array<int, array{at: ?string}>
     */
    public function hiddenForIncident(ClientIncident $incident, array $shownIds): array
    {
        return FirstAidRecord::query()->where('related_incident_id', $incident->id)->whereNotIn('id', $shownIds)
            ->orderBy('created_at')->orderBy('id')->pluck('created_at')
            ->map(fn ($at) => ['at' => $at?->toIso8601String()])->values()->all();
    }

    public function forMyDay(User $viewer): array
    {
        return $this->available($viewer, function ($current, $reads, $sites) {
            $records = $this->access->readableQuery($current, $reads, $sites)->select('id');

            return $reads->query(FirstAidFollowup::query())->where('assigned_to_user_id', $current->id)->whereNull('completed_at')->whereIn('first_aid_record_id', $records)
                ->with('record.site:id,name')->orderBy('due_at')->orderBy('id')->get()->map(function ($row) use ($current, $reads, $sites) {
                    $this->access->assertSource($row->record, $current, $reads, $sites);
                    $record = $row->record;
                    $name = FirstAidPresenter::recordedPersonLabel($record->treated_person_type === 'client' ? trim($record->client->first_name.' '.$record->client->last_name) : $record->treated_person_name);
                    $href = FirstAidReturn::href($record->id, '/my-day', 'followups', $row->id);

                    return ['id' => (int) $row->id, 'notes' => $row->notes, 'due_at' => $row->due_at?->toIso8601String(), 'is_overdue' => $row->due_at?->isPast() ?? false,
                        'record_id' => (int) $record->id, 'treated_person_name' => $name, 'site_name' => $record->site?->name, 'url' => $href, 'href' => $href];
                })->all();
        });
    }

    public function isFirstAidSource(?string $type): bool
    {
        return in_array($type, array_unique([FirstAidRecord::class, (new FirstAidRecord)->getMorphClass()]), true);
    }

    public function sourceForReview(HsEvent $source, User $viewer, ?HealthSafetyReadContext $context = null): ?array
    {
        $project = function (HealthSafetyReadContext $active) use ($source, $viewer): ?array {
            $active->assertViewer($viewer);

            return $this->access->withReadContext($active, function ($current, $reads, $sites) use ($source, $active) {
                if (! $current->canDo('hazards.view')) {
                    return null;
                }
                // The supplied model contributes only its ID. Current source identity is
                // narrowed before the complete H&S source intersection is evaluated.
                $event = $reads->query(HsEvent::query())->whereKey($source->id)
                    ->whereIn('source_type', array_unique([FirstAidRecord::class, (new FirstAidRecord)->getMorphClass()]))
                    ->where('event_category', HsEvent::CATEGORY_INJURY)->whereIn('site_id', $sites)
                    ->first(['id', 'source_type', 'source_id', 'event_category', 'site_id', 'client_id', 'staff_id']);
                if (! $event || ! $this->isFirstAidSource($event->source_type)) {
                    return null;
                }
                $record = $this->access->readableQuery($current, $reads, $sites, true, $active)
                    ->find($event->source_id, ['id', 'site_id', 'treated_person_type', 'treated_person_id', 'client_id', 'reference_number']);
                if (! $record || $event->event_category !== HsEvent::CATEGORY_INJURY || (int) $event->site_id !== (int) $record->site_id
                    || ($event->client_id === null ? null : (int) $event->client_id) !== ($record->treated_person_type === 'client' ? (int) $record->client_id : null)
                    || ($event->staff_id === null ? null : (int) $event->staff_id) !== ($record->treated_person_type === 'staff' && $record->treated_person_id ? (int) $record->treated_person_id : null)) {
                    return null;
                }
                $this->access->assertSource($record, $current, $reads, $sites);
                $query = HsEvent::query()->whereKey($event->id)->where('source_type', $event->source_type)->where('source_id', $record->id)
                    ->where('event_category', HsEvent::CATEGORY_INJURY)->where('site_id', $record->site_id)
                    ->where('client_id', $event->client_id)->where('staff_id', $event->staff_id);
                if (! app(HsEventAccessService::class)->applyReadableScope(clone $query, $current, $active, $query)->exists()) {
                    return null;
                }
                $back = FirstAidReturn::sanitize(request()->query('return_to'));
                $href = $back && parse_url($back, PHP_URL_PATH) === FirstAidReturn::REGISTER.'/'.$record->id ? $back
                    : FirstAidReturn::href($record->id, FirstAidReturn::sanitize(request()->getRequestUri()), 'connections');

                return ['type' => 'FirstAidRecord', 'id' => (int) $record->id, 'label' => $record->reference_number ?: 'FA-'.$record->id, 'url' => $href, 'unwired' => false];
            });
        };
        try {
            return $context !== null ? $project($context) : HealthSafetyReadContext::within($viewer,
                array_values(array_unique([...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS])), $project);
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return null;
        }
    }

    private function readableIncident(FirstAidRecord $record, User $viewer, CurrentAuthorizationReads $reads): ?ClientIncident
    {
        if ($record->treated_person_type !== 'client' || ! $record->related_incident_id) {
            return null;
        }

        return $this->incidents->apply(ClientIncident::query(), $viewer, $reads)->where('client_id', $record->client_id)->where('site_id', $record->site_id)->find($record->related_incident_id);
    }

    private function available(User $viewer, callable $operation, mixed $denied = []): mixed
    {
        try {
            return $this->access->withCurrentViewer($viewer, $operation);
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return $denied;
        }
    }
}
