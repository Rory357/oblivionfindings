<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Services\HrDocumentAccessService;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Policies\ClientPolicy;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Independent destinations never inherit confidential authority from the injury FK. */
final class InjuryConnectionsService
{
    public function __construct(private readonly InjuryAccessService $access,
        private readonly IncidentAccessService $incidents, private readonly ClientPolicy $clients,
        private readonly HsEventAccessService $events, private readonly ControlRoomAlertAccessService $alerts,
        private readonly UserSiteAccessService $sites, private readonly HrDocumentAccessService $documents) {}

    public function inContext(WorkplaceInjury $injury, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $source = $this->access->assertInjury($injury, $current, $reads, $sites);
        $incident = $this->incident($source, $current, $reads);
        $candidates = $reads->query(HsEvent::query())->where('source_type', WorkplaceInjury::class)
            ->where('source_id', $source->id)->orderBy('id')->limit(2)
            ->get(['id', 'source_type', 'source_id', 'event_category', 'staff_id', 'site_id', 'control_room_alert_id']);
        $event = $candidates->count() === 1 ? $candidates->sole() : null;
        $integrity = $candidates->count() > 1 ? 'unavailable' : 'valid';
        if ($event && ($event->event_category !== HsEvent::CATEGORY_INJURY
            || (int) $event->staff_id !== (int) $source->user_id || (int) $event->site_id !== (int) $source->site_id)) {
            $event = null;
            $integrity = 'unavailable';
        }
        $hs = $this->entry($integrity === 'unavailable' ? 'unavailable' : ($event ? 'restricted' : 'absent'));
        if ($event && $current->canDo('hazards.view') && $this->events->canView($event, $current)) {
            $event = $reads->query(HsEvent::query())->whereKey($event->id)->firstOrFail();
            $hs = [...$this->entry('readable'), 'id' => (int) $event->id, 'reference' => $event->reference_number,
                'status' => $event->status, 'url' => Route::has('health-safety.events.show') ? '/health-safety/events/'.$event->id : null];
        }
        $operational = $this->operational($source, $event, $current, $reads, $integrity);
        $worksafe = ['access' => $hs['access'], 'decision_state' => null, 'notification_state' => null,
            'notified_at' => null, 'acknowledged_at' => null, 'reference' => null, 'url' => null,
            'external_receipt_state' => 'unconfirmed'];
        if ($hs['access'] === 'readable') {
            $worksafe = [...$worksafe, 'decision_state' => $event->hasSignedWorksafeDecision() ? 'recorded' : 'unknown',
                'notification_state' => $event->worksafe_status, 'notified_at' => $event->worksafe_notified_at?->toIso8601String(),
                'acknowledged_at' => $event->worksafe_acknowledged_at?->toIso8601String(),
                'reference' => $event->worksafe_reference, 'url' => $hs['url']];
        }
        return ['incident' => $incident, 'health_safety' => $hs, 'control_room' => $operational, 'worksafe' => $worksafe];
    }

    private function entry(string $access): array
    {
        return ['access' => $access, 'id' => null, 'reference' => null, 'status' => null, 'url' => null];
    }

    private function incident(WorkplaceInjury $injury, User $current, CurrentAuthorizationReads $reads): array
    {
        if ($injury->related_incident_id === null) {
            return $this->entry('absent');
        }
        $candidate = $reads->query(ClientIncident::query())->whereKey($injury->related_incident_id)->first(['id', 'client_id', 'site_id', 'shift_id']);
        if (! $candidate) {
            return $this->entry('unavailable');
        }
        try {
            if ($this->sites->effectiveClientIncidentSiteId($candidate) !== (int) $injury->site_id) {
                return $this->entry('unavailable');
            }
        } catch (\LogicException) {
            return $this->entry('unavailable');
        }
        if (! $this->canReadIncident($candidate, $current, $reads)) {
            return $this->entry('restricted');
        }
        $candidate = $reads->query(ClientIncident::query())->whereKey($candidate->id)
            ->firstOrFail(['id', 'reference_number', 'status', 'title', 'type']);
        return [...$this->entry('readable'), 'id' => (int) $candidate->id, 'reference' => $candidate->reference_number,
            'status' => $candidate->status, 'title' => $candidate->title ?: ucfirst(str_replace('_', ' ', (string) $candidate->type)),
            'url' => Route::has('incidents.show') ? '/incidents/'.$candidate->id : null];
    }

    private function canReadIncident(ClientIncident $incident, User $current, CurrentAuthorizationReads $reads): bool
    {
        if (! $this->incidents->canView($current, $incident, $reads) || $incident->client_id === null) {
            return false;
        }
        $client = $reads->query(Client::query())->whereKey($incident->client_id)->first();
        return $client !== null && $this->clients->viewFromCurrentEvidence($current, $client, $reads);
    }

    public function incidentOptionsInContext(User $current, CurrentAuthorizationReads $reads, array $sites, ?int $siteId = null): array
    {
        if ((! $current->canDo('incidents.viewAny') && ! $current->canDo('incidents.viewAssigned'))
            || (! $current->canDo('clients.viewAny') && ! $current->canDo('clients.viewAssigned'))) {
            return [];
        }
        if ($siteId !== null) {
            abort_unless(in_array($siteId, $sites, true), 404);
        }
        $query = $this->incidents->apply(ClientIncident::query(), $current, $reads);
        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get();
        $options = [];
        foreach ($rows as $row) {
            try {
                $actualSite = $this->sites->effectiveClientIncidentSiteId($row);
            } catch (\LogicException) {
                continue;
            }
            if (! in_array($actualSite, $sites, true) || ($siteId !== null && $actualSite !== $siteId)
                || ! $this->canReadIncident($row, $current, $reads)) {
                continue;
            }
            $options[] = ['id' => (int) $row->id, 'site_id' => $actualSite, 'label' => $row->reference_number,
                'title' => $row->title ?: ucfirst(str_replace('_', ' ', (string) $row->type)),
                'occurred_at' => $row->occurred_at?->toIso8601String()];
        }
        return $options;
    }

    private function operational(WorkplaceInjury $injury, ?HsEvent $event, User $current, CurrentAuthorizationReads $reads, string $integrity): array
    {
        if ($integrity === 'unavailable') {
            return $this->entry('unavailable');
        }
        $query = ControlRoomAlert::query();
        if ($event?->control_room_alert_id !== null) {
            $query->whereKey($event->control_room_alert_id);
        } else {
            $query->where('context->workplace_injury_id', $injury->id)->orderByDesc('id');
        }
        $rows = $reads->query($query)->limit(2)->get();
        if ($rows->isEmpty()) {
            return $this->entry($event?->control_room_alert_id !== null ? 'unavailable' : 'absent');
        }
        if ($event?->control_room_alert_id === null && $rows->count() > 1) {
            return $this->entry('unavailable');
        }
        $alert = $rows->first();
        if ($alert->source !== 'operations' || $alert->alert_type !== 'operations.workplace_injury'
            || (int) $alert->site_id !== (int) $injury->site_id
            || (int) data_get($alert->context, 'site_id') !== (int) $injury->site_id
            || (int) data_get($alert->context, 'workplace_injury_id') !== (int) $injury->id
            || (int) data_get($alert->context, 'user_id') !== (int) $injury->user_id) {
            return $this->entry('unavailable');
        }
        if (! $this->alerts->canView($alert, $current)) {
            return $this->entry('restricted');
        }
        return [...$this->entry('readable'), 'id' => (int) $alert->id, 'reference' => $alert->reference_number,
            'status' => $alert->status, 'url' => Route::has('control-room.alerts.show') ? '/control-room/alerts/'.$alert->id : null];
    }

    public function documentInContext(WorkplaceInjury $injury, int $id, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        try {
            $injury = $this->access->assertInjury($injury, $current, $reads, $sites);
            $document = $this->access->assertHrDocument($injury, $id, $current, $reads, $sites, 'view');
            return $this->document($document);
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }
            return ['access' => 'restricted', 'id' => null, 'title' => null, 'url' => null, 'availability' => 'unknown'];
        }
    }

    public function documentOptionsInContext(WorkplaceInjury $injury, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $injury = $this->access->assertInjury($injury, $current, $reads, $sites);
        $this->access->assertMedicalSource($injury, $current, $reads, $sites);
        if (! $current->canDo('hr.documents.view')) {
            return ['access' => 'restricted', 'items' => null];
        }
        $query = $this->documents->applyReadableDocumentScope(HrDocument::query(), $current)
            ->whereHas('employeeProfile', fn ($profile) => $profile->where('user_id', $injury->user_id));
        $items = [];
        foreach ($reads->query($query)->orderByDesc('id')->limit(100)->get() as $document) {
            $item = $this->document($document);
            if ($item['availability'] === 'available') {
                $items[] = $item;
            }
        }
        return ['access' => 'readable', 'items' => $items];
    }

    private function document(HrDocument $document): array
    {
        $disk = $document->storage_disk ?: 'private';
        $available = is_string($disk) && is_array(config('filesystems.disks.'.$disk)) && is_string($document->storage_path)
            && $document->storage_path !== '' && ! str_contains($document->storage_path, '..')
            && Storage::disk($disk)->exists($document->storage_path);
        return ['access' => 'readable', 'id' => (int) $document->id, 'title' => $document->title,
            'availability' => $available ? 'available' : 'unavailable',
            'url' => $available && Route::has('hr.documents.download') ? '/hr/documents/'.$document->id.'/download' : null];
    }
}
