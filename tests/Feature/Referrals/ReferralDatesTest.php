<?php

use App\Domain\Referrals\Services\ReferralDates;
use Illuminate\Validation\ValidationException;

it('keeps Auckland wall times distinct from UTC and explicitly resolves the repeated hour', function () {
    expect(ReferralDates::parse('2026-10-16T09:00', 'due_at')->toISOString())->toBe('2026-10-15T20:00:00.000000Z');
    expect(ReferralDates::parse('2026-04-05T02:30+13:00', 'due_at')->toISOString())->toBe('2026-04-04T13:30:00.000000Z');
    expect(ReferralDates::parse('2026-04-05T02:30+12:00', 'due_at')->toISOString())->toBe('2026-04-04T14:30:00.000000Z');
    expect(ReferralDates::parse('2026-10-16T09:00:00Z', 'due_at')->toISOString())->toBe('2026-10-16T09:00:00.000000Z');
});

it('rejects nonexistent ambiguous or mismatched Auckland local times', function (string $value) {
    expect(fn () => ReferralDates::parse($value, 'due_at'))->toThrow(ValidationException::class);
})->with(['2026-09-27T02:30', '2026-04-05T02:30', '2026-10-16T09:00+12:00']);
