<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Verified personal contact instructions, separate from referral callback contacts. */
final class ClientContactPreferences
{
    public const FIELDS = ['phone', 'email', 'channel', 'safe_time', 'leave_message', 'communication'];

    public function update(User $actor, int $id, string $fingerprint, array $input): array
    {
        $review = $this->validate($input);

        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $id, $fingerprint, $review) {
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor->id,
                ['clients.viewAny', 'clients.viewAssigned', 'clients.update', 'clinical.accessAllSites', 'sites.viewAll']);
            $person = Client::query()->lockForUpdate()->findOrFail($id);
            abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $person, $reads), 404);
            abort_unless(app(ClientPolicy::class)->updateFromCurrentEvidence($actor, $person, $reads), 403);
            abort_unless(hash_equals(self::fingerprint($person), $fingerprint), 409, 'Contact details changed. Reload the profile and review the current details before saving.');
            $this->apply($person, $review, $actor);

            return $this->preview($person->fresh());
        }));
    }

    public function validate(array $input): array
    {
        foreach (['phone', 'email', 'safe_time', 'communication', 'verification_note'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = trim($input[$key]);
            }
        }
        $data = Validator::make($input, [
            'phone' => 'present|nullable|string|max:50', 'email' => 'present|nullable|email:rfc|max:255',
            'channel' => ['required', Rule::in(['phone', 'email', 'none'])],
            'safe_time' => 'present|nullable|string|max:500', 'leave_message' => 'required|boolean',
            'communication' => 'present|nullable|string|max:1000',
            'verification_note' => 'required|string|min:10|max:2000', 'confirmed' => 'accepted',
        ])->validate();
        if ($data['channel'] !== 'none' && blank($data[$data['channel']])) {
            throw ValidationException::withMessages(['contact_handover' => 'Record the verified contact detail for the preferred channel.']);
        }
        if ($data['channel'] === 'none' && $data['leave_message']) {
            throw ValidationException::withMessages(['contact_handover' => 'Do not allow messages when the person has requested no direct contact.']);
        }
        foreach (['phone', 'email', 'safe_time', 'communication'] as $key) {
            $data[$key] = filled($data[$key]) ? $data[$key] : null;
        }
        $data['leave_message'] = (bool) $data['leave_message'];
        $data['confirmed'] = true;

        return $data;
    }

    public static function fingerprint(Client $person): string
    {
        return hash_hmac('sha256', json_encode([$person->id, $person->getRawOriginal('phone'), $person->getRawOriginal('email'),
            $person->getRawOriginal('contact_preferences')], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function preview(?Client $person): array
    {
        $preferences = $person?->contact_preferences;
        $current = $preferences ? ($preferences['phone'] ?? null) === $person->phone && ($preferences['email'] ?? null) === $person->email : false;

        return ['phone' => $person?->phone, 'email' => $person?->email,
            'preferences' => $preferences ? Arr::only($preferences, [...self::FIELDS, 'verified_at']) : null,
            'review_current' => $current, 'can_add' => blank($preferences),
            'fingerprint' => $person ? self::fingerprint($person) : null];
    }

    /** Caller holds the person row lock and current canonical permissions. */
    public function apply(Client $person, array $review, User $actor, bool $handover = false): array
    {
        if ($handover) {
            if (filled($person->contact_preferences)) {
                throw ValidationException::withMessages(['contact_handover' => 'Keep the existing contact review. Make corrections through the person profile.']);
            }
            foreach (['phone', 'email'] as $key) {
                if (filled($person->$key) && $review[$key] !== $person->$key) {
                    throw ValidationException::withMessages(['contact_handover' => 'Keep the person’s existing '.$key.'. Review corrections in their profile.']);
                }
            }
        }
        $preferences = [...Arr::only($review, self::FIELDS), 'verification_note' => $review['verification_note'],
            'verified_at' => now()->toISOString(), 'verified_by' => $actor->id, 'source' => $handover ? 'referral_handover' : 'person_profile'];
        $person->forceFill(['phone' => $review['phone'], 'email' => $review['email'], 'contact_preferences' => $preferences])->save();
        AuditLogger::logOrFail('client.contact_reviewed', $person, ['actor_id' => $actor->id, 'fields' => self::FIELDS, 'source' => $preferences['source']]);

        return $preferences;
    }
}
