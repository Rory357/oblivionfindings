<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * After signing out: the "You've signed out" page for the right audience.
 * The audience is captured from the Logout event (the user is gone by now).
 */
class LogoutResponse implements LogoutResponseContract
{
    public const AUDIENCE_ATTRIBUTE = 'signed_out_audience';

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return $request->attributes->get(self::AUDIENCE_ATTRIBUTE) === 'family'
            ? redirect()->route('signed-out', ['for' => 'family'])
            : redirect()->route('signed-out');
    }
}
