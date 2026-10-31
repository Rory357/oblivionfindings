<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\BroadcastIntent;
use App\Models\ControlRoom\Communication;
use App\Models\User;
use App\Support\ControlRoom\BroadcastCommandInput;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The broadcast intent ledger behind in-app staff messages.
 *
 * When Control Room sends a broadcast, record() keeps an immutable copy of what was
 * sent and to whom (control_room_broadcast_intents) and gives every Communication row
 * its member key. In-app rows also get typed delivery state, which the availability
 * writer advances when the recipient's inbox notice is created. Email, SMS and push
 * rows keep their existing status-only delivery.
 *
 * Key and digest formulas match the R78B BroadcastIntentService so the same rows stay
 * readable when that service lands.
 */
final class BroadcastRecipientLedger
{
    /**
     * @param  array{content:string,channels:list<string>,target_roles:list<string>,send_to_all:bool,template:?string,force_delivery:bool}  $intent
     * @param  Collection<int, User>  $targets
     */
    public function record(User $actor, string $groupId, array $intent, Collection $targets, DateTimeInterface $at): BroadcastIntent
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The broadcast ledger is written inside the sending transaction.');
        }
        $captured = self::capture($intent);
        $ids = $targets->pluck('id')->map(fn ($id) => (int) $id)->all();

        $ledger = new BroadcastIntent;
        $ledger->forceFill([
            'id' => $groupId,
            'actor_id' => (int) $actor->id,
            'idempotency_key' => (string) Str::uuid(),
            'payload_digest' => BroadcastCommandInput::digestIntent($captured),
            'selection_target_digest' => self::targetDigest($ids),
            'captured_intent' => $captured,
            'selection_outcome' => $ids === [] ? 'no_eligible_recipients' : 'queued',
            'selection_reason' => $ids === [] ? 'no_eligible_recipients' : null,
            'selection_checked_at' => $at,
            'selected_people_count' => count(array_unique($ids)),
            'selected_channel_attempt_count' => count(array_unique($ids)) * count($captured['channels']),
            'created_at' => $at,
            'updated_at' => $at,
        ])->saveOrFail();

        foreach (Communication::query()->where('broadcast_group_id', $groupId)->where('purpose', 'broadcast')->lockForUpdate()->get() as $row) {
            $attributes = ['delivery_key' => self::memberKey($groupId, (int) $row->target_user_id, (string) $row->channel)];
            if ($row->channel === 'in_app') {
                $attributes += [
                    'broadcast_delivery_state' => 'queued',
                    'broadcast_attempt_count' => 0,
                    'broadcast_outcome' => ['version' => 1, 'availability' => 'unknown', 'attempt_outcome' => 'not_attempted', 'reason' => null, 'receipts' => []],
                ];
            }
            $row->forceFill($attributes)->saveOrFail();
        }

        return $ledger;
    }

    /**
     * Claim a queued in-app row for one delivery attempt. Returns the attempt token, or
     * null when the row has no typed state (sent before the ledger existed) or was
     * already claimed: an in-app notice is never created twice for one row.
     */
    public function claimInApp(int $communicationId): ?string
    {
        return DB::transaction(function () use ($communicationId): ?string {
            $row = Communication::query()->whereKey($communicationId)->lockForUpdate()->first();
            if (! $row || $row->purpose !== 'broadcast' || $row->channel !== 'in_app'
                || $row->broadcast_delivery_state !== 'queued' || (int) $row->broadcast_attempt_count !== 0
                || $row->broadcast_attempt_token !== null) {
                return null;
            }
            $token = (string) Str::uuid();
            $row->forceFill([
                'broadcast_delivery_state' => 'attempting',
                'broadcast_attempt_token' => $token,
                'broadcast_attempt_count' => 1,
                'broadcast_attempt_started_at' => now('UTC')->startOfSecond(),
                'broadcast_outcome' => array_replace((array) $row->broadcast_outcome, ['attempt_outcome' => 'unknown', 'reason' => null]),
            ])->saveOrFail();

            return $token;
        });
    }

    /** A queued in-app row that will not be attempted (for example, the channel is turned off). */
    public function settleWithoutAttempt(int $communicationId, string $state, string $reason): void
    {
        DB::transaction(function () use ($communicationId, $state, $reason): void {
            $row = Communication::query()->whereKey($communicationId)->lockForUpdate()->first();
            if (! $row || $row->channel !== 'in_app' || $row->broadcast_delivery_state !== 'queued') {
                return;
            }
            $row->forceFill([
                'broadcast_delivery_state' => $state,
                'broadcast_outcome_at' => now('UTC')->startOfSecond(),
                'broadcast_outcome' => array_replace((array) $row->broadcast_outcome, ['availability' => 'unavailable', 'attempt_outcome' => 'not_attempted', 'reason' => $reason]),
            ])->saveOrFail();
        });
    }

    /** The audience exactly as selected: "everyone" when no role was chosen. */
    public static function capture(array $intent): array
    {
        $channels = array_values(array_filter(BroadcastCommandInput::CHANNELS, fn ($c) => in_array($c, $intent['channels'] ?? [], true)));
        $roles = array_values(array_filter(BroadcastCommandInput::ROLES, fn ($r) => in_array($r, $intent['target_roles'] ?? [], true)));
        $everyone = (bool) ($intent['send_to_all'] ?? false) || $roles === [];

        return [
            'content' => (string) $intent['content'],
            'channels' => $channels,
            'target_roles' => $everyone ? [] : $roles,
            'send_to_all' => $everyone,
            'template' => $intent['template'] ?? null,
            'force_delivery' => (bool) ($intent['force_delivery'] ?? false),
        ];
    }

    public static function targetDigest(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if ($id < 1 || $id > 9007199254740991) {
                throw new LogicException('Invalid broadcast selection ID.');
            }
        }

        return hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
    }

    public static function memberKey(string $group, int $user, string $channel): string
    {
        if (! BroadcastCommandInput::canonicalUuid($group) || $user < 1 || $user > 9007199254740991 || ! in_array($channel, BroadcastCommandInput::CHANNELS, true)) {
            throw new LogicException('Invalid broadcast tuple.');
        }

        return hash('sha256', json_encode(['broadcast', $group, $user, $channel], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
