<?php

use App\Models\Client;
use App\Models\MedicationReview;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

// .com 7 Nov 2026: a scheduled medication review reaches the profile's
// Actions list as an All Tasks row whose due time is an ISO string, and the
// aggregator called ->toISOString() on it, so the whole profile returned 500.
it('opens a person profile that has a scheduled medication review', function () {
    $this->seed(RbacSeeder::class);
    $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

    $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $review = MedicationReview::query()->create([
        'client_id' => $client->id,
        'review_type' => 'routine',
        'status' => 'scheduled',
        'scheduled_date' => now()->addWeek()->toDateString(),
    ]);

    $response = $this->actingAs($admin)
        ->get("/operations/clients/{$client->id}")
        ->assertOk();

    $action = collect($response->inertiaProps('actions_reviews'))
        ->firstWhere('source_id', $review->id);

    expect($action)->not->toBeNull()
        ->and($action['type'])->toBe('medication_review')
        ->and($action['due_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        ->and(Carbon::parse($action['due_at'])->isFuture())->toBeTrue();

    $listed = $this->actingAs($admin)
        ->getJson("/operations/clients/{$client->id}/actions")
        ->assertOk()
        ->json('data');

    expect(collect($listed)->firstWhere('source_id', $review->id)['due_at'] ?? null)
        ->toBe($action['due_at']);
});
