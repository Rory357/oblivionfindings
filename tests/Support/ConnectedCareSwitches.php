<?php

namespace Tests\Support;

use App\Models\AppSetting;

/**
 * Settings › Connected services (D4): each optional feature is off until an
 * organisation switches it on; the two-person rules are on until switched
 * off. Tests of a connected feature switch it on first, as an organisation
 * would. (Literal keys, so the same fixture also runs against older code.)
 */
final class ConnectedCareSwitches
{
    public const PREFIX = 'medications.connected.';

    public const FEATURES = ['prescriber_portal', 'provider_transfers', 'pharmacy_bridge', 'picture_catalogue', 'protected_backups'];

    public static function on(string ...$keys): void
    {
        self::set($keys ?: self::FEATURES, 'on');
    }

    public static function off(string ...$keys): void
    {
        self::set($keys, 'off');
    }

    /** @param list<string> $keys */
    private static function set(array $keys, string $value): void
    {
        foreach ($keys as $key) {
            AppSetting::query()->updateOrCreate(['key' => self::PREFIX.$key], ['value' => $value]);
        }
        // The settings are read once per request; a test changes them mid-request.
        app()->forgetInstance('App\Services\Medication\Connected\ConnectedCareSettings');
    }
}
