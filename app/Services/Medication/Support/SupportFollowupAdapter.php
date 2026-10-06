<?php

namespace App\Services\Medication\Support;

use App\Models\Client;
use App\Models\MedicationFollowup;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSupportTriggerOutbox;
use App\Models\User;
use App\Services\Medication\Followups\MedicationFollowupService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** P08a owns work identity, transitions, Tasks and history. */
class SupportFollowupAdapter
{
    public function open(Client $client, User $actor): Collection
    {
        return $this->openForClients([(int) $client->id], $actor);
    }

    public function openForClients(array $clientIds, User $actor): Collection
    {
        $work = app(MedicationFollowupService::class)->visibleQuery($actor)->whereIn('client_id', $clientIds)
            ->where('type', 'reassess_support')->whereNull('completed_at')->orderBy('due_at')->get()
            ->map(fn ($row) => [
                'id' => $row->id, 'client_id' => (int) $row->client_id,
                'trigger' => $row->context['support_trigger'] ?? 'review_date',
                'reason' => $row->context['reason'] ?? 'Reassess medication support.', 'due_at' => $row->due_at?->toIso8601String(),
            ]);
        $receipts = MedicationSupportTriggerOutbox::query()->whereIn('client_id', $clientIds)->whereNull('delivered_at')
            ->whereIn('assessment_id', MedicationSelfAdminAssessment::query()->whereIn('client_id', $clientIds)
                ->whereNotIn('id', MedicationSelfAdminAssessment::withTrashed()->whereNotNull('supersedes_id')->select('supersedes_id'))->select('id'))
            ->get()->map(fn ($row) => ['id' => 'pending-'.$row->id, 'client_id' => (int) $row->client_id, 'trigger' => $row->kind,
                'reason' => $row->reason.' (follow-up delivery pending)', 'due_at' => $row->occurred_at->setTimezone('Pacific/Auckland')->addDays(7)->utc()->toIso8601String()]);

        return $work->concat($receipts);
    }

    public function request(MedicationSelfAdminAssessment $assessment, string $kind, string $sourceKey, string $reason, ?CarbonImmutable $occurredAt = null): void
    {
        $client = Client::query()->findOrFail($assessment->client_id);
        app(MedicationFollowupService::class)->ensureForSource(
            source: 'support-reassessment', sourceId: $assessment->id.'_'.$kind.'_'.substr(hash('sha256', $sourceKey), 0, 32),
            client: $client, medication: null, administration: null, ownerId: null,
            dueAt: ($occurredAt ?? CarbonImmutable::now('UTC'))->setTimezone('Pacific/Auckland')->addDays(7)->utc(),
            context: ['support_assessment_id' => $assessment->id, 'support_trigger' => $kind, 'reason' => $reason,
                'source_url' => route('emar.support.show', $client).'?action=assess'],
        );
    }

    /** Under the authorized canonical Client lock. Caller appends collected audit events LAST. */
    public function completed(MedicationSelfAdminAssessment $assessment, User $actor, int $reassessmentId, array &$auditEvents): void
    {
        $service = app(MedicationFollowupService::class);
        $rows = MedicationFollowup::query()->where('client_id', $assessment->client_id)->where('type', 'reassess_support')
            ->where('context->support_assessment_id', $assessment->id)->whereNull('completed_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $service->completeFromSource($row->source_key, $actor, 'reassessed', ['support_reassessment_id' => $reassessmentId], $auditEvents);
        }
    }
}
