<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('frontline authenticated users can open their separate overview summary', function () {
    $this->actingAs($user = User::factory()->frontlineWorker()->create());

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page->component('dashboard')->where('role', 'worker'));
});
