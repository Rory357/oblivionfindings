<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalSignInLink;
use App\Services\Portal\PortalSessionStarter;
use App\Services\Portal\PortalSignInLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Email me a sign-in link" on the family portal sign-in page. */
class PortalSignInLinkController extends Controller
{
    public function __construct(
        private readonly PortalSignInLinkService $links,
        private readonly PortalSessionStarter $sessions,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $deviceSecret = $this->links->request($data['email'], $request->ip());

        // The same response whether or not an account exists.
        return redirect()
            ->route('portal.login')
            ->with('portalLinkSentTo', strtolower(trim($data['email'])))
            ->withCookie(cookie(
                PortalSignInLink::DEVICE_COOKIE,
                $deviceSecret,
                PortalSignInLink::VALID_FOR_MINUTES + 5,
                httpOnly: true,
                sameSite: 'lax',
            ));
    }

    public function consume(Request $request, string $token): RedirectResponse
    {
        $result = $this->links->consume($token, $request->cookie(PortalSignInLink::DEVICE_COOKIE));

        if ($result['user'] === null) {
            $message = $result['failure'] === 'device'
                ? 'Open the sign-in link in the same browser where you asked for it, or ask for a new link here.'
                : 'This sign-in link has expired or was already used. Ask for a new one below.';

            return redirect()->route('portal.login')->withErrors(['link' => $message]);
        }

        return $this->sessions
            ->start($request, $result['user'])
            ->withoutCookie(PortalSignInLink::DEVICE_COOKIE);
    }
}
