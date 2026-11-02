<?php

namespace Tests\Feature\HealthSafety;

use App\Models\RestraintEvent;
use Illuminate\Support\Str;

/**
 * E2E (2 Nov 2026): a restraint report that the server refuses comes back to the form with plain reasons on the fields
 * ("Choose the person.") — not "The client id field must be at least 1." — and nothing is saved.
 */
final class RestraintCaptureRefusalMessagesTest extends RestraintEventCommandTestCase
{
    public function test_a_refused_report_returns_plain_reasons_and_saves_nothing(): void
    {
        $before = RestraintEvent::query()->count();

        $this->actingAs($this->actor)->from('/health-safety/restraints/report')->post('/health-safety/restraints/events', [
            'idempotency_key' => (string) Str::uuid(), 'client_id' => 0, 'staff_involved' => [], 'authorised_by' => null,
            'restraint_type' => '', 'summary' => 'E2E TEST', 'severity' => '', 'started_at' => null, 'ended_at' => null,
            'duration_minutes' => 5, 'trigger_description' => '', 'de_escalation_attempted' => 'x', 'restraint_description' => 'x',
        ])->assertRedirect('/health-safety/restraints/report')->assertSessionHasErrors([
            'client_id' => 'Choose the person.',
            'started_at' => 'Add the date and time it started.',
            'restraint_type' => 'Choose the type of restraint.',
            'severity' => 'Choose the severity.',
            'trigger_description' => 'Say what led to the restraint.',
        ]);

        $this->assertSame($before, RestraintEvent::query()->count());
    }
}
