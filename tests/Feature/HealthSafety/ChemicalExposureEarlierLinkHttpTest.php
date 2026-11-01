<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureRecoveryCase;
use Illuminate\Support\Str;

/**
 * #15 (boards 15-1 to 15-3, approved by Stephan 2 Nov): the exposure page's "Earlier link to an incident" card and its
 * "Record a review" routes. A manager sees every later review and records one; a review's own words show only once it
 * confirms the link; a reader without hazards.manage sees only whether it was reviewed; the old record never changes.
 */
final class ChemicalExposureEarlierLinkHttpTest extends ChemicalExposureRecoveryTestCase
{
    private function card(\App\Models\User $viewer, int $exposureId): ?array
    {
        return $this->actingAs($viewer)->get('/health-safety/chemical-exposures/'.$exposureId.'?section=connections')
            ->assertOk()->inertiaProps('earlier_link');
    }

    private function review(int $exposureId, array $values)
    {
        return $this->post('/health-safety/chemical-exposures/'.$exposureId.'/earlier-link/reviews',
            ['idempotency_key' => (string) Str::uuid(), 'reviewed_at' => '2026-10-12T01:00:00Z', ...$values]);
    }

    public function test_manager_records_reviews_and_the_latest_replaces_the_earlier_one(): void
    {
        $root = $this->legacy(); $before = $root->fresh()->getRawOriginal(); $incident = $this->selectedIncident();

        $card = $this->card($this->manager, $root->id);
        $this->assertSame('unreviewed', $card['state']);
        $this->assertTrue($card['manager']);
        $this->assertTrue($card['can']['record_review']);
        $this->assertSame([], $card['reviews']);

        $this->review($root->id, ['expected_version' => 0, 'decision' => 'unresolved', 'reason' => 'UNRESOLVED-BASIS waiting for the roster'])
            ->assertRedirect('/health-safety/chemical-exposures/'.$root->id.'?section=connections')->assertSessionHasNoErrors();
        $this->review($root->id, ['expected_version' => 1, 'decision' => 'reviewed_association', 'incident_id' => $incident->id,
            'reason' => 'The roster puts the spill in the fall clean-up', 'notes' => 'Checked with the Site lead'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $card = $this->card($this->manager, $root->id);
        $this->assertSame('reviewed', $card['state']);
        $this->assertCount(2, $card['reviews']);
        [$latest, $earlier] = $card['reviews'];
        $this->assertSame('reviewed_association', $latest['decision']);
        $this->assertSame('The roster puts the spill in the fall clean-up', $latest['reason']);
        $this->assertSame('Checked with the Site lead', $latest['notes']);
        $this->assertSame($incident->reference_number, $latest['incident']['reference']);
        $this->assertSame('unresolved', $earlier['decision']);
        // An unresolved basis stays on file, unread (as the service's own inspection).
        $this->assertNull($earlier['reason']);
        $this->assertStringNotContainsString('UNRESOLVED-BASIS', json_encode($card, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('SEALED-ORIGINAL', json_encode($card, JSON_THROW_ON_ERROR));
        $this->assertSame($before, $root->fresh()->getRawOriginal(), 'The old record is never changed.');
    }

    public function test_a_stale_version_is_refused_and_the_form_keeps_its_words(): void
    {
        $root = $this->legacy();
        $this->actingAs($this->manager);
        $this->review($root->id, ['expected_version' => 0, 'decision' => 'unresolved', 'reason' => 'First'])->assertSessionHasNoErrors();
        $this->review($root->id, ['expected_version' => 0, 'decision' => 'reviewed_no_association', 'reason' => 'Second'])
            ->assertSessionHasErrors('expected_version');
        $this->assertSame(1, ChemicalExposureRecoveryCase::where('exposure_id', $root->id)->value('revision'));
    }

    public function test_a_reader_without_hazards_manage_sees_only_whether_it_was_reviewed_and_cannot_record(): void
    {
        $root = $this->legacy();
        $this->actingAs($this->manager);
        $this->review($root->id, ['expected_version' => 0, 'decision' => 'unresolved', 'reason' => 'MANAGER-ONLY words'])->assertSessionHasNoErrors();
        $reader = $this->staff(['hazards.view', 'hr.wellbeing.view']);

        $card = $this->card($reader, $root->id);
        $this->assertSame('reviewed', $card['state']);
        $this->assertFalse($card['manager']);
        $this->assertSame(['record_review' => false, 'add_file' => false], $card['can']);
        $this->assertSame([], $card['reviews']);
        $this->assertStringNotContainsString('MANAGER-ONLY', json_encode($card, JSON_THROW_ON_ERROR));

        $status = $this->actingAs($reader)->review($root->id, ['expected_version' => 1, 'decision' => 'reviewed_no_association', 'reason' => 'Not mine to record'])->status();
        $this->assertContains($status, [403, 404]);
        $this->assertSame(1, ChemicalExposureRecoveryCase::where('exposure_id', $root->id)->value('revision'));
    }

    public function test_a_record_without_an_unconfirmed_earlier_link_has_no_card(): void
    {
        $root = $this->exposure();

        $this->assertNull($this->card($this->manager, $root->id));
    }
}
