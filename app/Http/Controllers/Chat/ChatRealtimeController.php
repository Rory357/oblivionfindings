<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatRealtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

final class ChatRealtimeController extends Controller
{
    public function configuration(Request $request, ChatRealtime $realtime)
    {
        if (! $realtime->configured()) {
            return response()->json(['actor_id' => (int) $request->user()->id, 'enabled' => false])->header('Cache-Control', 'private, no-store');
        }
        abort_unless($realtime->eligible($request->user()), 403);
        if ($realtime->usesLive()) {
            // Oblivion Live: the browser asks /chat/api/live/ticket for a ticket, then connects.
            return response()->json(['actor_id' => (int) $request->user()->id, 'enabled' => true, 'transport' => 'live',
                'path' => config('chat.live.path')])->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['actor_id' => (int) $request->user()->id, 'enabled' => true, 'transport' => 'reverb', 'key' => config('broadcasting.connections.chat.key'),
            'path' => config('chat.realtime.path'), 'channel' => 'private-chat.user.'.$request->user()->id,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function authorizeChannel(Request $request, ChatRealtime $realtime)
    {
        $data = $request->validate(['socket_id' => ['required', 'string', 'max:100', 'regex:/^\d+\.\d+$/'], 'channel_name' => ['required', 'string', 'max:100']]);
        abort_unless(! $realtime->usesLive() && $realtime->eligible($request->user()) && $data['channel_name'] === 'private-chat.user.'.$request->user()->id, 403);

        // Deliberately sign only this viewer's channel. No client-supplied care-record
        // channel or generic broadcast authorisation can expand this grant.
        return response()->json(Broadcast::connection('chat')->validAuthenticationResponse($request, true))->header('Cache-Control', 'private, no-store');
    }
}
