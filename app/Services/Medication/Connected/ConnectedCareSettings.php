<?php

namespace App\Services\Medication\Connected;

use App\Models\AppSetting;
use App\Services\Medication\BackupDelivery\BackupPdfEncryption;
use App\Services\Medication\PharmacyConnect\PharmacyPartnerRegistry;
use App\Services\Medication\Settings\MedicationSettingDefinition as Definition;
use App\Services\Medication\Settings\MedicationSettingGroup;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use Illuminate\Container\Attributes\Scoped;

/**
 * Settings › Connected services (decision D4, 9 Oct: "PLEASE COMPLETE IT").
 *
 * Each optional third-party feature has its own switch, off until an
 * organisation turns it on (switching one on shares medication data
 * outside the eMAR, so it counts as loosening and asks for a reason). A
 * switched-on feature only runs when it is also configured: the pharmacy
 * bridge needs the installation's partner and its platform switch, and
 * protected backups need the reviewed PDF encryption — never without it.
 *
 * The two-person rules (identity verification, catalogue review, provider
 * handover review) are switches too; the safe default is on, so switching
 * one off is the loosening.
 *
 * Fixed, never a setting: backups go only to a recipient's HR work email.
 * Backup schedule and retention are set per house on Protected backups.
 *
 * One instance per request or job (#[Scoped]), reading every switch in one
 * query; forget the instance after writing a switch mid-request.
 */
#[Scoped]
final class ConnectedCareSettings
{
    public const GROUP = 'connected';

    public const PORTAL = 'prescriber_portal';

    public const TRANSFERS = 'provider_transfers';

    public const PHARMACY = 'pharmacy_bridge';

    public const CATALOGUE = 'picture_catalogue';

    public const BACKUPS = 'protected_backups';

    public const FEATURES = [self::PORTAL, self::TRANSFERS, self::PHARMACY, self::CATALOGUE, self::BACKUPS];

    public const TWO_PERSON_IDENTITY = 'two_person_identity';

    public const TWO_PERSON_CATALOGUE = 'two_person_catalogue';

    public const TWO_PERSON_HANDOVER = 'two_person_handover';

    public const STORAGE_PREFIX = 'medications.connected.';

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public static function group(): MedicationSettingGroup
    {
        $feature = fn (string $key, string $label) => new Definition(
            group: self::GROUP,
            key: $key,
            storageKey: self::STORAGE_PREFIX.$key,
            scope: Definition::SCOPE_ORGANISATION,
            section: 'services',
            label: $label,
            options: ['on' => 'On', 'off' => 'Off'],
            default: 'off',
            // Loosest first: switching a data-sharing feature on is the loosening.
            rank: ['on', 'off'],
        );
        $check = fn (string $key, string $label) => new Definition(
            group: self::GROUP,
            key: $key,
            storageKey: self::STORAGE_PREFIX.$key,
            scope: Definition::SCOPE_ORGANISATION,
            section: 'services',
            label: $label,
            options: ['off' => 'Off — one person can do both steps', 'on' => 'On'],
            default: 'on',
            rank: ['off', 'on'],
        );

        return new MedicationSettingGroup(self::GROUP, MedicationSettingsRegistry::VIEW_CONNECTIONS, 'Straight away, for every house', 'medications.connected_services.updated', [
            $feature(self::PORTAL, 'Outside prescriber portal'),
            $feature(self::TRANSFERS, 'Provider handovers'),
            $feature(self::PHARMACY, 'Pharmacy bridge'),
            $feature(self::CATALOGUE, 'Picture catalogue (reference images)'),
            $feature(self::BACKUPS, 'Protected chart backups'),
            $check(self::TWO_PERSON_IDENTITY, 'A second person grants access to a prescriber someone verified'),
            $check(self::TWO_PERSON_CATALOGUE, 'A second person reviews a catalogue source'),
            $check(self::TWO_PERSON_HANDOVER, 'A second person reviews a provider handover'),
        ]);
    }

    public function switchedOn(string $feature): bool
    {
        return $this->value($feature, 'off') === 'on';
    }

    /** @return array{ready: bool, reason: ?string} */
    public function readiness(string $feature): array
    {
        return match ($feature) {
            self::PHARMACY => app(PharmacyPartnerRegistry::class)->configured()
                ? ['ready' => true, 'reason' => null]
                : ['ready' => false, 'reason' => 'No pharmacy partner is set up for this installation yet.'],
            self::BACKUPS => app(BackupPdfEncryption::class)->ready()
                ? ['ready' => true, 'reason' => null]
                : ['ready' => false, 'reason' => 'Protected backups need strong PDF encryption, which isn’t set up yet.'],
            default => ['ready' => true, 'reason' => null],
        };
    }

    /** Switched on and configured: the feature runs. */
    public function enabled(string $feature): bool
    {
        return in_array($feature, self::FEATURES, true)
            && $this->switchedOn($feature)
            && $this->readiness($feature)['ready'];
    }

    public function twoPerson(string $check): bool
    {
        return $this->value($check, 'on') !== 'off';
    }

    /** @return array<string, bool> For navigation: which features run now. */
    public function switches(): array
    {
        return collect(self::FEATURES)->mapWithKeys(fn (string $feature): array => [$feature => $this->enabled($feature)])->all();
    }

    /** @return array<string, array{switched_on: bool, ready: bool, reason: ?string, running: bool}> */
    public function status(): array
    {
        return collect(self::FEATURES)->mapWithKeys(function (string $feature): array {
            $readiness = $this->readiness($feature);

            return [$feature => [
                'switched_on' => $this->switchedOn($feature),
                'ready' => $readiness['ready'],
                'reason' => $readiness['reason'],
                'running' => $this->switchedOn($feature) && $readiness['ready'],
            ]];
        })->all();
    }

    private function value(string $key, string $default): string
    {
        $this->values ??= AppSetting::query()->where('key', 'like', self::STORAGE_PREFIX.'%')->get(['key', 'value'])
            ->mapWithKeys(fn (AppSetting $row): array => [substr((string) $row->key, strlen(self::STORAGE_PREFIX)) => $row->value])->all();
        $value = $this->values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
