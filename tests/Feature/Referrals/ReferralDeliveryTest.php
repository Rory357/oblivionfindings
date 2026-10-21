<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralAcknowledgementTransport;
use App\Domain\Referrals\Services\ReferralDelivery;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

function referralDeliveryFixture(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $configuration = ReferralSettings::defaults();
    $configuration['public']['acknowledgement_enabled'] = true;
    ReferralSettingsVersion::create(['version' => 1, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);
    $referral = referralTestRecord($site, ['intake' => ['submitted_contact' => ['email' => 'fictional@example.test', 'channel' => 'email', 'safe_time' => '']], 'authority' => ['contact_permission' => true]]);
    $row = ReferralOutbox::create(['referral_id' => $referral->id, 'deduplication_key' => 'ack:'.$referral->id, 'kind' => 'public_acknowledgement', 'message' => ['recipient' => 'fictional@example.test', 'reference' => $referral->reference], 'available_at' => now()]);

    return [$site, $referral, $row];
}

it('does not claim external delivery without an explicitly configured real mail transport', function () {
    [, $referral, $row] = referralDeliveryFixture();
    config(['referral-delivery.enabled' => false]);
    app(ReferralDelivery::class)->deliver($row->id);
    expect($row->fresh()->state)->toBe('failed')->and($row->fresh()->last_error_code)->toBe('transport_not_configured')
        ->and($row->fresh()->sent_at)->toBeNull()->and($referral->fresh()->stage)->toBe('new');
    app(ReferralDelivery::class)->run();
    expect($row->fresh()->attempts)->toBe(1);
});

it('records transport acceptance separately from delivery and never counts it as human contact', function () {
    [, $referral, $row] = referralDeliveryFixture();
    $transport = Mockery::mock(ReferralAcknowledgementTransport::class);
    $transport->shouldReceive('configured')->andReturn(true);
    $transport->shouldReceive('send')->once()->with('fictional@example.test', $referral->reference)->andReturn(true);
    app()->instance(ReferralAcknowledgementTransport::class, $transport);
    $delivery = app(ReferralDelivery::class);
    $delivery->deliver($row->id);
    $delivery->deliver($row->id);
    expect($row->fresh()->state)->toBe('sent')->and($row->fresh()->sent_at)->not->toBeNull()->and($row->fresh()->delivered_at)->toBeNull()
        ->and($referral->fresh()->first_contact_attempt_at)->toBeNull()->and($referral->fresh()->first_human_contact_at)->toBeNull();
    expect(ReferralEvent::where('kind', 'communication.sent')->count())->toBe(1);
});

it('does not automatically repeat an uncertain transport result or retain sensitive exception text', function () {
    [, $referral, $row] = referralDeliveryFixture();
    $transport = Mockery::mock(ReferralAcknowledgementTransport::class);
    $transport->shouldReceive('configured')->andReturn(true);
    $transport->shouldReceive('send')->once()->andThrow(new RuntimeException('Sensitive SMTP detail fictional@example.test'));
    app()->instance(ReferralAcknowledgementTransport::class, $transport);
    $delivery = app(ReferralDelivery::class);
    $delivery->deliver($row->id);
    $delivery->run();
    expect($row->fresh()->state)->toBe('delivery_unknown')->and($row->fresh()->last_error_code)->toBe('transport_result_unknown')
        ->and(json_encode(ReferralEvent::get()->map->payload))->not->toContain('fictional@example.test');
});

it('recovers an interrupted claimed send as uncertain without invoking the transport', function () {
    [, , $row] = referralDeliveryFixture();
    $row->update(['state' => 'sending', 'claim_token' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(11)]);
    $transport = Mockery::mock(ReferralAcknowledgementTransport::class);
    $transport->shouldNotReceive('send');
    app()->instance(ReferralAcknowledgementTransport::class, $transport);
    app(ReferralDelivery::class)->run();
    expect($row->fresh()->state)->toBe('delivery_unknown')->and($row->fresh()->last_error_code)->toBe('worker_interrupted');
});

it('blocks queued contact after withdrawal changed recipient safe-time restriction or closure', function () {
    [, $referral, $row] = referralDeliveryFixture();
    $transport = Mockery::mock(ReferralAcknowledgementTransport::class);
    $transport->shouldNotReceive('send');
    app()->instance(ReferralAcknowledgementTransport::class, $transport);
    foreach (['withdrawn', 'changed', 'timed', 'closed'] as $case) {
        $referral->update(['stage' => $case === 'closed' ? 'closed' : 'new', 'authority' => ['contact_permission' => $case !== 'withdrawn'],
            'intake' => ['submitted_contact' => ['channel' => 'email', 'email' => $case === 'changed' ? 'different@example.test' : 'fictional@example.test', 'safe_time' => $case === 'timed' ? 'Only after my appointment' : '']]]);
        $row->update(['state' => 'pending']);
        app(ReferralDelivery::class)->deliver($row->id);
        expect($row->fresh()->state)->toBe('failed');
    }
});

it('delivers internal intake notices transactionally once to eligible scoped staff with no identifying payload', function () {
    [$site, $referral, $row] = referralDeliveryFixture();
    $owner = referralTestUser($site, ['viewAssigned']);
    $referral->update(['owner_id' => $owner->id]);
    referralTestUser($site, ['viewAny', 'assign']);
    $row->update(['kind' => 'intake_received']);
    $delivery = app(ReferralDelivery::class);
    $delivery->deliver($row->id);
    $delivery->deliver($row->id);
    $notices = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
    expect($notices)->toHaveCount(1)->and($notices->first()->data)->not->toContain($referral->reference)->not->toContain('fictional@example.test')
        ->and($row->fresh()->state)->toBe('delivered');
});

it('keeps intake notification failures recoverable when no eligible owner or triage recipient exists', function () {
    [, , $row] = referralDeliveryFixture();
    $row->update(['kind' => 'intake_received', 'attempts' => 4]);
    app(ReferralDelivery::class)->deliver($row->id);
    expect($row->fresh()->state)->toBe('failed')->and($row->fresh()->last_error_code)->toBe('no_eligible_recipient');
});

it('requires current independent communication permission exact parent and explicit uncertainty review for recovery', function () {
    [$site, $referral, $row] = referralDeliveryFixture();
    $actor = referralTestUser($site, ['viewAny', 'communications.manage']);
    $reader = referralTestUser($site, ['viewAny', 'contact']);
    $row->update(['state' => 'delivery_unknown']);
    $transport = Mockery::mock(ReferralAcknowledgementTransport::class);
    $transport->shouldReceive('configured')->andReturn(true);
    app()->instance(ReferralAcknowledgementTransport::class, $transport);
    $url = '/operations/referrals/'.$referral->reference.'/communications/'.$row->id;
    $input = ['version' => 1, 'request_key' => (string) Str::uuid(), 'operation' => 'retry', 'reason' => 'Reviewed the mail service and confirmed the need to retry', 'acknowledge_duplicate_risk' => false];
    $this->actingAs($reader)->postJson($url, $input)->assertForbidden();
    $this->actingAs($actor)->postJson($url, $input)->assertUnprocessable();
    $input['acknowledge_duplicate_risk'] = true;
    $this->postJson($url, $input)->assertOk();
    $this->postJson($url, $input)->assertOk();
    expect($row->fresh()->state)->toBe('pending')->and($referral->fresh()->lock_version)->toBe(2)
        ->and(ReferralEvent::where('kind', 'communication.retry')->count())->toBe(1);
    $other = referralTestRecord($site);
    $this->postJson('/operations/referrals/'.$other->reference.'/communications/'.$row->id, $input)->assertNotFound();
    $actor->hrEmployeeProfile->update(['is_active' => false]);
    $this->actingAs($actor->fresh())->postJson($url, $input)->assertNotFound();
});

it('sends an acknowledgement only after its claim commits, without holding the referral lock', function () {
    [, , $row] = referralDeliveryFixture();
    $baseline = DB::transactionLevel();
    $during = null;
    $transport = Mockery::mock(ReferralAcknowledgementTransport::class);
    $transport->shouldReceive('configured')->andReturn(true);
    $transport->shouldReceive('send')->once()->andReturnUsing(function () use (&$during): bool {
        $during = DB::transactionLevel();

        return true;
    });
    app()->instance(ReferralAcknowledgementTransport::class, $transport);
    app(ReferralDelivery::class)->deliver($row->id);
    expect($during)->toBe($baseline)->and($row->fresh()->state)->toBe('sent');
});
