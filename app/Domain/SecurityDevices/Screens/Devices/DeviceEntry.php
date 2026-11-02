<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use Carbon\CarbonInterface;

/** One visible device as the Devices screens see it. Built by DeviceDirectory. */
final readonly class DeviceEntry
{
    /** A battery at or below this needs changing soon. */
    public const int LOW_BATTERY = 20;

    /**
     * @param  list<int>  $monitorIds  enabled checks
     * @param  list<string>  $watching  what those checks look at, in words
     * @param  array<string, mixed>  $readings  the sensor's latest readings, if it sends any
     */
    public function __construct(
        public Device $device,
        public DeviceKind $kind,
        public DeviceState $state,
        public ?int $siteId,
        public ?string $siteName,
        public ?string $person,
        public array $monitorIds,
        public array $watching,
        public ?string $behind,
        public bool $behindHub,
        public ?string $failingCheck,
        public ?CarbonInterface $offlineSince,
        public ?CarbonInterface $lastCheckedAt,
        public array $readings,
    ) {}

    public function id(): int
    {
        return (int) $this->device->id;
    }

    public function name(): string
    {
        return (string) $this->device->name;
    }

    public function tab(): string
    {
        return $this->kind->tab();
    }

    public function battery(): ?int
    {
        return $this->device->battery_level === null ? null : (int) $this->device->battery_level;
    }

    public function batteryLow(): bool
    {
        $battery = $this->battery();

        return $battery !== null && $battery <= self::LOW_BATTERY && $this->state !== DeviceState::CantCheck;
    }

    /** Offline, needs attention, can't check, or a battery running down. Not-watched devices are never problems. */
    public function needsAttention(): bool
    {
        return in_array($this->state, [DeviceState::Offline, DeviceState::Problem, DeviceState::CantCheck], true)
            || ($this->state === DeviceState::Online && $this->batteryLow());
    }

    /** "Hikvision DS-2CD2347G2", or the kind when the model isn't known. */
    public function model(): string
    {
        $model = trim(trim((string) $this->device->manufacturer).' '.trim((string) $this->device->model));

        return $model !== '' ? $model : $this->kind->label();
    }

    /** Model · address, or model · the person it's with. */
    public function subline(): string
    {
        $second = trim((string) $this->device->ip_address);
        if ($second === '' && $this->person !== null) {
            $second = $this->person;
        }

        return $second === '' ? $this->model() : $this->model().' · '.$second;
    }

    public function lastSeenAt(): ?CarbonInterface
    {
        $seen = $this->device->last_seen_at;
        $signal = $this->device->last_signal_at;
        $checked = $this->state === DeviceState::Online ? $this->lastCheckedAt : null;

        return collect([$seen, $signal, $checked])->filter()->max();
    }

    /** Problems first, then by name. */
    public function sortKey(): string
    {
        $rank = $this->state->sortOrder();
        if ($this->state === DeviceState::Online && $this->batteryLow()) {
            $rank = 2;
        }

        return $rank.'|'.mb_strtolower($this->name());
    }
}
