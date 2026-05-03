<?php

use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'StrongerPass123!',
        'password_confirmation' => 'StrongerPass123!',
    ]);

    $this->assertGuest();
    $response
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHas('status', 'Account created. An administrator must approve your access before you can log in.');

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'approved_at' => null,
    ]);
    $this->assertTrue(User::where('email', 'test@example.com')->exists());
});
