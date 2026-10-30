<?php

namespace Tests\Feature\Incidents;

use App\Models\Site;
use PHPUnit\Framework\Attributes\DataProvider;

/** Canonical severity preservation, with existing one-organisation Site/role boundaries. */
final class IncidentCriticalDraftUpdateTest extends IncidentRoutingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->allow($this->manager, 'incidents.update');
    }

    public function test_editing_an_existing_critical_draft_keeps_critical_and_records_nz_wall_time(): void
    {
        $incident = $this->incident(['status' => 'draft', 'submitted_at' => null, 'severity' => 'critical']);
        $this->actingAs($this->manager)->from("/incidents/{$incident->id}")
            ->put("/incidents/{$incident->id}", [
                'type' => 'fall', 'severity' => 'critical', 'description' => 'Updated synthetic draft.',
                'occurred_at' => '2026-10-12T16:37',
            ])->assertRedirect("/incidents/{$incident->id}")->assertSessionHasNoErrors();
        $incident->refresh();
        $this->assertSame('critical', $incident->severity);
        $this->assertSame('draft', $incident->status);
        $this->assertSame('Updated synthetic draft.', $incident->description);
        $this->assertSame('2026-10-12 03:37', $incident->occurred_at->copy()->utc()->format('Y-m-d H:i'));
    }

    public function test_a_partial_critical_draft_edit_does_not_require_or_replace_its_retained_severity(): void
    {
        $incident = $this->incident(['status' => 'draft', 'submitted_at' => null, 'severity' => 'critical']);
        $this->actingAs($this->manager)->put("/incidents/{$incident->id}", ['description' => 'Keep critical severity.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('critical', $incident->fresh()->severity);
        $this->assertSame('Keep critical severity.', $incident->fresh()->description);
    }

    public function test_unknown_occurrence_time_can_be_explicitly_cleared_without_losing_critical_severity(): void
    {
        $incident = $this->incident(['status' => 'draft', 'submitted_at' => null, 'severity' => 'critical', 'occurred_at' => now()]);
        $this->actingAs($this->manager)->put("/incidents/{$incident->id}", ['occurred_at' => ''])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($incident->fresh()->occurred_at);
        $this->assertSame('critical', $incident->fresh()->severity);
    }

    #[DataProvider('lockedStatuses')]
    public function test_manager_notes_on_a_critical_locked_record_preserve_its_core_fields(string $status): void
    {
        $incident = $this->incident(['status' => $status, 'severity' => 'critical', 'is_notifiable' => true]);
        $description = $incident->description;
        $this->actingAs($this->manager)->put("/incidents/{$incident->id}", [
            'review_notes' => 'Manager review notes.', 'severity' => 'low',
            'description' => 'This core change must not be adopted.', 'is_notifiable' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $incident->refresh();
        $this->assertSame('Manager review notes.', $incident->review_notes);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame($description, $incident->description);
        $this->assertTrue($incident->is_notifiable);
        // Omitted severity is merged from the canonical record and must also validate.
        $this->actingAs($this->manager)->put("/incidents/{$incident->id}", ['review_notes' => 'Further review.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('critical', $incident->fresh()->severity);
        $this->assertSame('Further review.', $incident->fresh()->review_notes);
    }

    public static function lockedStatuses(): array
    {
        return ['submitted' => ['submitted'], 'reviewed' => ['reviewed']];
    }

    public function test_an_unknown_severity_is_rejected_without_changing_the_critical_record(): void
    {
        $incident = $this->incident(['status' => 'draft', 'submitted_at' => null, 'severity' => 'critical']);
        $this->actingAs($this->manager)->put("/incidents/{$incident->id}", ['type' => 'fall', 'severity' => 'unknown'])
            ->assertSessionHasErrors('severity');
        $this->assertSame('critical', $incident->fresh()->severity);
    }

    public function test_critical_severity_does_not_allow_editing_a_closed_record(): void
    {
        $incident = $this->incident(['status' => 'closed', 'severity' => 'critical']);
        $description = $incident->description;
        $this->actingAs($this->manager)->put("/incidents/{$incident->id}", ['description' => 'Denied closed edit.'])
            ->assertForbidden();
        $this->assertSame($description, $incident->fresh()->description);
        $this->assertSame('critical', $incident->fresh()->severity);
    }

    public function test_critical_severity_does_not_broaden_the_original_site_boundary(): void
    {
        $foreign = Site::factory()->create(['is_active' => true]);
        $incident = $this->incident(['site_id' => $foreign->id, 'status' => 'draft', 'submitted_at' => null, 'severity' => 'critical']);
        $description = $incident->description;
        $this->actingAs($this->manager)->put("/incidents/{$incident->id}", ['description' => 'Denied Site edit.'])
            ->assertForbidden();
        $this->assertSame($description, $incident->fresh()->description);
        $this->assertSame('critical', $incident->fresh()->severity);
    }
}
