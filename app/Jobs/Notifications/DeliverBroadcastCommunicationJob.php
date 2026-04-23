<?php

namespace App\Jobs\Notifications;

use App\Mail\BroadcastMail;
use App\Models\ControlRoom\Communication;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Deliver a single Communication row created by
 * {@see \App\Http\Controllers\ControlRoom\ControlRoomBroadcastController::store()}.
 *
 * Fan-out happens at the controller level (one Communication per user per
 * channel). This job handles the transport for one row:
 *   - email → Mail::to()->send(new BroadcastMail(...))
 *   - in_app → mark delivered; inbox reads from Communications directly
 *   - sms / push → fail with a reason when no provider is configured, so an
 *     admin can see *why* it didn't go out rather than having the row sit
 *     pending forever (the previous behaviour).
 *
 * Respects user notification preferences unless the broadcast has
 * `force_delivery=true` (e.g. emergency alerts).
 */
class DeliverBroadcastCommunicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $communicationId)
    {
    }

    public function handle(): void
    {
        /** @var Communication|null $comm */
        $comm = Communication::query()->find($this->communicationId);

        if (! $comm || $comm->status !== 'pending') {
            return;
        }

        // Respect user channel preferences unless this broadcast forces delivery.
        if (! $comm->force_delivery && $this->userHasDisabledChannel($comm)) {
            $comm->forceFill([
                'status' => 'skipped',
                'status_detail' => 'User has this channel disabled for broadcasts.',
            ])->save();
            return;
        }

        try {
            match ($comm->channel) {
                'email' => $this->sendEmail($comm),
                'in_app' => $this->markInApp($comm),
                'sms' => $this->sendSms($comm),
                'push' => $this->sendPush($comm),
                default => $this->markFailed($comm, 'Unsupported channel: ' . $comm->channel),
            };
        } catch (Throwable $e) {
            Log::warning('Broadcast delivery failed', [
                'communication_id' => $comm->id,
                'channel' => $comm->channel,
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($comm, substr($e->getMessage(), 0, 240));

            // Only rethrow if we have retries remaining — otherwise let the
            // status='failed' row stand as the final state.
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    private function sendEmail(Communication $comm): void
    {
        $email = $comm->target_email
            ?? optional($comm->targetUser)->email;

        if (! $email) {
            $this->markFailed($comm, 'No email address on record for the recipient.');
            return;
        }

        Mail::to($email)->send(new BroadcastMail($comm));

        $comm->forceFill([
            'status' => 'delivered',
            'delivered_at' => now(),
            'status_detail' => null,
        ])->save();
    }

    private function markInApp(Communication $comm): void
    {
        // The in-app inbox reads from control_room_communications directly,
        // so marking delivered is sufficient here.
        $comm->forceFill([
            'status' => 'delivered',
            'delivered_at' => now(),
            'status_detail' => null,
        ])->save();
    }

    private function sendSms(Communication $comm): void
    {
        $provider = config('services.sms.provider');

        if (! $provider) {
            $this->markFailed($comm, 'SMS provider is not configured.');
            return;
        }

        // Real SMS transport can be plugged in here once a provider is chosen.
        // For now, fail loudly so ops can see the config gap.
        $this->markFailed($comm, 'SMS provider "' . $provider . '" is not yet implemented.');
    }

    private function sendPush(Communication $comm): void
    {
        $provider = config('services.push.provider');

        if (! $provider) {
            $this->markFailed($comm, 'Push provider is not configured.');
            return;
        }

        $this->markFailed($comm, 'Push provider "' . $provider . '" is not yet implemented.');
    }

    private function markFailed(Communication $comm, string $reason): void
    {
        $comm->forceFill([
            'status' => 'failed',
            'status_detail' => $reason,
        ])->save();
    }

    /**
     * Check if the target user has disabled this channel for broadcasts. We
     * look up a preference keyed as `controlroom.broadcast` — if the user has
     * never opted in/out, we default to allowing delivery.
     */
    private function userHasDisabledChannel(Communication $comm): bool
    {
        if (! $comm->target_user_id) {
            return false;
        }

        $pref = UserNotificationPreference::query()
            ->where('user_id', $comm->target_user_id)
            ->where('key', 'controlroom.broadcast')
            ->first();

        if (! $pref) {
            return false;
        }

        if (! $pref->enabled) {
            return true;
        }

        return match ($comm->channel) {
            'in_app' => ! $pref->channel_inapp,
            'email' => ! $pref->channel_email,
            'push' => ! $pref->channel_push,
            default => false,
        };
    }
}
