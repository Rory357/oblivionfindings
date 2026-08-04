<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('frontline authenticated users are redirected to their canonical my day home', function () {
    $this->actingAs($user = User::factory()->frontlineWorker()->create());

    $this->get(route('dashboard'))->assertRedirect(route('my-day'));
});
