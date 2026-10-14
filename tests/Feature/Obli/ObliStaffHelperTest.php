<?php

use App\Domain\It\Data\ItTicketCreationResult;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\Obli\ObliComputerContexts;
use App\Domain\Obli\ObliComputerIntakeContext;
use App\Domain\Obli\ObliSignIn;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.obli.enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    URL::forceScheme('https');
    $this->withServerVariables(['HTTPS' => 'on']);
    Notification::fake();
    $this->obliUser = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
    $this->obliUser->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    $permission = Permission::query()->firstOrCreate(['key' => 'it.request'], ['name' => 'Request IT help', 'module' => 'it']);
    $this->obliUser->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    $this->obliSite = ensureCanonicalHrStaffProfile($this->obliUser);
});

function obliAuthorization(): array
{
    $verifier = str_repeat('v', 64);

    return [[
        'client_id' => 'obli-windows', 'response_type' => 'code', 'code_challenge_method' => 'S256',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'state' => str_repeat('s', 43), 'redirect_uri' => 'http://127.0.0.1:52101/obli/callback',
    ], $verifier];
}
function obliExchangeInput(User $user): array
{
    [$request, $verifier] = obliAuthorization();
    $callback = app(ObliSignIn::class)->approve($user, $request);
    parse_str(parse_url($callback, PHP_URL_QUERY), $query);

    return ['client_id' => 'obli-windows', 'grant_type' => 'authorization_code',
        'code' => $query['code'], 'code_verifier' => $verifier, 'redirect_uri' => $request['redirect_uri']];
}
function obliToken($test, ?User $user = null): string
{
    return $test->postJson('/api/obli/token', obliExchangeInput($user ?? $test->obliUser))
        ->assertOk()->assertHeader('Pragma', 'no-cache')->json('access_token');
}
function obliDraft($test): array
{
    return ['request_uuid' => (string) Str::uuid(), 'site_id' => $test->obliSite->id,
        'title' => 'Printer has stopped', 'description' => 'The paper light is on. I checked the tray.',
        'category' => 'hardware'];
}

it('uses browser consent and rejects non-loopback, downgraded and malformed authorization requests', function () {
    [$input] = obliAuthorization();
    $this->actingAs($this->obliUser)->get('/obli/authorize?'.http_build_query($input))->assertOk();
    expect(DB::table('obli_authorization_codes')->count())->toBe(0);
    foreach (['http://evil.example/obli/callback', 'http://127.0.0.1:52101/other', 'http://127.0.0.1:80/obli/callback', 'http://localhost:52101/obli/callback', 'http://127.0.0.1:52101/obli/callback?x=1'] as $redirect) {
        $this->post('/obli/authorize', [...$input, 'redirect_uri' => $redirect, 'decision' => 'allow'])->assertUnprocessable();
    }
    $this->post('/obli/authorize', [...$input, 'code_challenge_method' => 'plain', 'decision' => 'allow'])->assertUnprocessable();
    $response = $this->post('/obli/authorize', [...$input, 'decision' => 'decline'])->assertRedirect();
    expect($response->headers->get('Location'))->toContain('error=access_denied');
    expect(DB::table('obli_authorization_codes')->count())->toBe(0);
});

it('exchanges a code once with matching PKCE and never stores raw credentials', function () {
    $input = obliExchangeInput($this->obliUser);
    $this->postJson('/api/obli/token', [...$input, 'code_verifier' => str_repeat('x', 64)])->assertUnauthorized();
    $this->postJson('/api/obli/token', [...$input, 'redirect_uri' => 'http://127.0.0.1:52102/obli/callback'])->assertUnauthorized();
    $token = $this->postJson('/api/obli/token', $input)->assertOk()->json('access_token');
    $this->postJson('/api/obli/token', $input)->assertUnauthorized();
    expect(DB::table('obli_staff_sessions')->sole()->token_hash)->toBe(hash('sha256', $token));
    expect(json_encode(DB::table('obli_authorization_codes')->get()))->not->toContain($input['code']);
    $this->withToken($token)->getJson('/api/obli/me')->assertOk()->assertJsonPath('name', $this->obliUser->name);
});

it('expires authorization codes and sessions and revokes the selected session on sign-out', function () {
    $input = obliExchangeInput($this->obliUser);
    DB::table('obli_authorization_codes')->update(['expires_at' => now()->subSecond()]);
    $this->postJson('/api/obli/token', $input)->assertUnauthorized();
    $token = obliToken($this);
    $this->withToken($token)->postJson('/api/obli/logout')->assertNoContent();
    $this->withToken($token)->getJson('/api/obli/me')->assertUnauthorized();
    $token = obliToken($this);
    DB::table('obli_staff_sessions')->update(['expires_at' => now()->subSecond()]);
    $this->withToken($token)->getJson('/api/obli/me')->assertUnauthorized();
});

it('rechecks current approval, permissions, staff roles and Sites and rejects account changes', function () {
    $token = obliToken($this);
    $this->obliUser->update(['approved_at' => null]);
    $this->withToken($token)->getJson('/api/obli/me')->assertForbidden();
    $this->obliUser->update(['approved_at' => now()]);
    $token = obliToken($this);
    $permission = Permission::query()->where('key', 'it.request')->firstOrFail();
    $this->obliUser->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $this->withToken($token)->getJson('/api/obli/me')->assertForbidden();
    $this->obliUser->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
    $this->obliSite->update(['is_active' => false]);
    $this->withToken($token)->getJson('/api/obli/me')->assertForbidden();
    $this->obliSite->update(['is_active' => true]);
    $this->obliUser->update(['password' => 'a completely changed test password']);
    $this->withToken($token)->getJson('/api/obli/me')->assertUnauthorized();
    $this->obliUser->update(['role' => 'client']);
    expect(fn () => obliExchangeInput($this->obliUser))->toThrow(HttpException::class);
});

it('creates reviewed own tickets through canonical intake with idempotent retries and approved Site denial', function () {
    $token = obliToken($this);
    $draft = obliDraft($this);
    $id = $this->withToken($token)->postJson('/api/obli/tickets', $draft)->assertCreated()->json('ticket.id');
    $this->withToken($token)->postJson('/api/obli/tickets', $draft)->assertOk()->assertJsonPath('ticket.id', $id)->assertJsonPath('replayed', true);
    expect(ItTicket::query()->count())->toBe(1);
    $this->withToken($token)->postJson('/api/obli/tickets', [...$draft, 'title' => 'Changed content'])->assertConflict();
    $this->withToken($token)->postJson('/api/obli/tickets', [...$draft, 'request_uuid' => (string) Str::uuid(), 'site_id' => Site::factory()->create()->id])->assertForbidden();
    $this->withToken($token)->postJson('/api/obli/tickets', [...$draft, 'requester_user_id' => 999])->assertUnprocessable();
    $this->withToken($token)->getJson('/api/obli/tickets')->assertOk()->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.id', $id);
    expect(DB::table('it_ticket_command_receipts')->sole()->channel)->toBe('obli');
});

it('requires native authentication independently of browser login and closes disabled or insecure routes', function () {
    $this->actingAs($this->obliUser)->getJson('/api/obli/me')->assertUnauthorized();
    $this->withServerVariables(['HTTPS' => 'off'])->postJson('http://localhost/api/obli/token', [])->assertStatus(400);
    $this->withServerVariables(['HTTPS' => 'on']);
    config()->set('endpoint_management.obli.enabled', false);
    $response = $this->getJson('/api/obli/me')->assertNotFound()->assertHeader('Pragma', 'no-cache');
    expect($response->getContent())->not->toContain('trace', 'password', 'SQLSTATE');
});

it('keeps a personal helper private even for an IT technician and rejects forged ticket links', function () {
    $other = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
    $other->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($other, $this->obliSite);
    $ownToken = obliToken($this);
    $ownId = $this->withToken($ownToken)->postJson('/api/obli/tickets', obliDraft($this))->assertCreated()->json('ticket.id');
    $otherToken = obliToken($this, $other);
    $this->withToken($otherToken)->postJson('/api/obli/tickets', obliDraft($this))->assertCreated();
    $manager = Permission::query()->where('key', 'it.manage')->firstOrFail();
    $this->obliUser->permissionOverrides()->syncWithoutDetaching([$manager->id => ['allowed' => true]]);
    $this->withToken($ownToken)->getJson('/api/obli/tickets')->assertOk()->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.id', $ownId);
    $this->withToken($ownToken)->postJson('/api/obli/tickets', [...obliDraft($this), 'device_id' => 111])->assertUnprocessable();
    $this->obliUser->forceFill(['external_clinical_account' => true])->save();
    expect($this->obliUser->fresh()->external_clinical_account)->toBeTrue();
    $this->withToken($ownToken)->getJson('/api/obli/me')->assertForbidden();
});

it('rejects a different deployment origin without issuing credentials', function () {
    [$input] = obliAuthorization();
    $this->actingAs($this->obliUser)->post('https://unapproved.example/obli/authorize', [...$input, 'decision' => 'allow'])->assertStatus(400);
    expect(DB::table('obli_authorization_codes')->count())->toBe(0);
});

it('does not replay a transferred ticket to a technician through their personal helper', function () {
    $token = obliToken($this);
    $draft = obliDraft($this);
    $id = $this->withToken($token)->postJson('/api/obli/tickets', $draft)->assertCreated()->json('ticket.id');
    $other = User::factory()->create(['approved_at' => now()]);
    $manager = Permission::query()->where('key', 'it.manage')->firstOrFail();
    $this->obliUser->permissionOverrides()->syncWithoutDetaching([$manager->id => ['allowed' => true]]);
    ItTicket::query()->whereKey($id)->update(['requester_user_id' => $other->id, 'requested_for_user_id' => $other->id]);
    $this->withToken($token)->postJson('/api/obli/tickets', $draft)->assertNotFound();
});

it('attributes native actions to the token holder without logging credentials', function () {
    $other = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
    $this->actingAs($other);
    $token = obliToken($this);
    $event = AuditLog::query()->where('action', 'obli.staff.signed_in')->sole();
    expect((int) $event->user_id)->toBe((int) $this->obliUser->id)
        ->and(json_encode($event->toArray()))->not->toContain($token, 'code_verifier');
    $this->withToken($token)->postJson('/api/obli/logout')->assertNoContent();
    expect((int) AuditLog::query()->where('action', 'obli.staff.signed_out')->sole()->user_id)->toBe((int) $this->obliUser->id);
});

it('shows only public own-ticket history in chronological pages and bounds native responses', function () {
    $token = obliToken($this);
    $id = $this->withToken($token)->postJson('/api/obli/tickets', obliDraft($this))->assertCreated()->json('ticket.id');
    $ticket = ItTicket::findOrFail($id);
    $ids = [];
    foreach (range(1, 12) as $number) {
        $ids[] = $ticket->comments()->create(['author_user_id' => $this->obliUser->id,
            'body' => 'Public reply '.$number, 'is_internal' => false])->id;
    }
    $ticket->comments()->create(['author_user_id' => $this->obliUser->id, 'body' => 'SECRET INTERNAL NOTE', 'is_internal' => true]);
    $page = $this->withToken($token)->getJson('/api/obli/tickets/'.$id)->assertOk()
        ->assertJsonCount(10, 'comments')->assertJsonPath('ticket.can_reply', true)
        ->assertJsonPath('comments.0.id', $ids[2])->assertJsonPath('next_before', $ids[2]);
    expect($page->getContent())->not->toContain('SECRET INTERNAL NOTE', 'attachments', 'email', 'staff_context');
    $this->getJson('/api/obli/tickets/'.$id.'?before='.$ids[2])->assertOk()
        ->assertJsonCount(2, 'comments')->assertJsonPath('comments.0.id', $ids[0])->assertJsonPath('next_before', null);
    $ticket->forceFill(['description' => str_repeat("\x01", 12001)])->save();
    $ticket->comments()->publicOnly()->update(['body' => str_repeat("\x01", 5001)]);
    $bounded = $this->getJson('/api/obli/tickets/'.$id)->assertOk()->assertJsonPath('ticket.description_truncated', true);
    expect(strlen($bounded->getContent()))->toBeLessThan(131072)
        ->and($bounded->json('comments'))->not->toBeEmpty()
        ->and($bounded->json('comments.0.body_truncated'))->toBeTrue()
        ->and($bounded->json('next_before'))->not->toBeNull();
});

it('writes an Obli public reply once and recovers the lost acknowledgement without another write', function () {
    Bus::fake();
    $token = obliToken($this);
    $id = $this->withToken($token)->postJson('/api/obli/tickets', obliDraft($this))->assertCreated()->json('ticket.id');
    $ticket = ItTicket::findOrFail($id);
    $input = ['request_uuid' => (string) Str::uuid(), 'expected_version' => $ticket->lock_version, 'body' => 'I checked the cable.'];
    $first = $this->postJson('/api/obli/tickets/'.$id.'/replies', $input)->assertCreated();
    $this->postJson('/api/obli/tickets/'.$id.'/replies', $input)->assertOk()->assertExactJson($first->json());
    if (is_string($export = getenv('OBLI_TICKET_EXPORT')) && $export !== '') {
        file_put_contents($export, json_encode([
            'page' => $this->getJson('/api/obli/tickets')->assertOk()->json(),
            'detail' => $this->getJson('/api/obli/tickets/'.$id)->assertOk()->json(),
            'receipt' => $first->json(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $counts = [DB::table('it_ticket_comments')->count(), DB::table('it_ticket_command_receipts')->count(), DB::table('it_email_deliveries')->count()];
    $this->getJson('/api/obli/tickets/'.$id.'/replies/'.$input['request_uuid'])->assertOk()->assertExactJson($first->json());
    expect([DB::table('it_ticket_comments')->count(), DB::table('it_ticket_command_receipts')->count(), DB::table('it_email_deliveries')->count()])->toBe($counts);
    $comment = $ticket->comments()->sole();
    expect($comment->is_internal)->toBeFalse()->and($comment->source_channel)->toBe('obli')
        ->and((int) $comment->author_user_id)->toBe((int) $this->obliUser->id)
        ->and($first->json('committed_version'))->toBeGreaterThan($input['expected_version']);
    $this->postJson('/api/obli/tickets/'.$id.'/replies', [...$input, 'body' => 'Changed immutable intent'])->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    $this->postJson('/api/obli/tickets/'.$id.'/replies', [...$input, 'request_uuid' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'version_conflict');
    $ticket->refresh()->update(['status' => 'closed']);
    $this->getJson('/api/obli/tickets/'.$id)->assertOk()->assertJsonPath('ticket.can_reply', false);
    $this->getJson('/api/obli/tickets/'.$id.'/replies/'.$input['request_uuid'])->assertOk()->assertJsonPath('comment.id', $comment->id);
    $this->postJson('/api/obli/tickets/'.$id.'/replies', [...$input, 'request_uuid' => (string) Str::uuid(), 'expected_version' => $ticket->fresh()->lock_version])
        ->assertUnprocessable()->assertJsonPath('code', 'reply_rejected');
    expect($ticket->comments()->count())->toBe(1);
});

it('rejects forged reply fields blank bodies and missing recovery without creating receipts', function () {
    $token = obliToken($this);
    $id = $this->withToken($token)->postJson('/api/obli/tickets', obliDraft($this))->assertCreated()->json('ticket.id');
    $input = ['request_uuid' => (string) Str::uuid(), 'expected_version' => ItTicket::findOrFail($id)->lock_version, 'body' => 'Reply'];
    foreach (['actor_user_id' => $this->obliUser->id, 'is_internal' => true, 'channel' => 'browser', 'attachments' => [], 'work_payload' => '{}'] as $field => $value) {
        $this->postJson('/api/obli/tickets/'.$id.'/replies', [...$input, $field => $value])->assertUnprocessable()->assertJsonPath('code', 'invalid_request');
    }
    $this->postJson('/api/obli/tickets/'.$id.'/replies', [...$input, 'body' => '   '])->assertUnprocessable();
    $this->getJson('/api/obli/tickets/'.$id.'/replies/'.$input['request_uuid'])->assertNotFound();
    expect(DB::table('it_ticket_command_receipts')->where('operation', ItTicketCommandReceipt::COMMENT_OPERATION)->count())->toBe(0)
        ->and(DB::table('it_ticket_comments')->count())->toBe(0);
});

it('conceals detail write and recovery after the personal ticket boundary changes', function (string $change) {
    $token = obliToken($this);
    $id = $this->withToken($token)->postJson('/api/obli/tickets', obliDraft($this))->assertCreated()->json('ticket.id');
    $ticket = ItTicket::findOrFail($id);
    $input = ['request_uuid' => (string) Str::uuid(), 'expected_version' => $ticket->lock_version, 'body' => 'Original private reply'];
    $this->postJson('/api/obli/tickets/'.$id.'/replies', $input)->assertCreated();
    $manager = Permission::query()->where('key', 'it.manage')->firstOrFail();
    $this->obliUser->permissionOverrides()->syncWithoutDetaching([$manager->id => ['allowed' => true]]);
    match ($change) {
        'requester' => $ticket->update(['requester_user_id' => User::factory()->create()->id]),
        'site' => $ticket->update(['site_id' => Site::factory()->create()->id]),
        'merge' => $ticket->update(['merged_into_ticket_id' => ItTicket::factory()->create()->id]),
        'internal' => $ticket->comments()->update(['is_internal' => true]),
    };
    if ($change !== 'internal') {
        $this->getJson('/api/obli/tickets/'.$id)->assertNotFound();
        $this->postJson('/api/obli/tickets/'.$id.'/replies', $input)->assertNotFound();
    } else {
        $this->getJson('/api/obli/tickets/'.$id)->assertOk()->assertJsonCount(0, 'comments');
        $this->postJson('/api/obli/tickets/'.$id.'/replies', $input)->assertNotFound();
    }
    $response = $this->getJson('/api/obli/tickets/'.$id.'/replies/'.$input['request_uuid'])->assertNotFound();
    expect($response->getContent())->not->toContain('Original private reply', 'trace', 'SQLSTATE');
    expect(DB::table('it_ticket_comments')->count())->toBe(1);
})->with(['requester', 'site', 'merge', 'internal']);

it('rechecks staff role and revoked session for every native conversation operation', function () {
    $token = obliToken($this);
    $id = $this->withToken($token)->postJson('/api/obli/tickets', obliDraft($this))->assertCreated()->json('ticket.id');
    $input = ['request_uuid' => (string) Str::uuid(), 'expected_version' => ItTicket::findOrFail($id)->lock_version, 'body' => 'Retain my reply'];
    $this->postJson('/api/obli/tickets/'.$id.'/replies', $input)->assertCreated();
    $this->obliUser->update(['role' => 'client']);
    $this->getJson('/api/obli/tickets/'.$id)->assertForbidden();
    $this->postJson('/api/obli/tickets/'.$id.'/replies', $input)->assertForbidden();
    $this->getJson('/api/obli/tickets/'.$id.'/replies/'.$input['request_uuid'])->assertForbidden();
    $this->obliUser->update(['role' => 'support_worker']);
    DB::table('obli_staff_sessions')->update(['revoked_at' => now()]);
    $this->getJson('/api/obli/tickets/'.$id)->assertUnauthorized();
    $this->postJson('/api/obli/tickets/'.$id.'/replies', $input)->assertUnauthorized();
    $this->getJson('/api/obli/tickets/'.$id.'/replies/'.$input['request_uuid'])->assertUnauthorized();
});

it('keeps worst-case Unicode and escaped-control ticket lists within the native byte cap', function () {
    $token = obliToken($this);
    foreach (range(1, 31) as $number) {
        ItTicket::factory()->create([
            'requester_user_id' => $this->obliUser->id, 'requested_for_user_id' => $this->obliUser->id,
            'site_id' => $this->obliSite->id, 'reference' => str_repeat('😀', 190).$number,
            'title' => str_repeat('😀', 200), 'merged_into_ticket_id' => null,
        ]);
    }
    $page = $this->withToken($token)->getJson('/api/obli/tickets')->assertOk()->assertJsonCount(30, 'tickets');
    expect(strlen($page->getContent()))->toBeLessThan(131072)
        ->and($page->json('next_before'))->toBe($page->json('tickets.29.id'));
    $this->getJson('/api/obli/tickets?before='.$page->json('next_before'))->assertOk()->assertJsonCount(1, 'tickets');
    ItTicket::query()->update(['title' => str_repeat("\x01", 200)]);
    $page = $this->getJson('/api/obli/tickets')->assertOk()->assertJsonCount(30, 'tickets');
    expect(strlen($page->getContent()))->toBeLessThan(131072);
});

function obliComputerFixture($test, ?Site $site = null): array
{
    $site ??= $test->obliSite;
    $device = Device::factory()->create(['name' => 'Reviewed fixture PC', 'domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active']);
    DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    $credential = bin2hex(random_bytes(32));
    $agent = EndpointAgent::query()->create(['uuid' => (string) Str::uuid(), 'device_id' => $device->id, 'site_id' => $site->id, 'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($device), 'platform' => 'windows', 'credential_hash' => hash('sha256', $credential), 'enrolled_at' => now(), 'created_by_user_id' => $test->obliUser->id]);
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('p', 32)))));

    return [$device, $agent, $credential];
}
function obliConfirmedComputer($test): array
{
    [$device,$agent,$credential] = obliComputerFixture($test);
    $token = obliToken($test);
    $draft = obliDraft($test);
    $mint = $test->withToken($token)->postJson('/api/obli/ticket-computer-context', $draft)->assertOk()->json();
    $envelope = $test->withToken($credential)->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $mint['challenge']])->assertOk()->json();

    return [$device, $agent, $credential, $token, $draft, $mint, $envelope];
}
it('proves only the authenticated local computer and links it atomically through canonical intake', function () {
    [$device,$agent,$credential,$token,$draft,$mint,$envelope] = obliConfirmedComputer($this);
    expect($this->obliUser->canDo('securityDevices.devices.view'))->toBeFalse();
    $payload = base64_decode($envelope['payload'], true);
    $key = app(EndpointCommandSigner::class)->publicKey();
    expect(sodium_crypto_sign_verify_detached(base64_decode($envelope['signature'], true), $payload, base64_decode($key, true)))->toBeTrue();
    $ack = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    expect($ack)->toMatchArray(['type' => 'obli_ticket_computer_context', 'challenge_hash' => hash('sha256', $mint['challenge']), 'agent_uuid' => $agent->uuid, 'status' => 'confirmed'])->and($ack['expires_at'] - $ack['issued_at'])->toBe(30);
    $context = $this->withToken($token)->getJson('/api/obli/ticket-computer-context/'.$draft['request_uuid'])->assertOk()->assertJsonPath('computer.name', $device->name)->json();
    expect($context['computer'])->toHaveKeys(['name', 'site'])->not->toHaveKey('id');
    $receipt = $this->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertCreated()->assertJsonPath('replayed', false)->json();
    $ticket = ItTicket::query()->findOrFail($receipt['ticket']['id']);
    expect($ticket->links()->where('relationship', 'affected_device')->sole()->linkable_id)->toBe($device->id)->and(ItTicketCommandReceipt::query()->count())->toBe(1);
    $this->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertOk()->assertJsonPath('ticket.id', $ticket->id)->assertJsonPath('replayed', true);
    $this->getJson('/api/obli/tickets/requests/'.$draft['request_uuid'])->assertOk()->assertJsonPath('ticket.id', $ticket->id);
    $this->postJson('/api/obli/ticket-computer-context', $draft)->assertOk()->assertJsonPath('challenge', $mint['challenge']);
    $this->postJson('/api/obli/tickets', [...$draft, 'computer_context' => false])->assertUnprocessable();
    $this->postJson('/api/obli/tickets', $draft)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    if ($export = getenv('OBLI_COMPUTER_EXPORT')) {
        file_put_contents($export, json_encode(['mint' => $mint, 'context' => $context, 'receipt' => $receipt, 'envelope' => $envelope, 'public_key' => $key, 'challenge' => $mint['challenge'], 'agent_uuid' => $agent->uuid, 'origin' => 'https://localhost'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
});
it('rejects unconfirmed, altered and arbitrary computer associations before any intake write', function () {
    [$device,$agent,$credential] = obliComputerFixture($this);
    $token = obliToken($this);
    $draft = obliDraft($this);
    $this->withToken($token)->getJson('/api/obli/me')->assertOk()->assertJsonPath('computer_context_enabled', true);
    $this->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertNotFound();
    $mint = $this->postJson('/api/obli/ticket-computer-context', $draft)->assertOk()->json();
    $this->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertNotFound();
    $this->postJson('/api/obli/ticket-computer-context', [...$draft, 'title' => 'Changed intent'])->assertConflict();
    $this->postJson('/api/obli/tickets', [...$draft, 'device_id' => $device->id])->assertUnprocessable();
    $this->withToken($credential)->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $mint['challenge'], 'device_id' => $device->id])->assertUnprocessable();
    $this->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $mint['challenge']])->assertOk();
    $this->withToken($token)->postJson('/api/obli/tickets', [...$draft, 'description' => 'Changed intent', 'computer_context' => true])->assertConflict();
    expect(ItTicket::query()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(0);
});
it('allows committed lost-response recovery after proof expiry while pruning only sealed challenge material', function () {
    [$device,$agent,$credential,$token,$draft,$mint] = obliConfirmedComputer($this);
    $id = $this->withToken($token)->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertCreated()->json('ticket.id');
    DB::table('obli_computer_contexts')->where('request_uuid', $draft['request_uuid'])->update(['expires_at' => now()->subSecond()]);
    $this->artisan('obli:prune-sessions')->assertSuccessful();
    $row = DB::table('obli_computer_contexts')->sole();
    expect($row->challenge_encrypted)->toBeNull()->and((int) $row->ticket_id)->toBe($id);
    $this->withToken($token)->getJson('/api/obli/tickets/requests/'.$draft['request_uuid'])->assertOk()->assertJsonPath('ticket.id', $id);
    $this->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertOk()->assertJsonPath('ticket.id', $id);
    expect(ItTicket::query()->count())->toBe(1)->and(ItTicketCommandReceipt::query()->count())->toBe(1);
});
it('rejects expired uncommitted proof and does not infer that a missing receipt permits a new request', function () {
    [$device,$agent,$credential,$token,$draft,$mint] = obliConfirmedComputer($this);
    DB::table('obli_computer_contexts')->where('request_uuid', $draft['request_uuid'])->update(['expires_at' => now()->subSecond()]);
    $this->withToken($token)->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertNotFound()->assertJsonPath('code', 'context_expired');
    $this->getJson('/api/obli/tickets/requests/'.$draft['request_uuid'])->assertNotFound();
    expect(ItTicket::query()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(0);
});
it('binds proof to the exact staff session and prevents a second endpoint replacing a confirmed identity', function () {
    [$device,$agent,$credential,$token,$draft,$mint] = obliConfirmedComputer($this);
    $otherSession = obliToken($this);
    $this->withToken($otherSession)->getJson('/api/obli/ticket-computer-context/'.$draft['request_uuid'])->assertNotFound();
    $this->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertNotFound();
    [$otherDevice,$otherAgent,$otherCredential] = obliComputerFixture($this);
    $this->withToken($otherCredential)->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $mint['challenge']])->assertNotFound();
    $this->withToken($credential)->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $mint['challenge']])->assertOk();
    $this->withToken($token)->getJson('/api/obli/ticket-computer-context/'.$draft['request_uuid'])->assertOk()->assertJsonPath('computer.name', $device->name);
});
it('conceals committed associations when present endpoint, Site, requester or link authority changes', function (string $change) {
    [$device,$agent,$credential,$token,$draft] = obliConfirmedComputer($this);
    $id = $this->withToken($token)->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertCreated()->json('ticket.id');
    match ($change) {
        'revoked' => $agent->update(['revoked_at' => now(), 'credential_hash' => null]),
        'retired' => $device->update(['status' => 'decommissioned']),
        'assignment' => DeviceAssignment::query()->where('device_id', $device->id)->update(['assigned_at' => now()->subHour()]),
        'site' => $this->obliSite->update(['is_active' => false]),
        'requester' => ItTicket::query()->whereKey($id)->update(['requester_user_id' => User::factory()->create()->id]),
        'link' => ItTicket::query()->find($id)->links()->delete(),
        'merged' => ItTicket::query()->whereKey($id)->update(['merged_into_ticket_id' => ItTicket::factory()->create()->id]),
    };
    foreach (['/api/obli/ticket-computer-context/'.$draft['request_uuid'], '/api/obli/tickets/requests/'.$draft['request_uuid']] as $path) {
        $response = $this->withToken($token)->getJson($path);
        expect(in_array($response->status(), [403, 404], true))->toBeTrue();
        expect($response->getContent())->not->toContain($device->name);
    }
    expect(ItTicketCommandReceipt::query()->count())->toBe(1);
})->with(['revoked', 'retired', 'assignment', 'site', 'requester', 'link', 'merged']);
it('requires selected ticket Site overlap and current staff authority for endpoint proof', function () {
    [$device,$agent,$credential] = obliComputerFixture($this, Site::factory()->create(['is_active' => true, 'archived' => false]));
    $token = obliToken($this);
    $draft = obliDraft($this);
    $mint = $this->withToken($token)->postJson('/api/obli/ticket-computer-context', $draft)->assertOk()->json();
    $this->withToken($credential)->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $mint['challenge']])->assertNotFound();
    DB::table('obli_staff_sessions')->where('token_hash', hash('sha256', $token))->update(['revoked_at' => now()]);
    $this->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $mint['challenge']])->assertUnauthorized();
    expect(DB::table('obli_computer_contexts')->sole()->confirmed_at)->toBeNull();
});

it('limits an active intake proof to one newly created ticket with the exact reviewed intent', function () {
    [$device,$agent,$credential,$token,$draft] = obliConfirmedComputer($this);
    $existing = ItTicket::factory()->create(['title' => $draft['title'], 'description' => $draft['description'], 'category' => $draft['category'], 'site_id' => $draft['site_id'], 'source' => 'portal', 'work_type' => 'incident', 'requester_user_id' => $this->obliUser->id]);
    app(ObliSignIn::class)->withSession($token, function (User $actor, string $hash) use ($draft, $existing) {
        app(ObliComputerContexts::class)->withIntake($actor, $hash, $draft, function ($proof) use ($actor, $existing, $draft) {
            expect(fn () => app(ItTicketLinkService::class)->linkVerifiedLocalComputerAtIntake($existing, $proof, $actor))->toThrow(HttpException::class);
            $fresh = ItTicket::factory()->create(['title' => 'Different title', 'description' => $draft['description'], 'category' => $draft['category'], 'site_id' => $draft['site_id'], 'source' => 'portal', 'work_type' => 'incident', 'requester_user_id' => $actor->id]);
            expect(fn () => $proof->deviceForTicket($actor, $fresh))->toThrow(HttpException::class);
            $fresh->update(['title' => $draft['title']]);
            $proof->deviceForTicket($actor, $fresh);
            $second = ItTicket::factory()->create(['title' => $draft['title'], 'description' => $draft['description'], 'category' => $draft['category'], 'site_id' => $draft['site_id'], 'source' => 'portal', 'work_type' => 'incident', 'requester_user_id' => $actor->id]);
            expect(fn () => $proof->deviceForTicket($actor, $second))->toThrow(HttpException::class);

            return new ItTicketCreationResult($fresh, $draft['request_uuid']);
        });
    });
    expect(ItTicketLink::query()->count())->toBe(0);
});
it('rolls back ticket receipt and verified computer link together if persistence fails', function () {
    [$device,$agent,$credential,$token,$draft] = obliConfirmedComputer($this);
    $failed = false;
    DB::listen(function ($event) use (&$failed) {
        if (! $failed && str_starts_with(strtolower($event->sql), 'insert into `it_ticket_links`')) {
            $failed = true;
            throw new RuntimeException('Private test storage failure.');
        }
    });
    $response = $this->withToken($token)->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertStatus(500);
    expect($response->getContent())->not->toContain('Private test storage failure')->and(ItTicket::query()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(0)->and(ItTicketLink::query()->count())->toBe(0)->and(DB::table('obli_computer_contexts')->sole()->ticket_id)->toBeNull();
    $this->postJson('/api/obli/tickets', [...$draft, 'computer_context' => true])->assertCreated();
});
it('fails closed when computer proof signing readiness is absent', function () {
    $token = obliToken($this);
    config()->set('endpoint_management.command_signing_secret', '');
    $this->withToken($token)->getJson('/api/obli/me')->assertOk()->assertJsonPath('computer_context_enabled', false);
    $this->postJson('/api/obli/ticket-computer-context', obliDraft($this))->assertNotFound();
    expect(DB::table('obli_computer_contexts')->count())->toBe(0);
});

it('never refreshes a confirmed proof onto a changed canonical assignment', function () {
    [$device,$agent,$credential,$token,$draft,$mint] = obliConfirmedComputer($this);
    DeviceAssignment::query()->where('device_id', $device->id)->update(['assigned_at' => now()->subHour()]);
    $agent->update(['assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($device)]);
    $this->withToken($credential)->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $mint['challenge']])->assertNotFound();
    $this->withToken($token)->getJson('/api/obli/ticket-computer-context/'.$draft['request_uuid'])->assertNotFound();
    expect(ItTicket::query()->count())->toBe(0);
});
it('cannot create an active proof outside an existing transaction or use it after its callback', function () {
    [$device,$agent,$credential,$token,$draft] = obliConfirmedComputer($this);
    $row = DB::table('obli_computer_contexts')->sole();
    $connection = DB::getFacadeRoot();
    $mock = Mockery::mock();
    $mock->shouldReceive('transactionLevel')->once()->andReturn(0);
    DB::swap($mock);
    try {
        expect(fn () => ObliComputerIntakeContext::within($this->obliUser, $draft, (int) $row->id, fn () => null))->toThrow(HttpException::class);
    } finally {
        DB::swap($connection);
    }
    $saved = null;
    try {
        app(ObliSignIn::class)->withSession($token, function (User $actor, string $hash) use ($draft, &$saved) {
            app(ObliComputerContexts::class)->withIntake($actor, $hash, $draft, function ($proof) use (&$saved) {
                $saved = $proof;
                throw new DomainException('Stop test before writing.');
            });
        });
        $this->fail('The test operation did not throw.');
    } catch (DomainException $error) {
        expect($error->getMessage())->toBe('Stop test before writing.');
    }
    expect(fn () => $saved->device($this->obliUser, $draft, ItTicketCommandChannel::Obli))->toThrow(HttpException::class);
});
