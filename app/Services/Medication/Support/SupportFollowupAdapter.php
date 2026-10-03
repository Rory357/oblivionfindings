<?php

namespace App\Services\Medication\Support;

use App\Models\Client;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\User;
use App\Services\Medication\Followups\MedicationFollowupService;
use Illuminate\Support\Collection;

/** Source adapter only. P08a owns work identity, transitions, Tasks and history. */
class SupportFollowupAdapter
{
    public function open(Client $client, User $actor): Collection
    {
        return app(MedicationFollowupService::class)->visibleQuery($actor)->where('client_id', $client->id)
            ->where('type', 'reassess_support')->whereNull('completed_at')->orderBy('due_at')->get()
            ->map(fn ($row) => [
                'id' => $row->id, 'trigger' => $row->context['support_trigger'] ?? 'review_date',
                'reason' => $row->context['reason'] ?? 'Reassess medication support.', 'due_at' => $row->due_at?->toIso8601String(),
            ]);
    }

    public function request(MedicationSelfAdminAssessment $assessment, string $kind, string $sourceKey, string $reason): void
    {
        $client = Client::query()->findOrFail($assessment->client_id);
        app(MedicationFollowupService::class)->ensure(
            sourceKey: 'support:'.$assessment->id.':'.$kind.':'.hash('sha256', $sourceKey),
            type: 'reassess_support', client: $client, medication: null, administration: null, ownerId: null,
            dueAt: now('Pacific/Auckland')->addDays(7)->utc(),
            context: ['support_assessment_id' => $assessment->id, 'support_trigger' => $kind, 'reason' => $reason],
        );
    }

    public function completed(MedicationSelfAdminAssessment $assessment, User $actor, int $reassessmentId): void
    {
        $service = app(MedicationFollowupService::class);
        $rows = $service->visibleQuery($actor)->where('client_id', $assessment->client_id)->where('type', 'reassess_support')
            ->where('context->support_assessment_id', $assessment->id)->whereNull('completed_at')->orderBy('id')->get();
        foreach ($rows as $row) {
            $service->completeFromSource($row->source_key, (int) $actor->id, ['support_reassessment_id' => $reassessmentId]);
        }
    }
}
