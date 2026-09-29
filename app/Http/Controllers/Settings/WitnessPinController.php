<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\Medication\WitnessPinService;
use App\Services\Medication\WitnessPinSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ],
        ]);
    }

    /** Set a first PIN, choose a new one after a reset, or change it with the current PIN. */
    public function update(Request $request): RedirectResponse
    {
        $user = $this->staff($request);
        $validated = $request->validate([
            'current_pin' => ['nullable', 'string', 'max:12'],
            'pin' => ['required', 'string', 'max:12', 'confirmed'],
            'pin_confirmation' => ['required', 'string', 'max:12'],
        ], [
            'pin.confirmed' => 'The two PINs don’t match.',
        ]);

        if ($this->pins->status($user) === WitnessPinService::STATUS_LOCKED) {
            throw ValidationException::withMessages([
                'current_pin' => 'Your PIN is locked. Reset it by confirming your login password instead.',
            ]);
        }
        if ($this->pins->status($user) === WitnessPinService::STATUS_SET) {
            $this->pins->assertOwnerKnowsPin($user, $validated['current_pin'] ?? null);
        }

        $this->pins->set($user, $validated['pin']);

        return back()->with('success', 'Your witness PIN is set. You can now co-sign and witness.');
    }

    /** Forgotten or locked: confirm the login password, then choose a new PIN. */
    public function reset(Request $request): RedirectResponse
    {
        $user = $this->staff($request);
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'pin' => ['required', 'string', 'max:12', 'confirmed'],
            'pin_confirmation' => ['required', 'string', 'max:12'],
        ], [
            'current_password.current_password' => 'That isn’t your login password.',
            'pin.confirmed' => 'The two PINs don’t match.',
        ]);

        $this->pins->set($user, $validated['pin']);

        return back()->with('success', 'Your witness PIN was reset. You can now co-sign and witness.');
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
