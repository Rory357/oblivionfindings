<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse as FortifyTwoFactorLoginResponse;

/** Completing MFA uses Fortify's separate response contract. */
final class TwoFactorLoginResponse extends FortifyTwoFactorLoginResponse
{
    public function toResponse($request)
    {
        $user = $request->user();
        if ($user instanceof User && $user->isExternalClinicianAccount()) {
            $request->session()->forget('url.intended');

            return $request->wantsJson() ? new JsonResponse('', 204) : redirect('/clinical-portal');
        }

        return parent::toResponse($request);
    }
}
