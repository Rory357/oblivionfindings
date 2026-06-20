<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Services\ClinicalSignalService;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The event review / sign-off / follow-up-complete / escalate workflow that the
 * Clinical Events register exposes via its context menu.
 */
class ClinicalEventWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->client = Client::factory()->create();
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $found = Role::where('name', $role)->first();
        if ($found) {
            $user->roles()->attach($found);
        }

        return $user;
    }

    private function event(array $overrides = []): ClinicalEvent
    {
        return ClinicalEvent::factory()->create(array_merge(['client_id' => $this->client->id], $overrides));
    }

    public function test_review_sets_reviewed_fields(): void
    {
        $lead = $this->userWithRole('clinical_lead');
        $event = $this->event();

        $this->actingAs($lead)
            ->from('/health-clinical/events')
            ->patch("/health-clinical/events/{$event->id}/review")
            ->assertRedirect();

        $event->refresh();
        $this->assertNotNull($event->reviewed_at);
        $this->assertSame($lead->id, $event->reviewed_by);
        $this->assertDatabaseHas('timeline_events', [
            'source_type' => ClinicalEvent::class,
            'source_id' => $event->id,
            'subject' => 'Clinical event reviewed',
        ]);
    }

    public function test_review_forbidden_without_permission(): void
    {
        $worker = $this->userWithRole('support_worker'); // record, not review
        $event = $this->event();

        $this->actingAs($worker)
            ->patch("/health-clinical/events/{$event->id}/review")
            ->assertForbidden();
    }

    public function test_complete_followup_sets_fields(): void
    {
        $lead = $this->userWithRole('clinical_lead');
        $event = $this->event(['requires_followup' => true]);

        $this->actingAs($lead)
            ->from('/health-clinical/events')
            ->patch("/health-clinical/events/{$event->id}/follow-up/complete")
            ->assertRedirect();

        $event->refresh();
        $this->assertNotNull($event->followup_completed_at);
        $this->assertSame($lead->id, $event->followup_completed_by);
    }

    public function test_escalate_emits_signal_and_records_timeline(): void
    {
        $signals = $this->mock(ClinicalSignalService::class);
        $signals->shouldReceive('emitForEscalation')->once();

        $lead = $this->userWithRole('clinical_lead');
        $event = $this->event();

        $this->actingAs($lead)
            ->from('/health-clinical/events')
            ->post("/health-clinical/events/{$event->id}/escalate")
            ->assertRedirect();

        $this->assertDatabaseHas('timeline_events', [
            'source_id' => $event->id,
            'subject' => 'Clinical event escalated',
        ]);
    }

    public function test_escalate_forbidden_without_permission(): void
    {
        $worker = $this->userWithRole('support_worker');
        $event = $this->event();

        $this->actingAs($worker)
            ->post("/health-clinical/events/{$event->id}/escalate")
            ->assertForbidden();
    }
}
