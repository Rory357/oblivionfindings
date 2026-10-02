<?php

namespace App\Services\Medication;

use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

    /**
     * A PIN a person may choose: exactly 6 digits and not an easily guessed
     * pattern — one digit repeated, a run (including wraparound, 890123), or
     * a repeat such as 121212, 123123, 112233 or 111222.
     */
    public function formatError(?string $pin): ?string
    {
        if (! is_string($pin) || preg_match('/^\d{'.self::LENGTH.'}$/', $pin) !== 1) {
            return 'Enter exactly 6 digits.';
        }
        if ($this->isGuessable($pin)) {
            return 'Choose a PIN that’s harder to guess — not one digit repeated, a run like 123456 or 890123, or a pattern like 121212 or 112233.';
        }

        return null;
    }

    private function isGuessable(string $pin): bool
    {
        $digits = array_map('intval', str_split($pin));
        $steps = [];
        for ($i = 1; $i < count($digits); $i++) {
            $steps[] = ($digits[$i] - $digits[$i - 1] + 10) % 10;
        }
        $uniformStep = count(array_unique($steps)) === 1;

        return ($uniformStep && in_array($steps[0], [0, 1, 9], true)) // 111111, 123456 / 890123, 654321 / 109876
            || preg_match('/^(\d\d)\1\1$/', $pin) === 1                // 121212
            || preg_match('/^(\d{3})\1$/', $pin) === 1                 // 123123
            || preg_match('/^(\d)\1(\d)\2(\d)\3$/', $pin) === 1        // 112233
            || preg_match('/^(\d)\1\1(\d)\2\2$/', $pin) === 1;         // 111222
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
        $renewal = $this->settings->renewalMonths();

        return $ids->mapWithKeys(fn (int $id): array => [$id => $this->statusWith($pins->get($id), $renewal)])->all();
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
        return $this->statusWith($pin, $pin === null ? null : $this->settings->renewalMonths());
    }

    /** statusOf() with the renewal setting read once for a whole list (B2 C1 review: Settings page cost). */
    private function statusWith(?UserWitnessPin $pin, ?int $renewal): string
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
        if ($renewal !== null && $pin->set_at !== null && $pin->set_at->copy()->addMonths($renewal)->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_SET;
    }

    /** How the owner proved who they are before setting a PIN (recorded in the audit). */
    public const CONFIRMED_WITH_CURRENT_PIN = 'current_pin';

    public const CONFIRMED_WITH_LOGIN_PASSWORD = 'login_password';

    /** Signed-in session only: a first PIN, or a new one after a reset, with the login check off. */
    public const CONFIRMED_WITH_SESSION = 'session';

    /**
     * The owner sets or replaces their own PIN; the caller has re-checked who
     * they are as $confirmedWith. Forgotten-PIN resets (login password while a
     * PIN was usable) audit as a self-reset, not a change.
     */
    public function set(User $owner, string $pin, string $confirmedWith = self::CONFIRMED_WITH_SESSION): void
    {
        if (($error = $this->formatError($pin)) !== null) {
            throw ValidationException::withMessages(['pin' => $error]);
        }

        DB::transaction(function () use ($owner, $pin, $confirmedWith): void {
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

            $action = match (true) {
                $existing === null => 'medications.witness_pin.set',
                $confirmedWith === self::CONFIRMED_WITH_LOGIN_PASSWORD
                    && $this->statusOf($existing) !== self::STATUS_RESET => 'medications.witness_pin.self_reset',
                default => 'medications.witness_pin.changed',
            };

            AuditLogger::logOrFail($action, $owner, [
                'actor_id' => (int) $owner->id,
                'after_reset' => (bool) $existing?->must_change,
                'confirmed_with' => $confirmedWith,
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

        // Per recorder + witness budget (the organisation's attempt limit). If
        // the limiter's store survives a rollback, the attempt is reserved
        // before the PIN is compared, so parallel requests from one account
        // can't out-run the durable lock. A database store on the default
        // connection would roll back with the caller, so there the attempt is
        // counted with the durable failure write instead.
        $limiterKey = isset($context['actor_id'])
            ? 'medication-witness-pin:'.(int) $context['actor_id'].':'.(int) $witness->id
            : null;
        $reserved = false;
        if ($limiterKey !== null) {
            if (RateLimiter::tooManyAttempts($limiterKey, $this->settings->maxAttempts())) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($limiterKey) / 60));

                throw ValidationException::withMessages([
                    $errorKey => 'Too many PIN attempts for '.$witness->name.' from your account. Try again in '.$minutes.' minute'.($minutes === 1 ? '' : 's').'.',
                ]);
            }
            if (! $this->limiterRollsBackWithCaller()) {
                RateLimiter::hit($limiterKey, $this->limiterDecaySeconds());
                $reserved = true;
            }
        }

        if (preg_match('/^\d{'.self::LENGTH.'}$/', (string) $pin) === 1 && Hash::check((string) $pin, $record->pin_hash)) {
            if ($record->failed_attempts > 0) {
                $record->forceFill(['failed_attempts' => 0, 'last_failed_at' => null])->save();
            }
            if ($limiterKey !== null) {
                RateLimiter::clear($limiterKey);
            }

            return;
        }

        $lockedUntil = $this->recordFailureDurably(
            (int) $witness->id,
            $record,
            $context + ['purpose' => $context['purpose'] ?? 'second_person'],
            $reserved ? null : $limiterKey,
        );

        throw ValidationException::withMessages([
            $errorKey => $lockedUntil !== null ? $this->lockedMessage($who, $lockedUntil) : self::INCORRECT,
        ]);
    }

    /**
     * Count a wrong PIN so the count survives the caller's transactions
     * rolling back (the ValidationException unwinds every level). The write
     * runs once, only when no application transaction is open any more: dose
     * paths nest transactions, and a savepoint rollback still leaves the outer
     * transaction open — a write there would be rolled back with it.
     *
     * The returned unlock time predicts this failure's effect for the message;
     * the stored count and lock are decided under the row lock at write time.
     *
     * @param  array<string, mixed>  $context
     * @param  string|null  $limiterKey  A per-recorder budget still to count (not reserved up front).
     * @return CarbonInterface|null When this failure locks the PIN, its unlock time.
     */
    private function recordFailureDurably(int $witnessId, UserWitnessPin $snapshot, array $context, ?string $limiterKey = null): ?CarbonInterface
    {
        $max = $this->settings->maxAttempts();
        $attempts = $this->attemptsSoFar($snapshot) + 1;
        $lockedUntil = $attempts >= $max ? now()->addMinutes($this->settings->lockoutMinutes()) : null;

        $done = false;
        $this->whenOutsideTransactions(function () use (&$done, $witnessId, $max, $context, $limiterKey): void {
            if ($done) {
                return;
            }
            $done = true;
            $this->writeFailure($witnessId, $max, $context);
            if ($limiterKey !== null) {
                RateLimiter::hit($limiterKey, $this->limiterDecaySeconds());
            }
        });

        return $lockedUntil;
    }

    /**
     * Run $callback once no application transaction is open. Laravel sets the
     * connection's new level before it runs commit/rollback callbacks, so each
     * callback re-checks and, while an outer transaction remains, re-registers
     * on it for whichever way it ends.
     */
    private function whenOutsideTransactions(Closure $callback): void
    {
        $schedule = function () use (&$schedule, $callback): void {
            if (! $this->insideApplicationTransaction()) {
                $callback();

                return;
            }

            DB::afterCommit($schedule);
            DB::afterRollBack($schedule);
        };

        $schedule();
    }

    /**
     * The rate limiter's cache writes share the caller's transaction when its
     * store is the database on the default connection (Laravel's default
     * store); a reservation there would roll back with a failed dose.
     */
    private function limiterRollsBackWithCaller(): bool
    {
        $store = (string) (config('cache.limiter') ?: config('cache.default'));
        if (config("cache.stores.{$store}.driver") !== 'database') {
            return false;
        }
        $connection = config("cache.stores.{$store}.connection");

        return $connection === null || $connection === '' || $connection === config('database.default');
    }

    private function limiterDecaySeconds(): int
    {
        return $this->settings->lockoutMinutes() * 60;
    }

    /**
     * True while an application transaction is open. A test's wrapping
     * transaction doesn't count: the testing transactions manager leaves it out
     * of the callback-applicable transactions.
     */
    private function insideApplicationTransaction(): bool
    {
        if (DB::transactionLevel() === 0) {
            return false;
        }

        $manager = app('db.transactions');

        // Without a manager, commit/rollback callbacks run straight away, so
        // deferring would only recurse: treat it as outside.
        return $manager instanceof DatabaseTransactionsManager
            && $manager->callbackApplicableTransactions()->isNotEmpty();
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
            ] + collect($context)->only(['site_id', 'surface', 'actor_id'])->all();

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
        $renewal = $this->settings->renewalMonths();

        return $users->map(function (User $user) use ($pins, $renewal): array {
            $pin = $pins->get($user->id);

            return [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'status' => $this->statusWith($pin, $renewal),
                'set_at' => $pin?->set_at?->toIso8601String(),
                'locked_until' => $pin?->locked_until?->isFuture() ? $pin->locked_until->toIso8601String() : null,
                'reset_at' => $pin?->must_change ? $pin->reset_at?->toIso8601String() : null,
            ];
        })->values();
    }
}
