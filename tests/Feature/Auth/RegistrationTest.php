<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertStatus(200);
});

test('new users can register', function () {
    Notification::fake();

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

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->approved_at)->toBeNull()
        ->and($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});
