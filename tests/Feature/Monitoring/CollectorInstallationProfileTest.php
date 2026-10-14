<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\CollectorEnrollment;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\Monitoring\Services\CollectorInstallationProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    $this->profileSite = Site::factory()->create(['name' => 'Main office', 'is_active' => true, 'archived' => false]);
    $this->profileActor = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
    $this->profileActor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    $this->profileActor->permissionOverrides()->syncWithoutDetaching(
        Permission::query()->whereIn('key', ['securityDevices.viewAny', 'securityDevices.integrations.manage'])
            ->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
    );
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->profileActor->id, 'primary_site_id' => $this->profileSite->id,
        'secondary_site_ids' => [], 'is_active' => true,
    ]);
    $this->profilePair = sodium_crypto_sign_seed_keypair(str_repeat('C', 32));
    config()->set([
        'endpoint_management.server_url' => 'https://care.example.test',
        'endpoint_management.organisation_label' => 'Example Care',
        'monitoring.collector.installation.server_url' => 'https://collectors.example.test:8443',
        'monitoring.collector.installation.tls_public_key_pin' => 'sha256//'.base64_encode(str_repeat('P', 32)),
        'monitoring.collector.signing_secret_key' => base64_encode(sodium_crypto_sign_secretkey($this->profilePair)),
    ]);
    $this->profileUrl = 'https://localhost/security-devices/discovery/collectors';
});

it('downloads a Site-bound deployment profile without exposing private signing authority', function (string $platform) {
    $body = ['site_id' => $this->profileSite->id, 'platform' => $platform];
    $review = $this->actingAs($this->profileActor)->getJson($this->profileUrl.'/installation-options?'.http_build_query($body))->assertOk();
    expect($review->json('configured'))->toBeTrue()
        ->and($review->headers->get('Cache-Control'))->toContain('no-store');
    $reply = $this->postJson($this->profileUrl.'/installation-profile', [...$body, 'context_hash' => $review->json('context_hash')])->assertCreated();
    $p = $reply->json();
    expect($p)->toHaveCount(13)
        ->and($p['platform'])->toBe($platform)
        ->and($p['deployment_url'])->toBe('https://care.example.test')
        ->and($p['server_url'])->toBe('https://collectors.example.test:8443')
        ->and($p['server_public_key'])->toBe(base64_encode(sodium_crypto_sign_publickey($this->profilePair)))
        ->and($p['site_id'])->toBe($this->profileSite->id)
        ->and($reply->getContent())->not->toContain(base64_encode(sodium_crypto_sign_secretkey($this->profilePair)))
        ->and($reply->headers->get('Cache-Control'))->toContain('no-store')
        ->and($reply->headers->get('Content-Disposition'))->toContain('attachment;', $p['collector_uuid']);
    $grant = CollectorEnrollment::query()->sole();
    expect($grant->expected_collector_uuid)->toBe($p['collector_uuid'])
        ->and($grant->token_hash)->toBe(hash('sha256', $p['enrolment_token']))
        ->and($grant->expires_at->diffInSeconds(now(), absolute: true))->toBeLessThanOrEqual(901)
        ->and($grant->issued_by_user_id)->toBe($this->profileActor->id);
    // The old enrolment entry point cannot steal a profile's grant for another UUID.
    expect(fn () => app(CollectorEnrollmentService::class)->enrol(
        $p['enrolment_token'], '018f0000-0000-7000-8000-000000000092', sodium_crypto_sign_publickey($this->profilePair),
    ))->toThrow(DomainException::class, 'does not match');
    expect($grant->fresh()->consumed_at)->toBeNull();
})->with(['windows', 'linux']);

it('refuses changed review details without issuing a grant', function (string $change) {
    $service = app(CollectorInstallationProfile::class);
    $review = $service->options($this->profileActor, $this->profileSite->id, 'windows');
    match ($change) {
        'site label' => $this->profileSite->update(['name' => 'Another office']),
        'transport' => config()->set('monitoring.collector.installation.server_url', 'https://new.example.test'),
        'pin' => config()->set('monitoring.collector.installation.tls_public_key_pin', 'sha256//'.base64_encode(str_repeat('Q', 32))),
        'key' => config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))),
    };
    try {
        $service->issue($this->profileActor, $this->profileSite->id, 'windows', $review['context_hash']);
        $this->fail('Stale review issued a profile.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    expect(CollectorEnrollment::query()->count())->toBe(0);
})->with(['site label', 'transport', 'pin', 'key']);

it('rechecks current actor authority and Site access even with an earlier valid review', function (string $change, int $status) {
    $service = app(CollectorInstallationProfile::class);
    $review = $service->options($this->profileActor, $this->profileSite->id, 'windows');
    match ($change) {
        'permission' => $this->profileActor->permissionOverrides()->updateExistingPivot(
            Permission::query()->where('key', 'securityDevices.integrations.manage')->value('id'), ['allowed' => false]),
        'approval' => User::query()->whereKey($this->profileActor->id)->update(['approved_at' => null]),
        'site assignment' => HrEmployeeProfile::query()->where('user_id', $this->profileActor->id)->update(['is_active' => false]),
        'site archived' => $this->profileSite->update(['archived' => true]),
    };
    try {
        $service->issue($this->profileActor, $this->profileSite->id, 'windows', $review['context_hash']);
        $this->fail('Unavailable authority issued a profile.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe($status);
    }
    expect(CollectorEnrollment::query()->count())->toBe(0);
})->with([['permission', 403], ['approval', 403], ['site assignment', 404], ['site archived', 404]]);

it('refuses unconfigured authority and cannot take the destination from the request', function () {
    $service = app(CollectorInstallationProfile::class);
    $review = $service->options($this->profileActor, $this->profileSite->id, 'windows');
    $body = ['site_id' => $this->profileSite->id, 'platform' => 'windows', 'context_hash' => $review['context_hash']];
    $this->actingAs($this->profileActor)->postJson($this->profileUrl.'/installation-profile', [...$body, 'server_url' => 'https://attacker.test'])->assertUnprocessable();
    $this->postJson(str_replace('https:', 'http:', $this->profileUrl).'/installation-profile', $body)->assertStatus(400);
    config()->set('monitoring.collector.installation.tls_public_key_pin', '');
    expect($service->options($this->profileActor, $this->profileSite->id, 'windows')['configured'])->toBeFalse();
    $this->postJson($this->profileUrl.'/installation-profile', $body)->assertStatus(503);
    expect(CollectorEnrollment::query()->count())->toBe(0);
});

it('denies unrelated Sites and unsupported computer-agent platforms', function () {
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->actingAs($this->profileActor)->getJson($this->profileUrl.'/installation-options?site_id='.$other->id.'&platform=windows')->assertNotFound();
    $this->getJson($this->profileUrl.'/installation-options?site_id='.$this->profileSite->id.'&platform=android')->assertUnprocessable();
    expect(CollectorEnrollment::query()->count())->toBe(0);
});
