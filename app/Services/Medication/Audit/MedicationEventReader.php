<?php

namespace App\Services\Medication\Audit;

use App\Models\Client;
use App\Models\MedicationEvent;
use App\Models\User;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\Reporting\MedicationReportPeriod;
use Illuminate\Database\Eloquent\Builder;

final class MedicationEventReader
{
    /** One query for the audit screen, event detail and full-period export. */
    public function query(User $actor, array $siteIds, MedicationReportPeriod $period, ?int $clientId = null, ?string $kind = null, string $search = ''): Builder
    {
        abort_unless($actor->canDo('medications.audit.view'), 403);
        abort_if(app(MedicationReportAccess::class)->financeOnly($actor), 403);
        $ids = app(MedicationReportAccess::class)->clientIds($actor, $siteIds);
        if ($clientId !== null) {
            abort_unless(in_array($clientId, $ids, true), 404);
            $ids = [$clientId];
        }
        $controlled = $actor->canDo('medications.controlled.view');

        return MedicationEvent::query()->whereIn('site_id', $siteIds)
            ->where(fn ($q) => $q->whereIn('client_id', $ids)->when($clientId === null, fn ($q) => $q->orWhereNull('client_id')))
            ->whereBetween('occurred_at', $period->bounds())
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            // Searching concealed summaries would leak whether a named
            // controlled medicine occurs. Concealed rows remain unsearched.
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('kind', 'like', '%'.$search.'%')->orWhere(fn ($q) => $q->when(! $controlled, fn ($q) => $q->where('controlled', false))->where('summary', 'like', '%'.$search.'%'))))
            ->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function present(User $actor, MedicationEvent $event, bool $detail = false): array
    {
        $concealed = $event->controlled && ! $actor->canDo('medications.controlled.view');
        $client = $event->client_id ? Client::find($event->client_id) : null;
        $result = ['id' => $event->id, 'sequence' => $event->sequence, 'site_id' => $event->site_id, 'kind' => $event->kind,
            'occurred_at' => $event->occurred_at->toIso8601String(), 'recorded_at' => $event->recorded_at->toIso8601String(),
            'summary' => $concealed ? 'Controlled medicine — details need controlled-medicine access' : $event->summary,
            'person' => $client ? trim($client->first_name.' '.$client->last_name) : null,
            'actor' => $event->actor_id ? User::find($event->actor_id)?->name : 'Automated', 'concealed' => $concealed];
        if ($detail) {
            $result += ['hash' => $event->hash, 'previous_hash' => $event->previous_hash, 'fingerprint_valid' => $event->hasValidFingerprint(),
                'facts' => $concealed ? [] : $event->facts, 'subject_type' => $concealed ? null : $event->subject_type, 'subject_id' => $concealed ? null : $event->subject_id, 'corrects_event_id' => $event->corrects_event_id];
        }

        return $result;
    }
}
