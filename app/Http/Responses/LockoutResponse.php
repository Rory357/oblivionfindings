<?php

namespace App\Http\Responses;

use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

/**
 * Too many sign-in attempts. Same behaviour as Fortify's response, plus the
 * wait in seconds so the sign-in page can show a countdown on its button.
 */
class LockoutResponse implements LockoutResponseContract
{
    public function __construct(protected LoginRateLimiter $limiter) {}

    public function toResponse($request)
    {
        $seconds = (int) $this->limiter->availableIn($request);

        if ($request->hasSession()) {
            $request->session()->flash('loginRetryAfter', $seconds);
        }

        throw ValidationException::withMessages([
            Fortify::username() => [self::message()],
        ])->status(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public static function message(): string
    {
        return 'Too many attempts. To keep your account safe, wait a minute and try again.';
    }
}
