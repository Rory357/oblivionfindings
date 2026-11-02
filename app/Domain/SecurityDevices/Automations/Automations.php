<?php

namespace App\Domain\SecurityDevices\Automations;

use App\Domain\SecurityDevices\Automations\Models\Automation;
use App\Domain\SecurityDevices\Modes\HouseModes;
use App\Domain\SecurityDevices\Modes\Models\HouseMode;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A house's automations (S&D Phase F, pg-sdr-13b): adding and changing them (pop-up 40), turning
 * them off or on, and removing them. Every change is checked by AutomationDefinition (safe actions
 * only, devices only from the automation's own Site) and goes in the audit log. Another Site's
 * automation is never found from here.
 */
final class Automations
{
    public function __construct(
        private readonly AutomationDevices $devices,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /**
     * The automations this person may see: their Sites' only, never removed ones.
     *
     * @return Builder<Automation>
     */
    public function scoped(User $viewer, ?int $siteId = null): Builder
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        if ($siteId !== null) {
            $siteIds = in_array($siteId, $siteIds, true) ? [$siteId] : [];
        }

        return Automation::query()->current()->whereIn('site_id', $siteIds ?: [0]);
    }

    /** The viewer's automation, or 404. */
    public function find(User $viewer, int $id): Automation
    {
        return $this->scoped($viewer)->whereKey($id)->firstOrFail();
    }

    /**
     * @return array{doors: array<int, string>, sensors: array<int, string>, cameras: array<int, string>, panels: array<int, string>, any: array<int, string>}
     */
    public function choosable(User $viewer, int $siteId): array
    {
        return $this->devices->forViewer($viewer, $siteId)[$siteId] ?? ['doors' => [], 'sensors' => [], 'cameras' => [], 'panels' => [], 'any' => []];
    }

    /** @return array<int, string> the Site's house modes (S&D Phase F), id => name */
    public static function modes(int $siteId): array
    {
        return HouseModes::forSite($siteId)->mapWithKeys(fn (HouseMode $mode): array => [(int) $mode->id => (string) $mode->name])->all();
    }

    /** @return list<string> */
    public static function roleNames(): array
    {
        return Role::query()->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all();
    }

    /**
     * Pop-up 40: add an automation at one of the person's Sites.
     *
     * @param  array<string, mixed>  $input  site_id, name, is_on, trigger, conditions, actions
     *
     * @throws ValidationException
     */
    public function add(User $actor, array $input): Automation
    {
        $siteId = is_numeric($input['site_id'] ?? null) ? (int) $input['site_id'] : 0;
        if ($siteId <= 0) {
            throw ValidationException::withMessages(['site_id' => 'Choose the Site.']);
        }
        // Another Site is never found from here.
        abort_unless(in_array($siteId, $this->access->accessibleSiteIds($actor), true), 404);
        $definition = AutomationDefinition::normalise($input, $this->choosable($actor, $siteId), self::roleNames(), self::modes($siteId));
        $on = (bool) ($input['is_on'] ?? true);

        return DB::transaction(function () use ($actor, $siteId, $definition, $on): Automation {
            Site::query()->whereKey($siteId)->lockForUpdate()->first(['id']);
            if (Automation::query()->current()->where('site_id', $siteId)->count() >= Automation::MAX_PER_SITE) {
                throw ValidationException::withMessages(['name' => 'A Site can have up to '.Automation::MAX_PER_SITE.' automations. Remove one you no longer need first.']);
            }
            $automation = Automation::query()->create([
                'site_id' => $siteId,
                'name' => $definition['name'],
                'trigger_kind' => $definition['trigger']['kind'],
                'trigger' => $definition['trigger'],
                'conditions' => $definition['conditions'],
                'actions' => $definition['actions'],
                'is_on' => $on,
                'version' => 1,
                'state' => null,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            AuditLogger::logOrFail('security_devices.automation.added', $automation, [
                'actor_id' => (int) $actor->id,
                'site_id' => $siteId,
                'name' => $definition['name'],
                'on' => $on,
                'trigger' => $definition['trigger'],
                'conditions' => $definition['conditions'],
                'actions' => $definition['actions'],
            ]);

            return $automation;
        }, 3);
    }

    /**
     * Pop-up 40 in edit: change the when, only if, then, name and On. Its Site stays.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function change(User $actor, Automation $automation, int $version, array $input): Automation
    {
        $siteId = (int) $automation->site_id;
        $definition = AutomationDefinition::normalise($input, $this->choosable($actor, $siteId), self::roleNames(), self::modes($siteId));
        $on = (bool) ($input['is_on'] ?? $automation->is_on);

        return DB::transaction(function () use ($actor, $automation, $version, $definition, $on, $siteId): Automation {
            $locked = Automation::query()->current()->whereKey($automation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $version, 409, 'This automation changed after you opened it. Close this and look again.');
            $before = ['name' => $locked->name, 'on' => $locked->is_on, 'trigger' => $locked->trigger, 'conditions' => $locked->conditions, 'actions' => $locked->actions];
            // Loose and order-free on purpose: MySQL JSON hands keys back in its own order.
            $triggerChanged = $locked->trigger != $definition['trigger'];
            $locked->forceFill([
                'name' => $definition['name'],
                'trigger_kind' => $definition['trigger']['kind'],
                'trigger' => $definition['trigger'],
                'conditions' => $definition['conditions'],
                'actions' => $definition['actions'],
                'is_on' => $on,
                'version' => $locked->version + 1,
                // A new trigger starts with a clean slate (no remembered sides or pauses).
                'state' => $triggerChanged ? null : $locked->state,
                'updated_by_user_id' => $actor->id,
            ])->save();
            AuditLogger::logOrFail('security_devices.automation.changed', $locked, [
                'actor_id' => (int) $actor->id,
                'site_id' => $siteId,
                'before' => $before,
                'after' => ['name' => $definition['name'], 'on' => $on, ...$definition],
            ]);

            return $locked;
        }, 3);
    }

    /** Turn an automation off (with a reason, kept in the history) or back on. */
    public function turn(User $actor, Automation $automation, bool $on, int $version, ?string $reason): Automation
    {
        return DB::transaction(function () use ($actor, $automation, $on, $version, $reason): Automation {
            $locked = Automation::query()->current()->whereKey($automation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $version, 409, 'This automation changed after you opened it. Close this and look again.');
            abort_if($locked->is_on === $on, 409, $on ? 'This automation is already on.' : 'This automation is already off.');
            $locked->forceFill(['is_on' => $on, 'version' => $locked->version + 1, 'updated_by_user_id' => $actor->id])->save();
            AuditLogger::logOrFail($on ? 'security_devices.automation.turned_on' : 'security_devices.automation.turned_off', $locked, [
                'actor_id' => (int) $actor->id,
                'site_id' => (int) $locked->site_id,
                'reason' => $reason,
            ]);

            return $locked;
        }, 3);
    }

    /** Remove an automation: it never runs again, and it and its runs stay in the history. */
    public function remove(User $actor, Automation $automation, int $version, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $automation, $version, $reason): void {
            $locked = Automation::query()->current()->whereKey($automation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $version, 409, 'This automation changed after you opened it. Close this and look again.');
            $locked->forceFill([
                'is_on' => false,
                'version' => $locked->version + 1,
                'removed_at' => CarbonImmutable::now('UTC'),
                'removed_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ])->save();
            AuditLogger::logOrFail('security_devices.automation.removed', $locked, [
                'actor_id' => (int) $actor->id,
                'site_id' => (int) $locked->site_id,
                'name' => $locked->name,
                'reason' => $reason,
            ]);
        }, 3);
    }
}
