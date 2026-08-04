<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Application credential-type catalogue. The seven built-ins are defined in
 * {@see self::defaults()} and merged with stored application overrides at read
 * time, so the defaults always appear even before rows are seeded. Stored rows
 * hold overrides (label/icon/active/order) and custom types.
 */
class CredentialType extends Model
{
    use WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'key',
        'label',
        'icon',
        'description',
        'active',
        'sort_order',
        'is_system',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Icon keys offered to custom types — mirror the frontend icon registry. */
    public const ICONS = [
        'lock', 'keyRound', 'fileKey', 'fingerprint', 'fileBadge', 'link2',
        'shield', 'globe', 'server', 'smartphone', 'wifi', 'creditCard',
        'mail', 'radio', 'database',
    ];

    /**
     * Built-in credential types, in default display order. `password` and
     * `other` are system types (cannot be deleted or hidden).
     *
     * @return array<int, array{key:string,label:string,icon:string,description:string,is_system:bool}>
     */
    public static function defaults(): array
    {
        return [
            ['key' => 'password', 'label' => 'Password', 'icon' => 'lock', 'description' => 'Username + secret', 'is_system' => true],
            ['key' => 'pin', 'label' => 'PIN / Code', 'icon' => 'fingerprint', 'description' => 'Door, alarm, panel', 'is_system' => false],
            ['key' => 'api_key', 'label' => 'API Key', 'icon' => 'keyRound', 'description' => 'Machine token', 'is_system' => false],
            ['key' => 'oauth', 'label' => 'OAuth', 'icon' => 'link2', 'description' => 'Delegated access', 'is_system' => false],
            ['key' => 'ssh_key', 'label' => 'SSH Key', 'icon' => 'fileKey', 'description' => 'Key pair', 'is_system' => false],
            ['key' => 'certificate', 'label' => 'Certificate', 'icon' => 'fileBadge', 'description' => 'TLS / signing', 'is_system' => false],
            ['key' => 'other', 'label' => 'Other', 'icon' => 'shield', 'description' => 'Anything else', 'is_system' => true],
        ];
    }

    /** @return array<int, string> */
    public static function defaultKeys(): array
    {
        return array_column(self::defaults(), 'key');
    }

    public static function isSystemKey(string $key): bool
    {
        foreach (self::defaults() as $default) {
            if ($default['key'] === $key) {
                return (bool) $default['is_system'];
            }
        }

        return false;
    }

    /**
     * The application catalogue: built-in defaults overlaid with stored
     * overrides, followed by custom stored types, ordered by sort_order.
     *
     * @return Collection<int, array{key:string,label:string,icon:string,description:?string,active:bool,sort_order:int,system:bool}>
     */
    public static function applicationCatalogue(): Collection
    {
        // Guard the table-existence check so a not-yet-migrated server (the
        // deploy window before `migrate` runs) falls back to the built-in
        // defaults instead of 500-ing every page that reads the registry.
        $stored = Schema::hasTable('credential_types')
            ? static::query()->get()->keyBy('key')
            : collect();

        $result = collect();
        $order = 0;
        foreach (self::defaults() as $default) {
            /** @var self|null $row */
            $row = $stored->get($default['key']);
            $result->push([
                'key' => $default['key'],
                'label' => $row?->label ?? $default['label'],
                'icon' => $row?->icon ?? $default['icon'],
                'description' => $row?->description ?? $default['description'],
                // System types can never be hidden.
                'active' => $default['is_system'] ? true : ($row ? (bool) $row->active : true),
                'sort_order' => $row?->sort_order ?? $order,
                'system' => (bool) $default['is_system'],
            ]);
            $order++;
        }

        $defaultKeys = self::defaultKeys();
        foreach ($stored as $key => $row) {
            if (in_array($key, $defaultKeys, true)) {
                continue;
            }
            $result->push([
                'key' => $row->key,
                'label' => $row->label,
                'icon' => $row->icon,
                'description' => $row->description,
                'active' => (bool) $row->active,
                'sort_order' => $row->sort_order,
                'system' => false,
            ]);
        }

        return $result->sortBy('sort_order')->values();
    }

    /**
     * Active types only, shaped for the credential tile picker.
     *
     * @return Collection<int, array{key:string,label:string,icon:string,description:?string}>
     */
    public static function pickerOptions(): Collection
    {
        return self::applicationCatalogue()
            ->where('active', true)
            ->map(fn (array $type) => [
                'key' => $type['key'],
                'label' => $type['label'],
                'icon' => $type['icon'],
                'description' => $type['description'],
            ])
            ->values();
    }
}
