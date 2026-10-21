<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatCall;
use App\Services\Chat\ChatWebhookVerifier;
use App\Services\Chat\SelfHostedCalling;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ChatMediaWebhookController extends Controller
{
    public function __invoke(Request $request, ChatWebhookVerifier $verifier, SelfHostedCalling $media)
    {
        abort_unless($media->configured(), 503);
        try {
            $event = $verifier->decode($request->getContent(), $request->header('Authorization', ''));
        } catch (\Throwable) {
            return response('', 401);
        }
        $data = Validator::make($event, [
            'id' => ['required', 'string', 'max:80'], 'event' => ['required', 'string', 'max:60'],
            'createdAt' => ['required', 'integer', 'min:1'], 'room.name' => ['required', 'string', 'max:80'],
            'participant.identity' => ['sometimes', 'string', 'max:80'],
        ])->validate();
        abort_if((int) $data['createdAt'] > time() + 60, 422);
        DB::transaction(function () use ($data): void {
            $call = ChatCall::whereKey($data['room']['name'])->lockForUpdate()->first();
            if (! $call) {
                return;
            }
            $occurred = CarbonImmutable::createFromTimestampUTC((int) $data['createdAt']);
            if (! DB::table('chat_media_events')->insertOrIgnore(['id' => $data['id'], 'call_id' => $call->id, 'event' => $data['event'], 'occurred_at' => $occurred, 'received_at' => now()])) {
                return;
            }
            if ($call->ended_at) {
                return;
            }
            if ($data['event'] === 'room_finished') {
                $call->update(['status' => 'ended', 'ended_at' => $occurred]);
                $call->participants()->whereIn('status', ['accepted', 'connected', 'invited'])->update(['status' => 'left', 'left_at' => $occurred]);

                return;
            }
            $participant = $call->participants()->where('media_identity', $data['participant']['identity'] ?? '')->first();
            if (! $participant || ! in_array($participant->status, ['accepted', 'connected'], true)) {
                return;
            }
            if ($data['event'] === 'participant_joined') {
                $participant->update(['status' => 'connected', 'joined_at' => $participant->joined_at ?? $occurred]);
                if (! $call->connected_at && $call->participants()->where('status', 'connected')->count() >= 2) {
                    $call->update(['connected_at' => $call->participants()->where('status', 'connected')->max('joined_at')]);
                }
            } elseif (in_array($data['event'], ['participant_left', 'participant_connection_aborted'], true)
                && (! $participant->joined_at || $occurred->greaterThanOrEqualTo($participant->joined_at))) {
                $participant->update(['status' => 'left', 'left_at' => $occurred]);
            }
        });

        return response('', 204)->header('Cache-Control', 'private, no-store');
    }
}
