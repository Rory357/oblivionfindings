<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Models\Client;
use App\Models\ClientSupportContact;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/** Release the reviewed role and safe-contact instructions, never emergency status or legal powers. */
final class ReferralSupportContactHandovers
{
    public function validate(array $input): array
    {
        $rows = Validator::make(['contacts' => $input], [
            'contacts' => 'array|max:20',
            'contacts.*' => 'required|array:contact_id,contact_version,purpose,confirmed',
            'contacts.*.contact_id' => 'required|uuid|distinct',
            'contacts.*.contact_version' => 'required|integer|min:1',
            'contacts.*.purpose' => 'required|string|min:10|max:2000',
            'contacts.*.confirmed' => 'accepted',
        ])->validate()['contacts'];
        foreach ($rows as &$row) {
            $row['contact_version'] = (int) $row['contact_version'];
            $row['purpose'] = trim($row['purpose']);
            abort_unless(mb_strlen($row['purpose']) >= 10, 422, 'Explain why this contact is needed for ongoing care.');
            $row['confirmed'] = true;
        }
        unset($row);
        usort($rows, fn ($a, $b) => strcmp($a['contact_id'], $b['contact_id']));

        return $rows;
    }

    /** Called with the referral and destination locked by conversion or care publication. */
    public function apply(User $actor, Client $person, CareReferral $referral, array $reviews, CurrentAuthorizationReads $reads): array
    {
        if ($reviews === []) {
            return [];
        }
        $access = app(ReferralAccess::class);
        abort_unless($access->audience($actor, 'assessment') && $access->audience($actor, 'assessment', true), 403);
        $policy = app(ClientPolicy::class);
        abort_unless($policy->viewFromCurrentEvidence($actor, $person, $reads)
            && $policy->updateFromCurrentEvidence($actor, $person, $reads), 403);
        abort_unless(ReferralCareHandoverRevisions::reviewReady($referral), 422, 'Review current authority and assessment before sharing contacts for care.');
        $mappings = [];
        foreach ($reviews as $review) {
            $source = $referral->intake['people_contacts'][$review['contact_id']] ?? null;
            abort_unless($source && (int) $source['version'] === $review['contact_version'], 409, 'A selected contact changed. Review the current contact before handover.');
            abort_unless(app(ReferralPeopleContacts::class)->current($referral, $source), 422, 'Only currently reviewed contacts can be shared for care.');
            $contact = ClientSupportContact::query()->where('source_referral_id', $referral->id)
                ->where('source_contact_id', $source['id'])->lockForUpdate()->first();
            abort_if($contact && (int) $contact->client_id !== (int) $person->id, 409, 'This contact handover already belongs to another person.');
            // Re-review renews the same canonical contact. It never rewrites emergency/portal records.
            $contact ??= new ClientSupportContact(['client_id' => $person->id, 'source_referral_id' => $referral->id, 'source_contact_id' => $source['id']]);
            $release = ['source_version' => $source['version'], 'authority_version' => $referral->authority['review']['version'],
                'assessment_version' => $referral->assessment['version'],
                'details' => Arr::except($source['details'], ['review_note', 'confirmed']),
                'purpose' => $review['purpose'], 'confirmed' => true, 'released_by' => $actor->id, 'released_at' => now()->toISOString()];
            $contact->version = ($contact->version ?? 0) + 1;
            $contact->release = $release;
            $contact->save();
            $mapping = ['id' => $contact->id, 'version' => $contact->version, 'source_contact_id' => $source['id'], ...$release];
            $mappings[] = $mapping;
            app(ReferralCommand::class)->record($referral, $actor, 'person_contact.released_for_care', 'assessment', $mapping);
        }

        return $mappings;
    }

    /** A canonical profile read must pass both its person policy and existing contacts-section boundary. */
    public function present(User $actor, Client $person): array
    {
        abort_unless(app(ClientPolicy::class)->view($actor, $person), 404);
        $sections = app(ClientProfileSectionAccess::class)->for($actor, $person);
        if (! $sections['portal_access'] && ! $sections['medical']) {
            return [];
        }
        $sites = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['clinical.accessAllSites', 'sites.viewAll']);
        $sources = CareReferral::query()->where('client_id', $person->id)->whereIn('site_id', $sites)
            ->when(! $actor->canDo('referrals.restricted'), fn ($q) => $q->where('restricted', false))
            ->whereIn('id', ClientSupportContact::where('client_id', $person->id)->select('source_referral_id'))->get()->keyBy('id');

        return ClientSupportContact::query()->where('client_id', $person->id)->whereIn('source_referral_id', $sources->keys())
            ->orderBy('id')->get()->map(function (ClientSupportContact $contact) use ($sources, $actor) {
                $referral = $sources[$contact->source_referral_id];
                $source = $referral->intake['people_contacts'][$contact->source_contact_id] ?? null;
                $release = $contact->release;
                $current = $source && ($release['confirmed'] ?? false)
                    && ($release['source_version'] ?? null) === $source['version']
                    && ($release['authority_version'] ?? null) === ($referral->authority['review']['version'] ?? null)
                    && ($release['assessment_version'] ?? null) === ($referral->assessment['version'] ?? null)
                    && ReferralCareHandoverRevisions::reviewReady($referral)
                    && app(ReferralPeopleContacts::class)->current($referral, $source);
                // A stale snapshot cannot expose actionable old phone/email or imply permission persists.
                $details = $current ? $release['details'] : Arr::only($release['details'], ['name', 'role', 'relationship']);
                $canReview = app(ReferralAccess::class)->can($actor, 'convert')
                    && app(ReferralAccess::class)->can($actor, 'assessment.view')
                    && app(ReferralAccess::class)->query($actor)->whereKey($referral->id)->exists();

                return ['id' => $contact->id, 'version' => $contact->version, 'details' => $details,
                    'review_current' => (bool) $current, 'purpose' => $current ? $release['purpose'] : null, 'released_at' => $release['released_at'],
                    'review_url' => $canReview ? '/operations/referrals/'.$referral->reference.'?tab=people-contacts' : null];
            })->all();
    }
}
