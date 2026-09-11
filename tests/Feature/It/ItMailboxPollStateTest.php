<?php

use App\Domain\It\Exceptions\ItMailboxPollSuperseded;
use App\Domain\It\Services\ItMailboxPollingToken;
use App\Domain\It\Services\ItMailboxPollState;
use App\Models\ItMailboxConnection;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;

function itRecoveryConnection(): ItMailboxConnection
{
    return ItMailboxConnection::query()->create([
        'provider' => 'microsoft', 'status' => 'connected',
        'account_email' => 'synthetic@example.test',
        'access_token' => 'synthetic-old-access', 'refresh_token' => 'synthetic-old-refresh',
        'token_expires_at' => now()->addHour(),
    ]);
}

test('one persisted claim excludes another poll and is hidden from serialization', function () {
    $connection = itRecoveryConnection();
    $state = app(ItMailboxPollState::class);
    $claim = $state->claim($connection->id);
    expect($claim)->not->toBeNull();
    expect($claim->last_poll_attempt_at)->not->toBeNull();
    expect($claim->last_polled_at)->toBeNull();
    expect($state->claim($connection->id))->toBeNull();
    expect($claim->toArray())->not->toHaveKeys(['access_token', 'refresh_token', 'poll_claim_token']);
});

test('expired ownership can be recovered and the old token view cannot overwrite its replacement', function () {
    $connection = itRecoveryConnection();
    $state = app(ItMailboxPollState::class);
    $old = $state->claim($connection->id);
    $token = new ItMailboxPollingToken($old, $state);
    $this->travel(21)->minutes();
    $current = $state->claim($connection->id);
    expect($current->poll_claim_token)->not->toBe($old->poll_claim_token);
    expect(fn () => $token->getAccessToken())->toThrow(ItMailboxPollSuperseded::class);
    expect(fn () => $token->storeRefreshedToken('stale-write', 'stale-refresh', 3600))->toThrow(ItMailboxPollSuperseded::class);
    expect($connection->fresh()->access_token)->toBe('synthetic-old-access');
    expect(fn () => $state->complete($old))->toThrow(ItMailboxPollSuperseded::class);
    $state->complete($current);
    expect($connection->fresh()->last_polled_at)->not->toBeNull();
});

test('a claim that expires while its current row is being read cannot enter the write callback', function () {
    $connection = itRecoveryConnection();
    $state = app(ItMailboxPollState::class);
    $claim = $state->claim($connection->id);
    $armed = true;
    $entered = false;
    // Advance after SQL has selected the still-valid row, reproducing the stale
    // query cutoff after a lock wait without introducing a timing-dependent sleep.
    ItMailboxConnection::retrieved(function (ItMailboxConnection $row) use (&$armed, $claim): void {
        if ($armed && $row->id === $claim->id) {
            $armed = false;
            $this->travelTo($claim->poll_claim_expires_at->addSecond());
        }
    });
    try {
        expect(fn () => $state->withinClaim($claim, function () use (&$entered): void {
            $entered = true;
        }))->toThrow(ItMailboxPollSuperseded::class);
        expect($armed)->toBeFalse();
        expect($entered)->toBeFalse();
        expect($connection->fresh()->poll_claim_token)->toBe($claim->poll_claim_token);
    } finally {
        $armed = false;
        $this->travelBack();
    }
});

test('rate-limited connection respects persisted cooldown then recovers without reconnecting', function () {
    $connection = itRecoveryConnection();
    $state = app(ItMailboxPollState::class);
    $state->fail($state->claim($connection->id), new ProviderRateLimited(123));
    $failed = $connection->fresh();
    expect($failed->status)->toBe('error');
    expect($failed->last_poll_failure_code)->toBe('rate_limited');
    expect($failed->consecutive_poll_failures)->toBe(1);
    expect($failed->next_poll_at->timestamp)->toBe(now()->addSeconds(123)->timestamp);
    expect($state->claim($connection->id))->toBeNull();
    $this->travel(124)->seconds();
    $claim = $state->claim($connection->id);
    expect($claim)->not->toBeNull();
    $state->complete($claim);
    $saved = $connection->fresh();
    expect($saved->status)->toBe('connected');
    expect($saved->last_error)->toBeNull();
    expect($saved->last_poll_failure_code)->toBeNull();
    expect($saved->next_poll_at)->toBeNull();
    expect($saved->consecutive_poll_failures)->toBe(0);
    expect($saved->access_token)->toBe('synthetic-old-access');
});

test('failed attempt preserves earlier successful poll and keeps exception details out of storage', function () {
    $connection = itRecoveryConnection();
    $state = app(ItMailboxPollState::class);
    $state->complete($state->claim($connection->id));
    $successful = $connection->fresh()->last_polled_at->toIso8601String();
    $this->travel(5)->minutes();
    $state->fail($state->claim($connection->id), new RuntimeException('PRIVATE database body token'));
    $saved = $connection->fresh();
    expect($saved->last_polled_at->toIso8601String())->toBe($successful);
    expect($saved->last_poll_attempt_at->gt($saved->last_polled_at))->toBeTrue();
    expect($saved->last_error)->not->toContain('PRIVATE');
    expect($saved->last_poll_failure_code)->toBe('processing_failed');
});

test('revoked authorization does not retry until an approved reconnect changes the connection', function () {
    $connection = itRecoveryConnection();
    $state = app(ItMailboxPollState::class);
    $state->fail($state->claim($connection->id), new MailboxProviderFailure('authentication'));
    $this->travel(2)->days();
    expect($state->claim($connection->id))->toBeNull();
    expect($connection->fresh()->next_poll_at)->toBeNull();
});

test('configuration change invalidates claimed credentials and completion', function () {
    $connection = itRecoveryConnection();
    $state = app(ItMailboxPollState::class);
    $claim = $state->claim($connection->id);
    $connection->forceFill(['configuration_version' => 2, 'access_token' => 'replacement'])->save();
    expect(fn () => (new ItMailboxPollingToken($claim, $state))->storeRefreshedToken('stale', null, 3600))
        ->toThrow(ItMailboxPollSuperseded::class);
    expect(fn () => $state->complete($claim))->toThrow(ItMailboxPollSuperseded::class);
    expect($connection->fresh()->access_token)->toBe('replacement');
});

test('disconnect invalidates local work and does not recreate the connection', function () {
    $connection = itRecoveryConnection();
    $state = app(ItMailboxPollState::class);
    $claim = $state->claim($connection->id);
    $connection->delete();
    $ran = false;
    expect(fn () => $state->withinClaim($claim, function () use (&$ran) {
        $ran = true;
    }))
        ->toThrow(ItMailboxPollSuperseded::class);
    expect($ran)->toBeFalse();
    expect(ItMailboxConnection::query()->count())->toBe(0);
});
