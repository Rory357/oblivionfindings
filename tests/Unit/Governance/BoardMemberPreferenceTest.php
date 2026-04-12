<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Models\BoardMemberPreference;
use Carbon\Carbon;
use Tests\TestCase;

class BoardMemberPreferenceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_digest_window_uses_member_timezone_and_expected_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-12 08:05:00', 'Pacific/Auckland'));

        $preference = new BoardMemberPreference([
            'timezone' => 'Pacific/Auckland',
            'digest_day' => 'Sunday',
            'digest_time' => '08:00',
            'digest_enabled' => true,
        ]);

        $window = $preference->digestWindowFor();

        $this->assertSame('2026-04-12 08:00', $window['start']->format('Y-m-d H:i'));
        $this->assertSame('2026-04-12 08:15', $window['end']->format('Y-m-d H:i'));
        $this->assertTrue($preference->isDigestDueAt(Carbon::parse('2026-04-12 08:10:00', 'Pacific/Auckland')));
        $this->assertFalse($preference->isDigestDueAt(Carbon::parse('2026-04-12 08:16:00', 'Pacific/Auckland')));
    }
}
