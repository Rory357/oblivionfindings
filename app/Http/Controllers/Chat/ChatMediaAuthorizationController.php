<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatCallParticipant;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatSettings;
use App\Services\Chat\SelfHostedCalling;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

/** Self-hosted signalling gateway: each initial connection and reconnect rechecks current rights. */
final class ChatMediaAuthorizationController extends Controller
{
    public function __invoke(Request $request, ChatAccess $access, ChatSettings $settings, SelfHostedCalling $media)
    {
        if (! config('chat.shared_experience') || ! $media->ready()) {
            return response('', 403);
        }
        $token = $request->header('X-Chat-Access-Token', '');
        if (strlen($token) > 4096) {
            return response('', 403);
        }
        try {
            $claims = JWT::decode($token, new Key(config('chat.calling.api_secret'), 'HS256'));
        } catch (\Throwable) {
            return response('', 403);
        }
        if (($claims->iss ?? null) !== config('chat.calling.api_key') || ! ($claims->video->roomJoin ?? false)) {
            return response('', 403);
        }
        $participant = ChatCallParticipant::where('media_identity', $claims->sub ?? '')
            ->where('call_id', $claims->video->room ?? '')
            ->whereIn('status', ['accepted', 'connected'])
            ->with(['user', 'call.conversation.client'])->first();
        if (! $participant?->user || ! $participant->call || $participant->call->ended_at) {
            return response('', 403);
        }
        $conversation = $participant->call->conversation;
        $policy = $settings->forConversation($conversation);
        if ($conversation->is_archived
            || ($participant->call->status !== 'active' && (! $policy['enabled'] || ! $policy['calls_enabled']
                || ($conversation->conversation_type === 'family' && (! $policy['family_enabled'] || ! $policy['family_calls_enabled']))))
            || ! $access->allows($participant->user, $conversation, true)) {
            return response('', 403);
        }

        return response('', 204)->header('Cache-Control', 'private, no-store');
    }
}
