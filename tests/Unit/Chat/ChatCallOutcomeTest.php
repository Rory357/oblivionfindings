<?php

use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Services\Chat\ChatCallOutcome;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ChatCallOutcomeTest extends TestCase
{
    public function test_an_unanswered_outgoing_call_is_not_a_missed_incoming_call(): void
    {
        $call = (new ChatCall)->setDateFormat('Y-m-d H:i:s')->fill(['started_by' => 1, 'ended_at' => CarbonImmutable::now(), 'status' => 'missed']);
        $caller = (new ChatCallParticipant)->setDateFormat('Y-m-d H:i:s')->fill(['user_id' => 1, 'status' => 'left']);
        $recipient = (new ChatCallParticipant)->setDateFormat('Y-m-d H:i:s')->fill(['user_id' => 2, 'status' => 'left']);

        $this->assertSame('unanswered', ChatCallOutcome::forParticipant($call, $caller));
        $this->assertSame('missed', ChatCallOutcome::forParticipant($call, $recipient));
    }

    public function test_a_missed_group_invitation_remains_missed_when_others_connected(): void
    {
        $call = (new ChatCall)->setDateFormat('Y-m-d H:i:s')->fill(['started_by' => 1, 'connected_at' => CarbonImmutable::now()->subMinute(), 'ended_at' => CarbonImmutable::now()]);
        $recipient = (new ChatCallParticipant)->setDateFormat('Y-m-d H:i:s')->fill(['user_id' => 2, 'status' => 'left']);
        $this->assertSame('missed', ChatCallOutcome::forParticipant($call, $recipient));

        $recipient->joined_at = CarbonImmutable::now()->subMinute();
        $this->assertSame('ended', ChatCallOutcome::forParticipant($call, $recipient));
    }

    public function test_declines_and_revocations_are_not_reported_as_missed_calls(): void
    {
        $call = (new ChatCall)->setDateFormat('Y-m-d H:i:s')->fill(['started_by' => 1, 'ended_at' => CarbonImmutable::now()]);
        $recipient = (new ChatCallParticipant)->setDateFormat('Y-m-d H:i:s')->fill(['user_id' => 2, 'status' => 'declined']);
        $this->assertSame('declined', ChatCallOutcome::forParticipant($call, $recipient));
        $recipient->status = 'revoked';
        $this->assertSame('access_ended', ChatCallOutcome::forParticipant($call, $recipient));
    }

    public function test_acceptance_without_verified_audio_does_not_claim_a_connected_call(): void
    {
        $call = (new ChatCall)->setDateFormat('Y-m-d H:i:s')->fill(['started_by' => 1]);
        $recipient = (new ChatCallParticipant)->setDateFormat('Y-m-d H:i:s')->fill(['user_id' => 2, 'status' => 'accepted']);
        $this->assertSame('ongoing', ChatCallOutcome::forParticipant($call, $recipient));
        $call->ended_at = CarbonImmutable::now();
        $this->assertSame('missed', ChatCallOutcome::forParticipant($call, $recipient));
        $recipient->joined_at = CarbonImmutable::now()->subMinute();
        $this->assertSame('not_connected', ChatCallOutcome::forParticipant($call, $recipient));
    }
}
