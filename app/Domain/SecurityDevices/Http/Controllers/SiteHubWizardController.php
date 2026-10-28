<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\CollectorEnrollment;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointBootstrap;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseUnavailable;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Support\SecurityPolicy;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SiteHubWizardController extends Controller
{
    public function __construct(
        private readonly CollectorEnrollmentService $enrollments,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $this->assertTransport($request);
        $actor = $this->actor($request);

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'min:1'],
            'platform' => ['nullable', 'in:windows,linux'],
        ]);
        $platform = (string) ($validated['platform'] ?? 'windows');

        if (isset($validated['site_id'])) {
            $this->access->assertCanViewSite($actor, (int) $validated['site_id']);
        }

        $accessibleSiteIds = $this->access->accessibleSiteIds($actor);
        $accessibleSites = $accessibleSiteIds === []
            ? []
            : Site::query()
                ->whereIn('id', $accessibleSiteIds)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (Site $site): array => [
                    'id' => (int) $site->id,
                    'name' => (string) $site->name,
                ])
                ->values()
                ->all();

        $profileMissingReason = null;
        try {
            $settings = $this->profileSettings();
            $profileConfigured = true;
        } catch (RuntimeException $exception) {
            $settings = null;
            $profileConfigured = false;
            $profileMissingReason = $exception->getMessage();
        }

        try {
            $catalogue = EndpointReleaseCatalogue::configured()->catalogue();
            $catalogueAvailable = true;
            $siteArtifacts = array_values(array_filter(
                $catalogue['artifacts'],
                fn (array $item): bool => in_array('site', $item['roles'], true) && $this->allowedArtifact($actor, $item),
            ));
        } catch (EndpointReleaseUnavailable) {
            $catalogue = null;
            $catalogueAvailable = false;
            $siteArtifacts = [];
        }

        $mappedArtifacts = array_map(static fn (array $item): array => [
            ...$item,
            'title' => $item['platform'] === 'windows'
                ? 'Obli & Site Hub Setup'
                : 'Oblivion Site Hub Service',
            'size_bytes' => (int) $item['size'],
            'download_url' => '/security-devices/agent-downloads/'.$item['id'].'/'.$item['sha256'],
        ], $siteArtifacts);

        $downloadsByPlatform = [
            'windows' => array_values(array_filter($mappedArtifacts, static fn (array $item): bool => $item['platform'] === 'windows')),
            'linux' => array_values(array_filter($mappedArtifacts, static fn (array $item): bool => $item['platform'] === 'linux')),
        ];

        return response()->json([
            'platform' => $platform,
            'profile_configured' => $profileConfigured,
            'profile_missing_reason' => $profileMissingReason,
            'catalogue_available' => $catalogueAvailable,
            'catalogue_expires_at' => $catalogue['expires_at'] ?? null,
            'deployment_url' => $settings['deployment_url'] ?? null,
            'server_url' => $settings['server_url'] ?? null,
            'organisation' => $settings['organisation'] ?? (string) config('endpoint_management.organisation_label', config('app.name')),
            'enrolment_minutes' => $settings['minutes'] ?? max(1, min(30, (int) config('monitoring.collector.installation.enrolment_minutes', 15))),
            'accessible_sites' => $accessibleSites,
            'downloads' => $downloadsByPlatform[$platform] ?? [],
            'downloads_by_platform' => $downloadsByPlatform,
        ], 200, $this->sensitiveHeaders());
    }

    public function issue(Request $request): JsonResponse
    {
        $this->assertTransport($request);
        $actor = $this->actor($request);

        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'platform' => ['required', 'in:windows,linux'],
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'covered_site_ids' => ['sometimes', 'array', 'max:64'],
            'covered_site_ids.*' => ['integer', 'min:1'],
        ]);

        $siteId = (int) $validated['site_id'];
        $platform = (string) $validated['platform'];
        $name = trim((string) $validated['name']);
        $coveredSiteIds = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $validated['covered_site_ids'] ?? [],
        )));

        $this->access->assertCanViewSite($actor, $siteId);
        foreach ($coveredSiteIds as $coveredSiteId) {
            $this->access->assertCanViewSite($actor, (int) $coveredSiteId);
        }

        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->firstOrFail();

        $profileMissingReason = null;
        try {
            $settings = $this->profileSettings();
            $this->assertLabel($site->name);
        } catch (RuntimeException $exception) {
            $settings = null;
            $profileMissingReason = $exception->getMessage();
        }

        $minutes = $settings['minutes'] ?? max(1, min(30, (int) config('monitoring.collector.installation.enrolment_minutes', 15)));
        try {
            $issue = $this->enrollments->issue(
                siteId: (int) $site->id,
                actorId: (int) $actor->id,
                expiresAt: CarbonImmutable::now('UTC')->addMinutes($minutes),
                intendedName: $name,
                intendedCoveredSiteIds: $coveredSiteIds,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'site_id' => $exception->getMessage(),
            ]);
        }

        $profile = null;
        $profileFilename = null;
        $collectorUuid = null;
        $setupCode = $issue->plainToken;
        $linuxCommand = null;

        if ($settings !== null) {
            $collectorUuid = (string) Str::uuid();
            $issue->enrollment->forceFill(['expected_collector_uuid' => $collectorUuid])->save();
            $profile = [
                'version' => 1,
                'component' => 'site',
                'platform' => $platform,
                'deployment_url' => $settings['deployment_url'],
                'server_url' => $settings['server_url'],
                'server_public_key' => $settings['server_public_key'],
                'tls_public_key_pin' => $settings['tls_public_key_pin'],
                'organisation' => $settings['organisation'],
                'site_id' => (int) $site->id,
                'site_label' => (string) $site->name,
                'collector_uuid' => $collectorUuid,
                'enrolment_token' => $issue->plainToken,
                'expires_at' => $issue->enrollment->expires_at->toISOString(),
            ];
            $encodedJson = json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $setupCode = 'ofh1_'.rtrim(strtr(base64_encode($encodedJson), '+/', '-_'), '=');
            $profileFilename = 'oblivion-collector-'.$collectorUuid.'.json';
            $linuxCommand = "sudo bash install.sh --code '".$setupCode."'";
        }

        return response()->json([
            'enrollment_id' => (int) $issue->enrollment->id,
            'expires_at' => $issue->enrollment->expires_at->toIso8601String(),
            'profile_configured' => $settings !== null,
            'profile_missing_reason' => $profileMissingReason,
            'setup_code' => $setupCode,
            'enrolment_token' => $issue->plainToken,
            'collector_uuid' => $collectorUuid,
            'profile' => $profile,
            'profile_filename' => $profileFilename,
            'linux_command' => $linuxCommand,
        ], 201, $this->sensitiveHeaders());
    }

    public function status(Request $request, int $enrollment): JsonResponse
    {
        $this->assertTransport($request);
        $actor = $this->actor($request);

        $record = CollectorEnrollment::query()
            ->with('consumedCollector')
            ->whereKey($enrollment)
            ->where('issued_by_user_id', (int) $actor->id)
            ->firstOrFail();

        $this->access->assertCanViewSite($actor, (int) $record->site_id);

        $collector = $record->consumedCollector;
        if ($record->revoked_at !== null || ($collector !== null && $collector->revoked_at !== null)) {
            $state = 'revoked';
        } elseif ($record->consumed_at !== null && $collector !== null) {
            $hasHeartbeat = $collector->last_heartbeat_at !== null
                || $collector->last_seen_at !== null
                || strtolower((string) $collector->status) === 'online';
            $state = $hasHeartbeat ? 'connected' : 'enrolled';
        } elseif ($record->expires_at !== null && $record->expires_at->lte(CarbonImmutable::now('UTC'))) {
            $state = 'expired';
        } else {
            $state = 'waiting';
        }

        return response()->json([
            'enrollment_id' => (int) $record->id,
            'state' => $state,
            'expires_at' => $record->expires_at?->toIso8601String(),
            'consumed_at' => $record->consumed_at?->toIso8601String(),
            'collector' => $collector === null ? null : [
                'id' => (int) $collector->id,
                'name' => (string) $collector->name,
                'status' => strtolower((string) $collector->status),
                'software_version' => $collector->softwareVersion(),
                'last_heartbeat_at' => ($collector->last_heartbeat_at ?? $collector->last_seen_at)?->toIso8601String(),
                'covered_site_ids' => $collector->coveredSiteIds(),
            ],
        ], 200, $this->sensitiveHeaders());
    }

    private function assertTransport(Request $request): void
    {
        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 400);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless(
            $actor !== null
            && $actor->approved_at !== null
            && $actor->email_verified_at !== null
            && ! $actor->external_clinical_account
            && $actor->canDo('securityDevices.viewAny')
            && $actor->canDo('securityDevices.integrations.manage')
            && (! SecurityPolicy::forceTwoFactor() || $actor->two_factor_confirmed_at !== null),
            403,
        );
        foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
            abort_if($actor->role === $role || $actor->hasRole($role), 403);
        }

        return $actor;
    }

    private function allowedArtifact(User $actor, array $artifact): bool
    {
        return (! in_array('computer', $artifact['roles'], true) || $actor->canDo('securityDevices.commands.admin'))
            && (! in_array('site', $artifact['roles'], true) || $actor->canDo('securityDevices.integrations.manage'));
    }

    /**
     * @return array{
     *     deployment_url: string,
     *     server_url: string,
     *     organisation: string,
     *     server_public_key: string,
     *     tls_public_key_pin: string,
     *     minutes: int
     * }
     */
    private function profileSettings(): array
    {
        $deployment = EndpointBootstrap::serverOrigin((string) config('endpoint_management.server_url'));
        $origin = EndpointBootstrap::serverOrigin((string) (config('monitoring.collector.installation.server_url') ?: $deployment));
        $organisation = (string) config('endpoint_management.organisation_label', config('app.name'));
        $this->assertLabel($organisation);

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
            'deployment_url' => $deployment,
            'server_url' => $origin,
            'organisation' => $organisation,
            'server_public_key' => base64_encode($public),
            'tls_public_key_pin' => $pin,
            'minutes' => max(1, min(30, (int) config('monitoring.collector.installation.enrolment_minutes', 15))),
        ];
    }

    private function assertLabel(mixed $value): void
    {
        if (! is_string($value) || $value === '' || trim($value) !== $value || mb_strlen($value) > 300 || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            throw new RuntimeException('Configure a valid installation identity label.');
        }
    }

    /** @return array<string, string> */
    private function sensitiveHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
