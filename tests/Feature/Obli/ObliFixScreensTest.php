<?php

use App\Domain\Obli\Fixes\ObliScreenPictures;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointActionPolicy;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\Setup\SetupPresenter;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/*
 * S&D Phase I, IT's side: screen pictures (pop-up 94), one-off requests (pop-up 95), turning a fix
 * on (pop-up 93), the session record (pop-up 96) and the Setup tabs (15i, 15j).
 */

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('g', 32)))));
    URL::forceScheme('https');
    $this->withServerVariables(['HTTPS' => 'on']);
    Notification::fake();
    Storage::fake('private');
    $this->screenSite = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false]);
    $this->screenIt = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->screenIt->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->screenIt, $this->screenSite);
    foreach (['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view'] as $key) {
        fixScreensGrant($this->screenIt, $key);
    }
    $this->screenDevice = Device::factory()->create(['name' => 'Synthetic office PC', 'domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active']);
    DeviceAssignment::query()->create(['device_id' => $this->screenDevice->id, 'assignable_type' => 'site', 'assignable_id' => $this->screenSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    $this->screenAgent = EndpointAgent::query()->create([
        'uuid' => (string) Str::uuid(), 'device_id' => $this->screenDevice->id, 'site_id' => $this->screenSite->id, 'platform' => 'windows',
        'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($this->screenDevice), 'credential_hash' => hash('sha256', str_repeat('e', 64)),
        'enrolled_at' => now(), 'last_seen_at' => now(), 'observed_at' => now(), 'created_by_user_id' => $this->screenIt->id,
        'service_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_scm', 'operations' => ['restart']],
    ]);
    $this->screenStaff = User::factory()->create(['approved_at' => now(), 'name' => 'Synthetic Staff']);
});

function fixScreensGrant(User $user, string $key): void
{
    $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'securityDevices']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
}

/** A finished help session on the computer, with one stored picture. */
function fixScreensSession($test, array $values = []): object
{
    $id = DB::table('obli_fix_sessions')->insertGetId([
        'uuid' => (string) Str::uuid(), 'user_id' => $test->screenStaff->id, 'session_hash' => str_repeat('a', 64), 'request_uuid' => (string) Str::uuid(),
        'site_id' => $test->screenSite->id, 'device_id' => $test->screenDevice->id, 'agent_uuid' => $test->screenAgent->uuid, 'assignment_fingerprint' => $test->screenAgent->assignment_fingerprint,
        'state' => 'done', 'outcome' => 'fixed', 'problem_hash' => str_repeat('b', 64), 'problem_encrypted' => Crypt::encryptString('The printer won’t print.'),
        'plan_encrypted' => Crypt::encryptString(json_encode(['title' => 'Printer won’t print', 'checks' => [], 'plan' => [['text' => 'Cancel your stuck jobs', 'kind' => 'screen', 'fix_key' => null]], 'minutes' => 2, 'refusal' => null])),
        'outcome_encrypted' => Crypt::encryptString(json_encode(['fixed' => true, 'summary' => 'Fixed. Your letter printed.', 'steps' => []])),
        'screen_width' => 320, 'screen_height' => 200, 'plan_steps' => 1, 'current_step' => 1, 'turns' => 1, 'last_turn' => 1,
        'approved_at' => now()->subMinutes(3), 'ended_at' => now()->subMinute(), 'expires_at' => now()->addMinutes(10), 'created_at' => now()->subMinutes(4), 'updated_at' => now(),
        ...$values,
    ]);
    $session = DB::table('obli_fix_sessions')->where('id', $id)->first();
    $image = imagecreatetruecolor(320, 200);
    ob_start();
    imagejpeg($image, null, 60);
    $bytes = (string) ob_get_clean();
    app(ObliScreenPictures::class)->store($session, 1, ['bytes' => $bytes, 'width' => 320, 'height' => 200, 'taken_at' => now()]);

    return $session;
}

it('opens pictures only with the permission and a reason, logs every view and never caches them', function () {
    $session = fixScreensSession($this);
    $this->actingAs($this->screenIt)->postJson('/security-devices/obli/sessions/'.$session->uuid.'/pictures', ['reason' => 'Checking the fix worked'])->assertForbidden();

    fixScreensGrant($this->screenIt, ObliScreenPictures::PERMISSION);
    $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/obli/sessions/'.$session->uuid.'/pictures', ['reason' => ''])->assertUnprocessable();
    $view = $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/obli/sessions/'.$session->uuid.'/pictures', ['reason' => 'Checking the fix worked'])
        ->assertOk()->assertHeader('Cache-Control')->json();
    expect($view['pictures'])->toHaveCount(1)
        ->and(DB::table('obli_picture_views')->where('session_id', $session->id)->where('user_id', $this->screenIt->id)->value('reason'))->toBe('Checking the fix worked')
        ->and(AuditLog::query()->where('action', 'obli.fix.pictures_viewed')->where('meta->reason', 'Checking the fix worked')->exists())->toBeTrue();

    $image = $this->actingAs($this->screenIt->fresh())->get($view['pictures'][0]['url'])->assertOk();
    expect($image->headers->get('Content-Type'))->toBe('image/jpeg')->and($image->headers->get('Cache-Control'))->toContain('no-store');

    // Someone else can't use this view; nor can it be used after 30 minutes.
    $other = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
    ensureCanonicalHrStaffProfile($other, $this->screenSite);
    foreach (['securityDevices.viewAny', 'securityDevices.devices.view', ObliScreenPictures::PERMISSION] as $key) {
        fixScreensGrant($other, $key);
    }
    $this->actingAs($other)->get($view['pictures'][0]['url'])->assertNotFound();
    $this->travel(31)->minutes();
    $this->actingAs($this->screenIt->fresh())->get($view['pictures'][0]['url'])->assertNotFound();
});

it('keeps another Site’s sessions and pictures out of reach (404)', function () {
    $elsewhere = Site::factory()->create(['name' => 'Elsewhere House', 'is_active' => true, 'archived' => false]);
    $outsider = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
    ensureCanonicalHrStaffProfile($outsider, $elsewhere);
    foreach (['securityDevices.viewAny', 'securityDevices.devices.view', ObliScreenPictures::PERMISSION, 'securityDevices.obli.fixes.manage'] as $key) {
        fixScreensGrant($outsider, $key);
    }
    $session = fixScreensSession($this);
    $this->actingAs($outsider)->getJson('/security-devices/obli/sessions/'.$session->uuid)->assertNotFound();
    $this->actingAs($outsider)->postJson('/security-devices/obli/sessions/'.$session->uuid.'/pictures', ['reason' => 'Curious about it'])->assertNotFound();
    expect(DB::table('obli_picture_views')->count())->toBe(0);
});

it('shows a session record with every step and no picture in it', function () {
    fixScreensGrant($this->screenIt, ObliScreenPictures::PERMISSION);
    $session = fixScreensSession($this);
    DB::table('obli_fix_steps')->insert(['session_id' => $session->id, 'turn' => 1, 'step' => 1, 'kind' => 'action', 'narration_encrypted' => Crypt::encryptString('cancelling stuck jobs'), 'action' => json_encode(['type' => 'click', 'x' => 10, 'y' => 10]), 'result' => 'done', 'picture_id' => DB::table('obli_screen_pictures')->value('id'), 'at' => now()]);
    $record = $this->actingAs($this->screenIt->fresh())->getJson('/security-devices/obli/sessions/'.$session->uuid)->assertOk()
        ->assertJsonPath('title', 'Printer won’t print')->assertJsonPath('steps.0.text', 'Cancelling stuck jobs')->assertJsonPath('steps.0.picture', true)
        ->assertJsonPath('pictures.count', 1)->assertJsonPath('pictures.can_open', true)->json();
    expect(json_encode($record))->not->toContain('obli-pictures/')->and(json_encode($record))->not->toContain('jpeg');
});

it('approving a one-off request runs nothing: a listed fix goes to the governed form, anything else is by hand', function () {
    fixScreensGrant($this->screenIt, 'securityDevices.obli.fixes.manage');
    $session = fixScreensSession($this, ['state' => 'waiting_it', 'outcome' => null, 'ended_at' => null, 'waiting_since' => now()]);
    $commands = DB::table('obli_fix_requests')->insertGetId(['session_id' => $session->id, 'device_id' => $this->screenDevice->id, 'site_id' => $this->screenSite->id, 'user_id' => $this->screenStaff->id,
        'kind' => 'commands', 'what_encrypted' => Crypt::encryptString('Remove the old printer driver'), 'commands_encrypted' => Crypt::encryptString(json_encode(['pnputil /delete-driver oem42.inf /uninstall'])),
        'state' => 'requested', 'asked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->screenIt->fresh())->getJson('/security-devices/obli/requests/'.$commands)->assertOk()
        ->assertJsonPath('commands.0', 'PS> pnputil /delete-driver oem42.inf /uninstall')->assertJsonPath('can_decide', true);
    $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/obli/requests/'.$commands.'/decide', ['decision' => 'approve'])->assertOk()
        ->assertJsonPath('route', 'by_hand')->assertJsonPath('governed_href', null);
    expect(DeviceCommandRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'obli.fix.request_approved')->exists())->toBeTrue();
    $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/obli/requests/'.$commands.'/decide', ['decision' => 'decline'])->assertConflict();

    $fix = DB::table('obli_fix_requests')->insertGetId(['session_id' => $session->id, 'device_id' => $this->screenDevice->id, 'site_id' => $this->screenSite->id, 'user_id' => $this->screenStaff->id,
        'kind' => 'fix', 'fix_key' => 'service:Spooler:restart', 'what_encrypted' => Crypt::encryptString('Restart Print Spooler'), 'commands_encrypted' => Crypt::encryptString('[]'),
        'state' => 'requested', 'asked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print Spooler', 'operations' => ['restart']]]);
    $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/obli/requests/'.$fix.'/decide', ['decision' => 'approve'])->assertOk()
        ->assertJsonPath('route', 'governed')
        ->assertJsonPath('governed_href', '/security-devices/devices/'.$this->screenDevice->id.'?section=management&action=endpoint.service.manage&command_service_name=Spooler');
    expect(DeviceCommandRequest::query()->count())->toBe(0);
});

it('needs “manage Obli’s fixes” to decide, and expires a request whose session ended', function () {
    $session = fixScreensSession($this, ['state' => 'stopped', 'outcome' => 'stopped']);
    $request = DB::table('obli_fix_requests')->insertGetId(['session_id' => $session->id, 'device_id' => $this->screenDevice->id, 'site_id' => $this->screenSite->id, 'user_id' => $this->screenStaff->id,
        'kind' => 'commands', 'what_encrypted' => Crypt::encryptString('Something'), 'commands_encrypted' => Crypt::encryptString('[]'), 'state' => 'requested', 'asked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->screenIt)->postJson('/security-devices/obli/requests/'.$request.'/decide', ['decision' => 'approve'])->assertForbidden();
    fixScreensGrant($this->screenIt, 'securityDevices.obli.fixes.manage');
    $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/obli/requests/'.$request.'/decide', ['decision' => 'approve'])->assertConflict();
    expect(DB::table('obli_fix_requests')->where('id', $request)->value('state'))->toBe('expired');
});

it('turns a fix on for one computer through today’s policy update, with the person’s own permissions', function () {
    config()->set('endpoint_management.actions.release_enabled', true);
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print Spooler', 'operations' => ['restart']]]);
    fixScreensGrant($this->screenIt, 'securityDevices.obli.fixes.manage');
    $body = ['fix_key' => 'service:Spooler:restart', 'device_id' => $this->screenDevice->id, 'reason' => 'Printers keep getting stuck here', 'read' => true];

    // Without the computer's policy permission, today's policy update refuses and nothing is turned on.
    $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/setup/obli/fixes/on', $body)->assertForbidden();
    expect(DB::table('obli_fix_allowances')->count())->toBe(0)->and(DB::table('endpoint_action_policies')->count())->toBe(0);

    fixScreensGrant($this->screenIt, EndpointActionPolicy::MANAGE);
    $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/setup/obli/fixes/on', ['read' => false] + $body)->assertUnprocessable();
    $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/setup/obli/fixes/on', $body)->assertOk();
    expect(DB::table('obli_fix_allowances')->where('device_id', $this->screenDevice->id)->whereNull('turned_off_at')->count())->toBe(1)
        ->and((bool) DB::table('endpoint_action_policies')->where('device_id', $this->screenDevice->id)->value('enabled'))->toBeTrue()
        ->and(DB::table('endpoint_action_policies')->where('device_id', $this->screenDevice->id)->value('consent_mode'))->toBe('attended')
        ->and(AuditLog::query()->where('action', 'obli.fix.turned_on')->where('meta->policy_changed', true)->exists())->toBeTrue();

    $rows = collect(app(\App\Domain\Obli\Fixes\ObliFixList::class)->presentSetup($this->screenIt->fresh())['rows']);
    expect($rows->firstWhere('key', 'service:Spooler:restart')['state']['label'])->toBe('On');

    $this->actingAs($this->screenIt->fresh())->postJson('/security-devices/setup/obli/fixes/off', ['fix_key' => 'service:Spooler:restart', 'device_id' => $this->screenDevice->id])->assertOk();
    expect(DB::table('obli_fix_allowances')->whereNull('turned_off_at')->count())->toBe(0)
        // The computer's own policy stays as it was: other IT work may use it.
        ->and((bool) DB::table('endpoint_action_policies')->where('device_id', $this->screenDevice->id)->value('enabled'))->toBeTrue();
});

it('shows Computers & Obli › Obli and › Fixes only to the people who may see them', function () {
    expect(array_key_exists('computers', SetupPresenter::sectionsFor($this->screenIt)))->toBeFalse();
    fixScreensGrant($this->screenIt, 'securityDevices.obli.fixes.manage');
    $tabs = SetupPresenter::sectionsFor($this->screenIt->fresh())['computers']['tabs'];
    expect(array_keys($tabs))->toBe(['obli', 'fixes']);

    $viewer = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
    fixScreensGrant($viewer, ObliScreenPictures::PERMISSION);
    expect(array_keys(SetupPresenter::sectionsFor($viewer->fresh())['computers']['tabs']))->toBe(['obli']);
});

it('ends abandoned sessions and scrubs what was typed after the chat retention', function () {
    $open = fixScreensSession($this, ['state' => 'working', 'outcome' => null, 'ended_at' => null, 'expires_at' => now()->subMinutes(30)]);
    $old = fixScreensSession($this, ['created_at' => now()->subDays(8), 'ended_at' => now()->subDays(8)]);
    $this->artisan('obli:fixes-prune')->assertSuccessful();
    expect(DB::table('obli_fix_sessions')->where('id', $open->id)->value('state'))->toBe('expired')
        ->and(DB::table('obli_fix_sessions')->where('id', $old->id)->value('problem_encrypted'))->toBeNull()
        ->and(DB::table('obli_fix_sessions')->where('id', $old->id)->value('scrubbed_at'))->not->toBeNull();

    $this->travel(91)->days();
    $this->artisan('obli:fixes-prune')->assertSuccessful();
    expect(DB::table('obli_fix_sessions')->whereIn('id', [$open->id, $old->id])->count())->toBe(0);
});
