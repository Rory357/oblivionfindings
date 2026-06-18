<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gated event closure (E-Gap 1) — HsEventService::closeEvent() + the gate:
 * a required investigation must be complete and every corrective action verified
 * (or overridden with a logged reason); a closure summary is always required.
 */
class HsEventClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function hsOfficer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_clean_event_closes_with_summary(): void
    {
        $event = HsEvent::factory()->create(); // low severity → no investigation required, no actions

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Resolved — controls verified, no further action.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertEquals(HsEvent::STATUS_CLOSED, $event->status);
        $this->assertNotNull($event->closed_at);
        $this->assertNotNull($event->closed_by);
        $this->assertNotEmpty($event->closure_summary);
    }

    public function test_close_blocked_when_required_investigation_incomplete(): void
    {
        $event = HsEvent::factory()->high()->create(); // investigation_required, none completed

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Trying to close.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_close_blocked_with_unverified_corrective_action(): void
    {
        $event = HsEvent::factory()->create();
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => 'open']);

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Trying to close.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_override_closes_a_blocked_event_with_logged_reason(): void
    {
        $event = HsEvent::factory()->high()->create();

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Closing with override.',
                'override_reason' => 'Investigation handled at board level; minuted outside the system.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_close_requires_a_summary(): void
    {
        $event = HsEvent::factory()->create();

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [])
            ->assertSessionHasErrors('closure_summary');

        $this->assertNotEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_close_requires_hazards_manage(): void
    {
        $event = HsEvent::factory()->create();
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'No permission.',
            ])
            ->assertForbidden();
    }
}
