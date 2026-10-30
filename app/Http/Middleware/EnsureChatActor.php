<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureChatActor
{
    public function handle(Request $request, Closure $next, string $transport = 'header'): Response
    {
        // Bind browser intent to the rendered account. This is an additional
        // stale-tab check, never a substitute for session authentication or access.
        $actor = $request->header('X-Chat-Actor');
        // Native download links cannot send a custom header. File routes alone
        // accept a non-secret expected-account query; it grants no file access.
        $queryActor = $transport === 'file' ? $request->query('_chat_actor') : null;
        $conflict = $queryActor !== null && $actor !== null && $queryActor !== $actor;
        if ($transport === 'file' && $actor === null) {
            $actor = $queryActor;
        }
        $current = $request->user()?->getAuthIdentifier();
        if ($conflict || ! is_string($actor) || ! preg_match('/^[1-9][0-9]*$/D', $actor)
            || $current === null || $actor !== (string) $current) {
            return new JsonResponse([
                'message' => 'Your signed-in account has changed. Refresh this page before continuing.',
                'code' => 'chat_actor_changed',
            ], 403, ['Cache-Control' => 'private, no-store']);
        }

        return $next($request);
    }
}
