<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Governance contract guard.
 *
 * ClinicalGovernanceAutomationService publishes board-report drill-down links of
 * the exact shape:
 *
 *   /health-clinical/events?event_type=fall&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
 *   /health-clinical/events?event_type=skin_integrity&date_from=…&date_to=…
 *   /health-clinical/events?event_type=infection_sign&date_from=…&date_to=…
 *
 * These hrefs are stored on governance findings, so the route path, name and the
 * three `event_type` enum values MUST stay stable and keep loading. This test
 * fails loudly if a redesign renames the route or drops/renames an enum value.
 */
class GovernanceDeepLinkRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The frozen governance deep-link event types (see
     * ClinicalGovernanceAutomationService source_href values).
     */
    private const FROZEN_EVENT_TYPES = ['fall', 'skin_integrity', 'infection_sign'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
    }

    protected function createClinicalLead(): User
    {
        $user = User::factory()->create(['role' => 'clinical_lead', 'approved_at' => now()]);
        if ($role = Role::where('name', 'clinical_lead')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_frozen_event_type_values_still_exist_on_the_enum(): void
    {
        foreach (self::FROZEN_EVENT_TYPES as $value) {
            $this->assertNotNull(
                ClinicalEventType::tryFrom($value),
                "Governance deep-link event_type '{$value}' no longer maps to a ClinicalEventType case."
            );
        }
    }

    #[DataProvider('frozenDeepLinkProvider')]
    public function test_governance_deep_link_loads_and_filters(string $eventType): void
    {
        $lead = $this->createClinicalLead();
        $client = Client::factory()->create();

        // The targeted event type within the window…
        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
            'event_type' => $eventType,
            'occurred_at' => now()->subDay(),
        ]);

        // …and a different type that must be filtered out.
        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
            'event_type' => ClinicalEventType::Other->value,
            'occurred_at' => now()->subDay(),
        ]);

        $href = sprintf(
            '/health-clinical/events?event_type=%s&date_from=%s&date_to=%s',
            $eventType,
            now()->subDays(7)->toDateString(),
            now()->toDateString(),
        );

        $this->actingAs($lead)
            ->get($href)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/Events')
                ->has('events.data', 1)
                ->where('events.data.0.event_type', $eventType)
            );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function frozenDeepLinkProvider(): array
    {
        return [
            'fall' => ['fall'],
            'skin_integrity' => ['skin_integrity'],
            'infection_sign' => ['infection_sign'],
        ];
    }
}
