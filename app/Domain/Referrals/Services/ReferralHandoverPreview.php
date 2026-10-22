<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\Clients\ClientContactPreferences;
use App\Services\Clients\ClientOnboardingAccess;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/** Read-only preparation. A preview is never an authorization to convert or commence. */
final class ReferralHandoverPreview
{
    public function __construct(private readonly ReferralAccess $access) {}

    public function inspect(User $actor, CareReferral $referral, ?int $targetId = null, ?CurrentAuthorizationReads $reads = null): array
    {
        $this->access->authorize($actor, $referral, 'convert', $reads);
        abort_unless($this->access->can($actor, 'assessment.view') && $this->access->can($actor, 'funding.view'), 403);
        $boundPerson = $referral->entry_kind === InternalSupportPreparation::KIND;
        if ($boundPerson) {
            abort_unless($referral->client_id && ($targetId === null || $targetId === (int) $referral->client_id), 422, 'Internal preparation must remain linked to its existing person.');
            $targetId = (int) $referral->client_id;
        }
        // Linking requires both canonical read and update access. Referral scope is insufficient.
        $target = $targetId ? Client::query()->findOrFail($targetId) : null;
        if ($target) {
            abort_unless($reads ? app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $target, $reads) : app(ClientPolicy::class)->view($actor, $target), 404);
            abort_unless($reads ? app(ClientPolicy::class)->updateFromCurrentEvidence($actor, $target, $reads) : app(ClientPolicy::class)->update($actor, $target), 403);
        }
        $assessment = $referral->assessment ?? [];
        $authority = $referral->authority['review'] ?? [];
        $settings = app(ReferralSettings::class)->effective($reads);
        $proposal = app(ReferralProposals::class)->latest($referral);
        $canonicalSites = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['clinical.accessAllSites', 'sites.viewAll'], $reads);
        $checks = [];
        $check = function (string $key, string $label, bool $passed, string $detail, string $section) use (&$checks): void {
            $checks[] = compact('key', 'label', 'passed', 'detail', 'section');
        };
        $check('open', 'Referral is open', ! in_array($referral->stage, ['closed', 'converted'], true) && ! $referral->converted_at,
            'Closed referrals must be reopened. A completed handover must reuse its receipt.', 'summary');
        $check('person', 'Person details are ready', ! $referral->general_enquiry && filled($referral->identity['first_name'] ?? null) && filled($referral->identity['last_name'] ?? null),
            'A process enquiry cannot create a person. Review the person’s names; a birth date may remain unknown.', 'summary');
        $check('form_answers', 'Required form answers are recorded', app(ReferralForms::class)->missing($referral) === 0,
            'Complete the required questions in Additional information. Restricted questions must be completed by a colleague with the appropriate access.', 'summary');
        $check('checklists', 'Preparation checklists are complete', app(ReferralChecklists::class)->missing($referral, 'handover') === 0,
            'Complete the required preparation steps. Checklist completion does not replace authority, assessment or funding decisions.', 'summary');
        $check('authority', 'Current authority to prepare support', ReferralAssessment::scope($referral, 'collect') && ReferralAssessment::scope($referral, 'service'),
            'Collection and service authority must both be current and reviewed.', 'assessment');
        $check('assessment', 'Assessment is current', ! empty($assessment['version']) && empty($assessment['invalidated_at'])
            && ($assessment['authority_version'] ?? null) === ($authority['version'] ?? null)
            && ! empty($assessment['review_due_at']) && CarbonImmutable::parse($assessment['review_due_at'])->isFuture(),
            'Review the assessment again after identity, requested support or authority changes.', 'assessment');
        $check('identity', 'Identity has been reviewed', ($assessment['identity_status'] ?? '') === 'verified',
            'A staff-reviewed identity basis is required; a matching name alone does not verify identity.', 'assessment');
        $check('intention', 'Person intends to proceed', ($assessment['intention'] ?? '') === 'proceed',
            'Record the person’s intention and supported participation or the documented lawful basis.', 'assessment');
        $check('suitability', 'Provider suitability accepted', ($assessment['suitability'] ?? '') === 'suitable',
            'The provider must accept suitability before preparing onboarding.', 'assessment');
        $check('care_handover', 'Care information has been reviewed', filled($assessment['goals'] ?? null) && filled($assessment['participation'] ?? null) && filled($assessment['safety_needs'] ?? null),
            'Record goals, participation and safety needs for the onboarding team. Record an explicit review when no specific need was identified.', 'assessment');
        $check('site', 'Approved site and team selected', $referral->site_id !== null && $this->access->site($actor, $referral->site_id, $reads)
            && in_array($referral->site_id, $canonicalSites, true) && filled($referral->team),
            'Both referral and person-record site access are required for the selected team.', 'summary');
        $check('service', 'Requested support is enabled', collect($settings?->configuration['services'] ?? [])->contains(fn ($service) => $service['id'] === $referral->requested_service && $service['enabled']),
            'The requested service must remain enabled in current published settings.', 'summary');
        $owner = $referral->owner_id ? User::query()->find($referral->owner_id) : null;
        // Standalone next actions are canonical calendar/task entries too. Stage and
        // next-action edits intentionally detach them from an earlier work item.
        $standaloneNext = ! $referral->primary_work_item_id && $referral->owner_id !== null
            && filled($referral->next_action) && $referral->next_due_at !== null;
        $check('owner', 'Accountable owner remains eligible', $owner && $this->access->eligibleForSite($owner, $referral->site_id, $reads)
            && (! $referral->restricted || $this->access->can($owner, 'restricted')),
            'A current staff member must remain responsible for the referral.', 'summary');
        $check('funding_position', 'Funding position is explicit', filled($proposal?->proposal['funding_position'] ?? null),
            'Record a support proposal with the current funding position. Pending funding does not prevent preparation.', 'proposals');
        $check('next_action', 'Next step has an owner and date', $standaloneNext || ($referral->primary_work_item_id !== null && ReferralWorkItem::query()
            ->where('referral_id', $referral->id)->whereKey($referral->primary_work_item_id)->where('status', 'open')
            ->whereNotNull('owner_id')->whereNotNull('due_at')->exists()),
            'Keep an open, assigned next action through handover; funding work is not silently completed.', 'work');
        $check('canonical_permission', $target ? 'Existing person can be linked' : 'Person creation is permitted',
            $target !== null || $actor->canDo('clients.create'), 'The canonical person permissions apply separately from referral permissions.', 'summary');

        $matches = $this->matches($actor, $referral, $reads);
        $conversion = ReferralConversion::query()->where('referral_id', $referral->id)->first();
        $linked = $conversion ? Client::query()->find($conversion->client_id) : null;
        $linkedVisible = $linked && app(ClientPolicy::class)->view($actor, $linked);
        $funding = ReferralFundingCase::query()->where('referral_id', $referral->id);

        return [
            'reference' => $referral->reference, 'version' => $referral->lock_version, 'checked_at' => now()->toISOString(),
            'preparation_checks_passed' => collect($checks)->every('passed'), 'checks' => $checks, 'matches' => $matches,
            'target_bound' => $boundPerson,
            'target' => $target ? ['id' => $target->id, 'name' => $target->full_name, 'status' => $target->status,
                'site_id' => $target->site_id, 'fingerprint' => self::fingerprint($target), 'existing_details_preserved' => true] : null,
            'fields' => $this->fields($referral, $target),
            'profile_fields' => app(ReferralProfileHandover::class)->preview($target),
            'contact_profile' => app(ClientContactPreferences::class)->preview($target),
            'people_contacts' => app(ReferralPeopleContacts::class)->present($actor, $referral),
            'can_release_contacts' => $this->access->audience($actor, 'assessment', true)
                && ($target !== null || $actor->canDo('clients.update')),
            'can_release_documents' => $this->access->can($actor, 'evidence.manage') && $this->access->audience($actor, 'assessment', true)
                && ($target !== null || $actor->canDo('clients.update')),
            'service_contexts' => $referral->site_id && in_array($referral->site_id, $canonicalSites, true)
                ? ReferralServiceSettings::contexts(ServiceContext::query()->availableToSite($referral->site_id)->where('is_active', true), $settings?->configuration ?? [], $referral->requested_service)
                    ->orderBy('name')->get(['id', 'name', 'type'])->map(fn ($context) => ['id' => $context->id, 'name' => $context->name, 'type' => $context->type->label()])->all() : [],
            'funding' => ['position' => $proposal?->proposal['funding_position'] ?? null, 'proposal_version' => $proposal?->version,
                'open_cases' => (clone $funding)->whereNotIn('status', ['closed', 'withdrawn'])->count(),
                'personal_income_cases' => (clone $funding)->where('personal_income', true)->count(),
                'proposal_readiness' => $proposal ? app(ReferralProposalReadiness::class)->inspect($referral, $proposal) : null],
            'open_work' => ReferralWorkItem::query()->where('referral_id', $referral->id)->where('status', 'open')->count() + ($standaloneNext ? 1 : 0),
            'conversion' => $conversion ? ['converted_at' => $conversion->converted_at->toISOString(), 'created_person' => $conversion->created_person,
                'can_refresh_care' => $linkedVisible && app(ClientPolicy::class)->update($actor, $linked),
                'workflow_id' => $linkedVisible && app(ClientOnboardingAccess::class)->canViewWorkflows($actor)
                    && in_array((int) ($conversion->receipt['site_id'] ?? 0), $canonicalSites, true) ? $conversion->workflow_id : null,
                'person' => $linkedVisible ? ['id' => $linked->id, 'name' => $linked->full_name] : null] : null,
        ];
    }

    private function fields(CareReferral $referral, ?Client $target): array
    {
        $fields = [];
        foreach (['first_name' => 'Given name', 'last_name' => 'Family name', 'preferred_name' => 'Preferred name', 'date_of_birth' => 'Date of birth'] as $key => $label) {
            $current = $target?->getAttribute($key);
            $fields[] = ['key' => $key, 'label' => $label, 'submitted' => $referral->identity[$key] ?? null,
                'current' => $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current,
                'destination' => $target ? 'Retain existing person value; review any difference separately' : 'New person profile after final review'];
        }
        // The website callback contact may be a referrer. Never treat it as the person's own phone/email.
        $fields[] = ['key' => 'contact', 'label' => 'Submitted callback contact',
            'submitted' => Arr::only($referral->intake['submitted_contact'] ?? [], ['name', 'phone', 'email', 'channel', 'safe_time', 'leave_message', 'communication']),
            'current' => null, 'destination' => 'Keep with referral and safe-contact instructions; verify before creating a person contact'];
        $fields[] = ['key' => 'goals', 'label' => 'Goals reviewed in assessment', 'submitted' => $referral->assessment['goals'] ?? null,
            'current' => null, 'destination' => 'Onboarding handover for care-plan review'];
        $fields[] = ['key' => 'participation', 'label' => 'Supported participation', 'submitted' => $referral->assessment['participation'] ?? null,
            'current' => null, 'destination' => 'Onboarding handover for consent and communication review'];
        $fields[] = ['key' => 'safety_needs', 'label' => 'Safety and support needs', 'submitted' => $referral->assessment['safety_needs'] ?? null,
            'current' => null, 'destination' => 'Onboarding handover for care and safety planning'];

        return $fields;
    }

    private function matches(User $actor, CareReferral $referral, ?CurrentAuthorizationReads $reads): array
    {
        $first = mb_strtolower(trim((string) ($referral->identity['first_name'] ?? '')));
        $last = mb_strtolower(trim((string) ($referral->identity['last_name'] ?? '')));
        if ($first === '' || $last === '') {
            return ['checked' => false, 'candidates' => [], 'review_required' => true, 'restricted_review_required' => false, 'more_results' => false, 'can_record_restricted_review' => false];
        }
        // Bounded candidate discovery, not identity confirmation. No contact-email matching.
        // Include archived identities in the private check so they are not silently recreated.
        $query = Client::withTrashed()->whereRaw('LOWER(TRIM(first_name)) = ?', [$first])->whereRaw('LOWER(TRIM(last_name)) = ?', [$last]);
        $candidates = [];
        $restricted = false;
        $records = ($reads ? $reads->query($query) : $query)->orderBy('id')->limit(26)->get();
        $reviewer = $this->access->can($actor, 'duplicates.manage');
        foreach ($records->take(25) as $client) {
            if (! $reviewer || ! ($reads ? app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $client, $reads) : app(ClientPolicy::class)->view($actor, $client))) {
                $restricted = true;

                continue;
            }
            // An archived person the reviewer may open is shown as archived; it cannot be linked until restored.
            $candidates[] = ['id' => $client->id, 'name' => $client->full_name, 'date_of_birth' => $client->date_of_birth?->toDateString(),
                'archived' => $client->trashed(),
                'can_link' => ! $client->trashed() && ($reads ? app(ClientPolicy::class)->updateFromCurrentEvidence($actor, $client, $reads) : app(ClientPolicy::class)->update($actor, $client))];
        }

        // Matches the reviewer cannot open (or beyond the bounded list) are never described. An
        // identity reviewer may record a reasoned decision to proceed; others must hand it to one.
        return ['checked' => true, 'candidates' => $candidates, 'review_required' => $records->isNotEmpty(),
            'restricted_review_required' => $restricted, 'more_results' => $records->count() > 25,
            'can_record_restricted_review' => $reviewer && ($restricted || $records->count() > 25)];
    }

    public static function fingerprint(Client $client): string
    {
        $facts = [];
        foreach (['id', 'first_name', 'last_name', 'preferred_name', 'date_of_birth', 'nhi_number', 'nhi_hash', 'phone', 'email', 'site_id', 'service_context_id', 'status', 'updated_at'] as $key) {
            $facts[$key] = $client->getRawOriginal($key);
        }
        $facts['date_of_birth'] = $client->date_of_birth?->toDateString();
        $facts['contact_preferences'] = $client->getRawOriginal('contact_preferences');
        foreach (array_keys(ReferralProfileHandover::LABELS) as $key) {
            $facts[$key] = $client->getRawOriginal($key);
        }
        foreach (['id', 'site_id', 'service_context_id'] as $key) {
            $facts[$key] = $facts[$key] === null ? null : (int) $facts[$key];
        }

        return hash_hmac('sha256', json_encode($facts, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
