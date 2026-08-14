<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('sends verification notification', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('does not send verification notification if email is verified', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('dashboard', absolute: false));

    Notification::assertNothingSent();
});

test('verification notification resend is throttled', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    foreach (range(1, 6) as $_) {
        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('home'));
    }

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertTooManyRequests();

    Notification::assertSentToTimes($user, VerifyEmail::class, 6);
});
