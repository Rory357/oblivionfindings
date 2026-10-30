<?php

namespace Tests\Unit\Services;

use App\Models\Shift;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Services\Eligibility\Rules\AvailabilityRule;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeclaredAvailabilityWindowTest extends TestCase
{
    #[DataProvider('windows')]
    public function test_declared_windows_cover_worker_local_instants(array $slots, string $start, string $end, bool $covered): void
    {
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $user = new User;
        $user->setRelation('staffAvailability', collect($slots)->map(fn (array $slot) => new StaffAvailability($slot)));
        $shift = new Shift(['starts_at' => Carbon::parse($start, 'Pacific/Auckland')->utc(), 'ends_at' => Carbon::parse($end, 'Pacific/Auckland')->utc()]);
        $rule = new class extends AvailabilityRule
        {
            public function declaration(Shift $shift, User $user): array
            {
                return $this->checkDeclaredAvailability($shift, $user);
            }
        };
        $result = $rule->declaration($shift, $user);
        $this->assertSame($covered, $result['passed']);
        if (! $covered) {
            $this->assertSame('warning', $result['severity']);
            $this->assertTrue($result['overrideable']);
        }
    }

    public static function windows(): array
    {
        $night = ['day_of_week' => 1, 'starts_at' => '21:00:00', 'ends_at' => '07:00:00', 'ends_next_day' => true];
        $morning = ['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00'];
        $afternoon = ['day_of_week' => 1, 'starts_at' => '12:00:00', 'ends_at' => '17:00:00'];

        return [
            'whole overnight declaration' => [[$night], '2026-10-05 21:00', '2026-10-06 07:00', true],
            'after-midnight preceding day carry' => [[$night], '2026-10-06 01:00', '2026-10-06 06:00', true],
            'before start is unavailable' => [[$night], '2026-10-05 20:00', '2026-10-05 22:00', false],
            'after end is unavailable' => [[$night], '2026-10-06 06:00', '2026-10-06 08:00', false],
            'Sunday Monday week wrap' => [[array_replace($night, ['day_of_week' => 0])], '2026-10-04 21:00', '2026-10-05 07:00', true],
            'daylight saving night uses wall times' => [[array_replace($night, ['day_of_week' => 6])], '2026-09-26 21:00', '2026-09-27 07:00', true],
            'adjacent windows form continuous availability' => [[$morning, $afternoon], '2026-10-05 09:00', '2026-10-05 17:00', true],
            'a gap remains unavailable' => [[$morning, array_replace($afternoon, ['starts_at' => '13:00:00'])], '2026-10-05 09:00', '2026-10-05 17:00', false],
            'unflagged negative window is not overnight' => [[array_replace($night, ['ends_next_day' => false])], '2026-10-05 21:00', '2026-10-06 07:00', false],
            'explicit full local day' => [[array_replace($night, ['starts_at' => '00:00:00', 'ends_at' => '00:00:00'])], '2026-10-05 00:00', '2026-10-06 00:00', true],
        ];
    }
}
