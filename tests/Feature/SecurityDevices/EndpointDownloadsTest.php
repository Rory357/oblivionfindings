<?php

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\SecurityPolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function downloadsActor(array $keys): User
{
    $role = Role::query()->create(['name' => 'download-test-'.str()->uuid(), 'label' => 'Download fixture', 'level' => 10, 'type' => 'custom']);
    $user = User::factory()->create(['role' => $role->name]);
    $user->roles()->attach($role);
    foreach ($keys as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'securityDevices', 'module' => 'Operations']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    return $user->fresh();
}

function signDownloadsCatalogue($test, ?array $catalogue = null): void
{
    $payload = json_encode($catalogue ?? $test->catalogue, JSON_THROW_ON_ERROR);
    file_put_contents($test->releaseDirectory.DIRECTORY_SEPARATOR.'downloads.json', json_encode([
        'payload' => base64_encode($payload),
        'signature' => base64_encode(sodium_crypto_sign_detached(EndpointReleaseCatalogue::DOMAIN.$payload, sodium_crypto_sign_secretkey($test->signingPair))),
    ], JSON_THROW_ON_ERROR));
}

function releaseUrl(array $artifact): string
{
    return 'https://localhost/security-devices/agent-downloads/'.$artifact['id'].'/'.$artifact['sha256'];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    SecurityPolicy::flushCache();
    $this->releaseDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'obli-download-http-'.bin2hex(random_bytes(8));
    mkdir($this->releaseDirectory, 0700);
    $this->signingPair = sodium_crypto_sign_keypair();
    $this->artifacts = [];
    $this->contents = [];
    foreach ([
        ['computer', 'windows', ['computer'], 'computer.exe'],
        ['site', 'linux', ['site'], 'collector.tar.gz'],
        ['combined', 'windows', ['computer', 'site'], 'combined.zip'],
    ] as [$id, $platform, $roles, $filename]) {
        $bytes = 'Fictional distribution bytes for '.$id.'; never executable.';
        $this->contents[$id] = $bytes;
        $this->artifacts[$id] = [
            'id' => $id, 'platform' => $platform, 'architecture' => 'amd64', 'roles' => $roles,
            'version' => '1.2.3', 'release_revision' => 7, 'filename' => $filename,
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
        ];
        file_put_contents($this->releaseDirectory.DIRECTORY_SEPARATOR.$filename, $bytes);
    }
    $this->catalogue = [
        'schema' => 1, 'product' => 'oblivion-downloads', 'revision' => 3,
        'issued_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        'artifacts' => array_values($this->artifacts),
    ];
    signDownloadsCatalogue($this);
    config()->set([
        'endpoint_management.release_directory' => $this->releaseDirectory,
        'endpoint_management.release_public_key' => base64_encode(sodium_crypto_sign_publickey($this->signingPair)),
        'endpoint_management.minimum_catalogue_revision' => 3,
    ]);
    $this->downloadKeys = ['securityDevices.viewAny', 'securityDevices.commands.admin', 'securityDevices.integrations.manage'];
});

afterEach(function () {
    foreach (glob($this->releaseDirectory.DIRECTORY_SEPARATOR.'*') as $file) {
        unlink($file);
    }
    rmdir($this->releaseDirectory);
    SecurityPolicy::flushCache();
});

test('release downloads require an authenticated module manager without unrelated IT grants or Site records', function () {
    $this->getJson('/security-devices/agent-downloads')->assertUnauthorized();
    $this->actingAs(downloadsActor(['securityDevices.viewAny']))->getJson('/security-devices/agent-downloads')->assertForbidden();
    $this->actingAs(downloadsActor(['securityDevices.commands.admin']))->getJson('/security-devices/agent-downloads')->assertForbidden();

    $actor = downloadsActor($this->downloadKeys);
    expect($actor->canDo('it.manage'))->toBeFalse()->and($actor->canDo('it.request'))->toBeFalse();
    $this->actingAs($actor)->get('/security-devices/agent-downloads')->assertOk()
        ->assertInertia(fn ($p) => $p->component('security-devices/agent-downloads')->has('artifacts', 3)
            ->where('catalogue_available', true)->where('can_manage_computers', false)->where('can_manage_collectors', false));
});

test('release projections and direct downloads enforce computer and collector capabilities', function () {
    foreach ([
        ['securityDevices.commands.admin', 'computer', 'site'],
        ['securityDevices.integrations.manage', 'site', 'computer'],
    ] as [$permission, $visible, $hidden]) {
        $actor = downloadsActor(['securityDevices.viewAny', $permission]);
        $this->actingAs($actor)->get('/security-devices/agent-downloads')->assertOk()
            ->assertInertia(fn ($p) => $p->has('artifacts', 1)->where('artifacts.0.id', $visible)
                ->where('artifacts.0.download_url', parse_url(releaseUrl($this->artifacts[$visible]), PHP_URL_PATH)));
        $this->get(releaseUrl($this->artifacts[$visible]))->assertOk()->assertStreamedContent($this->contents[$visible]);
        $this->get(releaseUrl($this->artifacts[$hidden]))->assertNotFound();
        $this->get(releaseUrl($this->artifacts['combined']))->assertNotFound();
    }
});

test('verified download bytes are private attachments and do not enrol machines or call providers', function () {
    $actor = downloadsActor([...$this->downloadKeys, 'securityDevices.devices.view', 'securityDevices.integrations.view']);
    $this->actingAs($actor)->get('/security-devices/agent-downloads')->assertOk()
        ->assertInertia(fn ($p) => $p->where('can_manage_computers', true)->where('can_manage_collectors', true));
    $count = EndpointAgent::query()->count();
    $response = $this->get(releaseUrl($this->artifacts['combined']))->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Length', (string) strlen($this->contents['combined']))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('Pragma', 'no-cache')
        ->assertStreamedContent($this->contents['combined']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment;')->toContain('combined.zip')
        ->and(EndpointAgent::query()->count())->toBe($count);
    Http::assertNothingSent();
});

test('catalogue failure shows an honest empty page and never substitutes an unsigned download', function () {
    $this->actingAs(downloadsActor($this->downloadKeys));
    config()->set('endpoint_management.release_public_key', '');
    $this->get('/security-devices/agent-downloads')->assertOk()
        ->assertInertia(fn ($p) => $p->has('artifacts', 0)->where('catalogue_available', false)->where('catalogue_expires_at', null));
    $this->getJson(releaseUrl($this->artifacts['computer']))->assertStatus(503);
});

test('downloads reject insecure transport stale links altered bytes and expired catalogues', function () {
    $this->actingAs(downloadsActor($this->downloadKeys));
    $url = releaseUrl($this->artifacts['computer']);
    $this->get(str_replace('https://', 'http://', $url))->assertStatus(400);
    $this->get(releaseUrl([...$this->artifacts['computer'], 'sha256' => str_repeat('0', 64)]))->assertNotFound();
    file_put_contents($this->releaseDirectory.DIRECTORY_SEPARATOR.'computer.exe', str_repeat('X', $this->artifacts['computer']['size']));
    $this->getJson($url)->assertStatus(503);
    $this->catalogue['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 1);
    signDownloadsCatalogue($this);
    $this->getJson(releaseUrl($this->artifacts['site']))->assertStatus(503);
});

test('current authority overrides an already loaded actor on each download', function () {
    $actor = downloadsActor($this->downloadKeys);
    $this->actingAs($actor);
    // Deliberately prime the in-memory permissions before changing database evidence.
    expect($actor->canDo('securityDevices.commands.admin'))->toBeTrue();
    $permission = Permission::query()->where('key', 'securityDevices.commands.admin')->sole();
    $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $this->get(releaseUrl($this->artifacts['computer']))->assertNotFound();
    $this->get(releaseUrl($this->artifacts['site']))->assertOk()->assertStreamedContent($this->contents['site']);
    User::query()->whereKey($actor->id)->update(['approved_at' => null]);
    $this->getJson(releaseUrl($this->artifacts['site']))->assertForbidden();
});

test('unverified and external identities cannot download even with grants', function () {
    foreach (['email_verified_at' => null, 'external_clinical_account' => true, 'role' => 'client'] as $field => $value) {
        $actor = downloadsActor($this->downloadKeys);
        User::query()->whereKey($actor->id)->update([$field => $value]);
        // A stale login object must not bypass current account checks.
        $this->actingAs($actor)->getJson(releaseUrl($this->artifacts['computer']))->assertForbidden();
    }
});
