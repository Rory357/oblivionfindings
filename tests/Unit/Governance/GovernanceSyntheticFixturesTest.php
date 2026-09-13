<?php

declare(strict_types=1);

namespace Tests\Unit\Governance;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceSyntheticFixtures;
use Tests\TestCase;

class GovernanceSyntheticFixturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_fixtures_seed_complete_isolated_environment(): void
    {
        $data = GovernanceSyntheticFixtures::seed();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('meetings', $data);
        $this->assertArrayHasKey('resolutions', $data);

        // Verify users
        $this->assertCount(7, $data['users']);
        $chair = User::find($data['users']['chair']);
        $this->assertNotNull($chair);
        $this->assertSame('Synthetic Chair', $chair->name);

        $member = User::find($data['users']['member']);
        $dupMember = User::find($data['users']['member_duplicate_name']);
        $this->assertNotNull($member);
        $this->assertNotNull($dupMember);
        $this->assertNotSame($member->id, $dupMember->id);
        $this->assertSame($member->name, $dupMember->name);

        // Verify 40 action items
        $this->assertSame(40, ActionItem::count());
        $blockedAction = ActionItem::where('status', 'blocked')->first();
        $this->assertNotNull($blockedAction);
        $this->assertSame($member->id, $blockedAction->assigned_to);

        // Verify 12 above-appetite risks
        $risks = RiskRegisterEntry::where('within_appetite', false)->get();
        $this->assertGreaterThanOrEqual(12, $risks->count());

        // Verify meetings: private earlier than regular
        $private = GovernanceMeeting::find($data['meetings']['private']);
        $regular = GovernanceMeeting::find($data['meetings']['regular']);
        $this->assertNotNull($private);
        $this->assertNotNull($regular);
        $this->assertTrue($private->isExecutiveSession());
        $this->assertFalse($regular->isExecutiveSession());
        $this->assertTrue($private->scheduled_at < $regular->scheduled_at);

        // Verify decisions: draft, open, expired, null deadline
        $this->assertSame(4, Resolution::count());
        $open = Resolution::find($data['resolutions']['open']);
        $this->assertSame('open', $open->status);
        $this->assertNotNull($open->deadline);

        $nullDeadline = Resolution::find($data['resolutions']['null_deadline']);
        $this->assertNull($nullDeadline->deadline);
    }
}
