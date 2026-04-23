<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toResponse($request)
    {
        $guard = app(StatefulGuard::class);
        $guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'Account created. An administrator must approve your access before you can log in.';

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 201)
            : redirect()->route('login')->with('status', $message);
    }
}
