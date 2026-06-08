<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('populates the My Day digest notifications prop from unread user notifications', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $unreadId = (string) Str::uuid();
    $readId = (string) Str::uuid();

    DB::table('notifications')->insert([
        [
            'id' => $unreadId,
            'type' => 'App\\Notifications\\ShiftTaskDueNotification',
            'notifiable_type' => $worker->getMorphClass(),
            'notifiable_id' => $worker->id,
            'data' => json_encode([
                'title' => 'Medication round updated',
                'body' => 'Morning medication round needs attention.',
                'priority' => 'high',
            ]),
            'read_at' => null,
            'acknowledged_at' => null,
            'escalation_count' => 0,
            'last_escalated_at' => null,
            'created_at' => Carbon::now()->subMinutes(12),
            'updated_at' => Carbon::now()->subMinutes(12),
        ],
        [
            'id' => $readId,
            'type' => 'App\\Notifications\\ShiftTaskDueNotification',
            'notifiable_type' => $worker->getMorphClass(),
            'notifiable_id' => $worker->id,
            'data' => json_encode([
                'title' => 'Already read update',
                'priority' => 'high',
            ]),
            'read_at' => Carbon::now()->subMinutes(2),
            'acknowledged_at' => null,
            'escalation_count' => 0,
            'last_escalated_at' => null,
            'created_at' => Carbon::now()->subMinutes(14),
            'updated_at' => Carbon::now()->subMinutes(14),
        ],
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('stats.notifications_unread', 1)
            ->has('notifications', 1)
            ->where('notifications.0.id', $unreadId)
            ->where('notifications.0.title', 'Medication round updated')
            ->where('notifications.0.at', '12 minutes ago')
            ->where('notifications.0.tone', 'primary')
        );
});
