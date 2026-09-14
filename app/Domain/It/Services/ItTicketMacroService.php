<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTicketCommentResult;
use App\Models\ItQueue;
use App\Models\ItReplyTemplate;
use App\Models\ItTicket;
use App\Models\ItTicketMacro;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Governed multi-action macros. A macro's preview names every change it
 * would make before anything mutates; application funnels through the
 * existing triage/transition/interaction services so a stale ticket, an
 * ineligible assignee or an unauthorized audience is rejected by the same
 * guards as a hand-made change.
 */
final class ItTicketMacroService
{
    public const ACTION_TYPES = [
        'set_status', 'set_waiting', 'set_priority',
        'assign_to_me', 'assign_user', 'set_queue', 'add_reply',
    ];

    public function __construct(
        private readonly ItTicketTriageService $triage,
        private readonly ItTicketInteractionService $interaction,
        private readonly ItReplyTemplateService $templates,
    ) {}

    public function create(User $actor, array $data): ItTicketMacro
    {
        $this->assertValidActions($data['actions']);

        return DB::transaction(function () use ($actor, $data): ItTicketMacro {
            $macro = ItTicketMacro::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'actions' => array_values($data['actions']),
                'is_active' => true,
                'lock_version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            AuditLogger::logOrFail('it.macros.created', $macro, ['actor_id' => $actor->id]);

            return $macro;
        });
    }

    public function update(ItTicketMacro $macro, User $actor, array $data): ItTicketMacro
    {
        $this->assertValidActions($data['actions']);

        return DB::transaction(function () use ($macro, $actor, $data): ItTicketMacro {
            $current = ItTicketMacro::query()->lockForUpdate()->findOrFail($macro->id);
            $this->assertCurrentVersion($current, (int) ($data['lock_version'] ?? 0));
            $current->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'actions' => array_values($data['actions']),
                'lock_version' => $current->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            AuditLogger::logOrFail('it.macros.updated', $current, ['actor_id' => $actor->id]);

            return $current;
        });
    }

    public function setActive(ItTicketMacro $macro, User $actor, bool $active, int $expectedVersion): ItTicketMacro
    {
        return DB::transaction(function () use ($macro, $actor, $active, $expectedVersion): ItTicketMacro {
            $current = ItTicketMacro::query()->lockForUpdate()->findOrFail($macro->id);
            $this->assertCurrentVersion($current, $expectedVersion);
            $current->fill([
                'is_active' => $active,
                'lock_version' => $current->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            AuditLogger::logOrFail($active ? 'it.macros.restored' : 'it.macros.archived', $current, ['actor_id' => $actor->id]);

            return $current;
        });
    }

    /**
     * Name every change this macro would make on this ticket, without
     * mutating anything. Unresolvable steps are reported as blockers.
     *
     * @return array{changes: list<string>, blockers: list<string>}
     */
    public function preview(ItTicketMacro $macro, ItTicket $ticket, User $actor): array
    {
        $changes = [];
        $blockers = [];
        if (! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)) {
            $blockers[] = 'This ticket is settled; reopen it before applying a macro.';
        }

        foreach ($macro->actions as $action) {
            switch ($action['type']) {
                case 'set_status':
                    $changes[] = 'Set status to '.str_replace('_', ' ', (string) $action['status']).'.';
                    break;
                case 'set_waiting':
                    $changes[] = 'Mark waiting on the '.$action['waiting_party']
                        .(filled($action['waiting_reason'] ?? null) ? ' — '.$action['waiting_reason'] : '').'.';
                    break;
                case 'set_priority':
                    $changes[] = 'Override priority to '.$action['priority'].' (reason: '.$action['reason'].').';
                    break;
                case 'assign_to_me':
                    $changes[] = 'Assign the ticket to you ('.$actor->name.').';
                    break;
                case 'assign_user':
                    $assignee = User::query()->find($action['user_id']);
                    if (! $assignee) {
                        $blockers[] = 'The configured assignee no longer exists.';
                        break;
                    }
                    $changes[] = 'Assign the ticket to '.$assignee->name.'.';
                    break;
                case 'set_queue':
                    $queue = ItQueue::query()->find($action['queue_id']);
                    if (! $queue || ! $queue->is_active) {
                        $blockers[] = 'The configured queue is no longer available.';
                        break;
                    }
                    $changes[] = 'Move the ticket to the '.$queue->name.' queue.';
                    break;
                case 'add_reply':
                    $template = ItReplyTemplate::query()->find($action['template_id']);
                    if (! $template || ! $template->is_active) {
                        $blockers[] = 'A configured reply template is archived or missing.';
                        break;
                    }
                    try {
                        $this->templates->render($template, $ticket, $actor);
                    } catch (ValidationException $raised) {
                        $blockers[] = 'Template "'.$template->name.'": '.collect($raised->errors())->flatten()->first();
                        break;
                    }
                    $changes[] = ($template->audience === 'internal' || ($action['internal'] ?? false)
                        ? 'Add an internal note from template "'
                        : 'Send a public reply from template "').$template->name.'".';
                    break;
            }
        }

        return ['changes' => $changes, 'blockers' => $blockers];
    }

    /**
     * Apply through the canonical services. Property changes go through one
     * triage update (stale versions and ineligible targets rejected there);
     * replies use deterministic per-action command identities derived from
     * the caller's request_uuid, so a retried application can never post the
     * same reply twice.
     */
    public function apply(ItTicketMacro $macro, ItTicket $ticket, User $actor, int $expectedVersion, string $requestUuid): ItTicket
    {
        $preview = $this->preview($macro, $ticket, $actor);
        if ($preview['blockers'] !== []) {
            throw ValidationException::withMessages(['macro' => $preview['blockers'][0]]);
        }

        return DB::transaction(function () use ($macro, $ticket, $actor, $expectedVersion, $requestUuid): ItTicket {
            $properties = ['expected_version' => $expectedVersion];
            $replies = [];
            foreach ($macro->actions as $index => $action) {
                switch ($action['type']) {
                    case 'set_status':
                        $properties['status'] = $action['status'];
                        break;
                    case 'set_waiting':
                        $properties['status'] = 'waiting';
                        $properties['waiting_party'] = $action['waiting_party'];
                        if (filled($action['waiting_reason'] ?? null)) {
                            $properties['waiting_reason'] = $action['waiting_reason'];
                        }
                        break;
                    case 'set_priority':
                        $properties['priority'] = $action['priority'];
                        $properties['priority_reason'] = $action['reason'];
                        break;
                    case 'assign_to_me':
                        $properties['assigned_to_user_id'] = $actor->id;
                        $properties['routing_reason'] = $action['routing_reason'] ?? 'Applied macro: '.$macro->name;
                        break;
                    case 'assign_user':
                        $properties['assigned_to_user_id'] = (int) $action['user_id'];
                        $properties['routing_reason'] = $action['routing_reason'] ?? 'Applied macro: '.$macro->name;
                        break;
                    case 'set_queue':
                        $properties['queue_id'] = (int) $action['queue_id'];
                        $properties['routing_reason'] = $action['routing_reason'] ?? 'Applied macro: '.$macro->name;
                        break;
                    case 'add_reply':
                        $replies[$index] = $action;
                        break;
                }
            }

            $current = $ticket;
            if (count($properties) > 1) {
                $current = $this->triage->update($ticket, $actor, $properties, 'macro');
            } else {
                app(ItTicketVersionService::class)->assertCurrent($current, $expectedVersion);
            }

            foreach ($replies as $index => $action) {
                $template = ItReplyTemplate::query()->findOrFail($action['template_id']);
                $internal = $template->audience === 'internal' || (bool) ($action['internal'] ?? false);
                $result = $this->interaction->addCommentCommand($current->refresh(), $actor, [
                    'request_uuid' => Uuid::uuid5($requestUuid, 'macro-action-'.$index)->toString(),
                    'actor_user_id' => $actor->id,
                    'expected_version' => (int) $current->lock_version,
                    'body' => $this->templates->render($template, $current, $actor),
                    'is_internal' => $internal,
                ]);
                $current = $result instanceof ItTicketCommentResult ? $result->ticket : $current;
            }

            AuditLogger::logOrFail('it.macros.applied', $current, [
                'actor_id' => $actor->id, 'macro_id' => $macro->id, 'macro_name' => $macro->name,
            ]);

            return $current->refresh();
        });
    }

    private function assertValidActions(mixed $actions): void
    {
        if (! is_array($actions) || $actions === []) {
            throw ValidationException::withMessages(['actions' => 'A macro needs at least one action.']);
        }
        foreach ($actions as $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;
            $valid = match ($type) {
                'set_status' => in_array($action['status'] ?? null, ['open', 'in_progress'], true),
                'set_waiting' => in_array($action['waiting_party'] ?? null, ['requester', 'vendor'], true),
                'set_priority' => in_array($action['priority'] ?? null, ItTicket::PRIORITIES, true)
                    && is_string($action['reason'] ?? null) && trim($action['reason']) !== '',
                'assign_to_me' => true,
                'assign_user' => is_int($action['user_id'] ?? null) && User::query()->whereKey($action['user_id'])->exists(),
                'set_queue' => is_int($action['queue_id'] ?? null) && ItQueue::query()->whereKey($action['queue_id'])->exists(),
                'add_reply' => is_int($action['template_id'] ?? null)
                    && ItReplyTemplate::query()->whereKey($action['template_id'])->where('is_active', true)->exists(),
                default => false,
            };
            if (! $valid) {
                throw ValidationException::withMessages([
                    'actions' => 'Unsupported or incomplete macro action'.(is_string($type) ? ' "'.$type.'"' : '').'.',
                ]);
            }
        }
    }

    private function assertCurrentVersion(ItTicketMacro $current, int $expectedVersion): void
    {
        if ($expectedVersion !== (int) $current->lock_version) {
            throw ValidationException::withMessages([
                'lock_version' => 'This macro changed while you were editing. Reload it and reapply your changes.',
            ]);
        }
    }
}
