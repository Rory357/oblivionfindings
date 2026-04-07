<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftHandoverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftHandoverService $service;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->service = app(ShiftHandoverService::class);
        $this->site = Site::factory()->create();
        $this->serviceContext = ServiceContext::factory()->create([
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
    }

    public function test_resolve_expected_incoming_shift_returns_unique_match(): void
    {
        $outgoingStaff = $this->makeUser();
        $incomingStaff = $this->makeUser();

        $outgoingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $outgoingStaff->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addMinutes(15),
            'status' => 'in_progress',
        ]);

        $incomingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $incomingStaff->id,
            'starts_at' => now()->addMinutes(30),
            'ends_at' => now()->addHours(4),
            'status' => 'scheduled',
        ]);

        $expectation = $this->service->resolveExpectedIncomingShift($outgoingShift);

        $this->assertFalse($expectation['ambiguous']);
        $this->assertSame($incomingShift->id, $expectation['matched_shift']?->id);
    }

    public function test_resolve_expected_incoming_shift_is_conservative_when_match_is_ambiguous(): void
    {
        $outgoingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->makeUser()->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addMinutes(15),
            'status' => 'in_progress',
        ]);

        $sameStart = now()->addMinutes(30);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->makeUser()->id,
            'starts_at' => $sameStart,
            'ends_at' => $sameStart->copy()->addHours(4),
            'status' => 'scheduled',
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->makeUser()->id,
            'starts_at' => $sameStart->copy(),
            'ends_at' => $sameStart->copy()->addHours(6),
            'status' => 'scheduled',
        ]);

        $expectation = $this->service->resolveExpectedIncomingShift($outgoingShift);
        $completionRequirement = $this->service->completionRequirement($outgoingShift);

        $this->assertTrue($expectation['ambiguous']);
        $this->assertNull($expectation['matched_shift']);
        $this->assertFalse($completionRequirement['requires_handover']);
    }

    protected function makeUser(): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);

        $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        return $user;
    }
}
