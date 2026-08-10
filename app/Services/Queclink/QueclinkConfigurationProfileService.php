<?php

namespace App\Services\Queclink;

use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class QueclinkConfigurationProfileService
{
    /** @var array<string, string> */
    private const SECTION_CODES = [
        'server' => 'SRI', 'sri' => 'SRI',
        'tracking' => 'CFG', 'global' => 'CFG', 'cfg' => 'CFG', 'cfg_alarm' => 'CFG',
        'pin' => 'PIN', 'dog' => 'DOG',
        'time' => 'TMA', 'tma' => 'TMA',
        'non_movement' => 'NMD', 'nmd' => 'NMD',
        'power' => 'PDS', 'pds' => 'PDS',
        'wifi' => 'WFI', 'wfi' => 'WFI',
        'geo' => 'GEO',
        'bluetooth' => 'BTS', 'bt' => 'BTS', 'bts' => 'BTS',
        'beacons' => 'BID', 'bid' => 'BID',
        'allowlist' => 'WLT', 'wlt' => 'WLT',
        'firmware_update' => 'UPC', 'upc' => 'UPC',
        'firmware_version' => 'FVR', 'fvr' => 'FVR',
    ];

    /** @var array<string, string> */
    private const SUMMARY_KEYS = [
        'SRI' => 'server', 'CFG' => 'global', 'PIN' => 'pin', 'DOG' => 'dog',
        'TMA' => 'time', 'NMD' => 'non_movement', 'PDS' => 'power', 'WFI' => 'wifi',
        'GEO' => 'geofences', 'BTS' => 'bluetooth', 'BID' => 'beacons', 'WLT' => 'allowlist',
        'UPC' => 'firmware_update', 'FVR' => 'firmware_version',
    ];

    public function __construct(private readonly CommandBuilder $commands) {}

    /** @return Collection<int, DeviceConfigurationProfile> */
    public function compatibleProfiles(Device $device): Collection
    {
        return DeviceConfigurationProfile::query()
            ->active()
            ->where(function ($query): void {
                $query->where('profile_key', 'like', 'queclink:device-%:draft:%')
                    ->orWhereExists(function ($preset): void {
                        $preset->selectRaw('1')
                            ->from('queclink_presets')
                            ->whereColumn(
                                'queclink_presets.device_configuration_profile_id',
                                'device_configuration_profiles.id',
                            )
                            ->whereNull('queclink_presets.retired_at');
                    });
            })
            ->where('provider', strtolower(trim((string) $device->provider)))
            ->where('device_domain', $device->domain)
            ->where(function ($query) use ($device): void {
                $query->whereNull('target_category')
                    ->orWhere('target_category', 'all')
                    ->orWhere('target_category', $device->category);
            })
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->orderByDesc('version')
            ->get();
    }

    public function assertCompatible(Device $device, int $profileId): DeviceConfigurationProfile
    {
        $profile = $this->compatibleProfiles($device)->firstWhere('id', $profileId);
        if (! $profile) {
            throw ValidationException::withMessages([
                'parameters.configuration_profile_id' => 'Choose an active configuration profile approved for this exact provider and Device class.',
            ]);
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $sections
     */
    public function createProfile(
        string $profileKey,
        string $name,
        ?string $description,
        ?string $targetCategory,
        array $sections,
        bool $isSystem,
        ?int $createdByUserId,
    ): DeviceConfigurationProfile {
        $sections = $this->normaliseSections($sections);

        return DB::transaction(function () use (
            $profileKey,
            $name,
            $description,
            $targetCategory,
            $sections,
            $isSystem,
            $createdByUserId,
        ): DeviceConfigurationProfile {
            $version = ((int) DeviceConfigurationProfile::query()
                ->where('profile_key', $profileKey)
                ->lockForUpdate()
                ->max('version')) + 1;
            $supersedes = DeviceConfigurationProfile::query()
                ->where('profile_key', $profileKey)
                ->where('version', $version - 1)
                ->first();

            return DeviceConfigurationProfile::query()->create([
                'profile_key' => $profileKey,
                'version' => $version,
                'name' => trim($name),
                'description' => $description === null ? null : trim($description),
                'provider' => 'queclink',
                'device_domain' => 'tracking',
                'target_category' => $targetCategory,
                'encrypted_payload' => $sections,
                'verification_sections' => $this->verificationSections($sections),
                'status' => DeviceConfigurationProfile::STATUS_ACTIVE,
                'is_system' => $isSystem,
                'created_by_user_id' => $createdByUserId,
                'supersedes_profile_id' => $supersedes?->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return array<string, array<string, mixed>>
     */
    public function normaliseSections(array $sections): array
    {
        if ($sections === []) {
            throw ValidationException::withMessages(['sections' => 'Add at least one configuration section.']);
        }

        $normalised = [];
        foreach ($sections as $section => $fields) {
            $key = strtolower(trim((string) $section));
            if (! isset(self::SECTION_CODES[$key]) || ! is_array($fields)) {
                throw ValidationException::withMessages(['sections' => "Unsupported Queclink configuration section [{$section}]."]);
            }
            if (array_key_exists('new_password', $fields)) {
                throw ValidationException::withMessages([
                    'sections' => 'Command-password rotation must use the credential rotation workflow and cannot be saved in a configuration profile.',
                ]);
            }

            $clean = collect($fields)
                ->reject(fn (mixed $value, mixed $field): bool => $field === 'command' || $value === null || $value === '')
                ->all();
            try {
                $built = $this->buildSection($key, $clean, 'profile-validation');
                if (isset($built['raw']) && is_string($built['raw']) && $built['raw'] !== '') {
                    sodium_memzero($built['raw']);
                }
            } catch (InvalidArgumentException $failure) {
                throw ValidationException::withMessages(['sections' => $failure->getMessage()]);
            }
            $normalised[$key] = Arr::sortRecursive($clean);
        }

        $canonical = json_encode(Arr::sortRecursive($normalised), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($canonical) > 65535) {
            throw ValidationException::withMessages(['sections' => 'The configuration profile is too large.']);
        }

        return Arr::sortRecursive($normalised);
    }

    /** @return list<array{command_word: string, raw: string, serial: string, role: string, section: string}> */
    public function buildGovernedSequence(
        DeviceConfigurationProfile $profile,
        string $family,
        #[\SensitiveParameter] string $password,
    ): array {
        if ($profile->provider !== 'queclink' || $profile->device_domain !== 'tracking') {
            throw new RuntimeException('The configuration profile is not a Queclink Tracking profile.');
        }
        if ($family !== CommandBuilder::FAMILY_GL30M) {
            throw new RuntimeException('Governed Queclink configuration writes currently require an exact GL30 family match.');
        }

        $sequence = [];
        foreach ($profile->sectionPayloads() as $section => $fields) {
            $built = $this->buildSection((string) $section, $fields, $password);
            $sequence[] = [...$built, 'role' => 'configuration_write', 'section' => self::SECTION_CODES[strtolower((string) $section)]];
        }
        $sequence[] = [
            ...$this->commands->readConfiguration($family, 'all', $password),
            'role' => 'verification',
            'section' => 'ALL',
        ];

        return $sequence;
    }

    /** @param array<string, mixed> $snapshot */
    public function matches(DeviceConfigurationProfile $profile, array $snapshot): bool
    {
        if (($snapshot['available'] ?? false) !== true) {
            return false;
        }
        $summary = (array) ($snapshot['summary'] ?? []);
        foreach ($profile->sectionPayloads() as $section => $desired) {
            $code = self::SECTION_CODES[strtolower((string) $section)] ?? null;
            $summaryKey = $code === null ? null : (self::SUMMARY_KEYS[$code] ?? null);
            if ($summaryKey === null || ! array_key_exists($summaryKey, $summary)) {
                return false;
            }
            $observed = $summary[$summaryKey];
            if ($code === 'GEO') {
                $slot = (int) ($desired['slot'] ?? 0);
                $observed = is_array($observed) ? ($observed[$slot] ?? null) : null;
            }
            if (! is_array($observed) || ! $this->subsetMatches($desired, $observed)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $sections @return list<string> */
    public function verificationSections(array $sections): array
    {
        return collect(array_keys($sections))
            ->map(fn (string $section): ?string => self::SECTION_CODES[strtolower($section)] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $payload @return array{command_word: string, raw: string, serial: string} */
    private function buildSection(string $section, array $payload, #[\SensitiveParameter] string $password): array
    {
        return match (strtolower($section)) {
            'server', 'sri' => $this->commands->gl30ServerRegistration($payload, $password),
            'tracking', 'global', 'cfg', 'cfg_alarm' => $this->commands->gl30GlobalConfiguration($payload, $password),
            'pin' => $this->commands->gl30Pin($payload, $password),
            'dog' => $this->commands->gl30Dog($payload, $password),
            'time', 'tma' => $this->commands->gl30Tma($payload, $password),
            'non_movement', 'nmd' => $this->commands->gl30Nmd($payload, $password),
            'power', 'pds' => $this->commands->gl30Pds($payload, $password),
            'wifi', 'wfi' => $this->commands->gl30Wifi($payload, $password),
            'geo' => $this->commands->gl30Geo((int) ($payload['slot'] ?? 0), $payload, $password),
            'bluetooth', 'bt', 'bts' => $this->commands->gl30Bts($payload, $password),
            'beacons', 'bid' => $this->commands->gl30Bid($payload, $password),
            'allowlist', 'wlt' => $this->commands->gl30Wlt($payload, $password),
            'firmware_update', 'upc' => $this->commands->gl30Upc($payload, $password),
            'firmware_version', 'fvr' => $this->commands->gl30Fvr($payload, $password),
            default => throw new InvalidArgumentException("Unsupported Queclink configuration command [{$section}]."),
        };
    }

    /** @param array<string, mixed> $desired @param array<string, mixed> $observed */
    private function subsetMatches(array $desired, array $observed): bool
    {
        foreach ($desired as $field => $value) {
            if ($field === 'new_password' || $field === 'command') {
                continue;
            }
            if (! array_key_exists($field, $observed)
                || $this->normaliseValue($value) !== $this->normaliseValue($observed[$field])) {
                return false;
            }
        }

        return true;
    }

    private function normaliseValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->normaliseValue(...), array_values($value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    public static function profileKey(string $slug): string
    {
        return 'queclink:'.Str::slug($slug);
    }
}
