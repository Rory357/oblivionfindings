<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralSubmission;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** An internal preparation case for an existing person, never a fictional external referral. */
final class InternalSupportPreparation
{
    public const KIND = 'internal_preparation';

    public const PERMISSIONS = ['clients.viewAny', 'clients.viewAssigned', 'clients.update', 'clinical.accessAllSites', 'sites.viewAll'];

    public function canPrepare(User $actor, Client $person, ?CurrentAuthorizationReads $reads = null): bool
    {
        $policy = app(ClientPolicy::class);
        $access = app(ReferralAccess::class);

        return ($reads ? $policy->viewFromCurrentEvidence($actor, $person, $reads) : $policy->view($actor, $person))
            && ($reads ? $policy->updateFromCurrentEvidence($actor, $person, $reads) : $policy->update($actor, $person))
            && $access->can($actor, 'create') && $access->eligibleForSite($actor, $person->site_id, $reads)
            && in_array($person->status, ['onboarding', 'active'], true);
    }

    public function options(User $actor, Client $person): array
    {
        abort_unless(app(ClientPolicy::class)->view($actor, $person), 404);
        abort_unless($this->canPrepare($actor, $person), 403);
        $settings = app(ReferralSettings::class)->effective();
        abort_unless($settings, 422, 'Publish the support preparation settings first.');
        $siteIds = array_values(array_intersect(
            app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['clinical.accessAllSites', 'sites.viewAll']),
            app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['referrals.viewAllSites']),
        ));
        $existing = app(ReferralAccess::class)->query($actor)->where('client_id', $person->id)->whereNotIn('stage', ['closed'])
            ->orderByDesc('id')->limit(21)->get();

        return ['person' => ['id' => $person->id, 'name' => $person->full_name, 'status' => $person->status,
            'fingerprint' => ReferralHandoverPreview::fingerprint($person), 'site_id' => $person->site_id, 'service_context_id' => $person->service_context_id],
            'settings_version_id' => $settings->id, 'team' => $settings->configuration['intake']['team'],
            'services' => array_values(array_filter($settings->configuration['services'], fn ($service) => $service['enabled'])),
            'sites' => Site::query()->whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name'])->toArray(),
            'contexts' => ServiceContext::query()->availableToSites($siteIds)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'site_id', 'type'])->toArray(),
            'existing_more' => $existing->count() > 20,
            'existing' => $existing->take(20)->map(fn ($record) => ['reference' => $record->reference, 'service' => $record->requested_service,
                'stage' => $record->stage, 'url' => '/operations/referrals/'.$record->reference])->all()];
    }

    public function create(User $actor, int $personId, array $input): CareReferral
    {
        $data = Validator::make($input, [
            'submission_key' => 'required|uuid', 'person_fingerprint' => 'required|string|size:64', 'settings_version_id' => 'required|integer|min:1',
            'site_id' => 'required|integer|min:1', 'service_context_id' => 'required|integer|min:1', 'requested_service' => 'required|string|max:64',
            'team' => 'required|string|min:2|max:160', 'reason' => 'required|string|min:10|max:2000',
            'next_action' => 'required|string|min:5|max:500', 'next_due_at' => 'required|date', 'acknowledge_review' => 'accepted',
        ])->validate();
        foreach (['site_id', 'service_context_id', 'settings_version_id'] as $key) {
            $data[$key] = (int) $data[$key];
        }
        $keyHash = hash_hmac('sha256', 'internal:'.$actor->id.':'.$personId.':'.$data['submission_key'], (string) config('app.key'));
        $intent = hash('sha256', json_encode(Arr::sortRecursive($data), JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $personId, $data, $keyHash, $intent): CareReferral {
                $person = Client::query()->lockForUpdate()->findOrFail($personId);
                $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor,
                    [...array_column(ReferralPermissions::definitions(), 'key'), ...self::PERMISSIONS]);

                return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $person, $data, $keyHash, $intent): CareReferral {
                    abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $person, $reads), 404);
                    abort_unless($this->canPrepare($actor, $person, $reads), 403);
                    $access = app(ReferralAccess::class);
                    $previous = ReferralSubmission::query()->where('idempotency_hash', $keyHash)->lockForUpdate()->first();
                    if ($previous) {
                        abort_unless(hash_equals($previous->payload_hash, $intent), 409, 'That request already prepared different support. Review the existing case.');

                        return $access->query($actor, $reads)->whereKey($previous->referral_id)->firstOrFail();
                    }
                    abort_unless(hash_equals(ReferralHandoverPreview::fingerprint($person), $data['person_fingerprint']), 409, 'The person record changed. Review the current details before preparing support.');
                    abort_unless($access->eligibleForSite($actor, $data['site_id'], $reads)
                        && in_array($data['site_id'], app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['clinical.accessAllSites', 'sites.viewAll'], $reads), true), 403);
                    $settings = app(ReferralSettings::class)->effective($reads);
                    abort_unless($settings && $settings->id === $data['settings_version_id'], 409, 'Preparation settings changed. Review the current configuration.');
                    abort_unless(collect($settings->configuration['services'])->contains(fn ($service) => $service['enabled'] && $service['id'] === $data['requested_service']), 422, 'Choose a currently offered service.');
                    $reads->query(ReferralServiceSettings::contexts(ServiceContext::query()->availableToSite($data['site_id'])->where('is_active', true),
                        $settings->configuration, $data['requested_service']))->findOrFail($data['service_context_id']);
                    $due = ReferralDates::parse($data['next_due_at'], 'next_due_at');
                    abort_unless($due->isFuture(), 422, 'Choose a future next-action date.');
                    $open = CareReferral::query()->where('client_id', $person->id)->where('entry_kind', self::KIND)
                        ->where('site_id', $data['site_id'])->where('requested_service', $data['requested_service'])->whereNotIn('stage', ['closed', 'converted'])
                        ->lock('for share nowait')->exists();
                    abort_if($open, 409, 'Support preparation is already open for this person, site and service. Ask the responsible manager to review the existing case.');
                    $record = CareReferral::create(['settings_version_id' => $settings->id, 'client_id' => $person->id, 'site_id' => $data['site_id'],
                        'source' => 'internal', 'entry_kind' => self::KIND, 'stage' => 'assessment', 'owner_id' => $actor->id, 'team' => $data['team'],
                        'requested_service' => $data['requested_service'], 'general_enquiry' => false,
                        'identity' => ['first_name' => $person->first_name, 'last_name' => $person->last_name, 'preferred_name' => $person->preferred_name, 'date_of_birth' => $person->date_of_birth?->toDateString()],
                        'intake' => ['provenance' => 'Internal support preparation from the existing person record; no external referral or contact asserted.',
                            'preparation_reason' => $data['reason'], 'service_context_id' => $data['service_context_id']],
                        'authority' => ['staff_review' => 'pending', 'contact_permission' => false], 'assessment' => [],
                        'next_action' => $data['next_action'], 'next_due_at' => $due, 'stage_changed_at' => now()]);
                    ReferralSubmission::create(['idempotency_hash' => $keyHash, 'payload_hash' => $intent, 'receipt_hash' => hash_hmac('sha256', $record->reference, (string) config('app.key')),
                        'referral_id' => $record->id, 'expires_at' => now()->addDays(30)]);
                    ReferralEvent::create(['referral_id' => $record->id, 'actor_id' => $actor->id, 'kind' => 'support.preparation_opened', 'audience' => 'intake',
                        'payload' => ['source' => 'internal', 'reason' => $data['reason'], 'settings_version_id' => $settings->id, 'service_commenced' => false], 'occurred_at' => now()]);
                    ReferralOutbox::create(['referral_id' => $record->id, 'deduplication_key' => 'intake:'.$record->reference, 'kind' => 'intake_received',
                        'message' => ['reference' => $record->reference], 'available_at' => now()]);

                    return $record;
                });
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                abort(409, 'The person or their support preparation is being updated. Retry the same request after checking current access.');
            }
            throw $exception;
        }
    }
}
