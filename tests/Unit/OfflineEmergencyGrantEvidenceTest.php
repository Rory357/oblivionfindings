<?php

use App\Models\ClientBreakGlassAccess;
use App\Services\Medication\EmergencyAccess\OfflineGrantEvidence;
use Carbon\CarbonImmutable;

function p10OfflineGrant(): ClientBreakGlassAccess
{
    $grant = new ClientBreakGlassAccess;
    $grant->created_at = CarbonImmutable::parse('2026-10-03T08:00:00Z');
    $grant->expires_at = CarbonImmutable::parse('2026-10-03T09:00:00Z');

    return $grant;
}

it('accepts an offline capture inside the grant and refuses an ordinary retrospective claim', function () {
    $grant = p10OfflineGrant();
    $at = CarbonImmutable::parse('2026-10-03T08:30:00Z');
    expect(OfflineGrantEvidence::eligible($grant, ['queued_offline' => true, 'captured_offline_at' => $at->toIso8601String()], $at, false))->toBeTrue()
        ->and(OfflineGrantEvidence::eligible($grant, ['queued_offline' => false, 'captured_offline_at' => $at->toIso8601String()], $at, false))->toBeFalse();
});

it('never permits an offline dose that needs a second person', function () {
    $at = CarbonImmutable::parse('2026-10-03T08:30:00Z');
    expect(OfflineGrantEvidence::eligible(p10OfflineGrant(), ['queued_offline' => true, 'captured_offline_at' => $at->toIso8601String()], $at, true))->toBeFalse();
});

it('uses the actual early end and an exclusive expiry boundary', function () {
    $grant = p10OfflineGrant();
    $grant->ended_at = CarbonImmutable::parse('2026-10-03T08:20:00Z');
    $at = CarbonImmutable::parse('2026-10-03T08:30:00Z');
    expect(OfflineGrantEvidence::eligible($grant, ['queued_offline' => true, 'captured_offline_at' => $at->toIso8601String()], $at, false))->toBeFalse();
    $grant = p10OfflineGrant();
    $at = CarbonImmutable::parse('2026-10-03T09:00:00Z');
    expect(OfflineGrantEvidence::eligible($grant, ['queued_offline' => true, 'captured_offline_at' => $at->toIso8601String()], $at, false))->toBeFalse();
});

it('rejects a missing timezone or clinical time outside the window', function () {
    $grant = p10OfflineGrant();
    expect(OfflineGrantEvidence::eligible($grant, ['queued_offline' => true, 'captured_offline_at' => '2026-10-03T08:30:00'], CarbonImmutable::parse('2026-10-03T08:30:00Z'), false))->toBeFalse()
        ->and(OfflineGrantEvidence::eligible($grant, ['queued_offline' => true, 'captured_offline_at' => '2026-10-03T08:30:00Z'], CarbonImmutable::parse('2026-10-03T07:59:00Z'), false))->toBeFalse();
});
