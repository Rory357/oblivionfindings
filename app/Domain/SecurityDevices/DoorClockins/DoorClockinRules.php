<?php

namespace App\Domain\SecurityDevices\DoorClockins;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Permission;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The fixed rules of clock-ins from doors (D15, D18, D19) and the words they share. None of these
 * is a setting: faces and fingerprints never count, residents' fobs never count, only people
 * matched to staff, and a door counts only from a start date at least a week after the notice.
 */
final class DoorClockinRules
{
    /** Who sees Access › Clock-ins and sorts out gaps (granted from timesheets.approve). */
    public const string REVIEW = 'attendance.doorClockIns.review';

    /** Turning it on or off, and its settings, also needs door access management. */
    public const string MANAGE_DOORS = 'securityDevices.accessControl.manage';

    /** Only these count. Never face or fingerprint (D18); never an exit button, a remote or a schedule. */
    public const array METHODS = ['card', 'pin', 'app'];

    /** A door that opened, or was unlocked, for the person. Denied, forced and held open never count. */
    public const array KINDS = ['opened', 'unlocked'];

    public const array BEFORE_OPTIONS = [30, 60, 90];

    public const array AFTER_OPTIONS = [15, 30, 60];

    /** The gap check runs this long after each rostered start. */
    public const int GAP_AFTER_MINUTES = 15;

    /** A badge and a clock-in further apart than this go to the manager to check. */
    public const int APART_MINUTES = 15;

    /** The notice goes out at least this many days before it starts (Privacy Act IPP3). */
    public const int NOTICE_DAYS = 7;

    /** Rows go after this many days, like the names on door events (D14). */
    public const int KEEP_DAYS = 90;

    /** Shifts that can still be clocked into (AttendanceService's own list). */
    public const array CLOCKABLE = ['draft', 'scheduled', 'in_progress'];

    public static function canReview(User $user): bool
    {
        return $user->canDo(self::REVIEW);
    }

    /** Turning it on sends a privacy notice about staff: both door access and timesheet authority. */
    public static function canChange(User $user): bool
    {
        return $user->canDo(self::MANAGE_DOORS) && $user->canDo(self::REVIEW);
    }

    /**
     * The Sites whose clock-ins someone sorts out: attendance's own Site rules (their HR Sites, or
     * every Site with reports.viewAny), the same as timesheets.
     *
     * @return list<int>
     */
    public static function reviewSiteIds(User $user): array
    {
        return array_values(app(UserSiteAccessService::class)->accessibleSiteIds($user, UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS));
    }

    /** The window, the badge-time switch and who hears about gaps apply to every Site, so only someone who sees every Site changes them. */
    public static function seesEverySite(User $user): bool
    {
        return app(UserSiteAccessService::class)->canBypass($user, UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS)
            || app(SecurityDevicesAccessService::class)->canViewAllSites($user);
    }

    public static function timezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }

    public static function local(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(self::timezone());
    }

    public static function today(?CarbonInterface $now = null): string
    {
        return self::local($now ?? CarbonImmutable::now())->toDateString();
    }

    /** "6:52 am" in NZ time. */
    public static function time(CarbonInterface $at): string
    {
        return self::local($at)->format('g:i a');
    }

    /** "Mon 19 Oct" (this year) or "Mon 19 Oct 2027". */
    public static function date(CarbonInterface|string $day, ?CarbonInterface $now = null): string
    {
        $date = is_string($day) ? CarbonImmutable::parse($day, self::timezone()) : self::local($day);
        $year = self::local($now ?? CarbonImmutable::now())->year;

        return $date->format($date->year === $year ? 'D j M' : 'D j M Y');
    }

    /** "Kauri House front door": the Site, then the door as UniFi names it (unless it already says the Site). */
    public static function doorPlace(?string $site, ?string $door): string
    {
        $door = trim((string) $door);
        $site = trim((string) $site);
        if ($door === '') {
            return $site !== '' ? "{$site} door" : 'a door';
        }
        if ($site === '' || str_contains(mb_strtolower($door), mb_strtolower($site))) {
            return $door;
        }

        return $site.' '.mb_strtolower(mb_substr($door, 0, 1)).mb_substr($door, 1);
    }

    public static function methodWord(?string $method): string
    {
        return match ($method) {
            'pin' => 'PIN',
            'app' => 'phone',
            default => 'card',
        };
    }

    public static function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? 'them' : (string) preg_split('/\s+/', $name)[0];
    }

    /**
     * The people who sort out clock-in gaps at a Site: those who hold the review key and can see
     * that Site. Managers bound to their own Sites come first; when a Site has none, those who can
     * see every Site are told instead, so a gap is never told to nobody.
     *
     * @return Collection<int, User>
     */
    public static function houseManagers(int $siteId): Collection
    {
        $permissionId = Permission::query()->where('key', self::REVIEW)->value('id');
        if ($permissionId === null) {
            return collect();
        }
        $attendance = app(UserSiteAccessService::class);
        $candidates = User::query()
            ->whereNotNull('approved_at')
            ->where(fn ($query) => $query
                ->whereHas('roles.permissions', fn ($permissions) => $permissions->whereKey($permissionId))
                ->orWhereHas('permissionOverrides', fn ($overrides) => $overrides->whereKey($permissionId)->where('permission_user.allowed', true)))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->filter(fn (User $user): bool => self::canReview($user) && in_array($siteId, self::reviewSiteIds($user), true))
            ->values();

        $local = $candidates->reject(fn (User $user): bool => $attendance->canBypass($user, UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS))->values();

        return $local->isNotEmpty() ? $local : $candidates;
    }
}
