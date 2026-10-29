<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\SecurityDevices\Cctv\Models\CameraEndpointTrust;
use App\Domain\SecurityDevices\Cctv\Services\CameraEndpointTrustService;
use App\Domain\SecurityDevices\Cctv\Services\OnvifCameraProfileClient;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Phase A, item A5 (docs/security-devices/GOAL.md): camera passwords are safe.
 * - An https camera or Protect console gets no password until its certificate is trusted; afterwards
 *   only the trusted key is accepted (checked here against a real local TLS server).
 * - Snapshots never send a plain Basic password; only Digest challenges are answered.
 */

/** A self-signed certificate and key in a temp folder, like a camera or console makes for itself. */
function selfSignedCertificate(string $commonName): array
{
    $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sd-camera-tls-'.Str::random(8);
    mkdir($folder);

    // PHP on Windows has no default openssl.cnf, so give it the smallest one that works everywhere.
    file_put_contents($folder.'/openssl.cnf', "[ req ]\ndistinguished_name = req_dn\n[ req_dn ]\n");
    $options = ['config' => $folder.'/openssl.cnf', 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => $commonName], $key, $options);
    $certificate = openssl_csr_sign($csr, null, $key, 30, $options);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem, null, $options);
    while (openssl_error_string() !== false) {
        // Drain OpenSSL's notes about the missing default config so they don't leak into later checks.
    }
    file_put_contents($folder.'/cert.pem', $certificatePem);
    file_put_contents($folder.'/key.pem', $keyPem);

    return ['folder' => $folder, 'cert' => $folder.'/cert.pem', 'key' => $folder.'/key.pem', 'pem' => $certificatePem];
}

/**
 * Start a tiny TLS web server in a child process. It answers every request with 200 "ok" and writes
 * one line per request it received to a log, noting whether a password header came with it.
 *
 * @return array{process: resource, port: int, log: string}
 */
function startTlsCamera(array $certificate): array
{
    $script = $certificate['folder'].'/server.php';
    file_put_contents($script, <<<'PHP'
<?php
[$self, $cert, $key, $log] = $argv;
$context = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errorNumber, $errorText, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (! $server) { fwrite(STDOUT, "failed\n"); exit(1); }
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, 'port '.substr($name, strrpos($name, ':') + 1)."\n");
fflush(STDOUT);
$deadline = time() + 60;
while (time() < $deadline) {
    $client = @stream_socket_accept($server, 1);
    if (! $client) { continue; }
    stream_set_timeout($client, 2);
    $request = '';
    while (! str_contains($request, "\r\n\r\n") && ($chunk = fread($client, 1024)) !== false && $chunk !== '') { $request .= $chunk; }
    if ($request !== '') {
        file_put_contents($log, (stripos($request, "\nauthorization:") !== false || stripos($request, "\nx-api-key:") !== false ? 'with-secret' : 'no-secret')."\n", FILE_APPEND);
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
    }
    fclose($client);
}
PHP);

    $log = $certificate['folder'].'/requests.log';
    touch($log);
    $process = proc_open([PHP_BINARY, $script, $certificate['cert'], $certificate['key'], $log], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();

    stream_set_blocking($pipes[1], false);
    $line = '';
    $started = microtime(true);
    while (! str_contains($line, "\n") && microtime(true) - $started < 10) {
        $line .= (string) fgets($pipes[1]);
        usleep(50_000);
    }
    expect($line)->toStartWith('port ');

    return ['process' => $process, 'port' => (int) trim(substr($line, 5)), 'log' => $log];
}

function stopTlsCamera(array $server): void
{
    proc_terminate($server['process']);
    proc_close($server['process']);
}

/** @return list<string> */
function cameraRequests(array $server): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($server['log']))));
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);

    $this->person = function (string $role): User {
        $user = User::factory()->create(['approved_at' => now(), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);

        return $user;
    };
});

it('pins the certificate’s public key and shows the fingerprint the way browsers do', function (): void {
    $certificate = selfSignedCertificate('UniFi Protect');

    $pin = CameraEndpointTrustService::publicKeyPin($certificate['pem']);
    $fingerprint = CameraEndpointTrustService::formatFingerprint((string) openssl_x509_fingerprint($certificate['pem'], 'sha256'));

    expect($pin)->toMatch(CameraEndpointTrustService::PIN_PATTERN)
        ->and($fingerprint)->toMatch('/\A[0-9A-F]{2}(:[0-9A-F]{2}){31}\z/')
        ->and(CameraEndpointTrustService::httpOptions(null))->toBe(['verify' => true])
        ->and(CameraEndpointTrustService::httpOptions($pin))->toBe(['verify' => false, 'curl' => [CURLOPT_PINNEDPUBLICKEY => $pin]]);
});

it('sends nothing to an untrusted camera, then talks only to the trusted key', function (): void {
    $trust = app(CameraEndpointTrustService::class);
    $manager = ($this->person)('facilities_manager');
    $camera = startTlsCamera(selfSignedCertificate('UniFi Protect'));
    $impostor = startTlsCamera(selfSignedCertificate('Not the camera'));

    try {
        // First contact: refused before any password, and the offered certificate is kept.
        $gate = $trust->gate($this->site->id, '127.0.0.1', $camera['port']);
        expect($gate['ok'])->toBeFalse()
            ->and($gate['reason_code'])->toBe('camera_certificate_untrusted')
            ->and(cameraRequests($camera))->toBe([]);

        $record = $trust->record($this->site->id, '127.0.0.1', $camera['port']);
        expect($record?->offered_fingerprint)->not->toBeNull()
            ->and($record?->trusted_pin)->toBeNull();

        $trust->trust($manager, $this->site->id, '127.0.0.1', $camera['port'], (string) $record->offered_fingerprint);

        // Trusted: the gate opens with the pin, and a request with the pin reaches the camera.
        $gate = $trust->gate($this->site->id, '127.0.0.1', $camera['port']);
        expect($gate['ok'])->toBeTrue()->and($gate['reason_code'])->toBe('camera_tls_pinned');

        $reply = Http::withOptions(CameraEndpointTrustService::httpOptions($gate['pin']))
            ->withHeaders(['Authorization' => 'Digest username="cam"'])
            ->get('https://127.0.0.1:'.$camera['port'].'/snapshot');
        expect($reply->status())->toBe(200)->and(cameraRequests($camera))->toBe(['with-secret']);

        // Something else with another key, reached with the camera's pin: cURL stops before sending.
        expect(fn () => Http::withOptions(CameraEndpointTrustService::httpOptions($gate['pin']))
            ->withHeaders(['Authorization' => 'Digest username="cam"'])
            ->get('https://127.0.0.1:'.$impostor['port'].'/snapshot'))->toThrow(Exception::class);
        expect(cameraRequests($impostor))->toBe([]);

        // Normal certificate checks still refuse a self-signed certificate that nobody trusted.
        expect(fn () => Http::withOptions(CameraEndpointTrustService::httpOptions(null))
            ->get('https://127.0.0.1:'.$impostor['port'].'/snapshot'))->toThrow(Exception::class);
        expect(cameraRequests($impostor))->toBe([]);
    } finally {
        stopTlsCamera($camera);
        stopTlsCamera($impostor);
    }
});

it('refuses a changed certificate until an IT admin replaces it', function (): void {
    $trust = app(CameraEndpointTrustService::class);
    $manager = ($this->person)('facilities_manager');
    $itAdmin = ($this->person)('it_manager');
    $original = selfSignedCertificate('UniFi Protect');
    $replacement = startTlsCamera(selfSignedCertificate('UniFi Protect (new)'));

    try {
        // The address was trusted with the original certificate; now it shows a different one.
        CameraEndpointTrust::query()->create([
            'site_id' => $this->site->id, 'host' => '127.0.0.1', 'port' => $replacement['port'],
            'trusted_pin' => CameraEndpointTrustService::publicKeyPin($original['pem']),
            'trusted_fingerprint' => CameraEndpointTrustService::formatFingerprint((string) openssl_x509_fingerprint($original['pem'], 'sha256')),
            'trusted_at' => now(),
        ]);

        $gate = $trust->gate($this->site->id, '127.0.0.1', $replacement['port']);
        expect($gate['ok'])->toBeFalse()->and($gate['reason_code'])->toBe('camera_certificate_changed');

        $offered = (string) $trust->record($this->site->id, '127.0.0.1', $replacement['port'])?->offered_fingerprint;
        expect(fn () => $trust->trust($manager, $this->site->id, '127.0.0.1', $replacement['port'], $offered))
            ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

        $trust->trust($itAdmin, $this->site->id, '127.0.0.1', $replacement['port'], $offered);
        expect($trust->gate($this->site->id, '127.0.0.1', $replacement['port'])['ok'])->toBeTrue()
            ->and(DB::table('audit_logs')->where('action', 'security_devices.camera_certificate.replaced')->count())->toBe(1);
    } finally {
        stopTlsCamera($replacement);
    }
});

it('lets only the right people trust a camera, and only the certificate it offered', function (): void {
    $camera = Device::factory()->create(['name' => 'Front porch camera', 'domain' => 'security', 'category' => 'cctv', 'ip_address' => '172.16.0.61']);
    DeviceAssignment::query()->create([
        'device_id' => $camera->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    $fingerprint = implode(':', array_fill(0, 32, 'AB'));
    CameraEndpointTrust::query()->create([
        'site_id' => $this->site->id, 'host' => '172.16.0.61', 'port' => 443,
        'offered_pin' => 'sha256//'.base64_encode(str_repeat('x', 32)), 'offered_fingerprint' => $fingerprint, 'offered_at' => now(),
    ]);
    $url = "/security-devices/devices/{$camera->id}/camera/certificate/trust";

    $this->actingAs(($this->person)('support_worker'))
        ->postJson($url, ['host' => '172.16.0.61', 'port' => 443, 'fingerprint' => $fingerprint])
        ->assertForbidden();

    $manager = ($this->person)('facilities_manager');
    $this->actingAs($manager)
        ->postJson($url, ['host' => '172.16.0.61', 'port' => 443, 'fingerprint' => implode(':', array_fill(0, 32, 'CD'))])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fingerprint');

    $this->actingAs($manager)
        ->postJson($url, ['host' => '172.16.0.61', 'port' => 443, 'fingerprint' => $fingerprint])
        ->assertOk()
        ->assertJsonPath('trusted_fingerprint', $fingerprint);

    expect(DB::table('audit_logs')->where('action', 'security_devices.camera_certificate.trusted')->count())->toBe(1);
});

it('never sends a plain Basic password for a snapshot', function (): void {
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'cidrs' => ['172.16.0.0/24'], 'protocols' => ['http', 'https', 'tcp', 'onvif'], 'exclusions' => [], 'status' => 'active',
    ]);
    $camera = Device::factory()->create([
        'name' => 'Lounge camera', 'domain' => 'security', 'category' => 'cctv', 'manufacturer' => 'TP-Link', 'model' => 'Tapo C120',
        'ip_address' => '172.16.0.78', 'config' => ['onvif' => ['port' => 2020]],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $camera->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    CredentialReference::query()->create([
        'reference_key' => 'database:onvif/'.$this->site->id.'/cam', 'site_id' => $this->site->id, 'provider' => 'onvif',
        'purpose' => 'device_management', 'capabilities' => ['onvif', 'cctv_snapshot'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/onvif',
        'secret_manager_reference_hash' => hash('sha256', 'onvif-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinutes(5), ['username' => 'camadmin', 'password' => 'test-only-camera-password']);
        }
    });
    $this->app->forgetInstance(OnvifCameraProfileClient::class);

    $authorizations = [];
    Http::fake(function (HttpClientRequest $request) use (&$authorizations) {
        $authorizations[] = $request->header('Authorization')[0] ?? '';
        $body = $request->body();
        if (str_contains($body, 'GetProfiles')) {
            return Http::response('<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl"><s:Body><trt:GetProfilesResponse><trt:Profiles token="profile_1"/></trt:GetProfilesResponse></s:Body></s:Envelope>', 200);
        }
        if (str_contains($body, 'GetSnapshotUri')) {
            return Http::response('<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema"><s:Body><trt:GetSnapshotUriResponse><trt:MediaUri><tt:Uri>http://172.16.0.78:2020/snapshot.jpg</tt:Uri></trt:MediaUri></trt:GetSnapshotUriResponse></s:Body></s:Envelope>', 200);
        }

        // The snapshot address asks for a plain password.
        return Http::response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="camera"']);
    });

    $result = app(OnvifCameraProfileClient::class)->captureSnapshot($camera);

    expect($result['ok'])->toBeFalse()
        ->and($result['reason_code'])->toBe('basic_auth_refused')
        ->and(collect($authorizations)->filter(fn (string $value): bool => stripos($value, 'Basic') === 0)->all())->toBe([])
        ->and(json_encode($authorizations))->not->toContain(base64_encode('camadmin:test-only-camera-password'));
});
