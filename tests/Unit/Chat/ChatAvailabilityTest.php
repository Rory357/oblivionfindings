<?php

use App\Services\Chat\ChatAvailability;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class ChatAvailabilityTest extends TestCase
{
    public function test_chat_quiet_states_outlast_an_expired_global_timer_without_mutating_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-18 20:00:00'));
        try {
            $expired = now()->subMinute();
            foreach (['dnd', 'off_duty'] as $state) {
                $this->assertTrue((new ChatAvailability)->quietUntil($state, true, $expired)->equalTo(now()->addMinutes(5)));
            }
            $this->assertTrue($expired->equalTo(now()->subMinute()));
            foreach ([null, 'available', 'busy', 'unavailable'] as $state) {
                $this->assertNull((new ChatAvailability)->quietUntil($state, false, null));
                $this->assertNull((new ChatAvailability)->quietUntil($state, true, $expired));
            }
            $future = now()->addHour();
            $this->assertSame($future, (new ChatAvailability)->quietUntil('available', true, $future));
            $this->assertTrue((new ChatAvailability)->quietUntil('busy', true, null)->equalTo(now()->addMinutes(5)));
        } finally {
            Carbon::setTestNow();
        }
    }
}
