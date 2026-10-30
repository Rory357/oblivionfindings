<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalInvitation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Portal\PortalInvitationService;
use App\Services\Portal\PortalSessionStarter;
use App\Services\SsoConfigurationService;
use App\Support\SecurityPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The family invitation page: choose a password or link Google/Microsoft. */
class PortalInvitationController extends Controller
{
    public const SSO_SESSION_KEY = 'portal.invitation';

    public function __construct(
        private readonly PortalInvitationService $invitations,
        private readonly PortalSessionStarter $sessions,
    ) {}

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->invitations->find($token);

        if (! $invitation instanceof PortalInvitation || $invitation->isRevoked() || ! $invitation->user instanceof User) {
            return Inertia::render('portal/invitation', ['state' => 'invalid', 'token' => $token]);
        }

        if ($invitation->isAccepted()) {
            return redirect()->route('portal.login')->with('portalNotice', 'Your account is already set up. Sign in below.');
        }

        if ($invitation->isExpired()) {
            return Inertia::render('portal/invitation', [
                'state' => 'expired',
                'token' => $token,
                'maskedEmail' => PortalInvitationService::maskEmail((string) $invitation->user->email),
                'resent' => (bool) $request->session()->get('portalInvitationResent', false),
            ]);
        }

        return Inertia::render('portal/invitation', [
            'state' => 'active',
            'token' => $token,
            'email' => $invitation->user->email,
            'inviterName' => $invitation->inviter?->name,
            'clientFirstName' => $invitation->client?->first_name,
            'expiresOn' => $invitation->expires_at->copy()->setTimezone((string) config('app.worker_timezone'))->format('D j M'),
            'ssoProviders' => app(SsoConfigurationService::class)->availability('portal'),
            'passwordRules' => SecurityPolicy::passwordRequirements(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', SecurityPolicy::passwordRule(), 'confirmed'],
        ]);

        $invitation = $this->invitations->find($token);
        if (! $invitation instanceof PortalInvitation) {
            return redirect()->route('portal.invitations.show', ['token' => $token]);
        }

        $user = DB::transaction(function () use ($invitation, $data): User {
            $locked = PortalInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->first();
            if (! $locked->isActive() || ! $user instanceof User || ! $user->isApproved() || ! $user->isPortalAccount()) {
                throw ValidationException::withMessages([
                    'password' => 'This invitation can no longer be used. Ask for a new one.',
                ]);
            }

            $user->forceFill([
                'password' => $data['password'],
                // Opening the emailed invitation proves mailbox control.
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();
            $locked->forceFill(['accepted_at' => now()])->save();

            AuditLogger::logOrFail('portal.invitation.accepted', null, [
                'actor_id' => (int) $user->id,
                'invitation_id' => (int) $locked->id,
                'method' => 'password',
            ]);

            return $user;
        }, 3);

        return $this->sessions->start($request, $user);
    }

    public function resend(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->find($token);
        if ($invitation instanceof PortalInvitation) {
            $this->invitations->renew($invitation);
        }

        // Same response whatever happened, so the link holder learns nothing new.
        return redirect()
            ->route('portal.invitations.show', ['token' => $token])
            ->with('portalInvitationResent', true);
    }

    /** Starts Google/Microsoft sign-in that links the provider to the invited account. */
    public function sso(Request $request, string $token, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, SsoConfigurationService::PROVIDERS, true), 404);

        $invitation = $this->invitations->find($token);
        if (! $invitation instanceof PortalInvitation || ! $invitation->isActive()) {
            return redirect()->route('portal.invitations.show', ['token' => $token]);
        }

        $request->session()->put(self::SSO_SESSION_KEY, [
            'id' => (int) $invitation->id,
            'user_id' => (int) $invitation->user_id,
            'started_at' => now()->timestamp,
        ]);

        return redirect()->route('portal.auth.'.$provider);
    }
}
