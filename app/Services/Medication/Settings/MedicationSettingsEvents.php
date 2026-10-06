<?php

namespace App\Services\Medication\Settings;

use App\Models\MedicationOnCallRule;
use App\Models\MedicationSettingChange;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;

/** Called last, after all P11 domain locks, writes, history and ordinary audits. */
final class MedicationSettingsEvents
{
    public function __construct(private readonly MedicationEventRecorder $recorder) {}

    /** @param list<array{site_id: int|null, group: string, key: string, change_id: int}> $changes */
    public function settings(User $actor, string $action, int $revision, array $changes): void
    {
        $siteIds = collect($changes)->pluck('site_id')->filter()->map(fn ($id): int => (int) $id);
        if (collect($changes)->contains(fn (array $change): bool => $change['site_id'] === null)) {
            // Organisation-wide policy affects the approved operational Sites.
            $siteIds = $siteIds->merge(Site::query()->active()->notArchived()->whereNull('archived_at')->orderBy('id')->pluck('id'));
        }
        $now = CarbonImmutable::now('UTC');
        $kept = $action === MedicationSettingChange::ACTION_KEPT;
        $events = $siteIds->unique()->sort()->values()->map(function (int $siteId) use ($actor, $action, $revision, $changes, $now, $kept): MedicationEventData {
            $here = collect($changes)->filter(fn (array $change): bool => $change['site_id'] === null || (int) $change['site_id'] === $siteId)
                ->map(fn (array $change): array => ['group' => $change['group'], 'key' => $change['key'], 'change_id' => $change['change_id']])->values()->all();

            return new MedicationEventData(
                siteId: $siteId, kind: $kept ? 'settings.kept' : 'settings.changed', subjectType: 'medication_settings', subjectId: (string) $revision,
                actorId: (int) $actor->id, occurredAt: $now, summary: $kept ? 'Medication settings defaults reviewed' : 'Medication settings changed',
                facts: ['revision' => $revision, 'action' => $action, 'changes' => $here],
            );
        })->all();
        // One appendMany acquires all head locks in canonical Site order.
        $this->recorder->appendMany($events);
    }

    public function onCall(User $actor, MedicationOnCallRule $rule, MedicationSettingChange $change, bool $removed): void
    {
        $this->recorder->append(new MedicationEventData(
            siteId: (int) $rule->site_id, kind: $removed ? 'settings.oncall_removed' : 'settings.oncall_updated',
            subjectType: 'medication_on_call_rule', subjectId: (string) $rule->id, actorId: (int) $actor->id, occurredAt: CarbonImmutable::now('UTC'),
            summary: $removed ? 'On-call contact removed' : 'On-call contact updated',
            facts: ['change_id' => (int) $change->id, 'action' => $removed ? 'removed' : 'updated'],
        ));
    }
}
