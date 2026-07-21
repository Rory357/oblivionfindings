<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

function itSelfServiceUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = itSelfServiceUser('hr');
    $this->worker = itSelfServiceUser('support_worker');
    $this->site = Site::factory()->create();
    foreach ([$this->hr, $this->worker] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

test('raising a ticket sends the requester a receipt with the reference only', function () {
    Notification::fake();

    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Tablet screen cracked',
            'description' => 'Dropped while supporting Hemi at Aroha House.',
            'category' => 'hardware',
            'priority' => 'normal',
        ])
        ->assertRedirect()
        ->assertSessionHas('it_ticket');

    $ticket = ItTicket::query()->firstWhere('title', 'Tablet screen cracked');

    Notification::assertSentTo(
        $this->worker,
        TicketCreatedNotification::class,
        function (TicketCreatedNotification $notification) use ($ticket) {
            $payload = $notification->toArray($this->worker);
            $mail = $notification->toMail($this->worker);

            return $payload['audience'] === 'receipt'
                && $payload['reference'] === $ticket->reference
                && $payload['action_url'] === '/it?tab=my-tickets'
                // Privacy: neither channel may carry the description (it can
                // name the people we support).
                && ! array_key_exists('description', $payload)
                && str_contains($mail->subject, $ticket->reference)
                && ! str_contains(json_encode($mail->toArray($this->worker)), 'Aroha House');
        },
    );

    // A normal-priority ticket never pings the queue agents.
    Notification::assertNotSentTo($this->hr, TicketCreatedNotification::class);
});

test('urgent tickets alert the it.manage agents but never the actor', function () {
    Notification::fake();

    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Phone dead mid-shift',
            'category' => 'hardware',
            'priority' => 'urgent',
        ])
        ->assertRedirect();

    // The requester gets a receipt; the agent gets the urgent alert.
    Notification::assertSentTo(
        $this->worker,
        TicketCreatedNotification::class,
        fn (TicketCreatedNotification $n) => $n->toArray($this->worker)['audience'] === 'receipt',
    );
    Notification::assertSentTo(
        $this->hr,
        TicketCreatedNotification::class,
        fn (TicketCreatedNotification $n) => $n->toArray($this->hr)['audience'] === 'urgent_alert',
    );

    Notification::fake();

    // When an AGENT logs the urgent ticket themselves they still get the
    // receipt (they are the requester) but never their own urgent alert.
    $this->actingAs($this->hr)
        ->post('/it/tickets', [
            'title' => 'Server room UPS beeping',
            'category' => 'other',
            'priority' => 'urgent',
            'site_id' => $this->site->id,
        ])
        ->assertRedirect();

    $alertsToActor = 0;
    Notification::assertSentTo(
        $this->hr,
        TicketCreatedNotification::class,
        function (TicketCreatedNotification $n) use (&$alertsToActor) {
            if ($n->toArray($this->hr)['audience'] === 'urgent_alert') {
                $alertsToActor++;
            }

            return true;
        },
    );
    expect($alertsToActor)->toBe(0);
});

test('the raise flash carries the new reference for the success pane', function () {
    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Printer jammed again',
            'category' => 'hardware',
            'priority' => 'low',
        ])
        ->assertRedirect()
        ->assertSessionHas('it_ticket', function (array $flash) {
            $ticket = ItTicket::query()->firstWhere('title', 'Printer jammed again');

            return $flash['id'] === $ticket->id && $flash['reference'] === $ticket->reference;
        });
});
