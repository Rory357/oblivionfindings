<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\Validator;

/** Explicit adoption of the current publication; original decisions remain historical evidence. */
final class ReferralSettingsMigration
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralCommand $command) {}

    private function authorize(User $actor, CareReferral $referral, ?CurrentAuthorizationReads $reads = null): void
    {
        foreach (['settings.governance', 'settings.view', 'edit', 'assessment.manage', 'funding.manage'] as $permission) {
            $this->access->authorize($actor, $referral, $permission, $reads);
        }
    }

    public function preview(User $actor, CareReferral $referral, ?CurrentAuthorizationReads $reads = null): array
    {
        $this->authorize($actor, $referral, $reads);
        $target = app(ReferralSettings::class)->effective($reads);
        abort_unless($target, 422, 'Publish reviewed settings before migrating a referral.');
        $from = ReferralSettingsVersion::findOrFail($referral->settings_version_id);
        $query = ReferralFundingCase::query()->where('referral_id', $referral->id)->whereNotIn('status', ['closed', 'withdrawn'])->orderBy('id');
        $cases = ($reads ? $reads->query($query) : $query)->get();
        $issues = [];
        if ($referral->stage === 'closed') {
            $issues[] = 'Reopen the referral before changing its working configuration.';
        }
        $service = collect($target->configuration['services'])->firstWhere('id', $referral->requested_service);
        if (! $referral->general_enquiry && $referral->requested_service !== 'unsure' && ! ($service['enabled'] ?? false)) {
            $issues[] = 'The requested service is not enabled in the current publication. Review the service before migration.';
        }
        $funding = [];
        foreach ($cases as $case) {
            $guide = collect($target->configuration['pathways'])->firstWhere('id', $case->pathway);
            if (! ($guide['enabled'] ?? false)
                || (bool) ($guide['personal_income'] ?? false) !== $case->personal_income
                || (! $case->personal_income && ! in_array($case->details['service_id'] ?? null, $guide['service_ids'] ?? [], true))) {
                $issues[] = 'An open funding case is incompatible with this publication. Resolve its pathway or service before migration.';
            }
            $funding[] = ['id' => $case->id, 'version' => $case->lock_version, 'from_settings_id' => $case->settings_version_id,
                'pathway' => $case->details['pathway_label'] ?? $case->pathway, 'next_label' => $guide['label'] ?? 'Unavailable',
                'previous_checklist' => $case->details['guide']['evidence_checklist'] ?? [], 'next_checklist' => $guide['evidence_checklist'] ?? [],
                'changes' => (int) $case->settings_version_id !== (int) $target->id];
        }
        $changes = (int) $from->id !== (int) $target->id || collect($funding)->contains('changes', true);
        if (! $changes) {
            $issues[] = 'This referral and its open funding cases already use the current publication.';
        }
        $token = hash('sha256', json_encode([$referral->id, $referral->lock_version, $from->id, $target->id, $target->configuration, $funding], JSON_THROW_ON_ERROR));

        return ['version' => $referral->lock_version, 'from' => ['id' => $from->id, 'version' => $from->version],
            'target' => ['id' => $target->id, 'version' => $target->version, 'reason' => $target->reason, 'effective_at' => $target->effective_at?->toISOString()],
            'configuration_before' => $from->configuration, 'configuration_after' => $target->configuration,
            'funding' => $funding, 'issues' => array_values(array_unique($issues)), 'ready' => $issues === [], 'preview_token' => $token];
    }

    public function apply(User $actor, int $id, int $version, array $input): array
    {
        $data = Validator::make($input, ['target_id' => 'required|integer|min:1', 'preview_token' => 'required|string|size:64',
            'reason' => 'required|string|min:10|max:2000', 'acknowledge_reviews' => 'required|accepted', 'submission_key' => 'required|uuid'])->validate();
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $this->command->run($actor, $id, $version, 'settings.governance', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $requestHash): array {
            $preview = $this->preview($actor, $referral, $reads);
            abort_unless($preview['target']['id'] === (int) $data['target_id'] && hash_equals($preview['preview_token'], $data['preview_token']), 409, 'The publication or referral changed. Refresh the migration preview.');
            abort_unless($preview['ready'], 422, implode(' ', $preview['issues']));
            $target = ReferralSettingsVersion::findOrFail($data['target_id']);
            foreach ($preview['funding'] as $item) {
                if (! $item['changes']) {
                    continue;
                }
                $case = ReferralFundingCase::query()->where('referral_id', $referral->id)->lockForUpdate()->findOrFail($item['id']);
                $guide = collect($target->configuration['pathways'])->firstWhere('id', $case->pathway);
                $details = $case->details;
                $previous = ['settings_version_id' => $case->settings_version_id, 'guide' => $details['guide'] ?? null, 'verification' => $details['verification'] ?? null];
                if (isset($details['verification'])) {
                    $details['verification'] = [...$details['verification'], 'status' => 'review_required'];
                }
                $case->update(['settings_version_id' => $target->id, 'lock_version' => $case->lock_version + 1,
                    'details' => [...$details, 'guide' => $guide, 'pathway_label' => $guide['label']]]);
                $this->command->record($referral, $actor, 'funding.settings_migrated', 'funding', ['case_id' => $case->id, 'previous' => $previous,
                    'target_settings_id' => $target->id, 'reason' => $data['reason']]);
            }
            $referral->settings_version_id = $target->id;
            app(ReferralAssessment::class)->invalidateReadiness($referral, $actor, 'settings migration');
            $this->command->record($referral, $actor, 'settings.migrated', 'intake', ['from_settings_id' => $preview['from']['id'], 'target_settings_id' => $target->id,
                'reason' => $data['reason'], 'submission_key' => $data['submission_key'], 'request_hash' => $requestHash]);

            return ['saved' => true, 'target_id' => $target->id];
        }, replay: function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $requestHash): ?array {
            $this->authorize($actor, $referral, $reads);
            $event = $reads->query(ReferralEvent::query()->where('referral_id', $referral->id)->where('kind', 'settings.migrated'))->get()
                ->first(fn ($event) => ($event->payload['submission_key'] ?? null) === $data['submission_key']);
            if (! $event) {
                return null;
            }
            abort_unless((int) $event->actor_id === (int) $actor->id && hash_equals($event->payload['request_hash'], $requestHash), 409, 'This migration request was already used for a different decision.');

            return ['saved' => true, 'target_id' => $event->payload['target_settings_id']];
        });
    }
}
