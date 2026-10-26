<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralCareHandover;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/** Updating care information never repeats person conversion or approves a service start. */
final class ReferralCareHandoverRevisions
{
    public function __construct(private readonly ReferralCommand $command) {}

    public function preview(User $actor, CareReferral $referral): array
    {
        app(ReferralAccess::class)->authorize($actor, $referral, 'convert');
        abort_unless(app(ReferralAccess::class)->can($actor, 'assessment.view'), 403);
        $conversion = ReferralConversion::query()->where('referral_id', $referral->id)->firstOrFail();
        $person = $this->canonicalAccess($actor, $conversion);
        $workflow = ClientOnboardingWorkflow::query()->findOrFail($conversion->workflow_id);
        $latest = ReferralCareHandover::query()->where('conversion_id', $conversion->id)->orderByDesc('version')->first();

        return ['referral_version' => $referral->lock_version, 'published_version' => $latest?->version ?? 1,
            'assessment_version' => $referral->assessment['version'] ?? null,
            'authority_version' => $referral->authority['review']['version'] ?? null,
            'people_contacts' => app(ReferralPeopleContacts::class)->present($actor, $referral),
            'can_release_contacts' => app(ReferralAccess::class)->audience($actor, 'assessment', true),
            'can_release_documents' => app(ReferralAccess::class)->can($actor, 'evidence.manage') && app(ReferralAccess::class)->audience($actor, 'assessment', true),
            'person' => ['id' => $person->id, 'name' => $person->full_name, 'fingerprint' => ReferralHandoverPreview::fingerprint($person)],
            'current_review_ready' => $referral->stage === 'converted' && self::reviewReady($referral) && $this->destinationReady($referral, $conversion, $workflow),
            'care' => Arr::only($referral->assessment ?? [], ['goals', 'participation', 'safety_needs']),
            'published_at' => ($latest?->published_at ?? $conversion->converted_at)->toISOString()];
    }

    public function publish(User $actor, int $referralId, int $version, array $input): array
    {
        $data = Validator::make($input, [
            'submission_key' => 'required|uuid', 'acknowledge_care_handover' => 'accepted',
            'reason' => 'required|string|min:10|max:3000', 'assessment_version' => 'required|integer|min:1',
            'authority_version' => 'required|integer|min:1',
            'person_fingerprint' => 'required|string|size:64',
            'evidence_ids' => 'required|array|min:1|max:40', 'evidence_ids.*' => 'required|integer|min:1|distinct',
            'document_handover' => 'sometimes|array|max:10',
            'support_contacts' => 'sometimes|array|max:20',
        ])->validate();
        $data['evidence_ids'] = array_map('intval', $data['evidence_ids']);
        if (array_key_exists('support_contacts', $data)) {
            $data['support_contacts'] = app(ReferralSupportContactHandovers::class)->validate($data['support_contacts']);
        }
        if (array_key_exists('document_handover', $data)) {
            $data['document_handover'] = app(ReferralDocumentHandovers::class)->validate($data['document_handover']);
        }
        sort($data['evidence_ids']);
        $intent = hash('sha256', json_encode(Arr::sortRecursive($data), JSON_THROW_ON_ERROR));
        $replay = function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $intent): ?array {
            abort_unless(app(ReferralAccess::class)->can($actor, 'assessment.view'), 403);
            $conversion = ReferralConversion::query()->where('referral_id', $referral->id)->lockForUpdate()->firstOrFail();
            $this->canonicalAccess($actor, $conversion, $reads);
            $previous = ReferralCareHandover::query()->where('conversion_id', $conversion->id)->where('submission_key', $data['submission_key'])->lockForUpdate()->first();
            if (! $previous) {
                return null;
            }
            abort_unless(hash_equals($previous->intent_hash, $intent), 409, 'This request has already published different care information. Refresh before starting another review.');

            return $this->receipt($previous);
        };

        return $this->command->run($actor, $referralId, $version, 'convert', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $intent): array {
            abort_unless($referral->stage === 'converted' && self::reviewReady($referral), 422, 'Complete the current assessment and authority review before publishing care information.');
            abort_unless((int) $data['assessment_version'] === (int) $referral->assessment['version']
                && (int) $data['authority_version'] === (int) $referral->authority['review']['version'], 409, 'The reviewed assessment or authority changed. Compare the current information before sharing.');
            $conversion = ReferralConversion::query()->where('referral_id', $referral->id)->lockForUpdate()->firstOrFail();
            $person = $this->canonicalAccess($actor, $conversion, $reads);
            abort_unless(hash_equals(ReferralHandoverPreview::fingerprint($person), $data['person_fingerprint']), 409, 'The linked person record changed. Confirm the current person before sharing care information.');
            $workflow = ClientOnboardingWorkflow::query()->lockForUpdate()->findOrFail($conversion->workflow_id);
            abort_unless($this->destinationReady($referral, $conversion, $workflow), 422, 'The onboarding destination changed. Review the canonical handover before sharing more information.');
            $documents = ReferralEvidence::query()->where('referral_id', $referral->id)->where('audience', 'assessment')->whereIn('id', $data['evidence_ids'])->lockForUpdate()->get();
            abort_unless($documents->count() === count($data['evidence_ids']), 422, 'Select assessment evidence belonging to this referral.');
            foreach ($documents as $document) {
                abort_unless($document->state === 'available' && empty($document->metadata['superseded_by_id'])
                    && (empty($document->metadata['expires_at']) || CarbonImmutable::parse($document->metadata['expires_at'])->isFuture())
                    && app(ReferralEvidenceFiles::class)->bytes($document) !== null, 422, 'Selected evidence must still be current, available and intact.');
            }
            $latest = ReferralCareHandover::query()->where('conversion_id', $conversion->id)->orderByDesc('version')->lockForUpdate()->first();
            $documentMappings = app(ReferralDocumentHandovers::class)->apply($actor, $person, $referral, $documents, $data['document_handover'] ?? [], $reads);
            $supportContacts = app(ReferralSupportContactHandovers::class)->apply($actor, $person, $referral, $data['support_contacts'] ?? [], $reads);
            $handover = ReferralCareHandover::create([
                'conversion_id' => $conversion->id, 'version' => ($latest?->version ?? 1) + 1,
                'submission_key' => $data['submission_key'], 'intent_hash' => $intent,
                'published_by' => $actor->id, 'published_at' => now(),
                'snapshot' => ['care_summary' => Arr::only($referral->assessment, ['goals', 'participation', 'safety_needs']),
                    'document_mappings' => $documentMappings,
                    'support_contacts' => $supportContacts,
                    'assessment_version' => $referral->assessment['version'], 'authority_version' => $referral->authority['review']['version'],
                    'review' => Arr::only($data, ['acknowledge_care_handover', 'reason', 'person_fingerprint']),
                    'evidence' => $documents->map(fn ($document) => ['id' => $document->id, 'sha256' => $document->sha256])->all()],
            ]);
            $this->command->record($referral, $actor, 'handover.care_published', 'assessment', ['handover_id' => $handover->id, 'version' => $handover->version, 'reason' => $data['reason']]);

            return $this->receipt($handover);
        }, ReferralConversionService::CANONICAL_PERMISSIONS, $replay);
    }

    public static function reviewReady(CareReferral $referral): bool
    {
        $assessment = $referral->assessment ?? [];

        return ReferralAssessment::scope($referral, 'collect') && ReferralAssessment::scope($referral, 'service')
            && empty($assessment['invalidated_at']) && ! empty($assessment['version'])
            && ($assessment['authority_version'] ?? null) === ($referral->authority['review']['version'] ?? null)
            && ! empty($assessment['review_due_at']) && CarbonImmutable::parse($assessment['review_due_at'])->isFuture()
            && ($assessment['intention'] ?? '') === 'proceed' && ($assessment['suitability'] ?? '') === 'suitable'
            && ($assessment['identity_status'] ?? '') === 'verified'
            && filled($assessment['goals'] ?? null) && filled($assessment['participation'] ?? null) && filled($assessment['safety_needs'] ?? null);
    }

    private function canonicalAccess(User $actor, ReferralConversion $conversion, ?CurrentAuthorizationReads $reads = null): Client
    {
        $query = Client::query()->whereKey($conversion->client_id);
        $person = ($reads ? $reads->query($query) : $query)->firstOrFail();
        $policy = app(ClientPolicy::class);
        abort_unless($reads ? $policy->viewFromCurrentEvidence($actor, $person, $reads) : $policy->view($actor, $person), 404);
        abort_unless($reads ? $policy->updateFromCurrentEvidence($actor, $person, $reads) : $policy->update($actor, $person), 403);

        return $person;
    }

    private function destinationReady(CareReferral $referral, ReferralConversion $conversion, ClientOnboardingWorkflow $workflow): bool
    {
        return $workflow->status !== 'cancelled' && (int) $workflow->client_id === (int) $conversion->client_id
            && (int) $referral->client_id === (int) $conversion->client_id
            && (! $workflow->site_id || (int) $workflow->site_id === (int) $referral->site_id)
            && (! $workflow->service_context_id || (int) $workflow->service_context_id === (int) ($conversion->receipt['service_context_id'] ?? 0))
            && (int) $referral->site_id === (int) ($conversion->receipt['site_id'] ?? 0);
    }

    private function receipt(ReferralCareHandover $handover): array
    {
        return ['saved' => true, 'published_version' => $handover->version, 'published_at' => $handover->published_at->toISOString()];
    }
}
