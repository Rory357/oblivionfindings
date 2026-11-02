<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Controllers\HealthSafety\HsEventController;
use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Models\User;

/**
 * Decision 12 (Stephan, 1 Nov 2026; G4-6): the team lead who authorised a restraint is never offered its safety review
 * and can't be made — or make themselves — its owner. Every owner path (the list of people offered, taking it on,
 * choosing and changing the owner) goes through HsEventController::canOwnEvent(), so the rule is checked there.
 */
class HsRestraintReviewIndependenceTest extends RestraintEventCommandTestCase
{
    private const MANAGER = ['hazards.view', 'hazards.manage', 'restraints.view', 'restraints.review'];

    private function canOwn(User $person, HsEvent $event, User $viewer): bool
    {
        return (fn (User $p, HsEvent $e, User $v): bool => $this->canOwnEvent($p, $e, $v))
            ->call(app(HsEventController::class), $person, $event, $viewer);
    }

    public function test_the_authoriser_of_a_restraint_can_never_own_its_safety_review(): void
    {
        $authoriser = $this->staff(self::MANAGER);
        $officer = $this->staff(self::MANAGER);
        $restraint = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'authorised_by' => $authoriser->id, 'created_by' => $authoriser->id,
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'event_category' => HsEvent::CATEGORY_RESTRAINT,
            'source_type' => RestraintEvent::class, 'source_id' => $restraint->id, 'owner_user_id' => null,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'status' => HsEvent::STATUS_OPEN,
        ]));

        $this->assertFalse($this->canOwn($authoriser, $event, $officer), 'Chosen by someone else.');
        $this->assertFalse($this->canOwn($authoriser, $event, $authoriser), 'Taking it on themselves.');

        // The same person can own a review of a restraint they didn't authorise.
        $otherRestraint = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'authorised_by' => $officer->id, 'created_by' => $officer->id,
        ]));
        $otherEvent = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'event_category' => HsEvent::CATEGORY_RESTRAINT,
            'source_type' => RestraintEvent::class, 'source_id' => $otherRestraint->id, 'owner_user_id' => null,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'status' => HsEvent::STATUS_OPEN,
        ]));
        $this->assertTrue($this->canOwn($authoriser, $otherEvent, $authoriser));
    }
}
