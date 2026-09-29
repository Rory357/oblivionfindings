<?php

namespace App\Services\Medication;

use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The personal 6-digit witness PIN (eMAR P00 v5 / PIN-1, Stephan's option B).
 *
 * Every medication second-person check — controlled-drug witness,
 * restricted-competency co-signer, verbal-order read-back and verification
 * waiver approver — verifies the second person's PIN here instead of their
 * login password. Wrong PINs are counted per owner across every screen; the
 * count survives the surrounding transaction rolling back, so the lock can't
 * be bypassed by a request that fails.
 */
final class WitnessPinService
{
    public const LENGTH = 6;

    public const STATUS_NOT_SET = 'not_set';

    public const STATUS_SET = 'set';

    public const STATUS_LOCKED = 'locked';

    /** An administrator reset it; the owner must choose a new PIN. */
    public const STATUS_RESET = 'reset';

    /** Older than the organisation's renewal period; the owner must choose a new PIN. */
    public const STATUS_EXPIRED = 'expired';

    public const METHOD = 'witness_pin';

    public const INCORRECT = 'Incorrect PIN. Repeated wrong attempts lock the PIN.';

    public function __construct(private readonly WitnessPinSettings $settings) {}

    /** A PIN a person may choose: exactly 6 digits, not all one digit, not a simple run. */
    public function formatError(?string $pin): ?string
    {
        if (! is_string($pin) || preg_match('/^\d{'.self::LENGTH.'}$/', $pin) !== 1) {
            return 'Enter exactly 6 digits.';
        }
        if (count(array_unique(str_split($pin))) === 1
            || str_contains('0123456789', $pin)
            || str_contains('9876543210', $pin)) {
            return 'Choose a PIN that isn’t all one digit or a simple run like 123456.';
        }

        return null;
    }

    public function status(User $user): string
    {
        return $this->statusOf($user->relationLoaded('witnessPin')
            ? $user->getRelation('witnessPin')
            : UserWitnessPin::query()->where('user_id', $user->id)->first());
    }

    /**
     * PIN status for many people at once (pickers, the staff list).
     *
     * @param  iterable<int>  $userIds
     * @return array<int, string>
     */
    public function statuses(iterable $userIds): array
    {
        $ids = collect($userIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $pins = UserWitnessPin::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');

        return $ids->mapWithKeys(fn (int $id): array => [$id => $this->statusOf($pins->get($id))])->all();
    }

    /**
     * Picker rows: people without a usable PIN are listed but can't be chosen.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, array{id: int, name: string, witness_pin: string}>
     */
    public function pickerRows(Collection $users): Collection
    {
        $statuses = $this->statuses($users->pluck('id'));

        return $users->map(fn (User $user): array => [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'witness_pin' => $statuses[(int) $user->id] ?? self::STATUS_NOT_SET,
        ])->values();
    }

    public function isUsable(User $user): bool
    {
        return $this->status($user) === self::STATUS_SET;
    }

    public function statusOf(?UserWitnessPin $pin): string
    {
        if ($pin === null) {
            return self::STATUS_NOT_SET;
        }
        if ($pin->must_change) {
            return self::STATUS_RESET;
        }
        if ($pin->locked_until !== null && $pin->locked_until->isFuture()) {
            return self::STATUS_LOCKED;
        }
        $renewal = $this->settings->renewalMonths();
        if ($renewal !== null && $pin->set_at !== null && $pin->set_at->copy()->addMonths($renewal)->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_SET;
    }

    /** The owner sets or replaces their own PIN (the caller has re-checked who they are). */
    public function set(User $owner, string $pin): void
    {
        if (($error = $this->formatError($pin)) !== null) {
            throw ValidationException::withMessages(['pin' => $error]);
        }

        DB::transaction(function () use ($owner, $pin): void {
            $existing = UserWitnessPin::query()->where('user_id', $owner->id)->lockForUpdate()->first();
            if ($existing !== null && ! $existing->must_change && Hash::check($pin, $existing->pin_hash)) {
                throw ValidationException::withMessages(['pin' => 'Choose a PIN you haven’t just been using.']);
            }

            UserWitnessPin::query()->updateOrCreate(
                ['user_id' => $owner->id],
                [
                    'pin_hash' => Hash::make($pin),
                    'set_at' => now(),
                    'failed_attempts' => 0,
                    'last_failed_at' => null,
                    'locked_until' => null,
                    'must_change' => false,
                ],
            );

            AuditLogger::logOrFail($existing === null ? 'medications.witness_pin.set' : 'medications.witness_pin.changed', $owner, [
                'actor_id' => (int) $owner->id,
                'after_reset' => (bool) $existing?->must_change,
            ]);
        });
    }

    /** The owner confirms their current PIN before changing it. Wrong PINs count towards the lock. */
    public function assertOwnerKnowsPin(User $owner, ?string $currentPin, string $errorKey = 'current_pin'): void
    {
        $this->verify($owner, $currentPin, $errorKey, ['purpose' => 'owner_change'], ownPin: true);
    }

    /** An authorised colleague resets another person's PIN. Nobody sees the old or new PIN. */
    public function resetByAdmin(User $target, User $actor): void
    {
        DB::transaction(function () use ($target, $actor): void {
            $pin = UserWitnessPin::query()->where('user_id', $target->id)->lockForUpdate()->first();
            if ($pin === null) {
                throw ValidationException::withMessages(['pin' => $target->name.' hasn’t set a witness PIN, so there is nothing to reset.']);
            }

            $pin->forceFill([
                'must_change' => true,
                'failed_attempts' => 0,
                'last_failed_at' => null,
                'locked_until' => null,
                'reset_by' => $actor->id,
                'reset_at' => now(),
            ])->save();

            AuditLogger::logOrFail('medications.witness_pin.reset', $target, [
                'actor_id' => (int) $actor->id,
                'target_user_id' => (int) $target->id,
            ]);
        });
    }

    /**
     * Verify a second person's PIN, typed on the recorder's screen.
     *
     * @param  array<string, mixed>  $context  Safe identifiers for the security log (no clinical detail).
     *
     * @throws ValidationException with the message under $errorKey
     */
    public function verify(User $witness, ?string $pin, string $errorKey, array $context = [], bool $ownPin = false): void
    {
        $who = $ownPin ? 'Your' : $witness->name.'’s';
        if (blank($pin)) {
            throw ValidationException::withMessages([
                $errorKey => $ownPin ? 'Enter your current PIN.' : 'Enter their 6-digit witness PIN.',
            ]);
        }

        $record = DB::transactionLevel() > 0
            ? UserWitnessPin::query()->where('user_id', $witness->id)->lockForUpdate()->first()
            : UserWitnessPin::query()->where('user_id', $witness->id)->first();
        $status = $this->statusOf($record);

        if ($status === self::STATUS_NOT_SET) {
            throw ValidationException::withMessages([
                $errorKey => $witness->name.' hasn’t set a witness PIN yet. They can set one in Settings › Witness PIN, or choose someone else.',
            ]);
        }
        if ($status === self::STATUS_RESET || $status === self::STATUS_EXPIRED) {
            throw ValidationException::withMessages([
                $errorKey => $who.' witness PIN '.($status === self::STATUS_RESET ? 'was reset' : 'needs renewing').'. A new one must be chosen in Settings › Witness PIN before it can be used.',
            ]);
        }
        if ($status === self::STATUS_LOCKED) {
            throw ValidationException::withMessages([$errorKey => $this->lockedMessage($who, $record->locked_until)]);
        }

        if (preg_match('/^\d{'.self::LENGTH.'}$/', (string) $pin) === 1 && Hash::check((string) $pin, $record->pin_hash)) {
            if ($record->failed_attempts > 0) {
                $record->forceFill(['failed_attempts' => 0, 'last_failed_at' => null])->save();
            }

            return;
        }

        $lockedUntil = $this->recordFailureDurably((int) $witness->id, $record, $context + ['purpose' => $context['purpose'] ?? 'second_person']);

        throw ValidationException::withMessages([
            $errorKey => $lockedUntil !== null ? $this->lockedMessage($who, $lockedUntil) : self::INCORRECT,
        ]);
    }

    /**
     * Count a wrong PIN so the count survives the caller's transaction rolling
     * back (the ValidationException normally rolls it back). The write runs
     * once: after rollback, after commit if the caller swallowed the error, or
     * immediately when no application transaction is open.
     *
     * @param  array<string, mixed>  $context
     * @return CarbonInterface|null When this failure locks the PIN, its unlock time.
     */
    private function recordFailureDurably(int $witnessId, UserWitnessPin $snapshot, array $context): ?CarbonInterface
    {
        $max = $this->settings->maxAttempts();
        $attempts = $this->attemptsSoFar($snapshot) + 1;
        $lockedUntil = $attempts >= $max ? now()->addMinutes($this->settings->lockoutMinutes()) : null;

        $done = false;
        $write = function () use (&$done, $witnessId, $max, $context): void {
            if ($done) {
                return;
            }
            $done = true;
            $this->writeFailure($witnessId, $max, $context);
        };

        DB::afterRollBack($write);
        DB::afterCommit($write);

        return $lockedUntil;
    }

    /** @param  array<string, mixed>  $context */
    private function writeFailure(int $witnessId, int $max, array $context): void
    {
        DB::transaction(function () use ($witnessId, $max, $context): void {
            $pin = UserWitnessPin::query()->where('user_id', $witnessId)->lockForUpdate()->first();
            if ($pin === null) {
                return;
            }

            $attempts = $this->attemptsSoFar($pin) + 1;
            $locks = $attempts >= $max;
            $pin->forceFill([
                'failed_attempts' => $locks ? 0 : $attempts,
                'last_failed_at' => now(),
                'locked_until' => $locks ? now()->addMinutes($this->settings->lockoutMinutes()) : $pin->locked_until,
            ])->save();

            $meta = [
                'witness_user_id' => $witnessId,
                'attempts' => $attempts,
                'attempt_limit' => $max,
                'locked' => $locks,
                'purpose' => (string) ($context['purpose'] ?? 'second_person'),
            ] + collect($context)->only(['site_id', 'surface'])->all();

            Log::warning('Medication witness PIN rejected.', ['security_event' => 'medication_witness_pin_rejected'] + $meta);
            AuditLogger::log($locks ? 'medications.witness_pin.locked' : 'medications.witness_pin.failed', $pin, $meta);
        });
    }

    /** Failed attempts since the last success, ignoring any lock that has already expired. */
    private function attemptsSoFar(UserWitnessPin $pin): int
    {
        if ($pin->locked_until !== null && $pin->locked_until->isPast()) {
            return 0;
        }

        return (int) $pin->failed_attempts;
    }

    private function lockedMessage(string $who, CarbonInterface $until): string
    {
        $local = $until->copy()->setTimezone((string) config('app.worker_timezone', config('app.timezone')));

        return $who.' witness PIN is locked after too many wrong attempts. It unlocks at '
            .$local->format('g:i').' '.strtolower($local->format('A'))
            .', or '.($who === 'Your' ? 'you' : 'they').' can reset it in Settings › Witness PIN.';
    }

    /**
     * Status rows for a list of people, for the staff PIN status list.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, array{id: int, name: string, status: string, set_at: ?string, locked_until: ?string, reset_at: ?string}>
     */
    public function statusRows(Collection $users): Collection
    {
        $pins = UserWitnessPin::query()->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        return $users->map(function (User $user) use ($pins): array {
            $pin = $pins->get($user->id);

            return [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'status' => $this->statusOf($pin),
                'set_at' => $pin?->set_at?->toIso8601String(),
                'locked_until' => $pin?->locked_until?->isFuture() ? $pin->locked_until->toIso8601String() : null,
                'reset_at' => $pin?->must_change ? $pin->reset_at?->toIso8601String() : null,
            ];
        })->values();
    }
}
