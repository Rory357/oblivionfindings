<?php

namespace App\Domain\SecurityDevices\Access;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Access\Models\AccessJob;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * S&D Phase H (D20): when someone's employment ends in HR, their UniFi Access person is turned off
 * at the end of their last day (New Zealand time), so their cards and PINs stop opening doors.
 * Only people matched to that staff member are touched. Turning off only ever tightens access.
 */
final class AccessLeavers
{
    private const string ZONE = 'Pacific/Auckland';

    /** After a change that didn't go through, wait this long before asking the hub again. */
    private const int RETRY_HOURS = 6;

    public function __construct(private readonly AccessJobs $jobs) {}

    /** @return int changes asked for */
    public function tick(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $queued = 0;
        AccessPerson::query()
            ->with('console')
            ->where('match_state', 'matched')
            ->where('kind', 'staff')
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (AccessPerson $person) use ($now, &$queued): void {
                $lastDay = HrEmployeeProfile::query()->where('user_id', $person->user_id)->whereNotNull('end_date')->orderByDesc('end_date')->value('end_date');
                if ($lastDay === null) {
                    return;
                }
                $endsAt = CarbonImmutable::parse(CarbonImmutable::parse($lastDay)->format('Y-m-d'), self::ZONE)->addDay()->utc();
                if ($now->lt($endsAt) || $this->recentlyAsked($person, $now)) {
                    return;
                }
                try {
                    $this->jobs->queue($person->console, 'user_status', [
                        'user_id' => $person->external_id,
                        'status' => 'deactivated',
                    ], null, 'Left: last day '.CarbonImmutable::parse($lastDay)->format('j M Y'), 1800);
                    $queued++;
                } catch (Throwable $exception) {
                    // No hub, an old hub or no saved login: the Access page shows the leaver still active.
                    Log::warning('UniFi Access leaver could not be turned off yet', ['person_id' => $person->id, 'error' => $exception->getMessage()]);
                }
            });

        return $queued;
    }

    private function recentlyAsked(AccessPerson $person, CarbonImmutable $now): bool
    {
        return AccessJob::query()
            ->where('console_id', $person->console_id)
            ->where('kind', 'user_status')
            ->where(fn ($query) => $query->whereIn('state', ['queued', 'sent'])->orWhere('created_at', '>', $now->subHours(self::RETRY_HOURS)))
            ->get()
            ->contains(fn (AccessJob $job): bool => ($job->params['user_id'] ?? null) === $person->external_id);
    }
}
