<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $timeoutMinutes = SecurityPolicy::sessionTimeoutMinutes();
        if ($timeoutMinutes <= 0 || $request->is('logout')) {
            return $next($request);
        }

        $lastActivity = $request->session()->get('last_activity_at');
        if ($lastActivity && (now()->timestamp - (int) $lastActivity) > ($timeoutMinutes * 60)) {
            $user = $request->user();
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Fetch must receive an authentication failure, not a followed sign-in HTML page.
            // The calling provider uses this to stop private audio and clear invitations.
            if ($request->expectsJson() && $request->is(
                'chat/api/*',
                'settings/chat',
                'settings/chat/configuration',
                'settings/chat/service-health',
                'settings/chat/calling-health',
            )) {
                return new JsonResponse([
                    'message' => 'Your session has expired. Sign in again to use Chat.',
                    'code' => 'chat_session_expired',
                ], 401, ['Cache-Control' => 'private, no-store']);
            }

            // Family portal accounts go back to the family sign-in, not staff.
            $route = $user instanceof User && $user->isPortalAccount() ? 'portal.login' : 'login';

            return redirect()
                ->route($route)
                ->withErrors(['session' => "You were signed out after {$timeoutMinutes} minutes without activity, to keep people's information safe."]);
        }

        // Polling, read receipts, presence and transport handshakes are not human activity.
        // Establish a baseline for older sessions, but never advance it on background work.
        if (! $lastActivity || ! $this->isBackgroundChatRequest($request)) {
            $request->session()->put('last_activity_at', now()->timestamp);
        }

        return $next($request);
    }

    private function isBackgroundChatRequest(Request $request): bool
    {
        if (($request->isMethod('GET') || $request->isMethod('HEAD'))
            && $request->is('chat/api/*', 'chat/notifications/*/display')) {
            // Opening a conversation, loading history or searching is reading, so it counts.
            return $request->header('X-Chat-Interactive') !== '1';
        }

        if ($request->isMethod('PATCH') && $request->is('chat/api/availability')) {
            return true;
        }

        // Oblivion Live renews its ticket every 13 minutes from an open tab: that alone must
        // never keep an idle session signed in.
        return $request->isMethod('POST') && $request->is(
            'chat/api/live/ticket',
            'chat/api/conversations/*/changes',
            'chat/api/conversations/*/read',
            'chat/api/calls/*/connected',
        );
    }
}
