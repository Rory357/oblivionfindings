<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralIntakeDraft;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Save-and-resume for staff intake (spec §5: a call's notes must survive the
 * dialog closing). Drafts belong to one user, are encrypted at rest, expire
 * after seven days and are deleted when the referral is submitted. Another
 * person's draft is indistinguishable from a missing one (404).
 */
final class ReferralIntakeDrafts
{
    public const DAYS = 7;

    private const LIMIT = 10;

    private const MAX_BYTES = 60000;

    public function __construct(private readonly ReferralAccess $access) {}

    /** @return list<array<string, mixed>> */
    public function list(User $actor): array
    {
        if (! $this->access->can($actor, 'create')) {
            return [];
        }
        $this->prune();

        return ReferralIntakeDraft::query()->where('user_id', $actor->id)->where('expires_at', '>', now())
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn (ReferralIntakeDraft $draft) => $this->summary($draft))->filter()->values()->all();
    }

    public function find(User $actor, string $reference): ReferralIntakeDraft
    {
        abort_unless($this->access->can($actor, 'create'), 403);

        return ReferralIntakeDraft::query()->where('user_id', $actor->id)->where('reference', $reference)
            ->where('expires_at', '>', now())->firstOrFail();
    }

    public function save(User $actor, array $input, ?string $reference = null): ReferralIntakeDraft
    {
        abort_unless($this->access->can($actor, 'create'), 403);
        Validator::make($input, ['payload' => 'required|array', 'payload.step' => 'nullable|integer|min:0|max:3'])->validate();
        // The whole scratch payload is kept (validated() would drop keys without their own rule);
        // the intake itself is validated in full when it is submitted.
        $payload = $input['payload'];
        if (strlen((string) json_encode($payload)) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['payload' => 'This draft is too large to save. Shorten the notes or submit the referral.']);
        }
        $this->prune();
        $draft = $reference ? $this->find($actor, $reference) : null;
        if (! $draft) {
            abort_if(ReferralIntakeDraft::query()->where('user_id', $actor->id)->where('expires_at', '>', now())->count() >= self::LIMIT, 422,
                'You already have '.self::LIMIT.' unfinished intake drafts. Resume or discard one before saving another.');
            $draft = new ReferralIntakeDraft(['user_id' => $actor->id]);
        }
        $draft->fill(['payload' => $payload, 'expires_at' => now()->addDays(self::DAYS)])->save();

        return $draft;
    }

    public function discard(User $actor, string $reference): void
    {
        $this->find($actor, $reference)->delete();
    }

    /** Called after a successful staff intake; silently ignores someone else's reference. */
    public function consumed(User $actor, ?string $reference): void
    {
        if ($reference) {
            ReferralIntakeDraft::query()->where('user_id', $actor->id)->where('reference', $reference)->delete();
        }
    }

    public function summary(ReferralIntakeDraft $draft): ?array
    {
        try {
            $payload = $draft->payload ?? [];
        } catch (DecryptException) {
            return null;
        }
        $form = is_array($payload['form'] ?? null) ? $payload['form'] : [];
        $person = trim(($form['person']['first_name'] ?? '').' '.($form['person']['last_name'] ?? ''));
        $contact = trim((string) ($form['contact']['name'] ?? ''));

        return [
            'reference' => $draft->reference,
            'label' => $person !== '' ? $person : ($contact !== '' ? 'Enquiry from '.$contact : 'Unnamed enquiry'),
            'source' => is_string($payload['source'] ?? null) ? $payload['source'] : null,
            'step' => (int) ($payload['step'] ?? 0),
            'saved_at' => $draft->updated_at?->toISOString(),
            'expires_at' => $draft->expires_at->toISOString(),
        ];
    }

    private function prune(): void
    {
        ReferralIntakeDraft::query()->where('expires_at', '<=', now())->delete();
    }
}
