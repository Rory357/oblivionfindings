<?php

namespace App\Domain\It\Services;

use App\Models\ItReplyTemplate;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Governed reusable replies. Bodies carry a strict placeholder allowlist —
 * an unknown token can never be saved, and a placeholder whose value is
 * unavailable on the target ticket blocks insertion instead of leaking the
 * raw token (or worse, a guessed value) into a message.
 */
final class ItReplyTemplateService
{
    /** Placeholder key => human description shown in the editor. */
    public const PLACEHOLDERS = [
        'ticket.reference' => 'Ticket reference (IT-000123)',
        'ticket.title' => 'Ticket title',
        'requester.name' => 'Requester full name',
        'requester.first_name' => 'Requester first name',
        'site.name' => 'Affected Site name',
        'service.name' => 'Affected service name',
        'assignee.name' => 'Assigned technician name',
        'actor.name' => 'Your name',
    ];

    public function create(User $actor, array $data): ItReplyTemplate
    {
        $this->assertKnownPlaceholders($data['body']);

        return DB::transaction(function () use ($actor, $data): ItReplyTemplate {
            $template = ItReplyTemplate::query()->create([
                'name' => $data['name'],
                'audience' => $data['audience'],
                'body' => $data['body'],
                'owner_user_id' => $data['owner_user_id'] ?? $actor->id,
                'review_due_at' => $data['review_due_at'] ?? null,
                'is_active' => true,
                'lock_version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->recordVersion($template, $actor);
            AuditLogger::logOrFail('it.reply_templates.created', $template, ['actor_id' => $actor->id]);

            return $template;
        });
    }

    public function update(ItReplyTemplate $template, User $actor, array $data): ItReplyTemplate
    {
        $this->assertKnownPlaceholders($data['body']);

        return DB::transaction(function () use ($template, $actor, $data): ItReplyTemplate {
            $current = ItReplyTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $this->assertCurrentVersion($current, (int) ($data['lock_version'] ?? 0));
            $current->fill([
                'name' => $data['name'],
                'audience' => $data['audience'],
                'body' => $data['body'],
                'owner_user_id' => $data['owner_user_id'] ?? $current->owner_user_id,
                'review_due_at' => $data['review_due_at'] ?? null,
                'lock_version' => $current->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->recordVersion($current, $actor);
            AuditLogger::logOrFail('it.reply_templates.updated', $current, ['actor_id' => $actor->id]);

            return $current;
        });
    }

    public function setActive(ItReplyTemplate $template, User $actor, bool $active, int $expectedVersion): ItReplyTemplate
    {
        return DB::transaction(function () use ($template, $actor, $active, $expectedVersion): ItReplyTemplate {
            $current = ItReplyTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $this->assertCurrentVersion($current, $expectedVersion);
            $current->fill([
                'is_active' => $active,
                'lock_version' => $current->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            AuditLogger::logOrFail($active ? 'it.reply_templates.restored' : 'it.reply_templates.archived', $current, ['actor_id' => $actor->id]);

            return $current;
        });
    }

    /**
     * Render a template against one ticket. Every placeholder must resolve
     * to a real value; anything unresolved blocks the insertion.
     */
    public function render(ItReplyTemplate $template, ItTicket $ticket, User $actor): string
    {
        $ticket->loadMissing(['requester:id,name', 'site:id,name', 'service:id,name', 'assignee:id,name']);
        $values = [
            'ticket.reference' => $ticket->reference,
            'ticket.title' => $ticket->title,
            'requester.name' => $ticket->requester?->name,
            'requester.first_name' => $ticket->requester?->name ? explode(' ', trim($ticket->requester->name))[0] : null,
            'site.name' => $ticket->site?->name,
            'service.name' => $ticket->service?->name,
            'assignee.name' => $ticket->assignee?->name,
            'actor.name' => $actor->name,
        ];

        $missing = [];
        $rendered = preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/i', function (array $match) use ($values, &$missing): string {
            $key = strtolower($match[1]);
            $value = $values[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;

                return $match[0];
            }

            return $value;
        }, $template->body);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'template' => 'This ticket has no value for '.implode(', ', array_unique($missing)).'. Fill the missing detail first or edit the text by hand.',
            ]);
        }

        return $rendered;
    }

    private function assertKnownPlaceholders(string $body): void
    {
        preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $body, $matches);
        $unknown = array_values(array_unique(array_filter(
            $matches[1],
            fn (string $token): bool => ! array_key_exists(strtolower($token), self::PLACEHOLDERS),
        )));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'body' => 'Unknown placeholder: '.implode(', ', $unknown).'. Allowed placeholders: '.implode(', ', array_keys(self::PLACEHOLDERS)).'.',
            ]);
        }
    }

    private function assertCurrentVersion(ItReplyTemplate $current, int $expectedVersion): void
    {
        if ($expectedVersion !== (int) $current->lock_version) {
            throw ValidationException::withMessages([
                'lock_version' => 'This template changed while you were editing. Reload it and reapply your changes.',
            ]);
        }
    }

    private function recordVersion(ItReplyTemplate $template, User $actor): void
    {
        $template->versions()->create([
            'version' => $template->lock_version,
            'name' => $template->name,
            'audience' => $template->audience,
            'body' => $template->body,
            'recorded_by_user_id' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
