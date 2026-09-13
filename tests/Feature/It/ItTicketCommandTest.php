<?php

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$this->worker, $this->agent] as $actor) {
        $actor->roles()->syncWithoutDetaching(Role::query()->where('name', $actor->role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
    }
    $this->input = [
        'request_uuid' => (string) Str::uuid(),
        'title' => 'Isolated command fixture',
        'description' => 'Private draft details that must not enter the command ledger.',
        'category' => 'hardware',
        'priority' => 'normal',
    ];
    Notification::fake();
    Storage::fake(ItAttachment::DISK);
});

test('a browser retry returns the same committed ticket without duplicate activity or notifications', function () {
    $first = $this->actingAs($this->worker)->postJson('/it/tickets', $this->input)
        ->assertCreated()->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.replayed', false);
    $ticketId = $first->json('data.id');
    $ticket = ItTicket::query()->findOrFail($ticketId);
    $eventCount = $ticket->events()->count();
    $deliveryCount = ItEmailDelivery::query()->count();

    $this->postJson('/it/tickets', $this->input)->assertOk()
        ->assertJsonPath('data.id', $ticketId)
        ->assertJsonPath('data.reference', $ticket->reference)
        ->assertJsonPath('data.request_uuid', $this->input['request_uuid'])
        ->assertJsonPath('data.replayed', true);

    expect(ItTicket::query()->count())->toBe(1)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(1)
        ->and($ticket->events()->count())->toBe($eventCount)
        ->and(ItEmailDelivery::query()->count())->toBe($deliveryCount);
    Notification::assertSentToTimes($this->worker, TicketCreatedNotification::class, 1);
});

test('changed command content conflicts without changing the saved ticket', function () {
    $this->actingAs($this->worker)->postJson('/it/tickets', $this->input)->assertCreated();
    $this->postJson('/it/tickets', [...$this->input, 'description' => 'Different content'])
        ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    $this->post('/it/tickets', [...$this->input, 'title' => 'Different title'])
        ->assertRedirect()->assertSessionHasErrors('request_uuid');

    expect(ItTicket::query()->count())->toBe(1)
        ->and(ItTicket::query()->first()->description)->toBe($this->input['description']);
    $receipt = ItTicketCommandReceipt::query()->firstOrFail();
    expect($receipt->request_hash)->toHaveLength(64)
        ->and(json_encode($receipt->getAttributes()))->not->toContain($this->input['description'])
        ->and(json_encode($receipt))->not->toContain($receipt->request_hash);
});

test('recovery is actor owned and returns only a current authorized reference', function () {
    $created = $this->actingAs($this->worker)->postJson('/it/tickets', $this->input)->assertCreated();
    $url = '/it/ticket-commands/'.$this->input['request_uuid'];
    $this->getJson($url)->assertOk()->assertExactJson([
        'status' => 'committed',
        'data' => [
            ...$created->json('data'),
            'replayed' => true,
        ],
    ]);
    $this->actingAs($this->agent)->getJson($url)->assertNotFound()->assertJsonMissingPath('code');
    $this->getJson('/it/ticket-commands/'.Str::uuid())->assertNotFound()->assertJsonMissingPath('code');

    // The same UUID is valid for another actor, but binds only their result.
    $second = $this->postJson('/it/tickets', [...$this->input, 'site_id' => $this->site->id])->assertCreated();
    expect($second->json('data.id'))->not->toBe($created->json('data.id'));
    $this->getJson($url)->assertOk()->assertJsonPath('data.id', $second->json('data.id'));
});

test('recovery and conflicting replays distinguish only an owned command whose record is unavailable', function (string $recordState) {
    $input = [...$this->input, 'site_id' => $this->site->id, 'requester_user_id' => $this->worker->id];
    $created = $this->actingAs($this->agent)->postJson('/it/tickets', $input)->assertCreated();
    if ($recordState === 'unlinked') {
        // A retained committed receipt must never recreate a missing result.
        ItTicketCommandReceipt::query()->sole()->forceFill(['it_ticket_id' => null])->save();
    } else {
        $hiddenSite = Site::factory()->create();
        ItTicket::query()->findOrFail($created->json('data.id'))->forceFill([
            'site_id' => $hiddenSite->id,
            'assigned_to_user_id' => null,
            'owner_user_id' => null,
            'team_id' => null,
            'queue_id' => null,
        ])->save();
    }

    $recovery = $this->getJson('/it/ticket-commands/'.$input['request_uuid'])
        ->assertNotFound()->assertJsonPath('code', 'access_unavailable')->assertJsonMissingPath('data');
    $replay = $this->postJson('/it/tickets', [...$input, 'title' => 'Changed after revocation'])
        ->assertNotFound()->assertJsonPath('code', 'access_unavailable')->assertJsonMissingPath('data');
    foreach ([$recovery, $replay] as $response) {
        expect($response->getContent())->not->toContain($input['description'])
            ->not->toContain($input['title'])
            ->not->toContain($created->json('data.reference'));
    }
    $this->post('/it/tickets', $input)->assertRedirect()
        ->assertSessionHasErrors('request_uuid')->assertSessionMissing('it_ticket');
    $this->get('/it/ticket-commands/'.$input['request_uuid'])->assertRedirect()
        ->assertSessionHasErrors('request_uuid')->assertSessionMissing('it_ticket');
    expect(ItTicket::query()->count())->toBe(1);
})->with(['inaccessible', 'unlinked']);

test('invalid input does not reserve the command identity and can be corrected safely', function () {
    $this->actingAs($this->worker)->postJson('/it/tickets', [...$this->input, 'title' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('title');
    expect(ItTicketCommandReceipt::query()->count())->toBe(0);
    $this->postJson('/it/tickets', $this->input)->assertCreated();
    $this->postJson('/it/tickets', [...$this->input, 'request_uuid' => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
    expect(ItTicket::query()->count())->toBe(1);
});

test('legacy browser submissions remain compatible while newly served forms adopt command identities', function () {
    $input = $this->input;
    unset($input['request_uuid']);
    $this->actingAs($this->worker)->post('/it/tickets', $input)->assertRedirect()->assertSessionHas('it_ticket');
    expect(ItTicket::query()->count())->toBe(1)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(0);
});

test('normalized identifiers and watcher ordering do not create false conflicts', function () {
    $input = [
        ...$this->input,
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'watchers' => [$this->worker->id, $this->agent->id],
    ];
    $this->actingAs($this->agent)->postJson('/it/tickets', $input)->assertCreated();
    $this->postJson('/it/tickets', [
        ...$input,
        'site_id' => (string) $this->site->id,
        'watchers' => [(string) $this->agent->id, (string) $this->worker->id, (string) $this->worker->id],
    ])->assertOk()->assertJsonPath('data.replayed', true);
    expect(ItTicket::query()->count())->toBe(1);
});

test('attachment fingerprints reject replacement content under the same request identity', function () {
    $firstFile = UploadedFile::fake()->image('screen.png', 10, 10);
    $first = app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input, [$firstFile]);
    $replay = app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input, [$firstFile]);
    expect($replay->ticket->id)->toBe($first->ticket->id)->and($replay->replayed)->toBeTrue();

    expect(fn () => app(ItTicketIntakeService::class)->createCommand(
        $this->worker,
        $this->input,
        [UploadedFile::fake()->image('screen.png', 20, 20)],
    ))->toThrow(ItTicketCommandConflict::class);
    expect(ItAttachment::query()->count())->toBe(1)
        ->and(Storage::disk(ItAttachment::DISK)->allFiles())->toHaveCount(1);
});

test('ticket audit attachments and command receipt roll back together if the binding cannot persist', function () {
    $firstIntentId = (int) ItAttachmentStorageIntent::query()->max('id');
    $event = 'eloquent.updating: '.ItTicketCommandReceipt::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic receipt storage failure'));
    try {
        expect(fn () => app(ItTicketIntakeService::class)->createCommand(
            $this->worker,
            $this->input,
            [UploadedFile::fake()->image('screen.png', 10, 10)],
        ))->toThrow(RuntimeException::class, 'Synthetic receipt storage failure');
    } finally {
        Event::forget($event);
    }
    expect(ItTicket::query()->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(0)
        ->and(ItAttachment::query()->count())->toBe(0)
        ->and(ItEmailDelivery::query()->count())->toBe(0);
    // RefreshDatabase still owns an enclosing transaction. The standalone
    // command-storage suite proves deletion after a real outer rollback.
    // A current read sees the separate connection's reservation despite this
    // test's earlier repeatable-read snapshot. It never authorizes deletion.
    $intent = ItAttachmentStorageIntent::query()->where('id', '>', $firstIntentId)->lockForUpdate()->sole();
    expect($intent->state)->toBe('reserved')
        ->and(Storage::disk(ItAttachment::DISK)->allFiles())->toBe([$intent->path]);

    $this->actingAs($this->worker)->postJson('/it/tickets', $this->input)->assertCreated();
});

test('notification dispatch failure preserves a successful committed response and a recoverable intent', function () {
    $this->partialMock(ItEmailDeliveryService::class, function (MockInterface $mock) {
        $mock->shouldReceive('dispatchPending')->andThrow(new RuntimeException('Synthetic dispatch failure'));
    });
    $this->actingAs($this->worker)->postJson('/it/tickets', $this->input)->assertCreated();
    $ticket = ItTicket::query()->sole();
    $this->getJson('/it/ticket-commands/'.$this->input['request_uuid'])->assertOk()
        ->assertJsonPath('data.id', $ticket->id);
    $this->postJson('/it/tickets', $this->input)->assertOk()
        ->assertJsonPath('data.id', $ticket->id)->assertJsonPath('data.replayed', true);
    expect(ItTicket::query()->count())->toBe(1)
        ->and(ItEmailDelivery::query()->sole()->dispatch_requested_at)->not->toBeNull()
        ->and(ItEmailDelivery::query()->sole()->dispatch_finished_at)->toBeNull();
});

test('the database rejects a duplicate actor channel and operation command binding', function () {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    $receipt = ItTicketCommandReceipt::query()->sole();
    expect(fn () => ItTicketCommandReceipt::query()->create($receipt->only([
        'actor_user_id', 'channel', 'operation', 'request_uuid', 'request_hash', 'it_ticket_id', 'committed_at',
    ])))->toThrow(UniqueConstraintViolationException::class);
});
