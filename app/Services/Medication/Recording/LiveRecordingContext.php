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

    /**
     * EA-060: an open dialog may refresh its own expired review and keep the
     * time the dose was first reviewed, but only for this long after it.
     */
    public const RENEW_WITHIN_MINUTES = 120;

    /**
     * @param  string|null  $renew  the open dialog's previous review. When it is
     *                              this worker's own, for this order and stock
     *                              unchanged, and first reviewed within
     *                              RENEW_WITHIN_MINUTES, the new review keeps
     *                              that first review time as its lower bound.
     */
    public static function issue(User $actor, ClientMedication $order, CarbonInterface $checkedAt, ?string $renew = null): string
    {
        $at = CarbonImmutable::instance($checkedAt)->utc();
        $stock = $order->stock()->first();
        $reviewedFrom = self::renewableFrom($renew, $actor, $order, $stock, $at);
        $payload = self::encode(json_encode([
            'actor_id' => (int) $actor->id, 'order_id' => (int) $order->id, 'site_id' => (int) $order->client->site_id,
            'order_fingerprint' => PaperEntryService::orderFingerprint($order),
            'stock_fingerprint' => self::stockFingerprint($stock),
            'checked_at' => $at->toIso8601String(), 'expires_at' => $at->addMinutes(self::REVIEW_MINUTES)->toIso8601String(),
            ...($reviewedFrom !== null ? ['reviewed_from' => $reviewedFrom->toIso8601String()] : []),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::signature($payload);
    }

    /** The first review time an open dialog's own earlier review proves, if any. */
    private static function renewableFrom(?string $token, User $actor, ClientMedication $order, ?ClientMedicationStock $stock, CarbonImmutable $now): ?CarbonImmutable
    {
        if (blank($token)) {
            return null;
        }
        try {
            $facts = self::verifiedFacts((string) $token);
            if ($facts === null || (int) ($facts['actor_id'] ?? 0) !== (int) $actor->id
                || (int) ($facts['order_id'] ?? 0) !== (int) $order->id || (int) ($facts['site_id'] ?? 0) !== (int) $order->client->site_id
                || ! hash_equals($facts['order_fingerprint'] ?? '', PaperEntryService::orderFingerprint($order))
                || ! hash_equals($facts['stock_fingerprint'] ?? '', self::stockFingerprint($stock))) {
                return null;
            }
            $from = CarbonImmutable::parse($facts['reviewed_from'] ?? $facts['checked_at'])->utc();
            if ($from->gt($now) || $from->lt($now->subMinutes(self::RENEW_WITHIN_MINUTES))) {
                return null;
            }

            return $from;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null the token's facts, only when its signature holds */
    private static function verifiedFacts(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0]) || ! hash_equals(self::signature($parts[0]), $parts[1])) {
            return null;
        }
        $facts = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);

        return is_array($facts) ? $facts : null;
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
            // A refreshed review keeps the first review time it was issued
            // with (EA-060); the signature above covers that field too.
            $from = isset($facts['reviewed_from']) ? CarbonImmutable::parse($facts['reviewed_from'])->utc() : $checked;
            if ($from->gt($checked) || $from->lt($checked->subMinutes(self::RENEW_WITHIN_MINUTES))) {
                return $invalid;
            }
            if (CarbonImmutable::instance($actualAt)->utc()->lt($from->startOfMinute())) {
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
