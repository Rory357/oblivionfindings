<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\AuditLogger;
use App\Services\Medication\WitnessPinService;
use App\Services\Medication\WitnessPinSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account settings › Witness PIN (PIN-1). The owner sets, changes or resets
 * their own 6-digit PIN; nobody else can see or set it.
 */
class WitnessPinController extends Controller
{
    public function __construct(
        private readonly WitnessPinService $pins,
        private readonly WitnessPinSettings $settings,
    ) {}

    public function edit(Request $request): Response
    {
        $user = $this->staff($request);
        $pin = UserWitnessPin::query()->with('resetBy:id,name')->where('user_id', $user->id)->first();

        return Inertia::render('settings/witness-pin', [
            'witnessPin' => [
                'status' => $this->pins->statusOf($pin),
                'setAt' => $pin?->set_at?->toIso8601String(),
                'lockedUntil' => $pin?->locked_until?->isFuture() ? $pin->locked_until->toIso8601String() : null,
                'resetAt' => $pin?->must_change ? $pin->reset_at?->toIso8601String() : null,
                'resetBy' => $pin?->must_change ? $pin->resetBy?->name : null,
            ],
            'rules' => [
                'maxAttempts' => $this->settings->maxAttempts(),
                'lockoutMinutes' => $this->settings->lockoutMinutes(),
                'renewalMonths' => $this->settings->renewalMonths(),
                'loginCheckToSet' => $this->loginCheckToSet(),
            ],
        ]);
    }

    /**
     * Set a first PIN, choose a new one after a reset, or change it with the
     * current PIN. With the login check on, having no usable PIN needs the
     * login password instead of the session alone.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $this->staff($request);
        $status = $this->pins->status($user);
        $needsLogin = $status !== WitnessPinService::STATUS_SET && $this->loginCheckToSet();
        $validated = $request->validate([
            'current_pin' => ['nullable', 'string', 'max:12'],
            'current_password' => $needsLogin ? ['required', 'string'] : ['nullable', 'string'],
            'pin' => ['required', 'string', 'max:12', 'confirmed'],
            'pin_confirmation' => ['required', 'string', 'max:12'],
        ], [
            'current_password.required' => 'Confirm your login password to set your witness PIN.',
            'pin.confirmed' => 'The two PINs don’t match.',
        ]);

        if ($status === WitnessPinService::STATUS_LOCKED) {
            throw ValidationException::withMessages([
                'current_pin' => 'Your PIN is locked. Reset it by confirming your login password instead.',
            ]);
        }

        $confirmedWith = WitnessPinService::CONFIRMED_WITH_SESSION;
        if ($status === WitnessPinService::STATUS_SET) {
            $this->pins->assertOwnerKnowsPin($user, $validated['current_pin'] ?? null);
            $confirmedWith = WitnessPinService::CONFIRMED_WITH_CURRENT_PIN;
        } elseif ($needsLogin) {
            $this->assertLoginPassword($user, (string) $validated['current_password'], 'set');
            $confirmedWith = WitnessPinService::CONFIRMED_WITH_LOGIN_PASSWORD;
        }

        $this->pins->set($user, $validated['pin'], $confirmedWith);

        return back()->with('success', 'Your witness PIN is set. You can now co-sign and witness.');
    }

    /** Forgotten or locked: confirm the login password, then choose a new PIN. */
    public function reset(Request $request): RedirectResponse
    {
        $user = $this->staff($request);
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'pin' => ['required', 'string', 'max:12', 'confirmed'],
            'pin_confirmation' => ['required', 'string', 'max:12'],
        ], [
            'pin.confirmed' => 'The two PINs don’t match.',
        ]);

        $this->assertLoginPassword($user, (string) $validated['current_password'], 'reset');
        $this->pins->set($user, $validated['pin'], WitnessPinService::CONFIRMED_WITH_LOGIN_PASSWORD);

        return back()->with('success', 'Your witness PIN was reset. You can now co-sign and witness.');
    }

    /** A wrong login password is audited: it guards who can choose this person's PIN. */
    private function assertLoginPassword(User $user, string $password, string $purpose): void
    {
        if (Hash::check($password, (string) $user->getAuthPassword())) {
            return;
        }

        AuditLogger::log('medications.witness_pin.login_check_failed', $user, [
            'actor_id' => (int) $user->id,
            'purpose' => $purpose,
        ]);

        throw ValidationException::withMessages([
            'current_password' => 'That isn’t your login password.',
        ]);
    }

    private function loginCheckToSet(): bool
    {
        return (bool) config('medications.witness_pin.login_check_to_set', false);
    }

    private function staff(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_if(
            in_array($user->role, ['client', 'next_of_kin'], true) || $user->hasRole('client', 'next_of_kin'),
            403,
        );

        return $user;
    }
}
