<?php

namespace App\Services\Chat;

use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ChatCallReconciler
{
    public function __construct(private readonly ChatAccess $access, private readonly ChatSettings $settings, private readonly SelfHostedCalling $media) {}

    /**
     * Interactive commands (removing a member, ending a call) keep media teardown and
     * the record change atomic: if the voice server fails, nothing changes and the
     * person sees a failure they can retry. The scheduled sweep defers media requests
     * until after commit so an unreachable voice server cannot hold call-row locks or
     * stall the sweep; failed removals are retried on its next run.
     */
    public function reconcile(ChatCall $call, bool $deferMedia = false): void
    {
        $media = [];
        // Interactive callers apply teardown inside the transaction so a failure rolls it back.
        $flush = function () use (&$media, $deferMedia): void {
            if ($deferMedia) {
                return;
            }
            foreach ($media as [$action, $target, $participant]) {
                $action === 'end' ? $this->media->end($target) : $this->media->remove($target, $participant);
            }
            $media = [];
        };
        DB::transaction(function () use ($call, &$media, $flush): void {
            $call = ChatCall::whereKey($call->id)->lockForUpdate()->firstOrFail();
            if ($call->ended_at) {
                return;
            }
            $policy = $this->settings->forConversation($call->conversation);
            $expired = $call->status === 'ringing' && $call->ring_until->lte(now());
            $disabled = ! config('chat.shared_experience') || $call->conversation->is_archived
                || ($call->status !== 'active' && (! $policy['enabled'] || ! $policy['calls_enabled']
                    || ($call->conversation->conversation_type === 'family' && (! $policy['family_enabled'] || ! $policy['family_calls_enabled']))));
            if ($expired || $disabled) {
                $media[] = ['end', $call, null];
                $call->update(['status' => $expired ? 'missed' : 'ended', 'ended_at' => now()]);
                $call->participants()->whereIn('status', ['accepted', 'connected', 'invited'])->update(['status' => 'left', 'left_at' => now()]);
                $flush();

                return;
            }
            foreach ($call->participants()->with('user')->whereIn('status', ['accepted', 'connected', 'invited', 'removing'])->get() as $participant) {
                if ($participant->status === 'removing') {
                    $media[] = ['remove', $call, $participant];
                    $participant->update(['status' => 'removed', 'left_at' => now()]);

                    continue;
                }
                $unauthorised = ! $participant->user || ! $this->access->allows($participant->user, $call->conversation, true);
                $abandoned = $participant->status !== 'invited' && $participant->updated_at->lt(now()->subSeconds(45));
                $invitationExpired = $participant->invitationExpired($call);
                if ($unauthorised || $abandoned || $invitationExpired) {
                    if ($participant->status !== 'invited') {
                        $media[] = ['remove', $call, $participant];
                    }
                    $participant->update(['status' => $unauthorised ? 'revoked' : ($invitationExpired ? 'expired' : 'left'), 'left_at' => now()]);
                }
            }
            $remaining = $call->participants()->whereIn('status', ['accepted', 'connected'])->orderBy('id')->get();
            if ($remaining->isEmpty() || ($remaining->count() === 1 && ! $call->participants()->where('status', 'invited')->exists())) {
                $media[] = ['end', $call, null];
                $call->update(['status' => 'ended', 'ended_at' => now()]);
            } elseif (! $remaining->contains('user_id', $call->host_id)) {
                $host = app(ChatCallHost::class)->successor($call, $remaining);
                if ($host === null) {
                    $media[] = ['end', $call, null];
                    $call->update(['status' => 'ended', 'ended_at' => now()]);
                } else {
                    $call->update(['host_id' => $host]);
                }
            }
            $flush();
        });
        $this->applyMedia($media);
    }

    /** @param  list<array{0: string, 1: ChatCall, 2: ?ChatCallParticipant}>  $actions */
    private function applyMedia(array $actions): void
    {
        foreach ($actions as [$action, $call, $participant]) {
            try {
                $action === 'end' ? $this->media->end($call) : $this->media->remove($call, $participant);
            } catch (\Throwable $error) {
                if ($participant) {
                    // A revoked person must not stay connected: retry the removal on the next run.
                    ChatCallParticipant::whereKey($participant->id)->update(['status' => 'removing']);
                }
                // Report once per call every five minutes, not on every ten-second run.
                if (Cache::add("chat:reconcile-media-error:{$call->id}", true, 300)) {
                    report($error);
                }
            }
        }
    }
}
