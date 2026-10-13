<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CollectorCertificateIssuer;
use App\Domain\Monitoring\Data\CollectorCertificateBundle;
use App\Domain\Monitoring\Models\CollectorEnrollment;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorEnrollmentRecoveryPruner;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\Monitoring\Services\CollectorRecoverableEnrollment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Composer\Autoload\ClassLoader;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Oblivion\Collector\Enrollment\RecoverableEnrollment;
use Oblivion\Collector\Exceptions\CentralApiFailure;

final class RecoverableCollectorCertificateFixture implements CollectorCertificateIssuer
{
    public int $calls = 0;

    public function issue(string $collectorUuid): CollectorCertificateBundle
    {
        $this->calls++;

        return new CollectorCertificateBundle(
            "fixture certificate for {$collectorUuid}", 'fixture-private-certificate-key',
            hash('sha256', $collectorUuid.'-'.$this->calls), CarbonImmutable::now('UTC')->addDay(),
        );
    }
}

beforeEach(function () {
    $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    $this->recoverySite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->recoveryActor = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
    $this->recoveryActor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    $this->recoveryActor->permissionOverrides()->syncWithoutDetaching(
        Permission::query()->whereIn('key', ['securityDevices.viewAny', 'securityDevices.integrations.manage'])
            ->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
    );
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->recoveryActor->id, 'primary_site_id' => $this->recoverySite->id,
        'secondary_site_ids' => [], 'is_active' => true,
    ]);
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey(
        sodium_crypto_sign_seed_keypair(str_repeat('C', 32)),
    )));
    $this->recoveryCertificateIssuer = new RecoverableCollectorCertificateFixture;
    app()->instance(CollectorCertificateIssuer::class, $this->recoveryCertificateIssuer);
    $this->recoveryIssue = app(CollectorEnrollmentService::class)->issue($this->recoverySite->id, $this->recoveryActor->id);
    $this->recoveryUuid = '018f0000-0000-7000-8000-000000000091';
    $this->recoveryPair = sodium_crypto_sign_seed_keypair(str_repeat('R', 32));
    $this->recoveryBody = recoverableCollectorBody($this->recoveryIssue->plainToken, $this->recoveryUuid, $this->recoveryPair);
});

function recoverableCollectorBody(string $token, string $uuid, string $pair): array
{
    $public = sodium_crypto_sign_publickey($pair);

    return [
        'collector_id' => $uuid, 'collector_public_key' => base64_encode($public),
        'proof' => base64_encode(sodium_crypto_sign_detached(
            CollectorRecoverableEnrollment::proofMessage($token, $uuid, $public), sodium_crypto_sign_secretkey($pair),
        )),
    ];
}

function openRecoverableCollectorFixture(array $response, string $pair): array
{
    $public = sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey($pair));
    $secret = sodium_crypto_sign_ed25519_sk_to_curve25519(sodium_crypto_sign_secretkey($pair));
    $box = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $public);
    $plaintext = sodium_crypto_box_seal_open(base64_decode($response['sealed_identity'], true), $box);
    if (! is_string($plaintext)) {
        throw new RuntimeException('Fixture response could not be decrypted.');
    }

    return json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
}

it('retries the exact encrypted identity without a second certificate collector or token consumption', function () {
    $url = 'https://localhost/api/monitoring/collectors/enrol/recoverable';
    $first = $this->withToken($this->recoveryIssue->plainToken)->postJson($url, $this->recoveryBody)->assertCreated();
    $second = $this->postJson($url, $this->recoveryBody)->assertCreated();
    expect($second->json())->toBe($first->json())
        ->and($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and($first->getContent())->not->toContain($this->recoveryIssue->plainToken, 'fixture-private-certificate-key', 'client_private_key')
        ->and($this->recoveryCertificateIssuer->calls)->toBe(1)
        ->and(MonitoringCollector::query()->count())->toBe(1);
    $payload = openRecoverableCollectorFixture($first->json(), $this->recoveryPair);
    expect($payload['version'])->toBe(2)
        ->and($payload['collector_id'])->toBe($this->recoveryUuid)
        ->and($payload['site_id'])->toBe($this->recoverySite->id)
        ->and($payload['client_private_key'])->toBe('fixture-private-certificate-key');
    $enrollment = $this->recoveryIssue->enrollment->fresh();
    expect($enrollment->consumed_at)->not->toBeNull()
        ->and($enrollment->recovery_response)->toBe($first->json('sealed_identity'))
        ->and($enrollment->toArray())->not->toHaveKeys(['token_hash', 'recovery_response', 'recovery_identity_fingerprint']);
});

it('requires proof by the original key and refuses another key or collector after consumption', function () {
    $url = 'https://localhost/api/monitoring/collectors/enrol/recoverable';
    $this->withToken($this->recoveryIssue->plainToken)->postJson($url, [...$this->recoveryBody, 'proof' => base64_encode(str_repeat('x', 64))])->assertUnprocessable();
    expect($this->recoveryCertificateIssuer->calls)->toBe(0);
    $this->postJson($url, $this->recoveryBody)->assertCreated();
    $other = sodium_crypto_sign_keypair();
    $this->postJson($url, recoverableCollectorBody($this->recoveryIssue->plainToken, $this->recoveryUuid, $other))->assertUnprocessable();
    $this->postJson($url, recoverableCollectorBody($this->recoveryIssue->plainToken, '018f0000-0000-7000-8000-000000000092', $this->recoveryPair))->assertUnprocessable();
    expect($this->recoveryCertificateIssuer->calls)->toBe(1);
});

it('rechecks expiry revocation certificate generation and central authority on recovery', function (string $change) {
    $url = 'https://localhost/api/monitoring/collectors/enrol/recoverable';
    $this->withToken($this->recoveryIssue->plainToken)->postJson($url, $this->recoveryBody)->assertCreated();
    $collector = MonitoringCollector::query()->sole();
    match ($change) {
        'expiry' => CollectorEnrollment::query()->whereKey($this->recoveryIssue->enrollment->id)->update(['expires_at' => now()->subSecond()]),
        'token revoked' => CollectorEnrollment::query()->whereKey($this->recoveryIssue->enrollment->id)->update(['revoked_at' => now()]),
        'collector revoked' => $collector->update(['revoked_at' => now(), 'status' => 'revoked']),
        'certificate changed' => $collector->update(['client_certificate_fingerprint' => str_repeat('b', 64)]),
        'central key changed' => config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))),
    };
    $this->postJson($url, $this->recoveryBody)->assertUnprocessable();
    expect($this->recoveryCertificateIssuer->calls)->toBe(1);
})->with(['expiry', 'token revoked', 'collector revoked', 'certificate changed', 'central key changed']);

it('uses current issuer permissions approval and Site assignment for recovery', function (string $change, int $status) {
    $url = 'https://localhost/api/monitoring/collectors/enrol/recoverable';
    $this->withToken($this->recoveryIssue->plainToken)->postJson($url, $this->recoveryBody)->assertCreated();
    $collector = MonitoringCollector::query()->sole();
    match ($change) {
        'permission removed' => $this->recoveryActor->permissionOverrides()->updateExistingPivot(
            Permission::query()->where('key', 'securityDevices.integrations.manage')->value('id'), ['allowed' => false],
        ),
        'approval removed' => User::query()->whereKey($this->recoveryActor->id)->update(['approved_at' => null]),
        'staff Site removed' => HrEmployeeProfile::query()->where('user_id', $this->recoveryActor->id)->update(['is_active' => false]),
        'Site inactive' => $this->recoverySite->update(['is_active' => false]),
        'device assignment released' => $collector->collectorDevice->assignments()->update(['released_at' => now()]),
    };
    $this->postJson($url, $this->recoveryBody)->assertStatus($status);
    expect($this->recoveryCertificateIssuer->calls)->toBe(1);
})->with([
    ['permission removed', 422], ['approval removed', 422], ['staff Site removed', 404],
    ['Site inactive', 422], ['device assignment released', 422],
]);

it('refuses insecure ambiguous or oversized recovery requests before changing enrolment', function () {
    $url = 'https://localhost/api/monitoring/collectors/enrol/recoverable';
    $this->withToken($this->recoveryIssue->plainToken)->postJson(str_replace('https:', 'http:', $url), $this->recoveryBody)->assertStatus(400);
    $this->postJson($url, [...$this->recoveryBody, 'site_id' => $this->recoverySite->id])->assertUnprocessable();
    $this->postJson($url, [...$this->recoveryBody, 'proof' => str_repeat('x', 20_000)])->assertUnprocessable();
    $raw = json_encode($this->recoveryBody, JSON_THROW_ON_ERROR);
    $raw = str_replace('"collector_id":', '"collector_id":"duplicate","collector_id":', $raw);
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->recoveryIssue->plainToken], $raw)->assertUnprocessable();
    expect($this->recoveryCertificateIssuer->calls)->toBe(0)
        ->and($this->recoveryIssue->enrollment->fresh()->consumed_at)->toBeNull();
});

it('does not convert an already consumed legacy enrolment into a recovery grant', function () {
    app(CollectorEnrollmentService::class)->enrol($this->recoveryIssue->plainToken, $this->recoveryUuid, sodium_crypto_sign_publickey($this->recoveryPair));
    $this->withToken($this->recoveryIssue->plainToken)
        ->postJson('https://localhost/api/monitoring/collectors/enrol/recoverable', $this->recoveryBody)->assertUnprocessable();
    expect($this->recoveryIssue->enrollment->fresh()->recovery_response)->toBeNull()
        ->and($this->recoveryCertificateIssuer->calls)->toBe(1);
});

it('recovers a lost controller reply through the real collector client with a valid certificate', function () {
    require_once base_path('collector/tests/Pest.php');
    $loader = new ClassLoader;
    $loader->addPsr4('Oblivion\\Collector\\', base_path('collector/src'));
    $loader->register();
    $root = collectorTempDirectory('laravel-recovery-contract');
    $issuer = new class implements CollectorCertificateIssuer
    {
        public int $calls = 0;

        public function issue(string $collectorUuid): CollectorCertificateBundle
        {
            $this->calls++;
            $certificate = collectorCertificatePair('oblivion-collector-'.$collectorUuid);
            $parsed = openssl_x509_parse($certificate['certificate']);

            return new CollectorCertificateBundle(
                $certificate['certificate'], $certificate['private_key'],
                strtolower(openssl_x509_fingerprint($certificate['certificate'], 'sha256')),
                CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']),
            );
        }
    };
    app()->instance(CollectorCertificateIssuer::class, $issuer);
    $replies = [];
    $client = new RecoverableEnrollment(
        function ($origin, $_pin, $token, $uuid, $public, $proof) use (&$replies): array {
            $response = $this->withToken($token)->postJson($origin.'/api/monitoring/collectors/enrol/recoverable', [
                'collector_id' => $uuid, 'collector_public_key' => base64_encode($public), 'proof' => base64_encode($proof),
            ])->assertCreated();
            $replies[] = $response->json();
            if (count($replies) === 1) {
                throw new CentralApiFailure('Test reply lost after commit.');
            }

            return $response->json();
        },
    );
    $args = [$root.'/collector.identity.json', $this->recoveryUuid, $this->recoverySite->id,
        'https://localhost', 'sha256//'.base64_encode(str_repeat('P', 32)), $root, $this->recoveryIssue->plainToken];
    try {
        expect(fn () => $client->enrol(...$args))->toThrow(CentralApiFailure::class);
        $client->enrol(...$args);
        $identity = json_decode(file_get_contents($args[0]), true, 16, JSON_THROW_ON_ERROR);
        $collector = MonitoringCollector::query()->sole();
        expect($replies)->toHaveCount(2)->and($replies[0])->toBe($replies[1])
            ->and($issuer->calls)->toBe(1)
            ->and($identity['collector_id'])->toBe($collector->collector_uuid)
            ->and($identity['site_id'])->toBe($this->recoverySite->id)
            ->and($identity['client_certificate_fingerprint'])->toBe($collector->client_certificate_fingerprint)
            ->and(base64_encode(sodium_crypto_sign_publickey_from_secretkey(base64_decode($identity['request_signing_secret_key'], true))))->toBe($collector->public_key)
            ->and(file_get_contents($args[0]))->not->toContain($this->recoveryIssue->plainToken)
            ->and($root.'/enrolment.pending.json')->not->toBeFile();
        $client->enrol(...$args);
        expect($replies)->toHaveCount(2);
    } finally {
        removeCollectorDirectory($root);
        $loader->unregister();
    }
});

it('retains consumed replies for lost-reply retries and erases them exactly at expiry', function () {
    $url = 'https://localhost/api/monitoring/collectors/enrol/recoverable';
    $first = $this->withToken($this->recoveryIssue->plainToken)->postJson($url, $this->recoveryBody)->assertCreated();
    $pruner = app(CollectorEnrollmentRecoveryPruner::class);
    expect($pruner->prune())->toBe(0);
    $this->postJson($url, $this->recoveryBody)->assertCreated()->assertExactJson($first->json());
    $collectorBefore = MonitoringCollector::query()->sole()->getAttributes();
    $enrollment = $this->recoveryIssue->enrollment->fresh();
    $enrollment->update(['expires_at' => now()->startOfSecond()]);
    $before = $enrollment->fresh()->getAttributes();
    expect($pruner->prune())->toBe(1)->and($pruner->prune())->toBe(0);
    $after = $enrollment->fresh()->getAttributes();
    foreach ($before as $key => $value) {
        if (! in_array($key, ['recovery_response', 'recovery_identity_fingerprint', 'updated_at'], true)) {
            expect($after[$key])->toBe($value);
        }
    }
    expect($after['recovery_response'])->toBeNull()->and($after['recovery_identity_fingerprint'])->toBeNull()
        ->and(MonitoringCollector::query()->sole()->getAttributes())->toBe($collectorBefore);
    $this->postJson($url, $this->recoveryBody)->assertUnprocessable();
    expect($this->recoveryCertificateIssuer->calls)->toBe(1);
});

it('bounds recovery cleanup and clears orphaned fingerprints without deleting history', function () {
    $rows = collect(range(1, 3))->map(fn () => CollectorEnrollment::query()->create([
        'site_id' => $this->recoverySite->id, 'issued_by_user_id' => $this->recoveryActor->id,
        'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->subMinute(),
    ]));
    foreach ($rows as $row) {
        $row->forceFill(['recovery_identity_fingerprint' => str_repeat('a', 64)])->save();
    }
    $this->artisan('monitoring:prune-enrolment-recovery', ['--batch-size' => 1, '--max-batches' => 2])
        ->expectsOutput('Expired collector recovery responses erased: 2')->assertSuccessful();
    expect(CollectorEnrollment::query()->whereNotNull('recovery_identity_fingerprint')->count())->toBe(1)
        ->and(CollectorEnrollment::query()->count())->toBe(4);
    $this->artisan('monitoring:prune-enrolment-recovery', ['--batch-size' => 0])->assertExitCode(2);
});

it('rechecks expiry in the cleanup write when a selected row becomes recoverable again', function () {
    $enrollment = $this->recoveryIssue->enrollment;
    $enrollment->forceFill(['expires_at' => now()->subMinute(), 'recovery_response' => 'sealed fixture',
        'recovery_identity_fingerprint' => str_repeat('a', 64)])->save();
    $extended = false;
    DB::listen(function ($query) use ($enrollment, &$extended) {
        if (! $extended && str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'monitoring_collector_enrollments')
            && str_contains($query->sql, 'recovery_response')) {
            $extended = true;
            CollectorEnrollment::query()->whereKey($enrollment->id)->update(['expires_at' => now()->addHour()]);
        }
    });
    expect(app(CollectorEnrollmentRecoveryPruner::class)->prune())->toBe(0)
        ->and($extended)->toBeTrue()->and($enrollment->fresh()->recovery_response)->toBe('sealed fixture');
});
