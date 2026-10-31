<?php

namespace App\Services\ControlRoom;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\ControlRoom\BroadcastIntent;
use App\Models\ControlRoom\Communication;
use App\Models\User;
use App\Notifications\ControlRoom\BroadcastRecipientNotice;
use App\Services\AuditLogger;
use App\Support\ControlRoom\BroadcastCommandInput;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Makes one claimed in-app broadcast row available in its recipient's inbox.
 *
 * publish() runs once per claimed attempt token. It re-checks the ledger link and the
 * recipient's current eligibility, creates the BroadcastRecipientNotice, then records
 * the outcome on the Communication row ("available" + the notice UUID and time) and an
 * immutable audit entry holding IDs and digests only, never the message text. Nothing
 * is backfilled: rows without a claimed token, and rows sent before the ledger existed,
 * stay unavailable.
 */
final class BroadcastRecipientAvailabilityService
{
    public const AUDIT_ACTION = 'controlRoom.broadcast.inAppAvailable';

    public function publish(int $communicationId, string $attemptToken): void
    {
        if (! BroadcastCommandInput::canonicalUuid($attemptToken)) {
            throw new LogicException('Invalid broadcast attempt token.');
        }

        DB::transaction(function () use ($communicationId, $attemptToken): void {
            $row = Communication::query()->whereKey($communicationId)->lockForUpdate()->first();
            if (! $row || $row->purpose !== 'broadcast' || $row->channel !== 'in_app'
                || $row->broadcast_delivery_state !== 'attempting' || (int) $row->broadcast_attempt_count !== 1
                || $row->broadcast_attempt_token !== $attemptToken) {
                return; // Another attempt owns this row, or it already settled.
            }
            $intent = BroadcastIntent::query()->whereKey($row->broadcast_group_id)->lockForUpdate()->first();
            $reason = $this->refusal($row, $intent);
            if ($reason !== null) {
                $this->settle($row, 'superseded', ['availability' => 'unavailable', 'attempt_outcome' => 'not_attempted', 'reason' => $reason]);

                return;
            }
            if (DatabaseNotification::query()->where('type', BroadcastRecipientNotice::class)
                ->where('data->communication_id', (int) $row->id)->exists()) {
                throw new LogicException('This broadcast already has an inbox notice.');
            }

            /** @var User $recipient */
            $recipient = User::query()->whereKey($row->target_user_id)->firstOrFail();
            $noticeId = (string) Str::uuid();
            $recipient->notifyNow(new BroadcastRecipientNotice($noticeId, (string) $intent->id, (int) $row->id, (int) $recipient->id));
            $notice = DatabaseNotification::query()->whereKey($noticeId)->first();
            if (! $notice || $notice->type !== BroadcastRecipientNotice::class || (int) $notice->notifiable_id !== (int) $recipient->id) {
                throw new LogicException('The broadcast inbox notice was not stored.');
            }

            $availableAt = BroadcastRecipientReadService::iso($notice->created_at);
            $this->settle($row, 'in_app_available', [
                'availability' => 'available',
                'attempt_outcome' => 'accepted',
                'reason' => null,
                'recipient_notification_id' => $noticeId,
                'recipient_available_at' => $availableAt,
            ], $notice->created_at);

            AuditLogger::logOrFail(self::AUDIT_ACTION, $row, self::auditMeta($intent, $row, $noticeId, (array) $notice->data), null, true);
        });
    }

    /** IDs and digests only: enough to prove the link later, never the message text. */
    public static function auditMeta(BroadcastIntent $intent, Communication $row, string $noticeId, array $reference): array
    {
        return [
            'intent_id' => (string) $intent->id,
            'communication_id' => (int) $row->id,
            'recipient_user_id' => (int) $row->target_user_id,
            'notification_id' => $noticeId,
            'attempt_token' => (string) $row->broadcast_attempt_token,
            'content_sha256' => hash('sha256', (string) $row->content),
            'reference_sha256' => hash('sha256', BroadcastRecipientReadService::json($reference)),
            'payload_digest' => (string) $intent->payload_digest,
        ];
    }

    private function refusal(Communication $row, ?BroadcastIntent $intent): ?string
    {
        if (! $intent || ! BroadcastCommandInput::canonicalUuid((string) $intent->id)
            || (int) $row->initiated_by_user_id !== (int) $intent->actor_id
            || $row->delivery_key !== BroadcastRecipientLedger::memberKey((string) $intent->id, (int) $row->target_user_id, 'in_app')
            || ! is_array($intent->captured_intent)
            || ! hash_equals((string) $intent->payload_digest, BroadcastCommandInput::digestIntent($intent->captured_intent))
            || (string) $row->content !== (string) $intent->captured_intent['content']) {
            return 'ledger_mismatch';
        }
        if (! app(HrCurrentStaffService::class)->isCurrent((int) $row->target_user_id)) {
            return 'recipient_unavailable';
        }
        if (! $intent->captured_intent['send_to_all']
            && ! User::query()->whereKey($row->target_user_id)
                ->whereHas('roles', fn ($roles) => $roles->whereIn('name', $intent->captured_intent['target_roles']))
                ->exists()) {
            return 'recipient_unavailable';
        }

        return null;
    }

    private function settle(Communication $row, string $state, array $outcome, $at = null): void
    {
        $row->forceFill([
            'broadcast_delivery_state' => $state,
            'broadcast_outcome_at' => $at ?? now('UTC')->startOfSecond(),
            'broadcast_outcome' => array_replace((array) $row->broadcast_outcome, $outcome),
        ])->saveOrFail();
    }
}
