<?php

namespace Tests\Support\SecurityDevices;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Test job: records the S&D Sites a viewer sees inside one queue job. */
class RecordAccessibleSiteIds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<list<int>> */
    public static array $seen = [];

    public function __construct(public int $userId) {}

    public function handle(SecurityDevicesAccessService $access): void
    {
        self::$seen[] = $access->accessibleSiteIds(User::query()->findOrFail($this->userId));
    }
}
