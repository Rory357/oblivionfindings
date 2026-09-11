<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItMailboxConnectionPresenter;
use App\Domain\It\Services\ItMailboxPollState;
use App\Models\ItEmailDelivery;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Schema;

function channelHealthManager(bool $mailbox = false): User
{
    $user = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $role = Role::query()->create(['name' => 'channel-health-'.str()->uuid(), 'label' => 'Synthetic channel operator', 'level' => 50, 'type' => 'custom']);
    $permissions = ['it.view', 'it.manage'];
    if ($mailbox) {
        $permissions[] = 'integrations.manage_secrets';
    }
    $role->permissions()->attach(Permission::query()->whereIn('key', $permissions)->pluck('id'));
    $user->roles()->attach($role);

    return $user;
}

function channelHealthMailbox(): ItMailboxConnection
{
    return ItMailboxConnection::query()->create([
        'provider' => 'microsoft', 'status' => 'connected',
        'account_email' => 'private-account@example.test', 'mailbox_email' => 'private-mailbox@example.test',
        'access_token' => 'synthetic-private-access', 'refresh_token' => 'synthetic-private-refresh',
    ]);
}

function channelHealthReceipt(ItMailboxConnection $connection, array $attributes): ItInboundEmail
{
    $receipt = new ItInboundEmail;
    $receipt->forceFill(array_merge([
        'mailbox_scope_hash' => $connection->mailboxScopeHash(),
        'from_email' => 'private-sender@example.test', 'subject' => 'Private subject',
        'body_preview' => 'Private content', 'status' => 'pending', 'received_at' => now()->subHour(),
    ], $attributes))->save();

    return $receipt;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->travelTo(now()->startOfSecond());
});

test('mailbox health is permission gated, scope bounded and excludes secrets and message details', function () {
    $manager = channelHealthManager(true);
    $connection = channelHealthMailbox();
    $connection->forceFill([
        'status' => 'error', 'last_polled_at' => now()->subDay(), 'last_poll_attempt_at' => now()->subMinute(),
        'last_poll_failure_code' => 'scanner_timeout', 'consecutive_poll_failures' => 3,
        'last_error' => 'Raw private provider error', 'next_poll_at' => now()->addMinutes(5),
    ])->save();
    channelHealthReceipt($connection, ['received_at' => now()->subHours(3), 'processing_attempts' => 4]);
    channelHealthReceipt($connection, ['status' => 'processed', 'acknowledgement_attempts' => 2]);
    channelHealthReceipt($connection, ['status' => 'quarantined', 'received_at' => now()->subDays(2), 'acknowledged_at' => now(), 'processing_attempts' => 99]);
    channelHealthReceipt($connection, ['mailbox_scope_hash' => hash('sha256', 'other-mailbox'), 'received_at' => now()->subWeek(), 'processing_attempts' => 70]);

    $health = app(ItMailboxConnectionPresenter::class)->operations($manager);
    expect($health['can_view'])->toBeTrue()->and($health['available'])->toBeTrue()
        ->and($health['settings_url'])->toBe('/settings/it-mailbox')
        ->and($health['connections'][0])->toMatchArray([
            'failure_category' => 'scanner_timeout', 'consecutive_failed_polls' => 3,
            'last_completed_poll_at' => now()->subDay()->toIso8601String(),
            'last_attempt_at' => now()->subMinute()->toIso8601String(),
            'pending' => ['processing' => 1, 'acknowledgement' => 1,
                'oldest_received_at' => now()->subHours(3)->toIso8601String(),
                'processing_attempts' => 4, 'acknowledgement_attempts' => 2],
            'quarantine' => ['count' => 1, 'oldest_received_at' => now()->subDays(2)->toIso8601String()],
        ]);
    $encoded = json_encode($health);
    foreach (['private-', 'Private subject', 'Private content', 'Raw private', 'access_token', 'mailbox_scope_hash'] as $private) {
        expect($encoded)->not->toContain($private);
    }

    $this->actingAs($manager)->get('/it/setup')->assertInertia(fn ($page) => $page
        ->where('operationsAudit.mailbox_health.can_view', true)
        ->where('operationsAudit.email.connections', 1));

    $ordinary = channelHealthManager();
    $this->actingAs($ordinary)->get('/it/setup')->assertInertia(fn ($page) => $page
        ->where('operationsAudit.mailbox_health.can_view', false)
        ->where('operationsAudit.mailbox_health.settings_url', null)
        ->has('operationsAudit.mailbox_health.connections', 0)
        ->where('operationsAudit.email.connections', null)
        ->where('operationsAudit.email.connection_errors', null));
    $this->actingAs($ordinary)->get('/settings/it-mailbox')->assertForbidden();

    // A previously loaded User cannot retain revoked settings authority.
    $manager->roles()->detach();
    expect(app(ItMailboxConnectionPresenter::class)->operations($manager)['can_view'])->toBeFalse();
});

test('mailbox health never treats a paused scan or unknown provider error as a completed poll', function () {
    $manager = channelHealthManager(true);
    $connection = channelHealthMailbox();
    $state = app(ItMailboxPollState::class);
    $claim = $state->claim($connection->id, $connection->configuration_version);
    expect($claim)->not->toBeNull();
    $state->pause($claim);
    $health = app(ItMailboxConnectionPresenter::class)->operations($manager)['connections'][0];
    expect($health['last_completed_poll_at'])->toBeNull()->and($health['last_attempt_at'])->not->toBeNull();
    $connection->refresh()->forceFill(['status' => 'error', 'last_poll_failure_code' => 'private-unexpected-code'])->save();
    expect(app(ItMailboxConnectionPresenter::class)->operations($manager)['connections'][0]['failure_category'])->toBe('unknown');
});

test('mailbox health reports unavailable when the recovery schema is not ready', function () {
    $manager = channelHealthManager(true);
    Schema::shouldReceive('hasColumns')->once()->with('it_mailbox_connections', Mockery::type('array'))->andReturn(false);
    $health = app(ItMailboxConnectionPresenter::class)->operations($manager);
    expect($health['available'])->toBeFalse()->and($health['connections'])->toBe([]);
});

test('delivery backlog uses all currently visible records and does not call accepted or sending delivered', function () {
    $manager = channelHealthManager();
    $site = Site::factory()->create();
    HrEmployeeProfile::factory()->create(['user_id' => $manager->id, 'primary_site_id' => $site->id,
        'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
    $visible = ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $manager->id]);
    $hidden = ItTicket::factory()->create(['site_id' => Site::factory()->create()->id]);
    $common = ['it_ticket_id' => $visible->id, 'recipient_user_id' => $manager->id];
    ItEmailDelivery::factory()->count(101)->create(array_merge($common, ['status' => 'queued', 'queued_at' => now()->subHours(2)]));
    ItEmailDelivery::factory()->create(array_merge($common, ['status' => 'sending', 'queued_at' => now()->subHours(4)]));
    ItEmailDelivery::factory()->create(array_merge($common, ['status' => 'accepted', 'queued_at' => now()->subHours(3), 'accepted_at' => now()->subHour()]));
    ItEmailDelivery::factory()->create(['it_ticket_id' => $hidden->id, 'status' => 'queued', 'queued_at' => now()->subWeek()]);
    ItEmailDelivery::factory()->create(['it_ticket_id' => $hidden->id, 'status' => 'delivered', 'delivered_at' => now()]);
    $health = app(ItEmailDeliveryService::class)->operationsHealth($manager);
    expect($health)->toMatchArray(['queued' => 101, 'sending' => 1, 'accepted' => 1,
        'oldest_queued_at' => now()->subHours(2)->toIso8601String(),
        'oldest_unconfirmed_at' => now()->subHours(4)->toIso8601String(), 'last_confirmed_delivery_at' => null]);
    ItEmailDelivery::factory()->create(array_merge($common, ['status' => 'delivered', 'delivered_at' => now()->subMinutes(30)]));
    $this->actingAs($manager)->get('/it/setup')->assertInertia(fn ($page) => $page
        ->where('operationsAudit.delivery_health.queued', 101)
        ->where('operationsAudit.delivery_health.last_confirmed_delivery_at', now()->subMinutes(30)->toIso8601String())
        ->has('emailDeliveries', 100));
});
