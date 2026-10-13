<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\Obli\ObliStaffAccess;
use App\Models\User;
use Illuminate\Http\Request;

final class EndpointSoftwareIdentity
{
    public function begin(Request $request, User $actor): void
    {
        $request->session()->forget('auth.password_confirmed_at');
        $request->session()->put('endpoint_software_identity', ['actor_id' => (int) $actor->id, 'fingerprint' => ObliStaffAccess::fingerprint($actor), 'started_at' => now()->timestamp]);
        $request->session()->put('url.intended', '/security-devices/software?expected_actor_id='.$actor->id.'&status=pending_review');
    }

    public function confirmed(User $actor): bool
    {
        $request = app('request');
        if (! $request instanceof Request || ! $request->hasSession()) {
            return false;
        }

        return $this->matches($actor, $request->session()->get('endpoint_software_identity'), $request->session()->get('auth.password_confirmed_at'), now()->timestamp);
    }

    private function matches(User $actor, mixed $proof, mixed $confirmedAt, int $now): bool
    {
        // This proof is created only by the server before Fortify verifies the current actor's password.
        return is_array($proof) && array_keys($proof) === ['actor_id', 'fingerprint', 'started_at']
            && $proof['actor_id'] === (int) $actor->id && is_string($proof['fingerprint'])
            && hash_equals(ObliStaffAccess::fingerprint($actor), $proof['fingerprint'])
            && is_int($proof['started_at']) && is_int($confirmedAt)
            && $proof['started_at'] <= $confirmedAt && $confirmedAt <= $now && $confirmedAt >= $now - 900;
    }
}
