<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ServiceContext;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Clients\ClientContactPreferences;
use App\Services\Clients\ClientIdentityWrites;
use App\Services\Clients\ClientOnboardingAccess;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Gate A only. Neither person creation nor an onboarding handover starts a service. */
final class ReferralConversionService
{
    public const CANONICAL_PERMISSIONS = ['clients.create', 'clients.viewAny', 'clients.viewAssigned', 'clients.update', 'clinical.accessAllSites', 'sites.viewAll', 'onboarding.create', 'onboarding.edit', 'onboarding.view', 'onboarding.viewAny'];

    public function __construct(private readonly ReferralCommand $command, private readonly ReferralAccess $access, private readonly ReferralHandoverPreview $preview) {}

    public function convert(User $actor, int $id, int $version, array $input): array
    {
        $data = Validator::make($input, [
            'target_id' => 'present|nullable|integer|min:1', 'target_fingerprint' => 'present|nullable|string|size:64',
            'service_context_id' => 'required|integer|min:1', 'onboarding_owner_id' => 'required|integer|min:1',
            'identity_review' => 'required|string|min:10|max:3000', 'handover_note' => 'required|string|min:10|max:4000',
            'evidence_ids' => 'required|array|min:1|max:40', 'evidence_ids.*' => 'required|integer|min:1|distinct',
            'duplicate_decisions' => 'present|array|max:25', 'duplicate_decisions.*' => 'required|array:client_id,conclusion,reason',
            'duplicate_decisions.*.client_id' => 'required|integer|min:1|distinct',
            'duplicate_decisions.*.conclusion' => ['required', Rule::in(['same_person', 'different_person'])],
            'duplicate_decisions.*.reason' => 'required|string|min:10|max:2000',
            // A reasoned review of possible matches the reviewer cannot open; their details stay undisclosed.
            'restricted_match_review' => 'sometimes|nullable|array:reason,acknowledged',
            'acknowledge_pending_work' => 'accepted', 'acknowledge_separate_start' => 'accepted',
            'acknowledge_care_handover' => 'accepted',
            'profile_handover' => 'sometimes|array',
            'contact_handover' => 'sometimes|array',
            'document_handover' => 'sometimes|array|max:10',
            'support_contacts' => 'sometimes|array|max:20',
        ])->validate();
        if (! empty($data['restricted_match_review'])) {
            $data['restricted_match_review'] = Validator::make($data['restricted_match_review'], [
                'reason' => 'required|string|min:20|max:2000', 'acknowledged' => 'accepted',
            ])->validate();
        }
        if (array_key_exists('profile_handover', $data)) {
            $data['profile_handover'] = app(ReferralProfileHandover::class)->validate($data['profile_handover']);
        }
        if (array_key_exists('contact_handover', $data)) {
            $data['contact_handover'] = app(ClientContactPreferences::class)->validate($data['contact_handover']);
        }
        $data['target_id'] = $data['target_id'] === null ? null : (int) $data['target_id'];
        if (array_key_exists('support_contacts', $data)) {
            $data['support_contacts'] = app(ReferralSupportContactHandovers::class)->validate($data['support_contacts']);
        }
        if (array_key_exists('document_handover', $data)) {
            $data['document_handover'] = app(ReferralDocumentHandovers::class)->validate($data['document_handover']);
        }
        $data['evidence_ids'] = array_map('intval', $data['evidence_ids']);
        sort($data['evidence_ids']);
        usort($data['duplicate_decisions'], fn ($a, $b) => $a['client_id'] <=> $b['client_id']);
        $intent = hash('sha256', json_encode(Arr::sortRecursive($data), JSON_THROW_ON_ERROR));

        return $this->command->run($actor, $id, $version, 'convert', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $intent): array {
            app(ClientIdentityWrites::class)->lock();
            if ($referral->entry_kind === InternalSupportPreparation::KIND) {
                abort_unless($referral->client_id && $data['target_id'] === (int) $referral->client_id, 422, 'Keep this preparation linked to its existing person. It cannot create or switch to another person.');
            }
            $target = $data['target_id'] ? Client::query()->lockForUpdate()->findOrFail($data['target_id']) : null;
            $preview = $this->preview->inspect($actor, $referral, $target?->id, $reads);
            abort_unless($preview['preparation_checks_passed'], 422, 'Complete the current preparation checks before handover.');
            $unresolved = $preview['matches']['restricted_review_required'] || $preview['matches']['more_results'];
            if ($unresolved) {
                abort_unless($this->access->can($actor, 'duplicates.manage'), 422, 'An authorized identity reviewer must resolve all possible matches before handover.');
                abort_if(empty($data['restricted_match_review']), 422, 'Record your review of the possible matches you cannot open, with a reason, before handover. Their details are not shown.');
            }
            if ($target) {
                abort_unless($this->access->can($actor, 'duplicates.manage'), 403);
                abort_unless(is_string($data['target_fingerprint']) && hash_equals(ReferralHandoverPreview::fingerprint($target), $data['target_fingerprint']), 409, 'The person record changed. Compare the current details before linking.');
            }
            $candidateIds = array_column($preview['matches']['candidates'], 'id');
            $reviewIds = array_map('intval', array_column($data['duplicate_decisions'], 'client_id'));
            sort($candidateIds);
            sort($reviewIds);
            abort_unless($candidateIds === $reviewIds, 409, 'Possible identity matches changed. Review the current candidate list.');
            if ($reviewIds !== []) {
                abort_unless($this->access->can($actor, 'duplicates.manage'), 403);
            }
            foreach ($data['duplicate_decisions'] as $decision) {
                abort_unless(($decision['conclusion'] === 'same_person') === ($target && (int) $decision['client_id'] === (int) $target->id), 422, 'The identity decisions must agree with the selected person. Never merge people by name alone.');
            }
            $settings = ReferralSettingsVersion::query()->where('state', 'published')->where('effective_at', '<=', now())->orderByDesc('version')->lockForUpdate()->first();
            abort_unless(collect($settings?->configuration['services'] ?? [])->contains(fn ($service) => $service['enabled'] && $service['id'] === $referral->requested_service), 422, 'The requested service is no longer enabled.');
            $context = ReferralServiceSettings::contexts(ServiceContext::query()->availableToSite($referral->site_id)->where('is_active', true),
                $settings?->configuration ?? [], $referral->requested_service)->lockForUpdate()->findOrFail($data['service_context_id']);
            $owner = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $data['onboarding_owner_id'], [...array_column(ReferralPermissions::definitions(), 'key'), ...self::CANONICAL_PERMISSIONS]);
            abort_unless(app(ReferralOnboardingOwners::class)->eligible($owner, $referral->site_id, $referral->restricted, $target, $reads), 422, 'Choose current eligible staff who can open the person and view and manage onboarding.');
            $documents = ReferralEvidence::query()->where('referral_id', $referral->id)->where('audience', 'assessment')->whereIn('id', $data['evidence_ids'])->lockForUpdate()->get();
            abort_unless($documents->count() === count($data['evidence_ids']), 422, 'Choose assessment evidence belonging to this referral.');
            foreach ($documents as $document) {
                abort_unless($document->state === 'available' && empty($document->metadata['superseded_by_id'])
                    && (empty($document->metadata['expires_at']) || CarbonImmutable::parse($document->metadata['expires_at'])->isFuture())
                    && app(ReferralEvidenceFiles::class)->bytes($document) !== null, 422, 'Handover evidence must be current, available and intact.');
            }
            // Retain every outstanding action. Reject stranded owners before transferring responsibility.
            $openWork = ReferralWorkItem::query()->where('referral_id', $referral->id)->where('status', 'open')->lockForUpdate()->get();
            $primaryWork = $openWork->firstWhere('id', $referral->primary_work_item_id);
            $nextAction = ['work_item_id' => $primaryWork?->id, 'owner_id' => $primaryWork?->owner_id ?? $referral->owner_id,
                'title' => $primaryWork?->details['title'] ?? $referral->next_action,
                'due_at' => ($primaryWork?->due_at ?? $referral->next_due_at)?->toISOString()];
            foreach ($openWork->groupBy('owner_id') as $staffId => $items) {
                abort_unless($staffId, 422, 'Assign every outstanding action before handover.');
                $staff = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $staffId, array_column(ReferralPermissions::definitions(), 'key'));
                abort_unless($this->access->eligibleForSite($staff, $referral->site_id, $reads) && (! $referral->restricted || $this->access->can($staff, 'restricted')), 422, 'An outstanding action owner needs reassignment before handover.');
                foreach ($items as $item) {
                    abort_unless($this->access->audience($staff, $item->audience), 422, 'An outstanding action owner no longer has access to its information.');
                }
            }
            $fundingOwners = ReferralFundingCase::query()->where('referral_id', $referral->id)->whereNotIn('status', ['closed', 'withdrawn'])->lockForUpdate()->pluck('navigator_id')->unique()->sort();
            foreach ($fundingOwners as $staffId) {
                abort_unless($staffId, 422, 'Assign a navigator to every open funding case before handover.');
                $navigator = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $staffId, array_column(ReferralPermissions::definitions(), 'key'));
                abort_unless($this->access->eligibleForSite($navigator, $referral->site_id, $reads) && $this->access->can($navigator, 'funding.manage')
                    && (! $referral->restricted || $this->access->can($navigator, 'restricted')), 422, 'An open funding case needs a current eligible navigator before handover.');
            }
            $created = $target === null;
            $person = $target ?? Client::query()->create([
                ...Arr::only($referral->identity, ['first_name', 'last_name', 'preferred_name', 'date_of_birth']),
                'site_id' => $referral->site_id, 'service_context_id' => $context->id, 'status' => 'onboarding',
            ]);
            $profileMappings = app(ReferralProfileHandover::class)->apply($person, $data['profile_handover'] ?? null);
            $supportContacts = app(ReferralSupportContactHandovers::class)->apply($actor, $person, $referral, $data['support_contacts'] ?? [], $reads);
            $documentMappings = app(ReferralDocumentHandovers::class)->apply($actor, $person, $referral, $documents, $data['document_handover'] ?? [], $reads);
            $contactMapping = isset($data['contact_handover'])
                ? app(ClientContactPreferences::class)->apply($person, $data['contact_handover'], $actor, true) : null;
            abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($owner, $person, $reads), 422, 'The onboarding owner must be able to open the canonical person record.');
            $scope = ! $created && $person->status === 'active' ? 'additional_support' : 'person';
            $workflow = ClientOnboardingWorkflow::query()->where('client_id', $person->id)->where('status', 'in_progress')
                ->where(fn ($query) => $query->whereNull('source_referral_id')->orWhere('source_referral_id', $referral->id))
                ->where('scope', $scope)->where(function ($query) use ($scope, $context, $referral): void {
                    $query->where('service_context_id', $context->id)->where('site_id', $referral->site_id);
                    if ($scope === 'person') {
                        $query->orWhere(fn ($legacy) => $legacy->whereNull('service_context_id')->whereNull('source_referral_id'));
                    }
                })->orderBy('id')->lockForUpdate()->first();
            $workflow ??= ClientOnboardingWorkflow::createForClient($person, $actor->id, ['scope' => $scope, 'service_context_id' => $context->id, 'site_id' => $referral->site_id, 'source_referral_id' => $referral->id]);
            if (! $workflow->source_referral_id) {
                $workflow->update(['scope' => $scope, 'service_context_id' => $context->id, 'site_id' => $referral->site_id, 'source_referral_id' => $referral->id]);
            }
            if (! $workflow->assigned_to) {
                $workflow->update(['assigned_to' => $owner->id]);
            } else {
                abort_unless((int) $workflow->assigned_to === (int) $owner->id, 409, 'The existing onboarding workflow has another owner. Review that assignment before handover.');
            }
            $proposal = ReferralProposal::query()->where('referral_id', $referral->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $conversion = ReferralConversion::create(['referral_id' => $referral->id, 'client_id' => $person->id, 'workflow_id' => $workflow->id,
                'converted_by' => $actor->id, 'created_person' => $created, 'converted_at' => now(), 'receipt' => [
                    'intent_hash' => $intent, 'review' => $data, 'fields' => $preview['fields'], 'assessment' => $referral->assessment,
                    'profile_mappings' => $profileMappings,
                    'support_contacts' => $supportContacts,
                    'document_mappings' => $documentMappings,
                    'contact_mapping' => $contactMapping,
                    // Explicitly released care facts only. Private funding, identity-review notes,
                    // referrer contacts and application documents never enter this projection.
                    'care_summary' => Arr::only($referral->assessment, ['goals', 'participation', 'safety_needs']),
                    'assessment_version' => $referral->assessment['version'],
                    'authority_version' => $referral->authority['review']['version'], 'settings_version_id' => $settings->id,
                    'proposal_id' => $proposal->id, 'proposal_version' => $proposal->version, 'funding_position' => $proposal->proposal['funding_position'],
                    'service_context_id' => $context->id, 'site_id' => $referral->site_id, 'workflow_scope' => $scope,
                    'evidence' => $documents->map(fn ($document) => ['id' => $document->id, 'sha256' => $document->sha256])->all(),
                    'open_work' => $openWork->map(fn ($work) => ['id' => $work->id, 'owner_id' => $work->owner_id, 'due_at' => $work->due_at->toISOString()])->all(),
                    'next_action' => $nextAction,
                    'person_status_preserved' => $target?->status, 'service_commenced' => false,
                    'restricted_match_review' => $unresolved ? ['reason' => $data['restricted_match_review']['reason'], 'reviewed_by' => $actor->id, 'reviewed_at' => now()->toISOString()] : null,
                ]]);
            $previousStage = $referral->stage;
            $referral->fill(['client_id' => $person->id, 'stage' => 'converted', 'stage_changed_at' => now(), 'converted_at' => now()]);
            $this->command->record($referral, $actor, 'handover.committed', 'intake', ['conversion_id' => $conversion->id, 'previous_stage' => $previousStage, 'created_person' => $created, 'open_work_count' => $openWork->count(), 'service_commenced' => false,
                'restricted_matches_reviewed' => $unresolved]);
            ReferralOutbox::create(['referral_id' => $referral->id, 'deduplication_key' => 'conversion:'.$referral->id, 'kind' => 'handover_committed', 'available_at' => now(),
                'message' => ['owner_id' => $owner->id, 'reference' => $referral->reference, 'conversion_id' => $conversion->id]]);

            return $this->receipt($actor, $conversion, $reads);
        }, self::CANONICAL_PERMISSIONS, function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($intent): ?array {
            $existing = ReferralConversion::query()->where('referral_id', $referral->id)->lockForUpdate()->first();
            if (! $existing) {
                return null;
            }
            abort_unless($this->access->can($actor, 'assessment.view') && $this->access->can($actor, 'funding.view'), 403);
            abort_unless(hash_equals($existing->receipt['intent_hash'] ?? '', $intent), 409, 'This referral already has a handover. Open its receipt instead of creating another person.');

            return $this->receipt($actor, $existing, $reads);
        });
    }

    private function receipt(User $actor, ReferralConversion $conversion, CurrentAuthorizationReads $reads): array
    {
        $client = Client::query()->find($conversion->client_id);
        $visible = $client && app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $client, $reads);

        return ['saved' => true, 'conversion_id' => $conversion->id, 'converted_at' => $conversion->converted_at->toISOString(),
            'created_person' => $conversion->created_person, 'person' => $visible ? ['id' => $client->id, 'name' => $client->full_name] : null,
            'workflow_id' => $visible && app(ClientOnboardingAccess::class)->canViewWorkflows($actor) ? $conversion->workflow_id : null, 'service_commenced' => false];
    }
}
