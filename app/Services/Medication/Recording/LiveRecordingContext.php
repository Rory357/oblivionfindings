<?php

namespace App\Services\Medication\Recording;

use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\User;
use App\Services\Medication\Downtime\PaperEntryService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** A bounded connected form review, never an administration grant or offline proof. */
final class LiveRecordingContext
{
    public const REVIEW_MINUTES = 30;

    public static function issue(User $actor, ClientMedication $order, CarbonInterface $checkedAt): string
    {
        $at = CarbonImmutable::instance($checkedAt)->utc();
        $payload = self::encode(json_encode([
            'actor_id' => (int) $actor->id, 'order_id' => (int) $order->id, 'site_id' => (int) $order->client->site_id,
            'order_fingerprint' => PaperEntryService::orderFingerprint($order),
            'stock_fingerprint' => self::stockFingerprint($order->stock()->first()),
            'checked_at' => $at->toIso8601String(), 'expires_at' => $at->addMinutes(self::REVIEW_MINUTES)->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::signature($payload);
    }

    /** Called after current canonical authority and exact retained replay, before new physical posting. */
    public static function validate(?string $token, User $actor, ClientMedication $order, ClientMedicationStock $stock, CarbonInterface $actualAt, CarbonInterface $receivedAt): ?array
    {
        $invalid = ['success' => false, 'error_field' => 'live_recording_context',
            'error' => 'The connected recording review no longer matches this worker, medicine or stock context. Keep the draft and review the current dose again; earlier actual facts need reviewed paper recovery.'];
        try {
            $parts = explode('.', (string) $token);
            if (count($parts) !== 2 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0]) || ! hash_equals(self::signature($parts[0]), $parts[1])) {
                return $invalid;
            }
            $facts = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($facts) || (int) ($facts['actor_id'] ?? 0) !== (int) $actor->id
                || (int) ($facts['order_id'] ?? 0) !== (int) $order->id || (int) ($facts['site_id'] ?? 0) !== (int) $order->client->site_id
                || ! hash_equals($facts['order_fingerprint'] ?? '', PaperEntryService::orderFingerprint($order))
                || ! hash_equals($facts['stock_fingerprint'] ?? '', self::stockFingerprint($stock))) {
                return $invalid;
            }
            $checked = CarbonImmutable::parse($facts['checked_at'])->utc();
            $expires = CarbonImmutable::parse($facts['expires_at'])->utc();
            if ($checked->gt($receivedAt) || $expires->lte(now()) || ! $expires->equalTo($checked->addMinutes(self::REVIEW_MINUTES))) {
                return ['success' => false, 'error_field' => 'live_recording_context',
                    'error' => 'The connected recording review expired after 30 minutes. Keep the draft. Review the current dose again, or use reviewed paper recovery for earlier actual facts; no dose time is reset.'];
            }
            if (CarbonImmutable::instance($actualAt)->utc()->lt($checked->startOfMinute())) {
                return ['success' => false, 'error_field' => 'administered_at',
                    'error' => 'The actual dose time is earlier than this connected review. Keep the signed actual dose and pack evidence for reviewed paper recovery; current stock cannot prove earlier pack use.'];
            }
        } catch (\Throwable) {
            return $invalid;
        }

        return null;
    }

    private static function stockFingerprint(?ClientMedicationStock $stock): string
    {
        return hash('sha256', json_encode([$stock?->id, $stock?->getRawOriginal('lots_started_at'), $stock?->unit], JSON_THROW_ON_ERROR));
    }

    private static function signature(string $payload): string
    {
        return self::encode(hash_hmac('sha256', $payload, (string) config('app.key'), true));
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
