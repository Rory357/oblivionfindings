<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use App\Domain\Monitoring\Models\CollectorEnrollment;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointBootstrap;
use App\Domain\SecurityDevices\Hubs\Models\SetupCode;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time setup codes (D2): one computer, 24 hours, can be cancelled.
 *
 * The code is 8 characters (shown as T6WD-3KQN) and travels in the installer's file name:
 * "Oblivion-Setup (Tōtara House) [oblivionfindings.com T6WD-3KQN].exe". On first run the
 * installer reads it from its own name (or asks for it if the file was renamed), redeems it
 * over HTTPS for its setup profile, and enrols. A hub from a leaked file still waits for an
 * admin's approval before it gets any saved login.
 */
final readonly class SetupCodeService
{
    public const LIFETIME_HOURS = 24;

    /** Crockford base32: no I, L, O or U, so a code read aloud can't be misheard. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private CollectorEnrollmentService $enrollments,
        private HubInstallationSettings $settings,
        private DeviceRegistryService $devices,
    ) {}

    /**
     * A setup code for a new hub (Add a hub, step 2), or for the computer that replaces one.
     *
     * @param  list<int>  $coveredSiteIds
     * @return array{code: SetupCode, plain: string}
     */
    public function issueHub(User $actor, Site $site, string $name, array $coveredSiteIds, string $platform, ?MonitoringCollector $replaces = null): array
    {
        if (! in_array($platform, ['windows', 'linux'], true)) {
            throw new DomainException('Choose Windows or Linux.');
        }
        $settings = $this->settings->resolve();
        HubInstallationSettings::assertLabel($site->name);
        $expiresAt = CarbonImmutable::now('UTC')->addHours(self::LIFETIME_HOURS)->subSecond();

        return DB::transaction(function () use ($actor, $site, $name, $coveredSiteIds, $platform, $replaces, $settings, $expiresAt): array {
            $issue = $this->enrollments->issue(
                siteId: (int) $site->id,
                actorId: (int) $actor->id,
                expiresAt: $expiresAt,
                intendedName: trim($name),
                intendedCoveredSiteIds: $coveredSiteIds,
            );
            $collectorUuid = (string) Str::uuid();
            $issue->enrollment->forceFill(['expected_collector_uuid' => $collectorUuid])->save();

            $plain = $this->newCode();
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
                'expires_at' => $expiresAt->toISOString(),
            ];

            $code = SetupCode::query()->create([
                'role' => 'hub',
                'platform' => $platform,
                'site_id' => (int) $site->id,
                'issued_by_user_id' => (int) $actor->id,
                'collector_enrollment_id' => (int) $issue->enrollment->id,
                'replaces_collector_id' => $replaces?->id,
                'intended_name' => trim($name),
                'code_hash' => self::hash($plain),
                'code_ciphertext' => Crypt::encryptString($plain),
                'profile_ciphertext' => Crypt::encryptString(json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'expires_at' => $expiresAt,
            ]);
            AuditLogger::logOrFail('security_devices.setup_code.issued', $code, [
                'actor_id' => (int) $actor->id,
                'site_id' => (int) $site->id,
                'role' => 'hub',
                'platform' => $platform,
                'expires_at' => $expiresAt->toISOString(),
                ...($replaces === null ? [] : ['replaces_collector_id' => (int) $replaces->id]),
            ]);

            return ['code' => $code, 'plain' => $plain];
        }, 3);
    }

    /**
     * A setup code for a staff computer at this Site (pop-up 23). "staff" installs Obli for the
     * signed-in person with no admin prompt; "full" is IT's install with the service.
     *
     * @return array{code: SetupCode, plain: string}
     */
    public function issueComputer(User $actor, Site $site, string $mode): array
    {
        if (! in_array($mode, ['staff', 'full'], true)) {
            throw new DomainException('Choose who the install is for.');
        }
        HubInstallationSettings::assertLabel($site->name);
        $expiresAt = CarbonImmutable::now('UTC')->addHours(self::LIFETIME_HOURS)->subSecond();
        $plain = $this->newCode();
        $code = SetupCode::query()->create([
            'role' => 'computer',
            'platform' => 'windows',
            'install_mode' => $mode,
            'site_id' => (int) $site->id,
            'issued_by_user_id' => (int) $actor->id,
            'code_hash' => self::hash($plain),
            'code_ciphertext' => Crypt::encryptString($plain),
            'expires_at' => $expiresAt,
        ]);
        AuditLogger::logOrFail('security_devices.setup_code.issued', $code, [
            'actor_id' => (int) $actor->id,
            'site_id' => (int) $site->id,
            'role' => 'computer',
            'install_mode' => $mode,
            'expires_at' => $expiresAt->toISOString(),
        ]);

        return ['code' => $code, 'plain' => $plain];
    }

    /**
     * The installer swaps its code for its setup profile. Refused once used, cancelled or
     * expired; the message says which, so "This installer was already used" shows on a second PC.
     * A computer code also needs the computer's name: its device record is made at that moment.
     *
     * @return array{role: string, install_mode: string|null, profile: array<string, mixed>}
     */
    public function redeem(string $rawCode, ?string $computerName = null): array
    {
        $code = self::normalise($rawCode);
        if ($code === null) {
            throw new SetupCodeRefused('unknown');
        }
        $record = SetupCode::query()->where('code_hash', self::hash($code))->first();
        if ($record?->role === 'computer') {
            return $this->redeemComputer($record, $computerName);
        }

        // The refusal is thrown after the transaction commits, so a spent code's secrets are
        // still wiped when it is refused.
        $outcome = DB::transaction(function () use ($code): array {
            $record = SetupCode::query()->where('code_hash', self::hash($code))->lockForUpdate()->first();
            if ($record === null) {
                return ['refused' => 'unknown'];
            }
            $enrollment = $record->collector_enrollment_id === null
                ? null
                : CollectorEnrollment::query()->whereKey($record->collector_enrollment_id)->first();
            if ($record->consumed_at === null && $enrollment?->consumed_at !== null) {
                $this->markUsed($record);
            }
            $state = $record->state();
            if ($state !== 'waiting' || ! is_string($record->profile_ciphertext)) {
                $this->forget($record);

                return ['refused' => $state === 'waiting' ? 'unknown' : $state];
            }
            $record->forceFill(['redeemed_at' => now(), 'redeem_count' => (int) $record->redeem_count + 1])->save();

            return ['profile' => json_decode(Crypt::decryptString($record->profile_ciphertext), true, 16, JSON_THROW_ON_ERROR)];
        }, 3);
        if (isset($outcome['refused'])) {
            throw new SetupCodeRefused($outcome['refused']);
        }

        return ['role' => 'hub', 'install_mode' => null, 'profile' => $outcome['profile']];
    }

    /**
     * A staff computer: find or make its device at the Site (by computer name), then issue the
     * computer agent's own short-lived enrolment as the person who made the download. The code
     * is spent at once; the enrolment inside the profile works once too.
     *
     * @return array{role: string, install_mode: string|null, profile: array<string, mixed>}
     */
    private function redeemComputer(SetupCode $record, ?string $computerName): array
    {
        $name = is_string($computerName) ? strtoupper(trim($computerName)) : '';
        if (preg_match('/\A[A-Z0-9][A-Z0-9-]{0,62}\z/', $name) !== 1) {
            throw new SetupCodeRefused('unknown');
        }
        $outcome = DB::transaction(function () use ($record, $name): array {
            $locked = SetupCode::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $state = $locked->state();
            if ($state !== 'waiting') {
                $this->forget($locked);

                return ['refused' => $state];
            }
            $issuer = User::query()->whereKey($locked->issued_by_user_id)->whereNotNull('approved_at')->first();
            if ($issuer === null) {
                return ['refused' => 'cancelled'];
            }
            $device = $this->computerDevice((int) $locked->site_id, $name, $issuer);
            $profile = app(EndpointBootstrap::class)->issue($issuer, $device);
            $locked->forceFill([
                'redeemed_at' => now(),
                'redeem_count' => (int) $locked->redeem_count + 1,
                'consumed_at' => now(),
                'code_ciphertext' => null,
                'profile_ciphertext' => null,
            ])->save();
            AuditLogger::logOrFail('security_devices.setup_code.used', $locked, [
                'site_id' => (int) $locked->site_id,
                'role' => 'computer',
                'device_id' => (int) $device->id,
            ]);

            return ['profile' => $profile, 'install_mode' => (string) $locked->install_mode];
        }, 3);
        if (isset($outcome['refused'])) {
            throw new SetupCodeRefused($outcome['refused']);
        }

        return ['role' => 'computer', 'install_mode' => $outcome['install_mode'], 'profile' => $outcome['profile']];
    }

    /** The Site's computer with this name and no working Obli, or a new one. */
    private function computerDevice(int $siteId, string $name, User $issuer): Device
    {
        $existing = Device::query()
            ->where('domain', 'it_infrastructure')
            ->where('category', 'endpoint')
            ->where('name', $name)
            ->whereHas('assignments', fn ($query) => $query->active()
                ->where('assignable_type', DeviceAssignment::TARGET_SITE)
                ->where('assignable_id', $siteId))
            ->whereNotIn('id', EndpointAgent::query()->whereNull('revoked_at')->whereNotNull('enrolled_at')->select('device_id'))
            ->first();

        return $existing ?? $this->devices->registerDiscoveredDevice([
            'name' => $name,
            'domain' => 'it_infrastructure',
            'category' => 'endpoint',
            'subcategory' => 'windows_computer',
            'provider' => 'oblivion_endpoint',
        ], $siteId, (int) $issuer->id);
    }

    public function cancel(SetupCode $code, User $actor): SetupCode
    {
        return DB::transaction(function () use ($code, $actor): SetupCode {
            $locked = SetupCode::query()->whereKey($code->id)->lockForUpdate()->firstOrFail();
            if ($locked->state() !== 'waiting') {
                return $locked;
            }
            $locked->forceFill([
                'cancelled_at' => now(),
                'cancelled_by_user_id' => (int) $actor->id,
                'code_ciphertext' => null,
                'profile_ciphertext' => null,
            ])->save();
            if ($locked->collector_enrollment_id !== null) {
                CollectorEnrollment::query()->whereKey($locked->collector_enrollment_id)
                    ->whereNull('consumed_at')->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }
            AuditLogger::logOrFail('security_devices.setup_code.cancelled', $locked, [
                'actor_id' => (int) $actor->id,
                'site_id' => (int) $locked->site_id,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /** The plain code, only while it can still be used (for the download's file name). */
    public function plainCode(SetupCode $code): ?string
    {
        if ($code->state() !== 'waiting' || ! is_string($code->code_ciphertext)) {
            $this->forget($code);

            return null;
        }

        return Crypt::decryptString($code->code_ciphertext);
    }

    /** "Oblivion-Setup (Tōtara House) [oblivionfindings.com T6WD-3KQN].exe" */
    public function installerFileName(SetupCode $code, string $plain): string
    {
        $site = preg_replace('/[\\\\\/:*?"<>|\p{Cc}\p{Cf}\[\]()]+/u', ' ', (string) ($code->site?->name ?? 'Site')) ?? 'Site';
        $site = trim(preg_replace('/\s+/u', ' ', $site) ?? 'Site');

        return 'Oblivion-Setup ('.($site === '' ? 'Site' : mb_substr($site, 0, 60)).') ['.$this->settings->serverHost().' '.$plain.'].exe';
    }

    /** Called when the hub that used this code enrols: the code is spent and its secrets go. */
    public function markUsedForEnrollment(CollectorEnrollment $enrollment): void
    {
        SetupCode::query()->where('collector_enrollment_id', $enrollment->id)->whereNull('consumed_at')
            ->get()->each(fn (SetupCode $code) => $this->markUsed($code));
    }

    /** XXXX-XXXX from the code alphabet; accepts lower case, spaces, and O/I/L typed for 0/1. */
    public static function normalise(string $raw): ?string
    {
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $raw) ?? '');
        $clean = strtr($clean, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (strlen($clean) !== 8 || strspn($clean, self::ALPHABET) !== 8) {
            return null;
        }

        return substr($clean, 0, 4).'-'.substr($clean, 4, 4);
    }

    private static function hash(string $code): string
    {
        return hash('sha256', 'oblivion-setup-code:'.$code);
    }

    private function newCode(): string
    {
        do {
            $chars = '';
            for ($i = 0; $i < 8; $i++) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = substr($chars, 0, 4).'-'.substr($chars, 4, 4);
        } while (SetupCode::query()->where('code_hash', self::hash($code))->exists());

        return $code;
    }

    private function markUsed(SetupCode $code): void
    {
        $code->forceFill(['consumed_at' => $code->consumed_at ?? now(), 'code_ciphertext' => null, 'profile_ciphertext' => null])->save();
    }

    /** Expired, used or cancelled codes keep no secrets. */
    private function forget(SetupCode $code): void
    {
        if ($code->code_ciphertext !== null || $code->profile_ciphertext !== null) {
            $code->forceFill(['code_ciphertext' => null, 'profile_ciphertext' => null])->save();
        }
    }
}
