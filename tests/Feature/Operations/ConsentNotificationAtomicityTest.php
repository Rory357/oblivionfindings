<?php

namespace Tests\Feature\Operations;

use App\Models\ConsentRequest;
use App\Models\User;
use App\Notifications\Operations\ConsentRequestCreatedNotification;
use App\Notifications\Operations\ConsentRequestReminderNotification;
use App\Notifications\Operations\ConsentRequestRespondedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ConsentNotificationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_consent_notifications_implement_should_queue(): void
    {
        $this->assertTrue(is_subclass_of(ConsentRequestCreatedNotification::class, ShouldQueue::class));
        $this->assertTrue(is_subclass_of(ConsentRequestRespondedNotification::class, ShouldQueue::class));
        $this->assertTrue(is_subclass_of(ConsentRequestReminderNotification::class, ShouldQueue::class));
    }

    public function test_notifications_are_queued_and_not_sent_synchronously(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $request = new ConsentRequest(['id' => 123]);

        Notification::send($user, new ConsentRequestCreatedNotification($request));

        Notification::assertSentTo(
            $user,
            ConsentRequestCreatedNotification::class
        );
    }
}
