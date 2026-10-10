<?php

namespace App\Services\Medication\ExternalClinical;

use App\Models\Client;
use App\Models\MedicationExternalClinician;
use App\Models\MedicationExternalGrant;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\Connected\ConnectedCareSettings;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExternalClinicalAccess
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $records,
    ) {}

    public function provision(User $actor, array $input): MedicationExternalClinician
    {
        return $this->internal($actor, (int) ($input['client_id'] ?? 0), function (Client $client, User $locked) use ($input) {
            $data = Validator::make($input, [
                'name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email',
                'provider_name' => 'required|string|max:255', 'registration_authority' => 'required|string|max:255',
                'registration_number' => 'required|string|max:100', 'identity_evidence' => 'required|string|max:2000',
                'identity_confirmed' => 'required|accepted', 'expires_at' => 'required|date|after:now',
            ])->validate();
            $expires = $this->expiry($data['expires_at'], 'maximum_identity_days');
            // Never convert an existing internal, family or client account.
            $user = new User(['name' => $data['name'], 'email' => mb_strtolower(trim($data['email'])),
                'password' => Str::random(64), 'role' => 'external_clinician', 'approved_at' => now(), 'approved_by' => $locked->id]);
            $user->forceFill(['external_clinical_account' => true]);
            $user->save();
            $profile = MedicationExternalClinician::query()->create([
                'user_id' => $user->id, 'provider_name' => $data['provider_name'],
                'registration_authority' => $data['registration_authority'], 'registration_number' => $data['registration_number'],
                'identity_evidence' => $data['identity_evidence'], 'verified_by' => $locked->id,
                'identity_verified_at' => now(), 'expires_at' => $expires,
            ]);
            AuditLogger::logOrFail('medications.external.identity_verified', $profile, ['actor_id' => $locked->id, 'client_id' => $client->id, 'external_user_id' => $user->id]);

            return $profile;
        });
    }

    public function grant(User $actor, array $input): MedicationExternalGrant
    {
        $submitted = MedicationExternalClinician::query()->findOrFail((int) ($input['clinician_id'] ?? 0));

        return $this->internal($actor, (int) ($input['client_id'] ?? 0), function (Client $client, User $locked) use ($input, $submitted) {
            $profile = MedicationExternalClinician::query()->whereKey($submitted->id)->lockForUpdate()->firstOrFail();
            // EA-104: the person who verified the prescriber's identity and
            // registration doesn't also grant them a chart (a Settings ›
            // Connected services switch, on by default).
            if (app(ConnectedCareSettings::class)->twoPerson(ConnectedCareSettings::TWO_PERSON_IDENTITY)
                && (int) $profile->verified_by === (int) $locked->id) {
                $this->invalid('clinician_id', 'Another person grants access. You verified this prescriber, so you can’t also grant them access.');
            }
            $this->assertIdentity($profile, User::query()->whereKey($profile->user_id)->lockForUpdate()->firstOrFail(), requireSetup: false);
            $data = Validator::make($input, ['purpose' => 'required|string|max:2000', 'expires_at' => 'required|date|after:now',
                'can_propose' => 'required|boolean', 'include_controlled' => 'required|boolean'])->validate();
            $expires = $this->expiry($data['expires_at'], 'maximum_grant_days');
            if ($expires->greaterThan($profile->expires_at)) {
                $this->invalid('expires_at', 'Access must end no later than the clinician identity verification.');
            }
            if ($data['include_controlled']) {
                abort_unless($locked->canDo('medications.controlled.view') && $locked->canDo('medications.controlled.record'), 404);
            }
            // One live named-person grant; replacement always revokes the old evidence.
            $old = MedicationExternalGrant::query()->where('clinician_id', $profile->id)->where('client_id', $client->id)
                ->whereNull('revoked_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($old as $grant) {
                $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $locked->id, 'revoke_reason' => 'Replaced by a new explicit access grant.'])->save();
            }
            $grant = MedicationExternalGrant::query()->create(['clinician_id' => $profile->id, 'client_id' => $client->id,
                'site_id' => $client->site_id, 'purpose' => $data['purpose'], 'expires_at' => $expires,
                'can_propose' => $data['can_propose'], 'include_controlled' => $data['include_controlled'], 'granted_by' => $locked->id]);
            AuditLogger::logOrFail('medications.external.access_granted', $grant, ['actor_id' => $locked->id, 'clinician_id' => $profile->id]);

            return $grant;
        }, [(int) $submitted->user_id]);
    }

    public function revokeGrant(User $actor, int $id, array $input): void
    {
        $submitted = MedicationExternalGrant::with('clinician')->findOrFail($id);
        $this->internal($actor, $submitted->client_id, function (Client $client, User $locked) use ($id, $input) {
            $grant = MedicationExternalGrant::query()->whereKey($id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            if ($grant->revoked_at !== null) {
                return;
            }
            $reason = Validator::make($input, ['reason' => 'required|string|max:2000'])->validate()['reason'];
            $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $locked->id, 'revoke_reason' => $reason])->save();
            AuditLogger::logOrFail('medications.external.access_revoked', $grant, ['actor_id' => $locked->id]);
        }, [(int) $submitted->clinician->user_id]);
    }

    public function revokeIdentity(User $actor, int $id, array $input): void
    {
        $submitted = MedicationExternalClinician::query()->findOrFail($id);
        $this->internal($actor, (int) ($input['client_id'] ?? 0), function (Client $client, User $locked) use ($id, $input) {
            // Identity withdrawal disables every named-person grant across all houses.
            // Historical verification and one-house access cannot authorize that global action.
            abort_unless($this->canRevokeIdentity($locked), 403, 'Clinical identity withdrawal requires current access management and explicit access to all sites.');
            $profile = MedicationExternalClinician::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($profile->revoked_at !== null) {
                return;
            }
            $reason = Validator::make($input, ['reason' => 'required|string|max:2000'])->validate()['reason'];
            $profile->forceFill(['revoked_at' => now(), 'revoked_by' => $locked->id, 'revoke_reason' => $reason])->save();
            // Marker and approval are retained: auth remains usable for password/logout only.
            AuditLogger::logOrFail('medications.external.identity_revoked', $profile, ['actor_id' => $locked->id, 'client_id' => $client->id]);
        }, [(int) $submitted->user_id]);
    }

    /** Call mutations with the current locked actor; the page uses the same permission rule. */
    public function canRevokeIdentity(User $actor): bool
    {
        return $actor->canDo('medications.external.manage')
            && collect(MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS)
                ->contains(fn (string $permission) => $actor->canDo($permission));
    }

    public function profile(User $actor): MedicationExternalClinician
    {
        $fresh = User::query()->findOrFail($actor->id);
        $profile = MedicationExternalClinician::query()->where('user_id', $fresh->id)->firstOrFail();
        $this->assertIdentity($profile, $fresh);

        return $profile;
    }

    public function assertIdentity(MedicationExternalClinician $profile, User $user, bool $requireSetup = true): void
    {
        abort_unless($user->isExternalClinicianAccount() && $user->approved_at !== null && $profile->active()
            && (int) $profile->user_id === (int) $user->id, 403, 'Clinical access is unavailable.');
        // Any staff/client identity collision fails closed, even after a mistaken profile assignment.
        abort_if($user->hrEmployeeProfile()->withTrashed()->exists() || $user->staffProfile()->exists()
            || $user->hasRole('client', 'next_of_kin') || $user->portalClients()->exists(), 403);
        if ($requireSetup) {
            abort_unless($user->hasVerifiedEmail() && $user->two_factor_confirmed_at && filled($user->two_factor_secret), 403);
        }
    }

    public function namedGrant(MedicationExternalClinician $profile, Client $client, bool $write = false, bool $lock = false): MedicationExternalGrant
    {
        $query = MedicationExternalGrant::query()->where('clinician_id', $profile->id)->where('client_id', $client->id)
            ->where('site_id', $client->site_id)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->when($write, fn ($q) => $q->where('can_propose', true))->orderByDesc('id');
        $grant = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($grant !== null, 404, 'The clinical record was not found.');
        $site = Site::query()->whereKey($client->site_id)->where('is_active', true)->where('archived', false)->whereNull('archived_at');
        abort_unless(($lock ? $site->lockForUpdate() : $site)->first() !== null, 404);

        return $grant;
    }

    /** Canonical person -> account -> identity -> named grant, also used for reads. */
    public function external(User $actor, int $clientId, Closure $callback, bool $write = false): mixed
    {
        return DB::transaction(function () use ($actor, $clientId, $callback, $write) {
            $client = Client::query()->whereKey($clientId)->lockForUpdate()->firstOrFail();
            $fresh = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Site::query()->whereKey($client->site_id)->where('is_active', true)->where('archived', false)->whereNull('archived_at')->lockForUpdate()->firstOrFail();
            $profile = MedicationExternalClinician::query()->where('user_id', $fresh->id)->lockForUpdate()->firstOrFail();
            $this->assertIdentity($profile, $fresh);
            $grant = $this->namedGrant($profile, $client, $write, true);

            return $callback($client, $fresh, $profile, $grant);
        }, 3);
    }

    /** Freeze every named-person disclosure in one canonical, ordered page snapshot. */
    public function page(User $actor, Closure $callback): mixed
    {
        $candidateIds = MedicationExternalGrant::query()
            ->whereHas('clinician', fn ($query) => $query->where('user_id', $actor->id))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->pluck('client_id')->unique()->all();

        return DB::transaction(function () use ($actor, $callback, $candidateIds) {
            $clients = Client::query()->whereIn('id', $candidateIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $fresh = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $sites = Site::query()->whereIn('id', $clients->pluck('site_id')->unique())->where('is_active', true)
                ->where('archived', false)->whereNull('archived_at')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $profile = MedicationExternalClinician::query()->where('user_id', $fresh->id)->lockForUpdate()->firstOrFail();
            $this->assertIdentity($profile, $fresh);
            $profile->setRelation('user', $fresh);
            $grants = MedicationExternalGrant::query()->where('clinician_id', $profile->id)
                ->whereIn('client_id', $clients->keys())->whereNull('revoked_at')->where('expires_at', '>', now())
                ->orderBy('client_id')->orderByDesc('id')->lockForUpdate()->get()
                ->filter(fn ($grant) => isset($sites[$grant->site_id])
                    && (int) $clients[$grant->client_id]->site_id === (int) $grant->site_id)
                ->unique('client_id')->values();
            foreach ($grants as $grant) {
                $grant->setRelation('client', $clients[$grant->client_id]);
                $grant->setRelation('clinician', $profile);
            }

            return $callback($fresh, $profile, $grants);
        }, 3);
    }

    public function internal(User $actor, int $clientId, Closure $callback, array $users = []): mixed
    {
        return DB::transaction(function () use ($actor, $clientId, $callback, $users) {
            Client::query()->whereKey($clientId)->lockForUpdate()->firstOrFail();
            // External identities are mutex subjects, never staff/witness evidence.
            User::query()->whereIn('id', array_values(array_unique([$actor->id, ...$users])))->orderBy('id')->lockForUpdate()->get();

            return $this->scope->forClient($actor, $clientId, 'medications.external.manage', function (Client $client, User $locked) use ($callback) {
                $this->records->assertReadable($locked, $client);

                return $callback($client, $locked);
            });
        }, 3);
    }

    private function expiry(string $input, string $setting): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/', $input)) {
            $this->invalid('expires_at', 'Choose a date and time with an explicit timezone offset.');
        }
        try {
            $parsed = new \DateTimeImmutable($input);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                $this->invalid('expires_at', 'Choose a valid date and time.');
            }
            $expires = CarbonImmutable::instance($parsed)->utc();
        } catch (\Exception) {
            $this->invalid('expires_at', 'Choose a valid date and time with an explicit timezone offset.');
        }
        if ($expires->greaterThan(now()->addDays((int) config('emar-external-clinical.'.$setting)))) {
            $this->invalid('expires_at', 'Choose an expiry within the permitted access period.');
        }

        return $expires;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
