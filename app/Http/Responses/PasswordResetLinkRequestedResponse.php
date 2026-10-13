<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * "Check your email" whether or not the address has an account, so the
 * forgot-password form can't be used to find out who has one. Bound to both
 * Fortify's success and failure responses.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        $message = 'If that email has an account, we have sent a link to reset the password.';

        if ($request->wantsJson()) {
            return new JsonResponse(['message' => $message], 200);
        }

        return back()
            ->with('status', $message)
            ->with('resetLinkSentTo', strtolower(trim((string) $request->input('email'))));
    }
}
