<?php

namespace App\Services\ControlRoom;

use App\Domain\SecurityDevices\Models\Device as CanonicalDevice;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoom\Signal;
use BackedEnum;

/**
 * Minimum-necessary presentation for the Control Room signal projection.
 */
class ControlRoomDevicePresenter
{
    public function list(Device $projection): array
    {
        $canonical = $this->canonical($projection);

        return [
            'id' => $projection->id,
            'name' => $canonical?->name ?? $projection->name,
            'device_uid' => $canonical?->device_uid ?? $projection->device_uid,
            'type' => $projection->type,
            'type_label' => Device::types()[$projection->type] ?? ucfirst(str_replace('_', ' ', $projection->type)),
            'vendor' => $canonical?->manufacturer ?? $projection->vendor,
            'model' => $canonical?->model ?? $projection->model,
            'reported_battery_level' => $projection->battery_level,
            'last_signal_at' => $projection->last_signal_at?->toISOString(),
            'signal_activity' => $this->signalActivity($projection),
            'location_description' => $projection->location_description,
            'site_id' => $projection->site_id,
            'site_name' => $projection->site_name,
            'signal_source_name' => $projection->signalSource?->name,
            'identity_source' => $canonical ? 'canonical' : 'signal_projection',
            'canonical_id' => $canonical?->id,
            'canonical_device_uid' => $canonical?->device_uid,
            'canonical_domain' => $canonical?->domain,
            'canonical_category' => $canonical?->category,
            'canonical_status' => $this->enumValue($canonical?->status),
            'canonical_health_status' => $this->enumValue($canonical?->health_status),
            'canonical_battery_level' => $canonical?->battery_level,
            'canonical_last_seen_at' => $canonical?->last_seen_at?->toISOString(),
            'canonical_detail_url' => $canonical ? "/security-devices/devices/{$canonical->id}" : null,
        ];
    }

    public function detail(Device $projection): array
    {
        $canonical = $this->canonical($projection);

        return [
            'id' => $projection->id,
            'name' => $canonical?->name ?? $projection->name,
            'device_uid' => $canonical?->device_uid ?? $projection->device_uid,
            'type' => $projection->type,
            'type_label' => Device::types()[$projection->type] ?? ucfirst(str_replace('_', ' ', $projection->type)),
            'vendor' => $canonical?->manufacturer ?? $projection->vendor,
            'model' => $canonical?->model ?? $projection->model,
            'reported_battery_level' => $projection->battery_level,
            'last_signal_at' => $projection->last_signal_at?->toISOString(),
            'signal_activity' => $this->signalActivity($projection),
            'latitude' => $projection->latitude ? (float) $projection->latitude : null,
            'longitude' => $projection->longitude ? (float) $projection->longitude : null,
            'location_description' => $projection->location_description,
            'identity_source' => $canonical ? 'canonical' : 'signal_projection',
            'canonical' => $canonical ? [
                'id' => $canonical->id,
                'domain' => $canonical->domain,
                'category' => $canonical->category,
                'subcategory' => $canonical->subcategory,
                'status' => $this->enumValue($canonical->status),
                'health_status' => $this->enumValue($canonical->health_status),
                'battery_level' => $canonical->battery_level,
                'last_seen_at' => $canonical->last_seen_at?->toISOString(),
                'detail_url' => "/security-devices/devices/{$canonical->id}",
            ] : null,
        ];
    }

    public function signal(Signal $signal): array
    {
        $alert = $signal->alert ?: $signal->correlatedAlert;
        $label = match (true) {
            $signal->alert !== null => 'Alert created',
            $signal->correlatedAlert !== null => 'Added to existing alert',
            $signal->status === 'processed' => 'Processed',
            $signal->status === 'suppressed' => 'Suppressed',
            $signal->status === 'failed' => 'Needs review',
            default => 'Awaiting processing',
        };
        $tone = match ($signal->status) {
            'processed' => 'success',
            'failed' => 'critical',
            'suppressed' => 'muted',
            default => 'warning',
        };

        return [
            'id' => $signal->id,
            'signal_type_code' => $signal->signal_type_code,
            'severity_hint' => $signal->severity_hint,
            'occurred_at' => $signal->occurred_at?->toISOString(),
            'status' => $signal->status,
            'outcome' => [
                'label' => $label,
                'tone' => $tone,
                'alert_reference' => $alert?->reference_number,
                'href' => $alert ? "/control-room/alerts/{$alert->id}" : null,
            ],
        ];
    }

    private function canonical(Device $projection): ?CanonicalDevice
    {
        if (! $projection->relationLoaded('canonicalDevice')) {
            return null;
        }

        $canonical = $projection->getRelation('canonicalDevice');

        return $canonical instanceof CanonicalDevice ? $canonical : null;
    }

    /** @return array{state: 'recent'|'quiet'|'never', label: string, tone: 'success'|'muted'} */
    private function signalActivity(Device $projection): array
    {
        if ($projection->last_signal_at === null) {
            return [
                'state' => 'never',
                'label' => 'No signal recorded',
                'tone' => 'muted',
            ];
        }

        if ($projection->last_signal_at->gte(now()->subDay())) {
            return [
                'state' => 'recent',
                'label' => 'Signal received in the last 24 hours',
                'tone' => 'success',
            ];
        }

        return [
            'state' => 'quiet',
            'label' => 'No signal in the last 24 hours',
            'tone' => 'muted',
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
