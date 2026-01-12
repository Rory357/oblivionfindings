<?php

namespace App\Actions\Fortify;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class EnsureUserIsApproved
{
    /**
     * Handle an incoming authentication request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && is_null($user->approved_at)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                Fortify::username() => 'Your account is awaiting approval.',
            ]);
        }

        return $next($request);
    }
}
