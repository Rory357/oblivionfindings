<?php

namespace Tests\Feature\Operations;

use App\Jobs\SyncCalendarJob;
use App\Models\CalendarSync;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CalendarSyncTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_trigger_sync_dispatches_sync_job_and_does_not_prematurely_advance_timestamp(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $sync = CalendarSync::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'calendar_id' => 'primary',
            'sync_direction' => 'both',
            'last_synced_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->post("/operations/calendar-sync/{$sync->id}/trigger");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Queue::assertPushed(SyncCalendarJob::class, function ($job) use ($sync) {
            return $job->calendarSync->id === $sync->id;
        });

        $sync->refresh();
        $this->assertNull($sync->last_synced_at, 'last_synced_at should only be updated by SyncCalendarJob upon truthful success');
    }

    public function test_trigger_sync_denies_access_to_non_owner(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $sync = CalendarSync::create([
            'user_id' => $owner->id,
            'provider' => 'google',
            'calendar_id' => 'primary',
            'sync_direction' => 'both',
            'last_synced_at' => null,
        ]);

        $response = $this->actingAs($otherUser)
            ->post("/operations/calendar-sync/{$sync->id}/trigger");

        $response->assertNotFound();
        Queue::assertNotPushed(SyncCalendarJob::class);
    }
}
