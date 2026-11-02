<?php

namespace App\Domain\SecurityDevices\Modes;

use App\Domain\SecurityDevices\Modes\Models\HouseMode;
use App\Domain\SecurityDevices\Modes\Models\HouseModeEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * A Site's house modes (S&D Phase F, pg-sdr-12c): adding and editing them (pop-up 43, audited),
 * switching by hand (pop-up 42) and the scheduler's tick. Every switch runs through HouseModeRunner.
 */
final class HouseModes
{
    /** A start missed by more than this (the scheduler was down) is skipped, not run late. */
    public const int GRACE_MINUTES = 10;

    public function __construct(
        private readonly HouseModeRunner $runner,
        private readonly HouseModeDevices $devices,
    ) {}

    /** @return Collection<int, HouseMode> */
    public static function forSite(int $siteId): Collection
    {
        return HouseMode::query()->where('site_id', $siteId)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** The Site's mode, or 404: another Site's mode is never found from here. */
    public static function find(int $siteId, int $modeId): HouseMode
    {
        return HouseMode::query()->where('site_id', $siteId)->whereKey($modeId)->firstOrFail();
    }

    /**
     * Add a mode (no `$mode`) or change one. Only safe actions; devices only from this Site.
     *
     * @param  array<string, mixed>  $input  name (when adding), schedule, actions
     *
     * @throws ValidationException
     */
    public function save(User $actor, Site $site, ?HouseMode $mode, array $input): HouseMode
    {
        $choosable = $this->devices->forViewer($actor, (int) $site->id);
        $actions = HouseModeActions::normalise($input['actions'] ?? null, $choosable);
        try {
            $schedule = HouseModeSchedule::normalise($input['schedule'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['schedule' => $exception->getMessage()]);
        }
        $name = $mode?->name;
        if ($mode === null) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 40 || preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
                throw ValidationException::withMessages(['name' => 'Give the mode a short name (up to 40 letters).']);
            }
        }

        return DB::transaction(function () use ($actor, $site, $mode, $name, $schedule, $actions): HouseMode {
            Site::query()->whereKey($site->id)->lockForUpdate()->first(['id']);
            $siblings = HouseMode::query()->where('site_id', $site->id)->get(['id', 'name', 'sort_order', 'schedule']);
            if ($mode === null) {
                if ($siblings->count() >= HouseMode::MAX_PER_SITE) {
                    throw ValidationException::withMessages(['name' => 'A Site can have up to '.HouseMode::MAX_PER_SITE.' modes.']);
                }
                if ($siblings->contains(fn (HouseMode $other): bool => mb_strtolower($other->name) === mb_strtolower((string) $name))) {
                    throw ValidationException::withMessages(['name' => 'This Site already has a mode called '.$name.'.']);
                }
            }
            if ($schedule !== null && $this->clashes($siblings->reject(fn (HouseMode $other): bool => $mode !== null && $other->id === $mode->id), $schedule)) {
                throw ValidationException::withMessages(['schedule' => 'Another mode already starts then on one of those days. Choose another time.']);
            }

            $before = $mode === null ? null : ['schedule' => $mode->schedule, 'actions' => $mode->actionList()];
            $mode ??= new HouseMode([
                'site_id' => (int) $site->id,
                'name' => $name,
                'sort_order' => ((int) $siblings->max('sort_order')) + 1,
                'created_by_user_id' => (int) $actor->id,
            ]);
            $mode->fill(['schedule' => $schedule, 'actions' => $actions, 'updated_by_user_id' => (int) $actor->id])->save();

            AuditLogger::logOrFail($before === null ? 'security_devices.house_mode.added' : 'security_devices.house_mode.changed', $mode, [
                'actor_id' => (int) $actor->id,
                'site_id' => (int) $site->id,
                'name' => $mode->name,
                'before' => $before,
                'after' => ['schedule' => $schedule, 'actions' => $actions],
            ]);

            return $mode;
        }, 3);
    }

    /** Switch by hand (pop-up 42). */
    public function switchByHand(User $actor, HouseMode $mode): HouseModeEvent
    {
        $event = $this->runner->switchTo($mode, 'hand', $actor, CarbonImmutable::now('UTC'));
        abort_if($event === null, 409, 'The house mode didn’t change. Refresh and try again.');

        return $event;
    }

    /**
     * Every minute: switch each Site whose mode's start has just come, then run alarm checks that are
     * due. A start more than GRACE_MINUTES old is skipped. Returns counts.
     *
     * @return array{switched: int, checks: int}
     */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $switched = 0;
        HouseMode::query()->whereNotNull('schedule')
            ->whereHas('site', fn ($site) => $site->where('is_active', true)->where('archived', false)->whereNull('archived_at'))
            ->orderBy('site_id')->orderBy('id')->get()
            ->groupBy('site_id')
            ->each(function (SupportCollection $modes) use ($now, &$switched): void {
                $due = null;
                $dueStart = null;
                foreach ($modes as $mode) {
                    try {
                        $schedule = HouseModeSchedule::normalise($mode->schedule);
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                    $start = $schedule === null ? null : HouseModeSchedule::lastStart($schedule, $now);
                    if ($start !== null && ($dueStart === null || $start->gt($dueStart))) {
                        [$due, $dueStart] = [$mode, $start];
                    }
                }
                if ($due === null || $dueStart === null || $dueStart->lt($now->subMinutes(self::GRACE_MINUTES))) {
                    return;
                }
                try {
                    if ($this->runner->switchTo($due, 'schedule', null, $now, $dueStart) !== null) {
                        $switched++;
                    }
                } catch (Throwable $exception) {
                    Log::error('Scheduled house mode switch failed', ['mode_id' => $due->id, 'error' => $exception->getMessage()]);
                }
            });

        $checks = 0;
        HouseModeEvent::query()->where('state', HouseModeEvent::STATE_PENDING)->where('due_at', '<=', $now)->orderBy('due_at')->limit(200)->get()
            ->each(function (HouseModeEvent $pending) use ($now, &$checks): void {
                $this->runner->runPendingCheck($pending, $now);
                $checks++;
            });

        return ['switched' => $switched, 'checks' => $checks];
    }

    /**
     * When the next scheduled mode other than the current one starts.
     *
     * @param  Collection<int, HouseMode>  $modes
     * @return array{mode: HouseMode, at: CarbonImmutable}|null
     */
    public static function next(Collection $modes, ?int $currentId, CarbonImmutable $now): ?array
    {
        $best = null;
        foreach ($modes as $mode) {
            if ($mode->schedule === null || (int) $mode->id === $currentId) {
                continue;
            }
            $at = HouseModeSchedule::nextStart($mode->schedule, $now);
            if ($at !== null && ($best === null || $at->lt($best['at']))) {
                $best = ['mode' => $mode, 'at' => $at];
            }
        }

        return $best;
    }

    /**
     * The wall-clock time a scheduled mode hands over to the next one ("07:00"), or null.
     *
     * @param  Collection<int, HouseMode>  $modes
     */
    public static function endsAt(HouseMode $mode, Collection $modes, CarbonImmutable $now): ?string
    {
        if ($mode->schedule === null) {
            return null;
        }
        $from = HouseModeSchedule::lastStart($mode->schedule, $now) ?? HouseModeSchedule::nextStart($mode->schedule, $now);
        if ($from === null) {
            return null;
        }
        $next = self::next($modes, (int) $mode->id, $from);

        return $next === null ? null : $next['at']->setTimezone(HouseModeSchedule::ZONE)->format('H:i');
    }

    /**
     * Two modes can't start at the same time on the same day: which one wins would be a guess.
     *
     * @param  SupportCollection<int, HouseMode>  $others
     * @param  array{days: list<int>, starts: string}  $schedule
     */
    private function clashes(SupportCollection $others, array $schedule): bool
    {
        return $others->contains(fn (HouseMode $other): bool => is_array($other->schedule)
            && ($other->schedule['starts'] ?? null) === $schedule['starts']
            && array_intersect((array) ($other->schedule['days'] ?? []), $schedule['days']) !== []);
    }
}
