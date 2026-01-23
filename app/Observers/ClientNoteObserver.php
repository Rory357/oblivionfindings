<?php

namespace App\Observers;

use App\Models\ClientNote;
use App\Models\TimelineEvent;

class ClientNoteObserver
{
    public function created(ClientNote $note): void
    {
        $this->upsertTimelineEvent($note);
    }

    public function updated(ClientNote $note): void
    {
        $this->upsertTimelineEvent($note);
    }

    public function deleted(ClientNote $note): void
    {
        TimelineEvent::query()
            ->where('type', 'note')
            ->where('source_type', ClientNote::class)
            ->where('source_id', $note->id)
            ->delete();
    }

    protected function upsertTimelineEvent(ClientNote $note): void
    {
        $note->loadMissing('client');

        $clientName = $note->client ? trim(($note->client->first_name ?? '') . ' ' . ($note->client->last_name ?? '')) : 'Client';

        TimelineEvent::query()->updateOrCreate(
            [
                'type' => 'note',
                'source_type' => ClientNote::class,
                'source_id' => $note->id,
            ],
            [
                'occurred_at' => $note->created_at ?? now(),
                'source_type' => ClientNote::class,
                'source_id' => $note->id,
                'actor_user_id' => $note->user_id,
                'client_id' => $note->client_id,
                'site_id' => $note->client?->site_id,
                'subject' => 'Note for ' . $clientName,
                'body' => $note->body,
                'meta' => array_filter([
                    'note_id' => $note->id,
                ], fn($v) => $v !== null),
                'visibility' => 'internal',
                'created_by' => $note->user_id,
            ]
        );
    }
}
