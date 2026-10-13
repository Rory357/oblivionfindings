<?php

use App\Domain\It\Services\ItObliDiagnosticSource;
use App\Domain\Obli\ObliAiSettings;
use App\Domain\Obli\ObliProviderHttp;
use App\Domain\Obli\ObliSignIn;
use App\Domain\Obli\ObliTextProvider;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class ObliInvestigationFakeProvider implements ObliTextProvider
{
    public int $calls = 0;

    public array $messages = [];

    public array $profiles = [];

    public ?Closure $during = null;

    public bool $fail = false;

    public function respond(array $configuration, array $messages): array
    {
        $this->calls++;
        $this->messages = $messages;
        $this->profiles[] = $configuration['instruction_profile'] ?? 'personal';
        expect($configuration['api_key'])->toBe('isolated-fake-provider-key');
        foreach ($messages as $message) {
            expect(array_keys($message))->toBe(['role', 'content']);
        }
        if ($this->during) {
            ($this->during)();
        }
        if ($this->fail) {
            throw new RuntimeException('private-upstream-body');
        }

        return ['text' => 'Read-only simulated suggestion.', 'input_tokens' => 50, 'output_tokens' => 20];
    }
}

function investigationStaffToken(User $actor): string
{
    $verifier = str_repeat('v', 64);
    $input = ['client_id' => 'obli-windows', 'response_type' => 'code', 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'state' => str_repeat('s', 43), 'redirect_uri' => 'http://127.0.0.1:52101/obli/callback'];
    $url = app(ObliSignIn::class)->approve($actor, $input);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    return app(ObliSignIn::class)->exchange(['client_id' => 'obli-windows', 'grant_type' => 'authorization_code', 'code' => $query['code'], 'code_verifier' => $verifier, 'redirect_uri' => $input['redirect_uri']])['access_token'];
}

beforeEach(function () {
    $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.obli.enabled', true);
    config()->set('endpoint_management.obli.technician_enabled', true);
    $this->sourceActor = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
    $this->sourceActor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    foreach (['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.endpoints.diagnostics'] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'securityDevices']);
        $this->sourceActor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $this->sourceSite = ensureCanonicalHrStaffProfile($this->sourceActor);
    $this->sourceDevice = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active']);
    DeviceAssignment::query()->create(['device_id' => $this->sourceDevice->id, 'assignable_type' => 'site', 'assignable_id' => $this->sourceSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    $this->sourceAgent = EndpointAgent::query()->create(['uuid' => '11111111-1111-4111-8111-111111111111', 'created_by_user_id' => $this->sourceActor->id, 'device_id' => $this->sourceDevice->id, 'site_id' => $this->sourceSite->id, 'platform' => 'windows', 'enrolled_at' => now(), 'credential_hash' => str_repeat('a', 64), 'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($this->sourceDevice), 'sequence' => 1, 'heartbeat_hash' => str_repeat('b', 64), 'observed_at' => now()->subSeconds(20), 'last_seen_at' => now()->subSeconds(10), 'inventory' => ['hostname' => 'private-hostname', 'os_version' => 'Windows 11 10.0.26100.1', 'architecture' => 'amd64', 'memory_total_bytes' => 8192, 'memory_available_bytes' => 4096, 'uptime_seconds' => 120, 'cpu_percent' => null, 'disks' => [], 'software' => [['name' => 'Microsoft Edge', 'version' => '130.0.1', 'publisher' => 'private-publisher']], 'software_complete' => true, 'services' => [['name' => 'Spooler', 'state' => 'running']], 'services_complete' => false, 'probe_errors' => ['cpu']]]);
    $this->source = app(ItObliDiagnosticSource::class);
    config()->set('endpoint_management.server_url', 'https://localhost');
    URL::forceScheme('https');
    $this->withServerVariables(['HTTPS' => 'on']);
    $permission = Permission::query()->where('key', 'securityDevices.obli.assist')->firstOrFail();
    $this->sourceActor->permissionOverrides()->attach($permission, ['allowed' => true]);
    $this->editor = User::factory()->create(['approved_at' => now()]);
    foreach (['settings.access.manage', 'integrations.manage_secrets'] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'settings']);
        $this->editor->permissionOverrides()->attach($permission, ['allowed' => true]);
    }
    $this->configuration = ['expected_actor_id' => $this->editor->id, 'expected_version' => 0, 'enabled' => true, 'technician_enabled' => true, 'diagnostic_categories' => ['system', 'software', 'services'], 'provider' => 'openai', 'model' => 'test-model', 'api_key' => 'isolated-fake-provider-key', 'clear_key' => false, 'max_output_tokens' => 2048, 'daily_budget_usd' => '10.00', 'monthly_budget_usd' => '100.00', 'input_price_usd' => '1', 'output_price_usd' => '2', 'chat_retention_days' => 7];
    app(ObliAiSettings::class)->save($this->editor, $this->configuration);
    $this->provider = new ObliInvestigationFakeProvider;
    app()->instance(ObliTextProvider::class, $this->provider);
    $this->base = '/it/remote-support/devices/'.$this->sourceDevice->id.'/obli';
    $this->query = '?expected_actor_id='.$this->sourceActor->id;
    $this->question = ['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $this->sourceActor->id, 'configuration_version' => 1, 'messages' => [['role' => 'user', 'content' => 'Why might printing fail?']]];
    $this->actingAs($this->sourceActor);
});

it('separates preview permission from the central assistant switches without promising Connect', function () {
    expect(ObliAiSettings::defaults()['technician_enabled'])->toBeFalse();
    $this->getJson($this->base.'/options'.$this->query)->assertOk()->assertJsonPath('can_assist', true)->assertJsonPath('mode', 'read_suggest');
    app(ObliAiSettings::class)->save($this->editor, [...$this->configuration, 'expected_version' => 1, 'enabled' => false, 'technician_enabled' => false]);
    $this->getJson($this->base.'/options'.$this->query)->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('can_preview', true)->assertJsonPath('can_assist', false);
    $this->getJson($this->base.'/diagnostics'.$this->query)->assertOk();
    $this->postJson($this->base.'/chat', [...$this->question, 'configuration_version' => 2])->assertStatus(503)->assertJsonPath('code', 'chat_unavailable');
    expect($this->provider->calls)->toBe(0)->and(DB::table('obli_ai_requests')->count())->toBe(0);
    $this->get('/it/remote-support?device='.$this->sourceDevice->id)->assertInertia(fn ($page) => $page->where('availability.obli', true)->where('availability.remote_support', false));
    config()->set('endpoint_management.obli.technician_enabled', false);
    $this->getJson($this->base.'/options'.$this->query)->assertNotFound()->assertJsonPath('code', 'source_unavailable');
});

it('requires expected actor and rejects raw or empty diagnostic attachments without ledger mutation', function () {
    $this->getJson($this->base.'/options')->assertUnprocessable()->assertJsonPath('code', 'invalid_request');
    $this->getJson($this->base.'/options?expected_actor_id='.$this->editor->id)->assertNotFound();
    foreach ([[], null, ['snapshot_version' => str_repeat('a', 64), 'groups' => []], ['snapshot_version' => str_repeat('a', 64), 'groups' => ['system'], 'snapshot' => ['password' => 'forged']], ['snapshot_version' => str_repeat('a', 64), 'groups' => ['system', 'system']]] as $binding) {
        $this->postJson($this->base.'/chat', [...$this->question, 'messages' => [['role' => 'user', 'content' => 'Question', 'computer_diagnostics' => $binding]]])->assertUnprocessable()->assertJsonPath('code', 'invalid_request');
    }
    $this->postJson($this->base.'/chat', [...$this->question, 'messages' => [['role' => 'user', 'content' => "Question\u{0085}inside"]]])->assertUnprocessable();
    expect($this->provider->calls)->toBe(0)->and(DB::table('obli_ai_requests')->count())->toBe(0);
});

it('allows plain assist-only questions and stores encrypted scoped authority without private prompt text', function () {
    $permission = Permission::query()->where('key', 'securityDevices.endpoints.diagnostics')->firstOrFail();
    $this->sourceActor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $this->getJson($this->base.'/options'.$this->query)->assertOk()->assertJsonPath('can_preview', false)->assertJsonPath('can_assist', true);
    $this->getJson($this->base.'/diagnostics'.$this->query)->assertForbidden()->assertJsonPath('code', 'source_unavailable');
    $this->postJson($this->base.'/chat', $this->question)->assertOk()->assertJsonPath('status', 'completed')->assertHeader('Pragma', 'no-cache');
    expect($this->provider->messages)->toBe($this->question['messages'])->and($this->provider->profiles)->toBe(['technician']);
    $row = DB::table('obli_ai_requests')->sole();
    expect($row->context_kind)->toBe('technician')->and(json_encode($row))->not->toContain('Why might printing fail?')->not->toContain('Read-only simulated')->not->toContain('isolated-fake-provider-key');
    $context = json_decode(Crypt::decryptString($row->investigation_context_encrypted), true);
    expect($context['device_id'])->toBe($this->sourceDevice->id)->and($context['sources'])->toBe([]);
    expect((int) $row->reserved_micros)->toBe(ObliAiSettings::cost(strlen($this->question['messages'][0]['content']) + strlen(ObliProviderHttp::instructions('technician')) + 4096, 2048, 1000000, 2000000));
    $this->getJson($this->base.'/chat/'.$this->question['request_uuid'].$this->query)->assertOk();
    $this->sourceAgent->forceFill(['uuid' => (string) Str::uuid()])->save();
    $this->getJson($this->base.'/chat/'.$this->question['request_uuid'].$this->query)->assertNotFound()->assertJsonPath('code', 'source_unavailable')->assertDontSee('Read-only simulated');
});

it('sends only reviewed selected server fields and exports the actual HTTP contract when requested', function () {
    $options = $this->getJson($this->base.'/options'.$this->query)->assertOk()->json();
    $preview = $this->getJson($this->base.'/diagnostics'.$this->query.'&groups[]=software')->assertOk()->json();
    $this->question['messages'][0]['computer_diagnostics'] = ['snapshot_version' => $preview['snapshot_version'], 'groups' => ['software']];
    $receipt = $this->postJson($this->base.'/chat', $this->question)->assertOk()->assertJsonPath('status', 'completed')->json();
    $sent = $this->provider->messages[0]['content'];
    expect($sent)->toContain('microsoft_edge')->toContain('untrusted observations')->not->toContain('private-hostname')->not->toContain('private-publisher')->not->toContain('memory_total_bytes')->not->toContain('print_spooler');
    $row = DB::table('obli_ai_requests')->sole();
    expect(json_encode($row))->not->toContain('microsoft_edge');
    $context = json_decode(Crypt::decryptString($row->investigation_context_encrypted), true);
    expect(count($context['sources']))->toBe(1)->and(array_keys($context['sources'][0]['snapshot']))->toBe(['schema', 'observed_at', 'software']);
    $export = getenv('OBLI_TECHNICIAN_EXPORT');
    if (is_string($export) && $export !== '') {
        file_put_contents($export, json_encode(['options' => $options, 'preview' => $preview, 'result' => $receipt, 'rendered_messages' => $this->provider->messages], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
});

it('rejects changed reviewed snapshots before provider calls and rendered diagnostic overflow', function () {
    $preview = $this->getJson($this->base.'/diagnostics'.$this->query)->assertOk()->json();
    $this->question['messages'][0]['computer_diagnostics'] = ['snapshot_version' => $preview['snapshot_version'], 'groups' => ['system']];
    $this->sourceAgent->forceFill(['sequence' => 2, 'heartbeat_hash' => str_repeat('c', 64)])->save();
    $this->postJson($this->base.'/chat', $this->question)->assertConflict()->assertJsonPath('code', 'source_changed');
    $preview = $this->getJson($this->base.'/diagnostics'.$this->query)->assertOk()->json();
    $this->question['messages'][0] = ['role' => 'user', 'content' => str_repeat('x', 11999), 'computer_diagnostics' => ['snapshot_version' => $preview['snapshot_version'], 'groups' => ['system']]];
    $this->postJson($this->base.'/chat', $this->question)->assertUnprocessable()->assertJsonPath('code', 'invalid_request');
    expect($this->provider->calls)->toBe(0)->and(DB::table('obli_ai_requests')->count())->toBe(0)->and(DB::table('obli_ai_budget_periods')->count())->toBe(0);
});

it('replays before fresh capture and reuses encrypted historical snapshots after heartbeat progression', function () {
    $preview = $this->getJson($this->base.'/diagnostics'.$this->query)->assertOk()->json();
    $binding = ['snapshot_version' => $preview['snapshot_version'], 'groups' => ['system']];
    $this->question['messages'][0]['computer_diagnostics'] = $binding;
    $this->postJson($this->base.'/chat', $this->question)->assertOk();
    $original = $this->provider->messages[0]['content'];
    $this->sourceAgent->forceFill(['sequence' => 2, 'heartbeat_hash' => str_repeat('c', 64), 'observed_at' => now()->subDay(), 'last_seen_at' => now()->subDay()])->save();
    $this->postJson($this->base.'/chat', $this->question)->assertOk();
    $this->getJson($this->base.'/chat/'.$this->question['request_uuid'].$this->query)->assertOk();
    expect($this->provider->calls)->toBe(1);
    $followup = [...$this->question, 'request_uuid' => (string) Str::uuid(), 'messages' => [['role' => 'user', 'content' => $this->question['messages'][0]['content'], 'computer_diagnostics' => [...$binding, 'source_request_uuid' => $this->question['request_uuid']]], ['role' => 'assistant', 'content' => 'Read-only simulated suggestion.'], ['role' => 'user', 'content' => 'What should I check next?']]];
    $this->postJson($this->base.'/chat', $followup)->assertOk();
    expect($this->provider->calls)->toBe(2)->and($this->provider->messages[0]['content'])->toBe($original);
    $permission = Permission::query()->where('key', 'securityDevices.endpoints.diagnostics')->firstOrFail();
    $this->sourceActor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $this->getJson($this->base.'/chat/'.$followup['request_uuid'].$this->query)->assertForbidden()->assertJsonPath('code', 'source_unavailable')->assertDontSee('Read-only simulated');
    $this->postJson($this->base.'/chat', $followup)->assertForbidden();
    expect($this->provider->calls)->toBe(2);
});

it('conceals generated output when current authority is withdrawn during a provider call', function () {
    $preview = $this->getJson($this->base.'/diagnostics'.$this->query)->assertOk()->json();
    $this->question['messages'][0]['computer_diagnostics'] = ['snapshot_version' => $preview['snapshot_version'], 'groups' => ['software']];
    $this->provider->during = function () {
        $permission = Permission::query()->where('key', 'securityDevices.obli.assist')->firstOrFail();
        $this->sourceActor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    };
    $this->postJson($this->base.'/chat', $this->question)->assertNotFound()->assertJsonPath('code', 'source_unavailable')->assertDontSee('Read-only simulated');
    expect($this->provider->calls)->toBe(1)->and(DB::table('obli_ai_requests')->sole()->status)->toBe('completed')->and((int) DB::table('obli_ai_requests')->sole()->charged_micros)->toBe(90);
    $this->getJson($this->base.'/chat/'.$this->question['request_uuid'].$this->query)->assertNotFound();
    $this->postJson($this->base.'/chat', $this->question)->assertNotFound();
    expect($this->provider->calls)->toBe(1);
});

it('isolates personal receipts from technician scope and shares their existing budget ledger', function () {
    $token = investigationStaffToken($this->sourceActor);
    $this->withToken($token)->postJson('/api/obli/chat', array_diff_key($this->question, ['expected_actor_id' => true]))->assertOk();
    $this->getJson($this->base.'/chat/'.$this->question['request_uuid'].$this->query)->assertNotFound();
    $this->postJson($this->base.'/chat', $this->question)->assertNotFound();
    $this->question['request_uuid'] = (string) Str::uuid();
    $this->postJson($this->base.'/chat', $this->question)->assertOk();
    $this->withToken($token)->getJson('/api/obli/chat/'.$this->question['request_uuid'])->assertNotFound();
    expect($this->provider->calls)->toBe(2)->and($this->provider->profiles)->toBe(['personal', 'technician'])->and(DB::table('obli_ai_budget_periods')->pluck('committed_micros')->map(fn ($value) => (int) $value)->all())->toBe([180, 180]);
    DB::table('obli_ai_budget_periods')->where('period', 'like', 'day:%')->update(['committed_micros' => 10000000]);
    $this->postJson($this->base.'/chat', [...$this->question, 'request_uuid' => (string) Str::uuid()])->assertStatus(429)->assertJsonPath('code', 'budget_exceeded');
    expect($this->provider->calls)->toBe(2);
});

it('binds the fixed linked ticket without automatically ingesting its private text', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->sourceSite->id, 'is_sensitive' => false, 'status' => 'open', 'work_type' => 'incident', 'merged_into_ticket_id' => null, 'description' => 'Never ingest this ticket description automatically.']);
    $ticket->links()->create(['relationship' => 'affected_device', 'linkable_type' => $this->sourceDevice->getMorphClass(), 'linkable_id' => $this->sourceDevice->id]);
    $question = [...$this->question, 'ticket_id' => $ticket->id];
    $this->postJson($this->base.'/chat', $question)->assertOk();
    expect(json_encode($this->provider->messages))->not->toContain('Never ingest');
    $this->getJson($this->base.'/chat/'.$question['request_uuid'].$this->query)->assertNotFound();
    $this->getJson($this->base.'/chat/'.$question['request_uuid'].$this->query.'&ticket_id='.$ticket->id)->assertOk();
    $ticket->links()->delete();
    $this->getJson($this->base.'/chat/'.$question['request_uuid'].$this->query.'&ticket_id='.$ticket->id)->assertNotFound()->assertJsonPath('code', 'source_unavailable');
});

it('keeps uncertain outcomes and immutable retry identities without additional provider calls', function () {
    $this->provider->fail = true;
    $this->postJson($this->base.'/chat', $this->question)->assertOk()->assertJsonPath('status', 'unconfirmed')->assertDontSee('private-upstream-body');
    $this->postJson($this->base.'/chat', $this->question)->assertOk()->assertJsonPath('status', 'unconfirmed');
    $this->postJson($this->base.'/chat', [...$this->question, 'messages' => [['role' => 'user', 'content' => 'Changed question']]])->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    $this->getJson($this->base.'/chat/'.Str::uuid().$this->query)->assertNotFound()->assertJsonPath('code', 'source_unavailable');
    expect($this->provider->calls)->toBe(1);
    $row = DB::table('obli_ai_requests')->sole();
    expect((int) $row->charged_micros)->toBe((int) $row->reserved_micros);
});

it('expires and purges encrypted diagnostic receipts with the existing answer retention', function () {
    $preview = $this->getJson($this->base.'/diagnostics'.$this->query)->assertOk()->json();
    $this->question['messages'][0]['computer_diagnostics'] = ['snapshot_version' => $preview['snapshot_version'], 'groups' => ['software']];
    $this->postJson($this->base.'/chat', $this->question)->assertOk();
    DB::table('obli_ai_requests')->update(['expires_at' => now()->subSecond()]);
    $this->getJson($this->base.'/chat/'.$this->question['request_uuid'].$this->query)->assertStatus(410)->assertJsonPath('code', 'outcome_expired');
    $this->artisan('obli:prune-sessions')->assertSuccessful();
    expect(DB::table('obli_ai_requests')->count())->toBe(0)->and(DB::table('obli_ai_budget_periods')->count())->toBe(2);
});

it('permits the exact chat route body budget while retaining smaller remote response limits', function () {
    $this->postJson($this->base.'/chat', [...$this->question, 'messages' => [['role' => 'user', 'content' => str_repeat('x', 10000)]]])->assertOk();
    $this->call('POST', $this->base.'/chat', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'], str_repeat('x', 131073))->assertStatus(413);
    $this->call('GET', $this->base.'/options'.$this->query, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'], str_repeat('x', 8193))->assertStatus(413);
    $route = app('router')->getRoutes()->getByName('it.remote-support.obli.chat.store');
    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('web', 'auth', 'throttle:obli-chat');
});
