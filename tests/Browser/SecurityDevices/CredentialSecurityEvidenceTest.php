<?php

use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceRules;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

test('credential lifecycle remains understandable and non-revealing on desktop', function () {
    $viewer = User::query()->where('email', 'admin@test.com')->firstOrFail();
    $site = Site::query()->where('name', 'QA Main Site')->firstOrFail();
    $run = Str::lower((string) Str::uuid());
    $referenceKey = 'vault:m05/site-'.$site->id.'/'.$run;
    $externalReference = 'secret/data/m05/'.$run.'/external-path-sentinel';
    $rules = app(CredentialReferenceRules::class);
    $reference = CredentialReference::query()->create([
        'reference_key' => $referenceKey,
        'site_id' => $site->id,
        'provider' => 'unifi',
        'purpose' => 'device_management',
        'capabilities' => ['command:network.device.reboot'],
        'secret_manager_reference' => $externalReference,
        'secret_manager_reference_hash' => $rules->fingerprint($externalReference),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 3,
        'last_tested_at' => now(),
        'last_rotated_at' => now()->subMinute(),
    ]);
    foreach ([
        ['status' => CredentialLeaseGrant::STATUS_ISSUED, 'suffix' => 'active'],
        ['status' => CredentialLeaseGrant::STATUS_REVOKE_PENDING, 'suffix' => 'pending'],
    ] as $lease) {
        $leaseId = "m05-browser-lease-{$lease['suffix']}-{$run}";
        CredentialLeaseGrant::query()->create([
            'credential_reference_id' => $reference->id,
            'reference_version' => 2,
            'site_id' => $site->id,
            'lease_id' => $leaseId,
            'lease_fingerprint' => $rules->fingerprint($leaseId),
            'capabilities' => ['command:network.device.reboot'],
            'status' => $lease['status'],
            'issued_at' => now()->subSeconds(10),
            'expires_at' => now()->addMinute(),
        ]);
    }

    $this->browse(function (Browser $browser) use ($viewer, $referenceKey, $externalReference, $run): void {
        $browser->loginAs($viewer);

        foreach ([
            ['width' => 1440, 'height' => 900],
            ['width' => 1280, 'height' => 800],
        ] as $viewport) {
            $browser->resize($viewport['width'], $viewport['height'])
                ->visit('/security-devices/settings')
                ->waitForText('Credential references', 40)
                ->assertSee($referenceKey)
                ->assertSee('1 short-lived lease active')
                ->assertSee('1 revocation pending')
                ->assertSee('Test passed')
                ->assertSee('Rotation current')
                ->assertDontSee($externalReference)
                ->assertDontSee("m05-browser-lease-active-{$run}")
                ->assertDontSee("m05-browser-lease-pending-{$run}")
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );
        }

        $severeLogs = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
            ->values()
            ->all();

        $this->assertSame([], $severeLogs, json_encode($severeLogs));
    });
});
