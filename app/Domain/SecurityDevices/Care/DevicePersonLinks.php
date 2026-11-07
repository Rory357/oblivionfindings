<?php

namespace App\Domain\SecurityDevices\Care;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * S&D Phase F (pop-up 65): link a sensor to a person's care, like their falls plan. Only someone
 * with securityDevices.people.link, only a person at the sensor's own Site, and only while that
 * person's consent to monitoring sensors is active. Alerts from the link go to whoever is on shift.
 */
final class DevicePersonLinks
{
    private const string TIME = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly SensorMonitoringConsent $consent,
    ) {}

    public function currentFor(Device $device): ?DevicePersonLink
    {
        return DevicePersonLink::query()->current()->where('device_id', $device->id)->with(['client', 'carePlan'])->latest('id')->first();
    }

    /** Sensors (not cameras or trackers, which have their own consent flows) can be linked. */
    public static function linkable(Device $device): bool
    {
        return DeviceKind::of($device) === DeviceKind::Sensor;
    }

    /**
     * Pop-up 65's choices: the people at the sensor's Site, each with their consent state and
     * active care plans.
     *
     * @return array{people: list<array<string, mixed>>, site: array{id: int|null, name: string|null}}
     */
    public function options(User $viewer, Device $device): array
    {
        $this->assertCan($viewer, $device);
        $siteId = $this->siteOf($device);
        $people = $siteId === null ? collect() : Client::query()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->limit(200)
            ->get();
        $plans = CarePlan::query()->whereIn('client_id', $people->pluck('id'))->whereIn('status', ['active', 'review'])->orderByDesc('id')->get()->groupBy('client_id');

        return [
            'site' => ['id' => $siteId, 'name' => $siteId === null ? null : \App\Models\Site::query()->whereKey($siteId)->value('name')],
            'people' => $people->map(fn (Client $client): array => [
                'id' => (int) $client->id,
                'name' => self::name($client),
                'consent' => $this->consent->state($client),
                'record_href' => '/clients/'.$client->id,
                'plans' => ($plans->get($client->id) ?? collect())->map(fn (CarePlan $plan): array => [
                    'id' => (int) $plan->id,
                    'title' => (string) $plan->title,
                    'review' => $plan->next_review_at?->toDateString(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $rule
     */
    public function link(User $actor, Device $device, int $clientId, string $purpose, ?int $carePlanId, ?array $rule, ?string $note): DevicePersonLink
    {
        $this->assertCan($actor, $device);
        $siteId = $this->siteOf($device);
        $client = Client::query()->whereKey($clientId)->first();
        // A person at another Site is "not found", the same as a device at another Site.
        abort_unless($client instanceof Client && $siteId !== null && (int) $client->site_id === $siteId, 404);
        if (! in_array($purpose, DevicePersonLink::PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => 'Choose why this sensor is linked.']);
        }
        $consent = $this->consent->state($client);
        if (! $consent['active']) {
            throw ValidationException::withMessages(['client_id' => self::name($client).'’s consent to monitoring sensors isn’t active. Record it in Clients first.']);
        }
        if ($carePlanId !== null && ! CarePlan::query()->whereKey($carePlanId)->where('client_id', $client->id)->whereIn('status', ['active', 'review'])->exists()) {
            throw ValidationException::withMessages(['care_plan_id' => 'Choose one of '.self::name($client).'’s care plans.']);
        }
        $rule = $this->rule($purpose, $rule);

        return DB::transaction(function () use ($actor, $device, $client, $siteId, $purpose, $carePlanId, $rule, $note, $consent): DevicePersonLink {
            Device::query()->whereKey($device->id)->lockForUpdate()->first();
            if (DevicePersonLink::query()->current()->where('device_id', $device->id)->exists()) {
                throw ValidationException::withMessages(['client_id' => 'This sensor is already linked to someone. Unlink it first.']);
            }
            $link = DevicePersonLink::query()->create([
                'device_id' => $device->id,
                'client_id' => $client->id,
                'site_id' => $siteId,
                'purpose' => $purpose,
                'care_plan_id' => $carePlanId,
                'rule' => $rule,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
                'consent_id' => $consent['consent_id'],
                'linked_by_user_id' => $actor->id,
                'linked_at' => now(),
            ]);
            AuditLogger::logOrFail('security_devices.person_link.created', $link, [
                'actor_id' => (int) $actor->id, 'device_id' => (int) $device->id, 'client_id' => (int) $client->id,
                'site_id' => $siteId, 'purpose' => $purpose, 'consent_id' => $consent['consent_id'],
            ]);

            return $link;
        });
    }

    public function unlink(?User $actor, DevicePersonLink $link, string $reason = 'by_hand'): void
    {
        if ($actor !== null) {
            $this->assertCan($actor, $link->device);
        }
        DB::transaction(function () use ($actor, $link, $reason): void {
            $locked = DevicePersonLink::query()->whereKey($link->id)->lockForUpdate()->first();
            if (! $locked instanceof DevicePersonLink || $locked->unlinked_at !== null) {
                return;
            }
            $locked->forceFill(['unlinked_at' => now(), 'unlinked_by_user_id' => $actor?->id, 'unlink_reason' => $reason])->save();
            AuditLogger::logOrFail('security_devices.person_link.removed', $locked, [
                'actor_id' => $actor?->id, 'device_id' => (int) $locked->device_id, 'client_id' => (int) $locked->client_id, 'reason' => $reason,
            ]);
        });
    }

    /** Unlink every link whose person's consent to monitoring is no longer active. */
    public function sweepConsents(): int
    {
        $ended = 0;
        DevicePersonLink::query()->current()->with('client')->orderBy('id')->limit(1000)->get()
            ->each(function (DevicePersonLink $link) use (&$ended): void {
                try {
                    if (! $link->client instanceof Client || ! $this->consent->state($link->client)['active']) {
                        $this->unlink(null, $link, 'consent_ended');
                        // Safety fix round (F2): the house manager hears that this sensor's care alerts stopped.
                        app(ConsentEndings::class)->stopped($link->refresh());
                        $ended++;
                    }
                } catch (Throwable) {
                    // Tried again on the next round.
                }
            });

        return $ended;
    }

    public static function name(Client $client): string
    {
        $preferred = trim((string) ($client->preferred_name ?? ''));

        return $preferred !== '' ? $preferred : trim((string) $client->first_name);
    }

    /**
     * The plan's rule, checked per purpose: a falls plan needs minutes out of bed and night hours;
     * a wandering plan needs night hours; other links carry no rule.
     *
     * @param  array<string, mixed>|null  $rule
     * @return array<string, int|string>|null
     */
    private function rule(string $purpose, ?array $rule): ?array
    {
        if (! in_array($purpose, ['falls', 'wandering'], true)) {
            return null;
        }
        $from = (string) ($rule['from'] ?? '22:00');
        $to = (string) ($rule['to'] ?? '06:00');
        if (preg_match(self::TIME, $from) !== 1 || preg_match(self::TIME, $to) !== 1 || $from === $to) {
            throw ValidationException::withMessages(['rule' => 'Choose the night hours.']);
        }
        if ($purpose === 'wandering') {
            return ['from' => $from, 'to' => $to];
        }
        $minutes = (int) ($rule['minutes'] ?? 10);
        if ($minutes < 1 || $minutes > 120) {
            throw ValidationException::withMessages(['rule' => 'Choose between 1 and 120 minutes.']);
        }

        return ['minutes' => $minutes, 'from' => $from, 'to' => $to];
    }

    private function assertCan(User $actor, Device $device): void
    {
        abort_unless($actor->canDo('securityDevices.devices.view') && $actor->canDo('securityDevices.people.link'), 403);
        $this->access->assertCanViewDevice($actor, $device);
        abort_unless(self::linkable($device), 404);
    }

    private function siteOf(Device $device): ?int
    {
        try {
            $siteId = $this->sites->resolve((int) $device->id);
        } catch (Throwable) {
            return null;
        }

        return $siteId > 0 ? $siteId : null;
    }
}
