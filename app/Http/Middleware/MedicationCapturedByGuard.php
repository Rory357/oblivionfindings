<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * eMAR audit Lane A (EA-047; review item 5, 10 Oct): every medication action
 * saved on a device names the worker who saved it — doses, controlled
 * register entries, balance checks, destructions, loss reports, stock
 * updates, corrections, follow-ups. Sent under anyone else's session (a
 * stale tab on a shared PC), it is refused before it reaches any handler,
 * so it is never recorded under the wrong name. The device keeps it for the
 * worker who saved it (the same answer the dose endpoints already give).
 */
final class MedicationCapturedByGuard
{
    public const MESSAGE = 'This was saved on this device by another worker, so it can’t be sent under your name. It sends when they sign in on this device.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && $request->exists('captured_by_user_id')) {
            $capturedBy = $request->input('captured_by_user_id');
            $user = $request->user();
            if ($capturedBy !== null && ($user === null
                || filter_var($capturedBy, FILTER_VALIDATE_INT) === false
                || (int) $capturedBy !== (int) $user->getAuthIdentifier())) {
                throw ValidationException::withMessages(['captured_by_user_id' => self::MESSAGE]);
            }
        }

        return $next($request);
    }
}
