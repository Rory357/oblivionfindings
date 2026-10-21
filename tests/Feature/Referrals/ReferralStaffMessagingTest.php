<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\ReferralPermissions;
use App\Domain\Referrals\Services\ReferralDelivery;
use App\Domain\Referrals\Services\ReferralDocumentRequests;
use App\Domain\Referrals\Services\ReferralMessageTransport;
use App\Domain\Referrals\Services\ReferralRetention;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Notifications\SmsProvider;
use App\Services\Notifications\SmsSendResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

beforeEach(function () {
    foreach (ReferralPermissions::definitions() as $definition) {
        Permission::query()->firstOrCreate(['key' => $definition['key']], $definition);
    }
});

/**
 * A self-referral whose safe contact is email, with messages allowed, published settings with
 * fictional provider details and every standard template switched on, a named coordinator, and
 * working email and text transports.
 */
function staffMessagingFixture(array $contact = [], array $intake = [], array $settings = []): array
{
    config(['referral-delivery.enabled' => true, 'referral-delivery.mailer' => 'smtp', 'referral-delivery.from_address' => 'referrals@example.test',
        'referral-delivery.from_name' => 'Fictional Living', 'referral-delivery.sms_enabled' => true, 'services.sms.provider' => 'twilio',
        'services.sms.twilio.account_sid' => 'ACfictional', 'services.sms.twilio.auth_token' => 'fictional', 'services.sms.twilio.from' => '+64210000000']);
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $configuration = ReferralSettings::defaults();
    $configuration['public']['provider_name'] = 'Fictional Living';
    $configuration['public']['contact_phone'] = '09 555 0100';
    // The standard templates start off; these tests use them switched on.
    $configuration['communications']['templates'] = array_map(fn (array $template) => [...$template, 'enabled' => true], $configuration['communications']['templates']);
    $configuration = array_replace_recursive($configuration, $settings);
    ReferralSettingsVersion::query()->firstOrCreate(['version' => 1], ['state' => 'published', 'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);
    $coordinator = referralTestUser($site, ['viewAny', 'communications.send']);
    $referral = referralTestRecord($site, [
        'owner_id' => $coordinator->id,
        'identity' => ['first_name' => 'Aroha', 'last_name' => 'Fictional', 'preferred_name' => null],
        'intake' => ['relationship' => 'self', 'reason' => 'Private reason: health detail', 'funding_situation' => 'need_help', ...$intake,
            'submitted_contact' => ['name' => 'Aroha Fictional', 'email' => 'aroha@example.test', 'phone' => '021 555 0101', 'channel' => 'email',
                'safe_time' => null, 'leave_message' => true, 'communication' => null, ...$contact]],
        'assessment' => ['notes' => 'Restricted assessment: diagnosis detail'],
    ]);

    return [$site, $referral, $coordinator];
}

function staffMessageInput(array $overrides = []): array
{
    return ['version' => 1, 'request_key' => (string) Str::uuid(), 'recipient' => 'person', 'channel' => 'email', 'template_id' => 'first_contact',
        'body' => ReferralSettings::defaults()['communications']['templates'][0]['body'], 'document_request_id' => null, 'send_at' => null, ...$overrides];
}

/** A send time in NZ time with its offset, as the approved date-time picker sends it. */
function nzSendAt($at): string
{
    return $at->copy()->setTimezone('Pacific/Auckland')->format('Y-m-d\TH:i:sP');
}

/** A reviewed, current People involved contact. */
function messagingPeopleContact(string $role, string $channel, array $details = []): array
{
    $id = (string) Str::uuid();

    return [$id => ['id' => $id, 'version' => 1, 'authority_version' => 1, 'reviewed_at' => now()->toISOString(), 'reviewed_by' => null,
        'updated_at' => now()->toISOString(), 'updated_by' => null, 'details' => [
            'name' => 'Fictional '.ucfirst($role), 'role' => $role, 'relationship' => 'Fictional relationship', 'organisation' => null,
            'phone' => '021 555 0177', 'email' => $role.'@example.test', 'channel' => $channel, 'safe_time' => null, 'leave_message' => false,
            'communication' => null, 'state' => 'current', 'may_contact' => true, 'purpose' => 'Coordinate support planning', 'authority_note' => null,
            'review_note' => 'Reviewed with the person', 'review_due_at' => now()->addMonth()->toISOString(), 'confirmed' => true, ...$details,
        ]]];
}

/** Current collection and contact authority, which People involved contacts need. */
function messagingAuthority(): array
{
    return ['review' => ['state' => 'current', 'version' => 1, 'review_due_at' => now()->addMonths(2)->toISOString(), 'scopes' => ['collect' => true, 'contact' => true]]];
}

function fakeSmsProvider(bool $accept = true): object
{
    $provider = new class implements SmsProvider
    {
        public array $sent = [];

        public bool $accept = true;

        public function send(string $to, string $message): SmsSendResult
        {
            $this->sent[] = [$to, $message];

            return $this->accept ? SmsSendResult::sent('SMfictional') : SmsSendResult::failed('Twilio error 21211: The number '.$to.' is not a valid phone number.');
        }
    };
    $provider->accept = $accept;
    app()->instance(SmsProvider::class, $provider);

    return $provider;
}

it('lets staff with send permission compose and queue, but never readers or support workers', function () {
    [$site, $referral, $coordinator] = staffMessagingFixture();
    $reader = referralTestUser($site, ['viewAny', 'contact', 'communications.manage']);
    $compose = '/operations/referrals/'.$referral->reference.'/communications/compose';
    $store = '/operations/referrals/'.$referral->reference.'/communications';
    $this->actingAs($reader)->getJson($compose)->assertForbidden();
    $this->actingAs($reader)->postJson($store, staffMessageInput())->assertForbidden();
    $this->actingAs($reader)->getJson($store)->assertOk()->assertJsonPath('can_send', false);

    $context = $this->actingAs($coordinator)->getJson($compose)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    expect($context['blocked'])->toBeNull()
        ->and(array_column($context['templates'], 'id'))->toBe(['first_contact', 'tried_to_call', 'documents_needed', 'appointment_reminder', 'please_call_us'])
        ->and($context['recipients'][0]['channels']['email']['reason'])->toBeNull()
        ->and($context['recipients'][0]['channels']['sms']['reason'])->toBe('Their safe way to be contacted is by email, so texts aren’t sent.')
        ->and($context['recipients'][1]['reason'])->toBe('They contacted us themselves, so there is no separate referrer.')
        ->and($context['values']['coordinator_name'])->toBe($coordinator->name)
        // Merge values never carry health, assessment or funding details.
        ->and(json_encode($context))->not->toContain('health detail')->not->toContain('diagnosis detail')->not->toContain('need_help');
    $this->getJson($store)->assertOk()->assertJsonPath('can_send', true);

    // Someone outside the referral's scope gets a generic not found.
    $outside = referralTestUser(Site::factory()->create(['is_active' => true, 'archived' => false]), ['viewAny', 'communications.send']);
    $this->actingAs($outside)->getJson($compose)->assertNotFound();
});

it('queues one message through the outbox with a history entry, and never sends inside the request', function () {
    [, $referral, $coordinator] = staffMessagingFixture();
    $transport = Mockery::mock(ReferralMessageTransport::class)->makePartial();
    $transport->shouldNotReceive('sendEmail');
    $transport->shouldNotReceive('sendSms');
    app()->instance(ReferralMessageTransport::class, $transport);
    $input = staffMessageInput();
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $first = $this->actingAs($coordinator)->postJson($url, $input)->assertCreated()->json('communication_id');
    // The exact same request returns the same message; it is never queued twice.
    $this->postJson($url, $input)->assertCreated()->assertJsonPath('communication_id', $first);
    $this->postJson($url, [...$input, 'version' => 2, 'body' => $input['body'].' Extra words.'])->assertConflict();

    $row = ReferralOutbox::query()->findOrFail($first);
    expect(ReferralOutbox::query()->where('kind', 'staff_message')->count())->toBe(1)
        ->and($row->state)->toBe('pending')->and($row->message['address'])->toBe('aroha@example.test')
        ->and($row->message['subject'])->toBe('About your enquiry with Fictional Living')
        ->and($row->message['body'])->toContain('Kia ora Aroha,')->toContain($coordinator->name)->toContain('09 555 0100')->toContain($referral->reference)
        ->and($row->message['edited'])->toBeFalse()
        ->and($referral->fresh()->lock_version)->toBe(2)
        ->and($referral->fresh()->first_contact_attempt_at)->toBeNull();
    $event = ReferralEvent::query()->where('kind', 'communication.queued')->sole();
    expect($event->actor_id)->toBe($coordinator->id)->and($event->audience)->toBe('intake')
        ->and($event->payload['summary'])->toBe('“First contact attempt” by email to Aroha Fictional (The person)')
        ->and($event->payload['recipient_role'])->toBe('The person')
        ->and(json_encode($event->payload))->not->toContain('aroha@example.test')->not->toContain('Kia ora');

    $list = $this->getJson($url)->assertOk()->json('communications.data.0');
    expect($list['kind'])->toBe('staff_message')->and($list['state'])->toBe('pending')->and($list['template'])->toBe('First contact attempt')
        ->and($list['recipient'])->toBe('Aroha Fictional · aroha@example.test')->and($list['recipient_role'])->toBe('The person')
        ->and($list['scheduled_for'])->toBeNull()->and($list['sender_name'])->toBe($coordinator->name);
});

it('offers only switched-on templates, so the standard ones wait for review', function () {
    [, $referral, $coordinator] = staffMessagingFixture();
    $version = ReferralSettingsVersion::query()->where('version', 1)->firstOrFail();
    $configuration = $version->configuration;
    unset($configuration['communications']);
    $version->update(['configuration' => $configuration]);
    // An older publication reads as the standard templates, switched off.
    $this->actingAs($coordinator)->getJson('/operations/referrals/'.$referral->reference.'/communications/compose')
        ->assertOk()->assertJsonPath('templates', []);
    $this->postJson('/operations/referrals/'.$referral->reference.'/communications', staffMessageInput())
        ->assertUnprocessable()->assertJsonPath('message', 'This template was turned off or changed. Choose another one.');
});

it('never messages against the saved contact preferences and explains why', function () {
    $cases = [
        'no messages' => [['leave_message' => false], [], 'They haven’t agreed to messages. Call them and log the contact instead.'],
        'phone preferred' => [['channel' => 'phone'], [], 'Their safe way to be contacted is by phone, so emails aren’t sent.'],
        'referrer only' => [[], ['relationship' => 'family'], 'Only the referrer’s contact details were given, so the person can’t be messaged directly.'],
    ];
    foreach ($cases as $name => [$contact, $intake, $reason]) {
        [, $referral, $coordinator] = staffMessagingFixture($contact, $intake);
        $context = $this->actingAs($coordinator)->getJson('/operations/referrals/'.$referral->reference.'/communications/compose')->json();
        $person = $context['recipients'][0];
        expect($person['reason'] ?? $person['channels']['email']['reason'])->toBe($reason, $name);
        $this->postJson('/operations/referrals/'.$referral->reference.'/communications', staffMessageInput())
            ->assertUnprocessable()->assertJsonPath('message', $reason);
    }
    expect(ReferralOutbox::query()->where('kind', 'staff_message')->count())->toBe(0);

    // A landline can't receive texts.
    [, $referral, $coordinator] = staffMessagingFixture(['channel' => 'phone', 'phone' => '09 555 0199']);
    $context = $this->actingAs($coordinator)->getJson('/operations/referrals/'.$referral->reference.'/communications/compose')->json();
    expect($context['recipients'][0]['channels']['sms']['reason'])->toBe('This number doesn’t look like a mobile, so it can’t receive texts.');

    // Closed referrals and missing contact permission block everything.
    [, $referral, $coordinator] = staffMessagingFixture();
    $referral->update(['authority' => ['contact_permission' => false]]);
    $this->actingAs($coordinator)->getJson('/operations/referrals/'.$referral->reference.'/communications/compose')
        ->assertJsonPath('blocked', 'There is no current permission to contact them. Review contact authority first.');
    $referral->update(['authority' => ['contact_permission' => true], 'stage' => 'closed']);
    $this->getJson('/operations/referrals/'.$referral->reference.'/communications/compose')
        ->assertJsonPath('blocked', 'This referral is closed. Reopen it before sending a message.');
    $this->postJson('/operations/referrals/'.$referral->reference.'/communications', staffMessageInput())->assertUnprocessable();
});

it('holds a message for the recipient’s safe contact time and sends it only at the time chosen', function () {
    [, $referral, $coordinator] = staffMessagingFixture(['safe_time' => 'Weekdays after 5pm only']);
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $this->actingAs($coordinator)->postJson($url, staffMessageInput())->assertUnprocessable()
        ->assertJsonPath('message', 'Choose when to send it, to suit their safe contact time: “Weekdays after 5pm only”.');
    $this->postJson($url, staffMessageInput(['send_at' => nzSendAt(now()->subHour())]))->assertUnprocessable()
        ->assertJsonPath('message', 'Choose a time that hasn’t passed yet.');
    $this->postJson($url, staffMessageInput(['send_at' => nzSendAt(now()->addDays(31))]))->assertUnprocessable()
        ->assertJsonPath('message', 'Choose a time within the next 30 days.');

    $at = now()->addHours(3)->startOfMinute();
    $response = $this->postJson($url, staffMessageInput(['send_at' => nzSendAt($at)]))->assertCreated();
    $id = $response->json('communication_id');
    $row = ReferralOutbox::query()->findOrFail($id);
    expect($row->available_at->equalTo($at))->toBeTrue()->and($response->json('scheduled_for'))->toBe($at->copy()->utc()->toISOString())
        ->and(ReferralEvent::query()->where('kind', 'communication.queued')->sole()->payload['summary'])->toContain('to send ')->toContain('NZ time');
    $this->getJson($url)->assertJsonPath('communications.data.0.scheduled_for', $at->copy()->utc()->toISOString())
        ->assertJsonPath('communications.data.0.state', 'pending');

    // Delivery never sends before the chosen time, then sends once it arrives.
    $transport = Mockery::mock(ReferralMessageTransport::class)->makePartial();
    $transport->shouldReceive('sendEmail')->once()->andReturnTrue();
    app()->instance(ReferralMessageTransport::class, $transport);
    app(ReferralDelivery::class)->run();
    app(ReferralDelivery::class)->deliver($id);
    expect($row->fresh()->state)->toBe('pending')->and($row->fresh()->attempts)->toBe(0);
    $this->travelTo($at->copy()->addMinute());
    app(ReferralDelivery::class)->run();
    expect($row->fresh()->state)->toBe('sent');

    // A safe time that changes while a message is held stops it.
    $later = now()->addHours(2)->startOfMinute();
    $held = $this->postJson($url, staffMessageInput(['version' => $referral->fresh()->lock_version, 'send_at' => nzSendAt($later)]))->assertCreated()->json('communication_id');
    $intake = $referral->fresh()->intake;
    $referral->update(['intake' => [...$intake, 'submitted_contact' => [...$intake['submitted_contact'], 'safe_time' => 'Mornings only now']]]);
    $this->travelTo($later->copy()->addMinute());
    app(ReferralDelivery::class)->deliver($held);
    expect(ReferralOutbox::query()->findOrFail($held)->last_error_code)->toBe('safe_time_changed');
});

it('lets staff cancel a scheduled message until it sends', function () {
    [, $referral, $coordinator] = staffMessagingFixture(['safe_time' => 'After 3pm']);
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $id = $this->actingAs($coordinator)->postJson($url, staffMessageInput(['send_at' => nzSendAt(now()->addHours(5))]))->assertCreated()->json('communication_id');
    $this->postJson($url.'/'.$id, ['version' => 2, 'request_key' => (string) Str::uuid(), 'operation' => 'cancel', 'reason' => 'They called us back before the time', 'acknowledge_duplicate_risk' => false])->assertOk();
    $this->travelTo(now()->addHours(6));
    app(ReferralDelivery::class)->run();
    expect(ReferralOutbox::query()->findOrFail($id)->state)->toBe('cancelled');
});

it('blocks templates that need details the referral is missing', function () {
    [, $referral, $coordinator] = staffMessagingFixture();
    $referral->update(['owner_id' => null]);
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $context = $this->actingAs($coordinator)->getJson($url.'/compose')->json();
    expect(collect($context['templates'])->firstWhere('id', 'first_contact')['reason'])->toBe('Assign a coordinator first. This template names them.')
        // A template that doesn't name the coordinator stays available.
        ->and(collect($context['templates'])->firstWhere('id', 'tried_to_call')['reason'])->toBeNull();
    $this->postJson($url, staffMessageInput())->assertUnprocessable()->assertJsonPath('message', 'Assign a coordinator first. This template names them.');
});

it('allows a short edit but keeps every merge field locked', function () {
    [, $referral, $coordinator] = staffMessagingFixture();
    $template = ReferralSettings::defaults()['communications']['templates'][0]['body'];
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $this->actingAs($coordinator);
    $this->postJson($url, staffMessageInput(['body' => str_replace('{first_name}', 'there', $template)]))->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Keep the merge fields as they are'));
    $this->postJson($url, staffMessageInput(['body' => $template.' {diagnosis}']))->assertUnprocessable();
    $this->postJson($url, staffMessageInput(['body' => $template.' }']))->assertUnprocessable();
    $this->postJson($url, staffMessageInput(['body' => $template.str_repeat(' more', 90)]))->assertUnprocessable()
        ->assertJsonPath('message', 'Keep edits short: add no more than 400 characters to the template.');
    $id = $this->postJson($url, staffMessageInput(['body' => str_replace('Thank you for contacting', 'Thanks for getting in touch with', $template)]))->assertCreated()->json('communication_id');
    $message = ReferralOutbox::query()->findOrFail($id)->message;
    expect($message['edited'])->toBeTrue()->and($message['body'])->toContain('Thanks for getting in touch with Fictional Living');
});

it('sends an email only after its claim commits and counts it as a contact attempt, never a conversation', function () {
    [, $referral, $coordinator] = staffMessagingFixture();
    $id = $this->actingAs($coordinator)->postJson('/operations/referrals/'.$referral->reference.'/communications', staffMessageInput())->json('communication_id');
    $baseline = DB::transactionLevel();
    $during = null;
    $transport = Mockery::mock(ReferralMessageTransport::class)->makePartial();
    $transport->shouldReceive('sendEmail')->once()->andReturnUsing(function (string $to, string $subject, string $body) use (&$during, $referral): bool {
        $during = DB::transactionLevel();
        expect($to)->toBe('aroha@example.test')->and($subject)->toBe('About your enquiry with Fictional Living')->and($body)->toContain($referral->reference);

        return true;
    });
    app()->instance(ReferralMessageTransport::class, $transport);
    app(ReferralDelivery::class)->deliver($id);
    app(ReferralDelivery::class)->run();
    $row = ReferralOutbox::query()->findOrFail($id);
    expect($during)->toBe($baseline)->and($row->state)->toBe('sent')->and($row->sent_at)->not->toBeNull()->and($row->attempts)->toBe(1)
        ->and(ReferralEvent::query()->where('kind', 'communication.sent')->count())->toBe(1)
        // Reports and first-contact targets read this column; a message never sets human contact.
        ->and($referral->fresh()->first_contact_attempt_at?->equalTo($row->sent_at))->toBeTrue()
        ->and($referral->fresh()->first_human_contact_at)->toBeNull();
    $this->getJson('/operations/referrals/'.$referral->reference.'/communications')->assertJsonPath('communications.data.0.state', 'sent');
});

it('sends texts through the SMS provider and keeps a rejection without the provider’s words', function () {
    [, $referral, $coordinator] = staffMessagingFixture(['channel' => 'phone', 'phone' => '021 555 0101']);
    $provider = fakeSmsProvider();
    $template = ReferralSettings::defaults()['communications']['templates'][1];
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $first = $this->actingAs($coordinator)->postJson($url, staffMessageInput(['channel' => 'sms', 'template_id' => 'tried_to_call', 'body' => $template['body']]))->assertCreated()->json('communication_id');
    // An earlier recorded attempt stays the first attempt.
    $earlier = now()->subDay()->startOfSecond();
    $referral->update(['first_contact_attempt_at' => $earlier]);
    app(ReferralDelivery::class)->deliver($first);
    expect(ReferralOutbox::query()->findOrFail($first)->state)->toBe('sent')
        ->and($provider->sent[0][0])->toBe('+64215550101')->and($provider->sent[0][1])->toStartWith('Kia ora Aroha, we tried to call you')
        ->and($referral->fresh()->first_contact_attempt_at->equalTo($earlier))->toBeTrue();

    $provider->accept = false;
    $second = $this->postJson($url, staffMessageInput(['version' => 2, 'channel' => 'sms', 'template_id' => 'tried_to_call', 'body' => $template['body']]))->assertCreated()->json('communication_id');
    app(ReferralDelivery::class)->deliver($second);
    $row = ReferralOutbox::query()->findOrFail($second);
    expect($row->state)->toBe('failed')->and($row->last_error_code)->toBe('sms_rejected')
        ->and(json_encode(ReferralEvent::query()->get()->map->payload))->not->toContain('+64215550101')->not->toContain('21211');

    // Texts stay off until they are switched on deliberately.
    config(['referral-delivery.sms_enabled' => false]);
    $this->getJson($url.'/compose')->assertJsonPath('recipients.0.channels.sms.reason', 'Text messaging isn’t set up yet. Ask your system administrator.');
});

it('re-checks contact preferences and the sender’s access at delivery', function () {
    [$site, $referral, $coordinator] = staffMessagingFixture();
    $transport = Mockery::mock(ReferralMessageTransport::class)->makePartial();
    $transport->shouldNotReceive('sendEmail');
    app()->instance(ReferralMessageTransport::class, $transport);
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $queue = function () use ($url, $referral): int {
        return $this->postJson($url, staffMessageInput(['version' => $referral->fresh()->lock_version]))->assertCreated()->json('communication_id');
    };
    $this->actingAs($coordinator);
    $intake = $referral->fresh()->intake;

    $id = $queue();
    $referral->update(['intake' => [...$intake, 'submitted_contact' => [...$intake['submitted_contact'], 'leave_message' => false]]]);
    app(ReferralDelivery::class)->deliver($id);
    expect(ReferralOutbox::query()->findOrFail($id)->last_error_code)->toBe('messages_not_permitted');

    $referral->update(['intake' => $intake]);
    $id = $queue();
    $referral->update(['intake' => [...$intake, 'submitted_contact' => [...$intake['submitted_contact'], 'email' => 'someone.else@example.test']]]);
    app(ReferralDelivery::class)->deliver($id);
    expect(ReferralOutbox::query()->findOrFail($id)->last_error_code)->toBe('recipient_changed');

    $referral->update(['intake' => $intake]);
    $id = $queue();
    $permission = Permission::query()->where('key', 'referrals.communications.send')->firstOrFail();
    $coordinator->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    app(ReferralDelivery::class)->deliver($id);
    $row = ReferralOutbox::query()->findOrFail($id);
    expect($row->state)->toBe('failed')->and($row->last_error_code)->toBe('sender_access_changed');
    // The retry review shows the blocker, so the old message can't be pushed out again.
    $manager = referralTestUser($site, ['viewAny', 'communications.manage']);
    $this->actingAs($manager)->getJson($url)->assertJsonPath('communications.data.0.retry_blocker', 'sender_access_changed');
    // Messages that were never sent are not contact attempts.
    expect($referral->fresh()->first_contact_attempt_at)->toBeNull();
});

it('messages reviewed People involved contacts with their own preferences, and records their role', function () {
    $family = messagingPeopleContact('family', 'email');
    $nasc = messagingPeopleContact('professional', 'phone', ['name' => 'Fictional NASC coordinator', 'organisation' => 'Fictional NASC']);
    $referrer = messagingPeopleContact('referrer', 'phone', ['leave_message' => true, 'phone' => '021 555 0188']);
    $withdrawn = messagingPeopleContact('advocate', 'email', ['state' => 'withdrawn', 'may_contact' => false, 'confirmed' => false]);
    $lapsed = messagingPeopleContact('supporter', 'email', ['review_due_at' => now()->subDay()->toISOString()]);
    [$site, $referral] = staffMessagingFixture([], ['people_contacts' => [...$family, ...$nasc, ...$referrer, ...$withdrawn, ...$lapsed]]);
    $referral->update(['authority' => messagingAuthority()]);
    $staff = referralTestUser($site, ['viewAny', 'communications.send', 'assessment.view']);
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $key = fn (array $contact): string => 'contact:'.array_key_first($contact);

    $recipients = collect($this->actingAs($staff)->getJson($url.'/compose')->assertOk()->json('recipients'))->keyBy('key');
    // The enquiry's two recipients plus every People involved contact except the withdrawn one.
    expect($recipients)->toHaveCount(6)->and($recipients->has($key($withdrawn)))->toBeFalse()
        ->and($recipients[$key($family)]['audience'])->toBe('contact')->and($recipients[$key($family)]['role_label'])->toBe('Family or whānau')
        ->and($recipients[$key($family)]['channels']['email']['reason'])->toBeNull()
        ->and($recipients[$key($family)]['channels']['sms']['reason'])->toBe('Their safe way to be contacted is by email, so texts aren’t sent.')
        ->and($recipients[$key($nasc)]['channels']['sms']['reason'])->toBe('They haven’t agreed to messages being left on their phone, so texts aren’t sent.')
        ->and($recipients[$key($nasc)]['relationship'])->toBe('Fictional relationship · Fictional NASC')
        ->and($recipients[$key($referrer)]['audience'])->toBe('referrer')->and($recipients[$key($referrer)]['channels']['sms']['reason'])->toBeNull()
        ->and($recipients[$key($lapsed)]['reason'])->toBe('Their contact review isn’t current. Review them in People involved first.');

    $template = collect(ReferralSettings::defaults()['communications']['templates'])->firstWhere('id', 'please_call_us');
    // A template written for the person doesn't fit someone involved.
    $this->postJson($url, staffMessageInput(['recipient' => $key($family)]))->assertUnprocessable()
        ->assertJsonPath('message', 'This template was turned off or changed. Choose another one.');
    $id = $this->postJson($url, staffMessageInput(['recipient' => $key($family), 'template_id' => 'please_call_us', 'body' => $template['body']]))->assertCreated()->json('communication_id');
    $row = ReferralOutbox::query()->findOrFail($id);
    expect($row->message['address'])->toBe('family@example.test')->and($row->message['recipient_role'])->toBe('family')
        ->and($row->message['body'])->toStartWith('Kia ora Fictional,')->not->toContain('Aroha');
    $event = ReferralEvent::query()->where('kind', 'communication.queued')->sole();
    expect($event->audience)->toBe('assessment')->and($event->payload['recipient_role'])->toBe('Family or whānau')
        ->and($event->payload['summary'])->toBe('“Please call us” by email to Fictional Family (Family or whānau)');
    // Retention clears the name, address, safe time and text but keeps who it went to, as a role.
    $redacted = ReferralRetention::redactMessage($row->message);
    expect($redacted)->toMatchArray(['recipient_kind' => 'contact', 'recipient_role' => 'family', 'recipient_name' => null, 'address' => null, 'safe_time' => null])
        ->and($redacted['body'])->toBe(ReferralRetention::REMOVED_MESSAGE)
        ->and(ReferralRetention::scrub($event->payload))->toMatchArray(['recipient' => 'people_contact', 'recipient_role' => 'Family or whānau', 'recipient_name' => null]);
    $this->postJson($url, staffMessageInput(['version' => 2, 'recipient' => $key($withdrawn), 'template_id' => 'please_call_us', 'body' => $template['body']]))
        ->assertUnprocessable()->assertJsonPath('message', 'This contact was withdrawn or archived, so they can’t be messaged.');

    // Staff without assessment access never see or message People involved, and only see the role in the list.
    $intakeOnly = referralTestUser($site, ['viewAny', 'communications.send']);
    $this->actingAs($intakeOnly)->getJson($url.'/compose')->assertJsonCount(2, 'recipients');
    $this->postJson($url, staffMessageInput(['version' => 2, 'recipient' => $key($family), 'template_id' => 'please_call_us', 'body' => $template['body']]))->assertForbidden();
    $this->getJson($url)->assertJsonPath('communications.data.0.recipient', 'Someone in People involved')
        ->assertJsonPath('communications.data.0.recipient_role', 'Family or whānau')
        ->assertJsonPath('communications.data.0.body', null)->assertJsonPath('communications.data.0.restricted', true);

    // Their own preferences are checked again at delivery.
    $people = $referral->fresh()->intake['people_contacts'];
    $people[array_key_first($family)]['details']['may_contact'] = false;
    $referral->update(['intake' => [...$referral->fresh()->intake, 'people_contacts' => $people]]);
    app(ReferralDelivery::class)->deliver($id);
    expect($row->fresh()->last_error_code)->toBe('contact_permission_missing');
});

it('lets the sender cancel a queued message with a reason', function () {
    [$site, $referral, $coordinator] = staffMessagingFixture();
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $id = $this->actingAs($coordinator)->postJson($url, staffMessageInput())->json('communication_id');
    $cancel = ['version' => 2, 'request_key' => (string) Str::uuid(), 'operation' => 'cancel', 'reason' => 'Sent to the wrong template by mistake', 'acknowledge_duplicate_risk' => false];
    $this->actingAs(referralTestUser($site, ['viewAny']))->postJson($url.'/'.$id, $cancel)->assertForbidden();
    $this->actingAs($coordinator)->postJson($url.'/'.$id, $cancel)->assertOk();
    expect(ReferralOutbox::query()->findOrFail($id)->state)->toBe('cancelled')
        ->and(ReferralEvent::query()->where('kind', 'communication.cancel')->count())->toBe(1);
    app(ReferralDelivery::class)->deliver($id);
    expect(ReferralOutbox::query()->findOrFail($id)->state)->toBe('cancelled');

    // Send permission covers staff messages only, never other notices.
    $notice = ReferralOutbox::query()->create(['referral_id' => $referral->id, 'deduplication_key' => 'ack:'.$referral->id, 'kind' => 'public_acknowledgement',
        'state' => 'failed', 'message' => ['recipient' => 'aroha@example.test'], 'available_at' => now()]);
    $this->postJson($url.'/'.$notice->id, [...$cancel, 'version' => 3, 'request_key' => (string) Str::uuid()])->assertForbidden();
});

it('adds a secure document link only at delivery and never stores it with the message', function () {
    [$site, $referral, $coordinator] = staffMessagingFixture([], ['relationship' => 'professional'], ['document_requests' => ['enabled' => true, 'maximum_days' => 7]]);
    $staff = referralTestUser($site, ['viewAny', 'evidence.manage', 'disclose', 'communications.manage', 'communications.send', 'funding.view', 'funding.manage']);
    $referral->update(['owner_id' => $staff->id, 'settings_version_id' => ReferralSettingsVersion::query()->value('id'), 'authority' => ['review' => ['state' => 'current', 'version' => 1,
        'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['collect' => true, 'contact' => true]]]]);
    $issued = app(ReferralDocumentRequests::class)->issue($staff, $referral->fresh(), $referral->fresh()->lock_version, [
        'request_key' => (string) Str::uuid(), 'audience' => 'funding', 'title' => 'Funding decision letter', 'instructions' => 'Please supply the current decision letter.',
        'category' => 'external_decision', 'recipient' => 'Fictional referrer', 'recipient_verification' => 'Discussed directly using their agreed safe contact channel.',
        'reason' => 'Collect evidence to review support funding.', 'days' => 7, 'separate_channel_confirmed' => true]);
    $url = '/operations/referrals/'.$referral->reference.'/communications';
    $context = $this->actingAs($coordinator)->getJson($url.'/compose')->json();
    expect(collect($context['templates'])->firstWhere('id', 'documents_needed')['reason'])->toBe('Sending a secure upload link needs permission to request information.');

    $context = $this->actingAs($staff)->getJson($url.'/compose')->assertOk()->json();
    expect($context['document_requests'])->toHaveCount(1)->and(json_encode($context))->not->toContain($issued['token'])->not->toContain($issued['passcode']);
    $template = ReferralSettings::defaults()['communications']['templates'][2];
    $input = staffMessageInput(['version' => $referral->fresh()->lock_version, 'recipient' => 'referrer', 'template_id' => 'documents_needed', 'body' => $template['body']]);
    $this->postJson($url, $input)->assertUnprocessable()->assertJsonPath('message', 'Choose an open secure document request for the link.');
    $id = $this->postJson($url, [...$input, 'document_request_id' => $issued['id']])->assertCreated()->json('communication_id');
    $stored = ReferralOutbox::query()->findOrFail($id);
    expect($stored->message['body'])->toContain('{document_link}')
        ->and(json_encode($stored->message))->not->toContain($issued['token'])
        ->and(json_encode(ReferralEvent::query()->get()->map->payload))->not->toContain($issued['token']);

    $sent = null;
    $transport = Mockery::mock(ReferralMessageTransport::class)->makePartial();
    $transport->shouldReceive('sendEmail')->once()->andReturnUsing(function (string $to, string $subject, string $body) use (&$sent): bool {
        $sent = $body;

        return true;
    });
    app()->instance(ReferralMessageTransport::class, $transport);
    app(ReferralDelivery::class)->deliver($id);
    expect($sent)->toContain('/referrals/documents/'.$issued['token'])->not->toContain($issued['passcode'])
        ->and(ReferralOutbox::query()->findOrFail($id)->state)->toBe('sent');
    $this->getJson($url)->assertJsonPath('communications.data.0.body', fn (string $body) => str_contains($body, '[secure upload link]') && ! str_contains($body, $issued['token']));

    // Once the request is revoked, a queued link is never sent.
    $second = $this->postJson($url, [...$input, 'version' => $referral->fresh()->lock_version, 'request_key' => (string) Str::uuid(), 'document_request_id' => $issued['id']])->assertCreated()->json('communication_id');
    app(ReferralDocumentRequests::class)->revoke($staff, $referral->fresh(), $referral->fresh()->lock_version, $issued['id'], 'Replaced by a new request');
    app(ReferralDelivery::class)->deliver($second);
    expect(ReferralOutbox::query()->findOrFail($second)->last_error_code)->toBe('document_request_unavailable');
});

it('grants send permission to coordinators and service managers on deploy, never to support workers', function () {
    foreach (['admin', 'ceo', 'coo', 'provider_manager', 'coordinator', 'finance', 'support_worker'] as $name) {
        \App\Models\Role::query()->firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
    }
    $migration = require base_path('database/migrations/2026_10_21_120000_grant_referral_communications_send.php');
    $migration->up();
    $migration->up();
    $holders = \App\Models\Role::query()->whereHas('permissions', fn ($query) => $query->where('key', 'referrals.communications.send'))->pluck('name')->sort()->values()->all();
    expect($holders)->toBe(['admin', 'ceo', 'coo', 'coordinator', 'provider_manager'])
        ->and(Permission::query()->where('key', 'referrals.communications.send')->count())->toBe(1)
        ->and(DB::table('role_permission')->where('permission_id', Permission::query()->where('key', 'referrals.communications.send')->value('id'))->count())->toBe(5);
    $support = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
    expect($support->canDo('referrals.communications.send'))->toBeFalse();
});
