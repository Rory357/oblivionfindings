<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\SsoGroupMappingLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IdentityDisconnectController extends Controller
{
    public function destroy(Request $request, string $provider)
    {
        abort_unless(in_array($provider, ['microsoft', 'google'], true), 404);

        $user = $request->user();
        abort_unless($user, 401);

        DB::transaction(function () use ($user, $provider, $request): void {
            // Share the publication mutex with sign-in and explicit identity linking.
            app(SsoGroupMappingLockService::class)->lockMappingSet();
            $current = app(AuthorizationEvidenceLockService::class)->lockForUsers([(int) $user->id], [])->get((int) $user->id);
            abort_unless($current?->isApproved(), 403);
            $identities = $current->identities()->where('provider', $provider)->orderBy('id')->lockForUpdate()->get();
            foreach ($identities as $identity) {
                $identity->delete();
            }
            AuditLogger::logOrFail('identity.disconnected', $current, [
                'provider' => $provider,
                'deleted' => $identities->count(),
                'identity_ids' => $identities->modelKeys(),
            ], $request);
        }, 3);
        $request->session()->forget(['sso.flow', 'oauth_link_user']);

        return back()->with('success', ucfirst($provider).' account disconnected.');
    }
}
