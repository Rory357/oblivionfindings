<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

test('old calendar sync bookmarks redirect admins to settings', function () {
    $this->seed(RbacSeeder::class);
    $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $admin->roles()->attach(Role::where('name', 'admin')->first());
    foreach (['/operations/calendar-sync', '/operations/calendar-sync/create'] as $path) {
        $this->actingAs($admin)->get($path)->assertStatus(301)->assertRedirect('/settings/calendar-sync');
    }
});

test('old calendar sync bookmarks require the settings permission', function () {
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    foreach (['/operations/calendar-sync', '/operations/calendar-sync/create'] as $path) {
        $this->actingAs($worker)->get($path)->assertForbidden();
    }
});

test('retired calendar sync write endpoints cannot create delete or queue a connection', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['approved_at' => now()]));
    $this->post('/operations/calendar-sync', ['provider' => 'google'])->assertStatus(405);
    $this->delete('/operations/calendar-sync/1')->assertNotFound();
    $this->post('/operations/calendar-sync/1/trigger')->assertNotFound();
    foreach (['store', 'destroy', 'trigger'] as $action) {
        expect(Route::has('operations.calendar_sync.'.$action))->toBeFalse();
    }
    Queue::assertNothingPushed();
});
