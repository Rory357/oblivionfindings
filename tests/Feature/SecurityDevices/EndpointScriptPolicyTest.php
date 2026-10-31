<?php

use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SecurityPolicy;
use Database\Seeders\EndpointScriptPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function scriptPolicyGrant(User $actor, string $key, bool $allow = true): void
{
    $permission = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'securityDevices']);
    $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allow]]);
}

function scriptPolicyProof(User $actor): array
{
    return ['endpoint_script_identity' => [
        'actor_id' => (int) $actor->id, 'fingerprint' => ObliStaffAccess::fingerprint($actor), 'started_at' => now()->timestamp,
    ], 'auth.password_confirmed_at' => now()->timestamp];
}

function scriptPolicyInput(User $actor, int $version = 0): array
{
    return ['expected_actor_id' => (int) $actor->id, 'expected_version' => $version, 'request_key' => (string) Str::uuid(),
        'enabled' => true, 'consent_mode' => 'attended', 'reason' => 'Configure this synthetic test computer.'];
}

function scriptPolicyDeny(callable $operation, int $status): void
{
    try {
        $operation();
        test()->fail('Expected script policy denial.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe($status);
    }
}

beforeEach(function () {
    $this->seed([EndpointScriptPermissionsSeeder::class, RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    $this->policyActor = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->policyOther = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->policySite = ensureCanonicalHrStaffProfile($this->policyActor);
    ensureCanonicalHrStaffProfile($this->policyOther, $this->policySite);
    foreach ([$this->policyActor, $this->policyOther] as $actor) {
        $actor->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        foreach (['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view', EndpointScriptPolicy::MANAGE] as $key) {
            scriptPolicyGrant($actor, $key);
        }
    }
    $this->policyDevice = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active']);
    $this->policyAssignment = DeviceAssignment::create(['device_id' => $this->policyDevice->id, 'assignable_type' => 'site', 'assignable_id' => $this->policySite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    $request = Request::create('https://localhost/synthetic-script-policy');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $this->policyActor);
    app()->instance('request', $request);
    $request->session()->put(scriptPolicyProof($this->policyActor));
    $this->policyService = app(EndpointScriptPolicy::class);
    $this->policyUrl = 'https://localhost/security-devices/devices/'.$this->policyDevice->id.'/endpoint/script-policy';
});

it('keeps default-off policy and immutable receipts separate from current policy without enabling execution', function () {
    $service = $this->policyService;
    $actor = $this->policyActor;
    $device = $this->policyDevice;
    expect($service->show($actor, $device, $actor->id)['policy'])->toBe(EndpointScriptPolicy::defaults());
    $input = scriptPolicyInput($actor);
    $first = $service->save($actor, $device, $input);
    expect($first['policy'])->toBe(['version' => 1, 'enabled' => true, 'consent_mode' => 'attended'])
        ->and($first['can_run'])->toBeFalse()->and($first['available'])->toBeFalse();
    expect($service->save($actor, $device, array_reverse($input, true)))->toBe($first);
    $second = $service->save($actor, $device, [...scriptPolicyInput($actor, 1), 'enabled' => false]);
    $replay = $service->save($actor, $device, $input);
    expect($replay['receipt'])->toBe($first['receipt'])->and($replay['policy'])->toBe($second['policy']);
    app('request')->session()->forget('endpoint_script_identity');
    expect($service->recover($actor, $device, $actor->id, $input['request_key'])['receipt'])->toBe($first['receipt']);
    scriptPolicyDeny(fn () => $service->save($actor, $device, $input), 403);
    config()->set('endpoint_management.scripts.release_enabled', true);
    expect($service->available())->toBeFalse()
        ->and(DB::table('endpoint_script_policy_revisions')->count())->toBe(2)
        ->and(DeviceEvent::where('event_type', 'endpoint_script_policy_changed')->count())->toBe(2)
        ->and(DeviceEvent::where('event_type', 'endpoint_script_policy_changed')->first()->payload)->toEqual(['actor_user_id' => (int) $actor->id, 'version' => 1])
        ->and(DB::table('device_command_requests')->count())->toBe(0)
        ->and(DB::table('endpoint_deliveries')->count())->toBe(0);
});

it('rejects conflicting intent stale versions cross-computer keys and another authors recovery', function () {
    $service = $this->policyService;
    $actor = $this->policyActor;
    $device = $this->policyDevice;
    $input = scriptPolicyInput($actor);
    $service->save($actor, $device, $input);
    scriptPolicyDeny(fn () => $service->save($actor, $device, [...$input, 'enabled' => false]), 409);
    scriptPolicyDeny(fn () => $service->save($actor, $device, scriptPolicyInput($actor)), 409);
    scriptPolicyDeny(fn () => $service->show($actor, $device, $this->policyOther->id), 409);
    scriptPolicyDeny(fn () => $service->recover($this->policyOther, $device, $this->policyOther->id, $input['request_key']), 404);
    $other = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint']);
    DeviceAssignment::create(['device_id' => $other->id, 'assignable_type' => 'site', 'assignable_id' => $this->policySite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    scriptPolicyDeny(fn () => $service->save($actor, $other, $input), 409);
    expect(DB::table('endpoint_script_policy_revisions')->count())->toBe(1);
});

it('requires independent policy and unattended grants even for cached users and exact retries', function () {
    $service = $this->policyService;
    $actor = $this->policyActor;
    $device = $this->policyDevice;
    foreach (['admin', 'it_manager'] as $role) {
        expect(Role::where('name', $role)->firstOrFail()->permissions()->whereIn('key', [EndpointScriptPolicy::MANAGE, EndpointScriptPolicy::RUN, EndpointScriptPolicy::UNATTENDED])->count())->toBe(0);
    }
    $input = [...scriptPolicyInput($actor), 'consent_mode' => 'unattended'];
    scriptPolicyDeny(fn () => $service->save($actor, $device, $input), 403);
    scriptPolicyGrant($actor, EndpointScriptPolicy::UNATTENDED);
    $service->save($actor, $device, $input);
    expect($actor->canDo(EndpointScriptPolicy::UNATTENDED))->toBeTrue();
    scriptPolicyGrant($actor, EndpointScriptPolicy::UNATTENDED, false);
    scriptPolicyDeny(fn () => $service->save($actor, $device, $input), 403);
    // Losing unattended authority must not prevent explicitly disabling it.
    expect($service->save($actor, $device, [...scriptPolicyInput($actor, 1), 'enabled' => false, 'consent_mode' => 'unattended'])['policy']['enabled'])->toBeFalse();
    scriptPolicyGrant($actor, EndpointScriptPolicy::MANAGE, false);
    scriptPolicyGrant($actor, EndpointScriptPolicy::RUN);
    expect($service->show($actor, $device, $actor->id)['can_manage'])->toBeFalse();
    scriptPolicyDeny(fn () => $service->save($actor, $device, scriptPolicyInput($actor, 2)), 403);
    scriptPolicyDeny(fn () => $service->recover($actor, $device, $actor->id, $input['request_key']), 403);
});

it('rechecks identity account MFA and active Site before reads writes and recovery', function () {
    AppSetting::updateOrCreate(['key' => 'force_2fa'], ['value' => false]);
    expect(SecurityPolicy::forceTwoFactor())->toBeFalse();
    $service = $this->policyService;
    $actor = $this->policyActor;
    $device = $this->policyDevice;
    $input = scriptPolicyInput($actor);
    $service->save($actor, $device, $input);
    app('request')->session()->put('auth.password_confirmed_at', now()->subSeconds(901)->timestamp);
    scriptPolicyDeny(fn () => $service->save($actor, $device, $input), 403);
    app('request')->session()->put(scriptPolicyProof($actor));
    User::whereKey($actor->id)->update(['two_factor_confirmed_at' => null]);
    scriptPolicyDeny(fn () => $service->show($actor, $device, $actor->id), 403);
    scriptPolicyDeny(fn () => $service->save($actor, $device, $input), 403);
    scriptPolicyDeny(fn () => $service->recover($actor, $device, $actor->id, $input['request_key']), 403);
    User::whereKey($actor->id)->update(['two_factor_confirmed_at' => now(), 'approved_at' => null]);
    scriptPolicyDeny(fn () => $service->recover($actor, $device, $actor->id, $input['request_key']), 403);
    User::whereKey($actor->id)->update(['approved_at' => now()]);
    $this->policySite->forceFill(['is_active' => false])->save();
    scriptPolicyDeny(fn () => $service->show($actor, $device, $actor->id), 403);
    $this->policySite->forceFill(['is_active' => true])->save();
    $actor->forceFill(['password' => 'new-test-only-password'])->save();
    scriptPolicyDeny(fn () => $service->save($actor, $device, $input), 403);
});

it('requires new configuration after reassignment and denies old scoped receipt recovery', function () {
    $service = $this->policyService;
    $actor = $this->policyActor;
    $device = $this->policyDevice;
    $input = scriptPolicyInput($actor);
    $service->save($actor, $device, $input);
    $this->policyAssignment->forceFill(['released_at' => now()])->save();
    DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $this->policySite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
    $current = $service->show($actor, $device, $actor->id);
    expect($current['assignment_matches'])->toBeFalse()->and($current['policy'])->toBe(['version' => 1, 'enabled' => false, 'consent_mode' => 'attended']);
    scriptPolicyDeny(fn () => $service->recover($actor, $device, $actor->id, $input['request_key']), 404);
    scriptPolicyDeny(fn () => $service->save($actor, $device, $input), 404);
    expect($service->save($actor, $device, scriptPolicyInput($actor, 1))['assignment_matches'])->toBeTrue();
});

it('fails closed on corrupted policy receipts binding or request digests', function () {
    $service = $this->policyService;
    $actor = $this->policyActor;
    $device = $this->policyDevice;
    $input = scriptPolicyInput($actor);
    $service->save($actor, $device, $input);
    $row = DB::table('endpoint_script_policy_revisions')->first();
    foreach (['receipt_json' => '{}', 'receipt_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64), 'assignment_fingerprint' => str_repeat('c', 64), 'version' => 2] as $field => $value) {
        DB::table('endpoint_script_policy_revisions')->where('id', $row->id)->update([$field => $value]);
        scriptPolicyDeny(fn () => $service->show($actor, $device, $actor->id), 409);
        DB::table('endpoint_script_policy_revisions')->where('id', $row->id)->update([$field => $row->{$field}]);
    }
    expect($service->recover($actor, $device, $actor->id, $input['request_key'])['policy']['version'])->toBe(1);
});

it('denies direct computer reads after device permission Site or category changes', function () {
    $service = $this->policyService;
    $actor = $this->policyActor;
    $device = $this->policyDevice;
    $input = scriptPolicyInput($actor);
    $service->save($actor, $device, $input);
    scriptPolicyGrant($actor, 'securityDevices.devices.view', false);
    scriptPolicyDeny(fn () => $service->show($actor, $device, $actor->id), 403);
    scriptPolicyGrant($actor, 'securityDevices.devices.view');
    $device->forceFill(['category' => 'camera'])->save();
    scriptPolicyDeny(fn () => $service->show($actor, $device, $actor->id), 404);
    $device->forceFill(['category' => 'endpoint'])->save();
    $outside = Site::factory()->create(['is_active' => true]);
    $this->policyAssignment->forceFill(['assignable_id' => $outside->id, 'custody_site_id' => $outside->id])->save();
    scriptPolicyDeny(fn () => $service->show($actor, $device, $actor->id), 404);
    scriptPolicyDeny(fn () => $service->recover($actor, $device, $actor->id, $input['request_key']), 404);
    expect(DB::table('endpoint_script_policy_revisions')->count())->toBe(1);
});

it('serves strict private HTTP policy save confirmation and actor-owned recovery', function () {
    $actor = $this->policyActor;
    $url = $this->policyUrl;
    $this->actingAs($actor);
    $query = '?expected_actor_id='.$actor->id;
    $this->get($url.$query, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('policy.enabled', false)->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get($url.'/confirm-identity'.$query)->assertRedirect(route('password.confirm'))
        ->assertSessionHas('url.intended', '/it/remote-support?device='.$this->policyDevice->id);
    $input = scriptPolicyInput($actor);
    $this->putJson($url, $input)->assertForbidden()->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertExactJson(['message' => 'Your current access does not allow this remote-support action.']);
    $this->withSession(scriptPolicyProof($actor));
    $saved = $this->putJson($url, $input)->assertOk()->assertJsonPath('available', false)->json();
    expect($this->get($url.'/requests/'.$input['request_key'].$query, ['Accept' => 'application/json'])->assertOk()->json()['receipt'])->toBe($saved['receipt']);
    foreach ([$query.'&expected_actor_id='.$actor->id, $query.'&extra=1', '?expected_actor_id[]=1', '?expected_actor_id=01'] as $bad) {
        $this->get($url.$bad, ['Accept' => 'application/json'])->assertBadRequest();
    }
    $this->putJson($url.$query, $input)->assertBadRequest();
    foreach ([['enabled' => 1], ['expected_version' => '0'], ['reason' => "bad\nreason"], ['extra' => true]] as $bad) {
        $this->putJson($url, [...$input, ...$bad])->assertUnprocessable();
    }
    $raw = json_encode($input, JSON_THROW_ON_ERROR);
    $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], str_replace('"enabled":true', '"enabled":false,"enabled":true', $raw))->assertUnprocessable();
    $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertStatus(415);
    // Empty JSON arrays are still request bodies and are invalid for this read API.
    $this->getJson($url.$query)->assertBadRequest();
    $this->get(str_replace('https:', 'http:', $url).$query, ['Accept' => 'application/json'])->assertBadRequest();
    $this->get(str_replace('localhost', 'wrong-origin.example', $url).$query, ['Accept' => 'application/json'])->assertBadRequest();
    expect(DB::table('endpoint_script_policy_revisions')->count())->toBe(1);
});

it('exports original policy HTTP receipts and fences delayed revisions after lost replies', function () {
    $actor = $this->policyActor;
    $url = $this->policyUrl;
    $query = '?expected_actor_id='.$actor->id;
    $this->actingAs($actor)->withSession(scriptPolicyProof($actor));
    $documents = [];
    $read = function (string $name, string $path, int $status = 200) use (&$documents): array {
        $response = $this->get($path, ['Accept' => 'application/json'])->assertStatus($status);
        $documents[] = ['name' => $name, 'method' => 'GET', 'request_json' => null, 'status' => $status, 'response_json' => $response->getContent()];

        return $response->json();
    };
    $write = function (string $name, array $input, int $status = 200) use (&$documents, $url): array {
        $raw = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertStatus($status);
        $documents[] = ['name' => $name, 'method' => 'PUT', 'request_json' => $raw, 'status' => $status, 'response_json' => $response->getContent()];

        return $response->json();
    };
    $read('default', $url.$query);
    $late = scriptPolicyInput($actor);
    $read('not_received', $url.'/requests/'.$late['request_key'].$query, 404);
    // After explicit current-policy review, a separate accepted revision fences
    // any older delayed write. This does not claim the old request was cancelled.
    $new = [...scriptPolicyInput($actor), 'enabled' => false, 'reason' => 'Review a separate change after an unconfirmed request.'];
    $write('new_after_unknown', $new);
    $write('late_old_rejected', $late, 409);
    $current = $read('current_after_late', $url.$query);
    expect($current['policy'])->toBe(['version' => 1, 'enabled' => false, 'consent_mode' => 'attended']);
    $read('old_still_unconfirmed', $url.'/requests/'.$late['request_key'].$query, 404);
    $original = [...scriptPolicyInput($actor, 1), 'reason' => '  Approved Māori staff / computer policy review.  '];
    $first = $write('original_save', $original);
    expect($first['receipt']['reason'])->toBe($original['reason']);
    $write('stale_new_rejected', [...scriptPolicyInput($actor, 1), 'enabled' => false], 409);
    $newer = $write('newer_save', [...scriptPolicyInput($actor, 2), 'enabled' => false]);
    $replay = $write('original_replay', $original);
    expect($replay['receipt'])->toBe($first['receipt'])->and($replay['policy'])->toBe($newer['policy']);
    $this->withSession(['auth.password_confirmed_at' => now()->subSeconds(901)->timestamp]);
    $recovered = $read('original_recovery', $url.'/requests/'.$original['request_key'].$query);
    expect($recovered['identity_confirmed'])->toBeFalse()->and($recovered['receipt'])->toBe($first['receipt'])
        ->and($recovered['policy']['version'])->toBe(3)->and($recovered['policy']['enabled'])->toBeFalse();
    scriptPolicyGrant($actor, EndpointScriptPolicy::MANAGE, false);
    $read('revoked_recovery', $url.'/requests/'.$original['request_key'].$query, 403);
    expect(DB::table('endpoint_script_policy_revisions')->count())->toBe(3)
        ->and(DB::table('device_command_requests')->count())->toBe(0);
    if ($path = getenv('OBLI_SCRIPT_POLICY_EXPORT')) {
        file_put_contents($path, json_encode(['version' => 1, 'actor_id' => (int) $actor->id, 'device_id' => (int) $this->policyDevice->id, 'documents' => $documents], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
});
