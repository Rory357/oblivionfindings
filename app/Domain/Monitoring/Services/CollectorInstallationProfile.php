<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointBootstrap;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Support\SecurityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class CollectorInstallationProfile
{
    public function __construct(
        private CollectorEnrollmentService $enrollments,
        private SecurityDevicesAccessService $access,
    ) {}

    public function options(User $actor, int $siteId, string $platform): array
    {
        return $this->authorized($actor, $siteId, $platform, fn (User $current, Site $site): array => $this->context($current, $site, $platform));
    }

    /** The raw profile is returned once; neither tokens nor private signing keys enter audit payloads. */
    public function issue(User $actor, int $siteId, string $platform, string $expectedContext): array
    {
        return $this->authorized($actor, $siteId, $platform, function (User $current, Site $site) use ($platform, $expectedContext): array {
            $context = $this->context($current, $site, $platform);
            abort_unless($context['configured'], 503, 'Complete collector installation settings first.');
            abort_unless(hash_equals($context['context_hash'], $expectedContext), 409, 'Installation details changed. Review them again.');
            $settings = $this->settings();
            $issue = $this->enrollments->issue($site->id, $current->id, CarbonImmutable::now('UTC')->addMinutes($settings['minutes']));
            $uuid = (string) Str::uuid();
            $issue->enrollment->forceFill(['expected_collector_uuid' => $uuid])->save();

            return [
                'version' => 1, 'component' => 'site', 'platform' => $platform,
                'deployment_url' => $settings['deployment_url'], 'server_url' => $settings['server_url'],
                'server_public_key' => $settings['server_public_key'], 'tls_public_key_pin' => $settings['tls_public_key_pin'],
                'organisation' => $settings['organisation'], 'site_id' => (int) $site->id, 'site_label' => $site->name,
                'collector_uuid' => $uuid, 'enrolment_token' => $issue->plainToken,
                'expires_at' => $issue->enrollment->expires_at->toISOString(),
            ];
        });
    }

    private function authorized(User $actor, int $siteId, string $platform, callable $operation): array
    {
        abort_unless(in_array($platform, ['windows', 'linux'], true), 422);

        return DB::transaction(function () use ($actor, $siteId, $operation): array {
            $site = Site::query()->whereKey($siteId)->lockForUpdate()->firstOrFail();
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $actor->id, ['*']);
            abort_unless($current->approved_at && $current->email_verified_at && ! $current->external_clinical_account
                && $current->canDo('securityDevices.viewAny') && $current->canDo('securityDevices.integrations.manage')
                && (! SecurityPolicy::forceTwoFactor() || $current->two_factor_confirmed_at), 403);
            foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
                abort_if($current->role === $role || $current->hasRole($role), 403);
            }
            abort_unless($site->is_active && ! $site->archived, 404);
            CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($current, $site): void {
                $this->access->forCurrentEvidence($reads, 0)->assertCanViewSite($current, (int) $site->id);
            });
            $this->label($site->name);

            return $operation($current, $site);
        }, 3);
    }

    private function context(User $actor, Site $site, string $platform): array
    {
        try {
            $settings = $this->settings();
            $configured = true;
        } catch (RuntimeException) {
            $settings = null;
            $configured = false;
        }
        $context = [
            'platform' => $platform, 'site_id' => (int) $site->id, 'site_label' => $site->name,
            'deployment_url' => $settings['deployment_url'] ?? null, 'server_url' => $settings['server_url'] ?? null,
            'organisation' => $settings['organisation'] ?? null, 'enrolment_minutes' => $settings['minutes'] ?? null,
            'configured' => $configured,
        ];

        return [...$context, 'context_hash' => hash('sha256', json_encode([
            $actor->id, $site->updated_at?->toISOString(), $context, $settings,
        ], JSON_THROW_ON_ERROR))];
    }

    private function settings(): array
    {
        $deployment = EndpointBootstrap::serverOrigin((string) config('endpoint_management.server_url'));
        $origin = EndpointBootstrap::serverOrigin((string) (config('monitoring.collector.installation.server_url') ?: $deployment));
        $organisation = (string) config('endpoint_management.organisation_label', config('app.name'));
        $this->label($organisation);
        $pin = config('monitoring.collector.installation.tls_public_key_pin');
        $decodedPin = is_string($pin) && str_starts_with($pin, 'sha256//') ? base64_decode(substr($pin, 8), true) : false;
        if (! is_string($decodedPin) || strlen($decodedPin) !== 32 || base64_encode($decodedPin) !== substr($pin, 8)) {
            throw new RuntimeException('Configure the collector server TLS public-key pin.');
        }
        $encoded = config('monitoring.collector.signing_secret_key');
        $secret = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Configure the collector signing authority.');
        }
        $pair = sodium_crypto_sign_seed_keypair(substr($secret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES));
        try {
            if (! hash_equals(sodium_crypto_sign_secretkey($pair), $secret)) {
                throw new RuntimeException('Collector signing authority is invalid.');
            }
            $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        } finally {
            sodium_memzero($pair);
            sodium_memzero($secret);
        }

        return [
            'deployment_url' => $deployment, 'server_url' => $origin, 'organisation' => $organisation,
            'server_public_key' => base64_encode($public), 'tls_public_key_pin' => $pin,
            'minutes' => max(1, min(30, (int) config('monitoring.collector.installation.enrolment_minutes', 15))),
        ];
    }

    private function label(mixed $value): void
    {
        if (! is_string($value) || $value === '' || trim($value) !== $value || mb_strlen($value) > 300 || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            throw new RuntimeException('Configure a valid installation identity label.');
        }
    }
}
