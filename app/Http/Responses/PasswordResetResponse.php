<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;

/**
 * After a successful password reset: back to the right sign-in page (family
 * portal accounts to the portal) showing "Password changed".
 */
class PasswordResetResponse implements PasswordResetResponseContract
{
    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => trans($this->status)], 200);
        }

        $user = User::query()->where('email', strtolower(trim((string) $request->input('email'))))->first();
        $route = $user instanceof User && $user->isPortalAccount() ? 'portal.login' : 'login';

        return redirect()->route($route)
            ->with('status', trans($this->status))
            ->with('passwordChanged', true);
    }
}
