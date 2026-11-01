<?php

use App\Domain\Obli\Fixes\ObliFixPlan;
use App\Domain\Obli\Fixes\ObliFixProvider;
use App\Domain\Obli\Fixes\ObliFixSafety;
use App\Domain\Obli\Fixes\ObliFixSettings;
use App\Domain\Obli\ObliAiSettings;
use App\Domain\Obli\ObliSignIn;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AppSetting;
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
 * S&D Phase I: Obli fixes things, server side (sd-obli-fix-protocol.md). The AI is a fake: these
 * tests prove what the server allows, whatever the model says.
 */

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.obli.enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('f', 32)))));
    URL::forceScheme('https');
    $this->withServerVariables(['HTTPS' => 'on']);
    Notification::fake();
    Storage::fake('private');
    $this->fixUser = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
    $this->fixUser->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    fixesGrant($this->fixUser, 'it.request');
    $this->fixSite = ensureCanonicalHrStaffProfile($this->fixUser);
    AppSetting::query()->updateOrCreate(['key' => ObliAiSettings::KEY], ['value' => [
        'version' => 5, 'enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'max_output_tokens' => 2048,
        'daily_budget_usd' => '10.00', 'monthly_budget_usd' => '100.00', 'input_price_usd' => '4.00', 'output_price_usd' => '20.00',
        'chat_retention_days' => 7, 'diagnostic_categories' => ['system'], 'knowledge_enabled' => false, 'technician_enabled' => false,
    ]]);
    AppSetting::query()->updateOrCreate(['key' => 'obli.ai.provider_secret'], ['value' => Crypt::encryptString('fake-test-key-not-a-real-key')]);
    AppSetting::query()->updateOrCreate(['key' => ObliFixSettings::KEY], ['value' => ['version' => 1, 'enabled' => true]]);
    $this->provider = new class implements ObliFixProvider
    {
        public array $plans = [];

        public array $acts = [];

        public int $planCalls = 0;

        public int $actCalls = 0;

        public array $messages = [];

        public function plan(array $configuration, string $system, string $message): array
        {
            $this->planCalls++;
            $this->messages[] = $message;

            return ['json' => array_shift($this->plans) ?? [], 'input_tokens' => 120, 'output_tokens' => 60];
        }

        public function act(array $configuration, string $system, string $message, array $picture): array
        {
            $this->actCalls++;
            $this->messages[] = $message;
            $next = array_shift($this->acts) ?? fixesAct('wait', ['duration' => 1]);

            return $next + ['input_tokens' => 1500, 'output_tokens' => 80];
        }
    };
    app()->instance(ObliFixProvider::class, $this->provider);
});

function fixesGrant(User $user, string $key): void
{
    $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'securityDevices']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
}

function fixesToken($test, ?User $user = null): string
{
    $verifier = str_repeat('w', 64);
    $request = [
        'client_id' => 'obli-windows', 'response_type' => 'code', 'code_challenge_method' => 'S256',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'state' => str_repeat('t', 43), 'redirect_uri' => 'http://127.0.0.1:52102/obli/callback',
    ];
    $callback = app(ObliSignIn::class)->approve($user ?? $test->fixUser, $request);
    parse_str((string) parse_url($callback, PHP_URL_QUERY), $query);

    return $test->postJson('/api/obli/token', ['client_id' => 'obli-windows', 'grant_type' => 'authorization_code', 'code' => $query['code'], 'code_verifier' => $verifier, 'redirect_uri' => $request['redirect_uri']])
        ->assertOk()->json('access_token');
}

function fixesComputer($test, ?Site $site = null): array
{
    $site ??= $test->fixSite;
    $device = Device::factory()->create(['name' => 'Synthetic fix PC', 'domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active']);
    DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    $credential = bin2hex(random_bytes(32));
    $agent = EndpointAgent::query()->create([
        'uuid' => (string) Str::uuid(), 'device_id' => $device->id, 'site_id' => $site->id, 'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($device),
        'platform' => 'windows', 'credential_hash' => hash('sha256', $credential), 'enrolled_at' => now(), 'last_seen_at' => now(), 'observed_at' => now(), 'created_by_user_id' => $test->fixUser->id,
    ]);

    return [$device, $agent, $credential];
}

function fixesPlanJson(array $overrides = []): array
{
    return [
        'title' => 'Printer won’t print',
        'found' => [['tone' => 'warning', 'text' => '3 jobs stuck in the queue']],
        'plan' => [['text' => 'Cancel your 3 stuck jobs', 'kind' => 'screen', 'fix_key' => null], ['text' => 'Print your letter once and check it comes out', 'kind' => 'screen', 'fix_key' => null]],
        'minutes' => 2, 'refuse' => null, 'needs_it' => null,
        ...$overrides,
    ];
}

function fixesAct(string $name, array $input, string $text = 'Step 1: cancelling stuck jobs', ?string $toolset = 'computer'): array
{
    return ['text' => $text, 'tool' => ['name' => $name, 'toolset' => $toolset, 'input' => $input], 'stop_reason' => 'tool_use'];
}

function fixesJpeg(int $width = 320, int $height = 200): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image, null, 60);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return base64_encode($bytes);
}

function fixesTurn(int $turn, array $focus = [], ?array $last = null): array
{
    return [
        'turn' => $turn,
        'picture' => ['jpeg_base64' => fixesJpeg(), 'width' => 320, 'height' => 200, 'taken_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
        'last' => $last,
        'focus' => [...['is_password' => false, 'window_title' => 'Office printer · queue', 'process' => 'explorer.exe'], ...$focus],
    ];
}

/** A session confirmed by the local service and planned (not yet approved). */
function fixesPlanned($test, ?array $plan = null): array
{
    [$device, $agent, $credential] = fixesComputer($test);
    $token = fixesToken($test);
    $start = $test->withToken($token)->postJson('/api/obli/fixes', [
        'request_uuid' => (string) Str::uuid(), 'problem' => 'The printer won’t print. I’ve sent my letter three times.',
        'site_id' => $test->fixSite->id, 'screen' => ['width' => 320, 'height' => 200],
    ])->assertCreated()->assertJsonPath('state', 'confirming')->assertHeader('Cache-Control')->json();
    $test->withToken($credential)->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $start['challenge']])->assertOk();
    $test->provider->plans[] = $plan ?? fixesPlanJson();
    $session = $test->withToken($token)->getJson('/api/obli/fixes/'.$start['uuid'])->assertOk()->json();

    return ['device' => $device, 'agent' => $agent, 'credential' => $credential, 'token' => $token, 'uuid' => $start['uuid'], 'request_uuid' => DB::table('obli_fix_sessions')->where('uuid', $start['uuid'])->value('request_uuid'), 'session' => $session];
}

function fixesWorking($test): array
{
    $planned = fixesPlanned($test);
    $test->withToken($planned['token'])->postJson('/api/obli/fixes/'.$planned['uuid'].'/approve', ['request_uuid' => $planned['request_uuid']])->assertOk()->assertJsonPath('state', 'working');

    return $planned;
}

it('plans only after the local service confirms the computer, and shows the checked plan', function () {
    [$device, $agent, $credential] = fixesComputer($this);
    $token = fixesToken($this);
    $start = $this->withToken($token)->postJson('/api/obli/fixes', [
        'request_uuid' => (string) Str::uuid(), 'problem' => 'The printer won’t print. I’ve sent my letter three times.',
        'site_id' => $this->fixSite->id, 'screen' => ['width' => 320, 'height' => 200],
    ])->assertCreated()->json();
    expect($start['challenge'])->toMatch('/\A[a-f0-9]{64}\z/');

    $this->withToken($token)->getJson('/api/obli/fixes/'.$start['uuid'])->assertOk()->assertJsonPath('state', 'confirming')->assertJsonPath('challenge', $start['challenge']);
    expect($this->provider->planCalls)->toBe(0);

    $this->withToken($credential)->postJson('/api/endpoints/agent/obli/ticket-computer-context', ['challenge' => $start['challenge']])->assertOk();
    $this->provider->plans[] = fixesPlanJson();
    $this->withToken($token)->getJson('/api/obli/fixes/'.$start['uuid'])->assertOk()
        ->assertJsonPath('state', 'planned')->assertJsonPath('title', 'Printer won’t print')
        ->assertJsonPath('plan.0.kind', 'screen')->assertJsonPath('plan.0.it_allows', false)
        ->assertJsonPath('note', 'I keep pictures of your screen for 7 days so IT can check my work.');
    expect($this->provider->planCalls)->toBe(1)
        ->and($this->provider->messages[0])->toContain('The printer won’t print')
        ->and((int) DB::table('obli_fix_sessions')->where('uuid', $start['uuid'])->value('device_id'))->toBe((int) $device->id)
        ->and(AuditLog::query()->where('action', 'obli.fix.planned')->exists())->toBeTrue();
});

it('never shows an invalid plan, and refuses unsafe steps in its own words', function () {
    $planned = fixesPlanned($this, fixesPlanJson(['plan' => [['text' => 'Restart printing', 'kind' => 'fix', 'fix_key' => 'service:Spooler:restart']]]));
    expect($planned['session']['state'])->toBe('needs_it')->and($planned['session']['refusal']['offers'][0]['key'])->toBe('ask_it');

    expect(ObliFixPlan::validate(fixesPlanJson(['plan' => [['text' => 'Turn off the antivirus so it runs faster', 'kind' => 'screen', 'fix_key' => null]]]), []))
        ->toMatchArray(['state' => 'refused', 'refusal' => ['text' => ObliFixPlan::REFUSAL, 'offers' => [['key' => 'ask_it', 'label' => 'Ask IT']]]])
        ->and(ObliFixPlan::validate(fixesPlanJson(['title' => str_repeat('x', 81)]), []))->toBeNull()
        ->and(ObliFixPlan::validate(fixesPlanJson(['extra' => true]), []))->toBeNull()
        ->and(ObliFixPlan::validate(fixesPlanJson(['plan' => []]), []))->toBeNull()
        ->and(ObliFixPlan::validate(fixesPlanJson(['plan' => [['text' => 'Restart printing', 'kind' => 'fix', 'fix_key' => 'service:Spooler:restart']]]), ['service:Spooler:restart'])['plan'][0]['fix_key'])->toBe('service:Spooler:restart')
        ->and(ObliFixPlan::validate(fixesPlanJson(['refuse' => ['text' => 'I can’t turn off antivirus; it protects client records.', 'alternative' => 'Find out why it’s slow']]), [])['refusal']['offers'][1])
        ->toBe(['key' => 'safe_alternative', 'label' => 'Find out why it’s slow']);
});

it('sends nothing to the screen before the one OK', function () {
    $planned = fixesPlanned($this);
    $this->withToken($planned['token'])->postJson('/api/obli/fixes/'.$planned['uuid'].'/turn', fixesTurn(1))->assertConflict();
    $this->withToken($planned['token'])->postJson('/api/obli/fixes/'.$planned['uuid'].'/approve', ['request_uuid' => (string) Str::uuid()])->assertConflict();
    expect($this->provider->actCalls)->toBe(0)->and(DB::table('obli_screen_pictures')->count())->toBe(0);

    $this->withToken($planned['token'])->postJson('/api/obli/fixes/'.$planned['uuid'].'/approve', ['request_uuid' => $planned['request_uuid']])
        ->assertOk()->assertJsonPath('state', 'working')->assertJsonPath('uuid', $planned['uuid']);
    $this->provider->acts[] = fixesAct('left_click', ['coordinate' => [40, 60]]);
    $this->withToken($planned['token'])->postJson('/api/obli/fixes/'.$planned['uuid'].'/turn', fixesTurn(1))->assertOk()
        ->assertJsonPath('turn', 1)->assertJsonPath('action', ['type' => 'click', 'x' => 40, 'y' => 60])
        ->assertJsonPath('narration', 'cancelling stuck jobs')->assertJsonPath('step', 1)->assertJsonPath('of', 2);
});

it('checks every action and never sends one it refuses', function (array $act, array $focus, string $expect, ?string $refusal) {
    $working = fixesWorking($this);
    if ($act !== []) {
        $this->provider->acts[] = $act;
    }
    $before = $this->provider->actCalls;
    $response = $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn(1, $focus))->assertOk();

    expect($response->json('action.type'))->toBe($expect);
    if ($refusal !== null) {
        expect(DB::table('obli_fix_steps')->where('refusal', $refusal)->exists())->toBeTrue()
            ->and(DB::table('obli_fix_steps')->where('kind', 'action')->exists())->toBeFalse();
    }
    if (in_array($refusal, ['password_focus', 'blocked_window'], true)) {
        // Decided before the AI is asked: no picture is kept and nothing is typed.
        expect($this->provider->actCalls)->toBe($before)->and(DB::table('obli_screen_pictures')->count())->toBe(0);
    }
})->with([
    'a password field has focus' => [fixesAct('type', ['text' => 'hunter2']), ['is_password' => true], 'pause_password', 'password_focus'],
    'a sign-in window is in front' => [fixesAct('type', ['text' => 'hello']), ['window_title' => 'Windows Security', 'process' => 'CredentialUIBroker.exe'], 'pause_password', 'password_focus'],
    'a security window is in front' => [fixesAct('left_click', ['coordinate' => [10, 10]]), ['window_title' => 'Windows Security', 'process' => 'SecHealthUI.exe'], 'stopped', 'blocked_window'],
    'the Windows key' => [fixesAct('key', ['text' => 'super+r']), [], 'wait', 'key_not_allowed'],
    'Ctrl+Alt+Del' => [fixesAct('key', ['text' => 'ctrl+alt+Delete']), [], 'wait', 'key_not_allowed'],
    'Alt+F4' => [fixesAct('key', ['text' => 'alt+F4']), [], 'wait', 'key_not_allowed'],
    'a point outside the picture' => [fixesAct('left_click', ['coordinate' => [5000, 10]]), [], 'wait', 'outside_picture'],
    'a click with keys held' => [fixesAct('left_click', ['coordinate' => [10, 10], 'text' => 'ctrl']), [], 'wait', 'not_supported'],
    'typing into a command window' => [fixesAct('type', ['text' => 'ipconfig']), ['window_title' => 'Terminal', 'process' => 'WindowsTerminal.exe'], 'wait', 'terminal'],
    'typing that turns off protection' => [fixesAct('type', ['text' => 'Set-MpPreference -DisableRealtimeMonitoring $true']), ['window_title' => 'Notes', 'process' => 'notepad.exe'], 'wait', 'unsafe'],
    'a fix that isn’t on for this computer' => [fixesAct('run_fix', ['fix_key' => 'service:Spooler:restart'], 'Step 1: restarting printing', null), [], 'wait', 'fix_not_on'],
    'a one-off request to turn off the firewall' => [fixesAct('ask_it', ['what' => 'Turn off the firewall for Teams', 'commands' => ['netsh advfirewall set allprofiles state off']], 'Step 1: asking IT', null), [], 'stopped', 'unsafe'],
    'an allowed shortcut' => [fixesAct('key', ['text' => 'ctrl+p']), [], 'key', null],
]);

it('stops after three refused actions in a row', function () {
    $working = fixesWorking($this);
    $this->provider->acts = [fixesAct('key', ['text' => 'super+r']), fixesAct('key', ['text' => 'super+x']), fixesAct('key', ['text' => 'super+i'])];
    foreach ([1, 2] as $turn) {
        $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn($turn))->assertOk()->assertJsonPath('action.type', 'wait');
    }
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn(3))->assertOk()->assertJsonPath('action.type', 'stopped');
    expect(DB::table('obli_fix_sessions')->where('uuid', $working['uuid'])->value('state'))->toBe('stopped');
});

it('ends after 15 minutes and after 60 actions', function () {
    $working = fixesWorking($this);
    $this->travel(16)->minutes();
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn(1))->assertOk()
        ->assertJsonPath('action.type', 'stopped')->assertJsonPath('action.summary', 'I stopped: I work for 15 minutes at most.');
    expect($this->provider->actCalls)->toBe(0);
    $this->travelBack();

    $second = fixesWorking($this);
    DB::table('obli_fix_sessions')->where('uuid', $second['uuid'])->update(['turns' => ObliFixSettings::MAX_ACTIONS]);
    $this->withToken($second['token'])->postJson('/api/obli/fixes/'.$second['uuid'].'/turn', fixesTurn(1))->assertOk()
        ->assertJsonPath('action.type', 'stopped')->assertJsonPath('action.summary', 'I stopped: I take 60 actions at most.');
    expect($this->provider->actCalls)->toBe(0);
});

it('stop ends everything: no AI call and no action afterwards', function () {
    $working = fixesWorking($this);
    $this->provider->acts[] = fixesAct('left_click', ['coordinate' => [20, 20]]);
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn(1))->assertOk()->assertJsonPath('action.type', 'click');

    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/stop', ['reason' => 'esc'])->assertOk()
        ->assertJsonPath('state', 'stopped')->assertJsonPath('uuid', $working['uuid']);
    $calls = $this->provider->actCalls;
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn(2, [], ['turn' => 1, 'result' => 'done', 'detail' => '']))->assertOk()
        ->assertJsonPath('action.type', 'stopped');
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/resume')->assertConflict();
    expect($this->provider->actCalls)->toBe($calls)
        ->and(AuditLog::query()->where('action', 'obli.fix.stopped')->where('meta->reason', 'esc')->exists())->toBeTrue();
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/stop', ['reason' => 'teleport'])->assertUnprocessable();
});

it('keeps the picture the tray sent, encrypted, and prunes it after 7 days', function () {
    $working = fixesWorking($this);
    $this->provider->acts[] = fixesAct('left_click', ['coordinate' => [20, 20]]);
    $turn = fixesTurn(1);
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', $turn)->assertOk();

    $picture = DB::table('obli_screen_pictures')->first();
    $stored = Storage::disk('private')->get($picture->path);
    expect($stored)->not->toBe(base64_decode($turn['picture']['jpeg_base64']))
        ->and(Crypt::decryptString($stored))->toBe(base64_decode($turn['picture']['jpeg_base64']))
        ->and($picture->sha256)->toBe(hash('sha256', base64_decode($turn['picture']['jpeg_base64'])));
    $this->withToken($working['token'])->getJson('/api/obli/fixes/'.$working['uuid'])->assertOk()->assertJsonPath('pictures', 1)->assertJsonMissingPath('picture');

    $this->travel(6)->days();
    $this->artisan('obli:fixes-prune')->assertSuccessful();
    expect(DB::table('obli_screen_pictures')->count())->toBe(1);
    $this->travel(2)->days();
    $this->artisan('obli:fixes-prune')->assertSuccessful();
    expect(DB::table('obli_screen_pictures')->count())->toBe(0)->and(Storage::disk('private')->exists($picture->path))->toBeFalse();
});

it('asks IT for a fix only when it is on for that computer, and waits without pictures', function () {
    config()->set('endpoint_management.actions.release_enabled', true);
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print Spooler', 'operations' => ['restart']]]);
    $working = fixesWorking($this);
    $this->provider->acts[] = fixesAct('run_fix', ['fix_key' => 'service:Spooler:restart'], 'Step 2: restarting printing', null);
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn(1))->assertOk()->assertJsonPath('action.type', 'wait');
    expect(DB::table('obli_fix_requests')->count())->toBe(0);

    EndpointAgent::query()->whereKey($working['agent']->id)->update(['service_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_scm', 'operations' => ['restart']]]);
    DB::table('endpoint_action_policies')->insert(['device_id' => $working['device']->id, 'version' => 1, 'enabled' => true, 'consent_mode' => 'attended', 'updated_by_user_id' => $this->fixUser->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('obli_fix_allowances')->insert(['fix_key' => 'service:Spooler:restart', 'device_id' => $working['device']->id, 'turned_on_by_user_id' => $this->fixUser->id, 'turned_on_at' => now(), 'reason' => 'Synthetic test allowance', 'created_at' => now(), 'updated_at' => now()]);
    $this->provider->acts[] = fixesAct('run_fix', ['fix_key' => 'service:Spooler:restart'], 'Step 2: restarting printing', null);
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn(2))->assertOk()->assertJsonPath('action.type', 'waiting_it');
    $request = DB::table('obli_fix_requests')->first();
    expect($request->kind)->toBe('fix')->and($request->state)->toBe('requested');

    // While IT decides the tray polls; the session stays waiting and nothing more is sent.
    $calls = $this->provider->actCalls;
    $this->withToken($working['token'])->getJson('/api/obli/fixes/'.$working['uuid'])->assertOk()->assertJsonPath('state', 'waiting_it');
    expect($this->provider->actCalls)->toBe($calls)
        ->and((int) DB::table('obli_fix_sessions')->where('uuid', $working['uuid'])->value('turns'))->toBe(2);
});

it('carries on when IT approves by hand, giving back the time spent waiting', function () {
    $working = fixesWorking($this);
    $this->provider->acts[] = fixesAct('ask_it', ['what' => 'Remove the old printer driver', 'commands' => ['pnputil /delete-driver oem42.inf /uninstall']], 'Step 1: asking IT', null);
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn(1))->assertOk()->assertJsonPath('action.type', 'waiting_it');
    $before = DB::table('obli_fix_sessions')->where('uuid', $working['uuid'])->value('expires_at');
    $this->travel(20)->minutes();
    DB::table('obli_fix_requests')->update(['state' => 'approved', 'route' => 'by_hand', 'decided_by_user_id' => $this->fixUser->id, 'decided_at' => now()]);

    $this->withToken($working['token'])->getJson('/api/obli/fixes/'.$working['uuid'])->assertOk()->assertJsonPath('state', 'working');
    expect(strtotime((string) DB::table('obli_fix_sessions')->where('uuid', $working['uuid'])->value('expires_at')))->toBeGreaterThan(strtotime((string) $before) + 19 * 60);
    $this->provider->acts[] = fixesAct('finish', ['fixed' => false, 'summary' => 'IT will remove the old driver by hand.', 'steps' => ['Asked IT to remove the old driver']], 'Step 1: finishing', null);
    $this->withToken($working['token'])->postJson('/api/obli/fixes/'.$working['uuid'].'/turn', fixesTurn(2))->assertOk()
        ->assertJsonPath('action.type', 'done')->assertJsonPath('action.fixed', false)->assertJsonPath('action.pictures', 2);
    expect(collect($this->provider->messages)->last())->toContain('IT approved it and will do it by hand');
});

it('keeps another person’s or another computer’s session private (404)', function () {
    $planned = fixesPlanned($this);
    $other = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
    $other->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    fixesGrant($other, 'it.request');
    ensureCanonicalHrStaffProfile($other, $this->fixSite);
    $otherToken = fixesToken($this, $other);
    $this->withToken($otherToken)->getJson('/api/obli/fixes/'.$planned['uuid'])->assertNotFound();
    $this->withToken($otherToken)->postJson('/api/obli/fixes/'.$planned['uuid'].'/approve', ['request_uuid' => $planned['request_uuid']])->assertNotFound();

    // The same person signed in to Obli on another computer has another session: still 404.
    $secondComputer = fixesToken($this);
    $this->withToken($secondComputer)->getJson('/api/obli/fixes/'.$planned['uuid'])->assertNotFound();
    $this->withToken($secondComputer)->postJson('/api/obli/fixes/'.$planned['uuid'].'/turn', fixesTurn(1))->assertNotFound();
});

it('refuses to start when fixes are off or the AI isn’t Anthropic', function () {
    $token = fixesToken($this);
    $body = ['request_uuid' => (string) Str::uuid(), 'problem' => 'The printer won’t print at all today.', 'site_id' => $this->fixSite->id, 'screen' => ['width' => 320, 'height' => 200]];
    AppSetting::query()->where('key', ObliFixSettings::KEY)->update(['value' => json_encode(['version' => 2, 'enabled' => false])]);
    $this->withToken($token)->postJson('/api/obli/fixes', $body)->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');

    AppSetting::query()->where('key', ObliFixSettings::KEY)->update(['value' => json_encode(['version' => 3, 'enabled' => true])]);
    $ai = AppSetting::query()->where('key', ObliAiSettings::KEY)->first();
    $ai->update(['value' => [...$ai->value, 'provider' => 'openai']]);
    $this->withToken($token)->postJson('/api/obli/fixes', $body)->assertStatus(503);
    expect(DB::table('obli_fix_sessions')->count())->toBe(0);
});

it('normalises keys to the tray’s names and refuses everything else', function () {
    expect(ObliFixSafety::keys('Return'))->toBe(['enter'])
        ->and(ObliFixSafety::keys('ctrl+p'))->toBe(['ctrl', 'p'])
        ->and(ObliFixSafety::keys('ctrl+shift+Tab'))->toBe(['ctrl', 'shift', 'tab'])
        ->and(ObliFixSafety::keys('alt+Tab'))->toBeNull()
        ->and(ObliFixSafety::keys('super'))->toBeNull()
        ->and(ObliFixSafety::keys('ctrl+shift+Escape'))->toBeNull()
        ->and(ObliFixSafety::keys('F3'))->toBeNull()
        ->and(ObliFixSafety::window(['is_password' => false, 'window_title' => 'Registry Editor', 'process' => 'regedit.exe']))->toBe('blocked')
        ->and(ObliFixSafety::window(['is_password' => false, 'window_title' => 'Sign in to your account', 'process' => 'msedge.exe']))->toBe('sign_in')
        ->and(ObliFixSafety::window(['is_password' => false, 'window_title' => 'Office printer', 'process' => 'explorer.exe']))->toBe('ok');
});
