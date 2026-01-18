<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        $g = Socialite::driver('google')->stateless()->user();

        $email = strtolower($g->getEmail() ?? '');
        abort_unless($email !== '', 401, 'No email returned from Google.');

        // Create the user if they don't exist yet.
        // IMPORTANT: We do NOT log them in. They must be approved first.
        $user = User::where('email', $email)->first();

        if (!$user) {
            User::create([
                'name' => $g->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                // Keep legacy role column blank or a safe default. Up to you.
                // 'role' => 'support_worker',
            ]);

            // If you have an "approval" mechanism (recommended), set it here.
            // Example (uncomment if these columns exist):
            // $user->forceFill(['is_approved' => false])->save();
        }

        // Always send them back to login with your existing "awaiting approval" message
        return redirect()
            ->route('login')
            ->with('success', 'Thanks for signing up! Your account is awaiting approval.');
    }
}
