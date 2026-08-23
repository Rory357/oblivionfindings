<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROVIDER_FIELDS = [
        'manufacturer',
        'model',
        'serial_number',
        'mac_address',
        'imei',
        'firmware_version',
        'ip_address',
        'status',
        'health_status',
        'provider',
        'last_seen_at',
        'battery_level',
        'battery_updated_at',
    ];

    private const LOCAL_FIELDS = [
        'name',
        'domain',
        'category',
        'subcategory',
        'manufacturer',
        'model',
        'serial_number',
        'mac_address',
        'imei',
        'asset_tag',
        'firmware_version',
        'ip_address',
        'status',
        'health_status',
        'provider',
        'location_description',
        'notes',
        'next_service_due',
    ];

    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->json('local_intended_state')->nullable()->after('meta');
            $table->json('provider_observed_state')->nullable()->after('local_intended_state');
            $table->json('provider_field_overrides')->nullable()->after('provider_observed_state');
        });

        DB::table('devices')
            ->select(array_values(array_unique([
                'id',
                'provider',
                'external_ref',
                'updated_at',
                ...self::PROVIDER_FIELDS,
                ...self::LOCAL_FIELDS,
            ])))
            ->orderBy('id')
            ->chunkById(200, function ($devices): void {
                foreach ($devices as $device) {
                    $recordedAt = $device->updated_at ?: now();
                    $observedAt = $device->last_seen_at ?: $recordedAt;
                    $local = [];
                    foreach (self::LOCAL_FIELDS as $field) {
                        $local[$field] = [
                            'value' => $device->{$field},
                            'recorded_at' => $recordedAt,
                            'source' => 'legacy_registry_backfill',
                            'quality' => 'legacy_inferred',
                            'recorded_by_user_id' => null,
                        ];
                    }

                    $observed = [];
                    $externalRef = is_string($device->external_ref)
                        ? json_decode($device->external_ref, true)
                        : [];
                    $provider = strtolower(trim((string) ($device->provider
                        ?: data_get(is_array($externalRef) ? $externalRef : [], 'provider', ''))));
                    if ($provider !== '' && ! in_array($provider, ['manual', 'local'], true)) {
                        foreach (self::PROVIDER_FIELDS as $field) {
                            $observed[$field] = [
                                'value' => $device->{$field},
                                'observed_at' => $observedAt,
                                'source' => $provider,
                                'quality' => 'legacy_inferred',
                            ];
                        }
                    }

                    DB::table('devices')->where('id', $device->id)->update([
                        'local_intended_state' => json_encode($local, JSON_THROW_ON_ERROR),
                        'provider_observed_state' => $observed === []
                            ? null
                            : json_encode($observed, JSON_THROW_ON_ERROR),
                        'provider_field_overrides' => json_encode([
                            'active' => [],
                            'history' => [],
                        ], JSON_THROW_ON_ERROR),
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn([
                'local_intended_state',
                'provider_observed_state',
                'provider_field_overrides',
            ]);
        });
    }
};
