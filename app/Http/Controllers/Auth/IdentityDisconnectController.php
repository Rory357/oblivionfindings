<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class IdentityDisconnectController extends Controller
{
    public function destroy(Request $request, string $provider)
    {
        abort_unless(in_array($provider, ['microsoft', 'google'], true), 404);

        $user = $request->user();
        abort_unless($user, 401);

        $deleted = $user->identities()->where('provider', $provider)->delete();

        AuditLogger::log('identity.disconnected', $user, [
            'provider' => $provider,
            'deleted' => $deleted,
        ], $request);

        return back()->with('success', ucfirst($provider) . ' account disconnected.');
    }
}
