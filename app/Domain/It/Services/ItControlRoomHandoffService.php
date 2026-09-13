<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Models\ControlRoomAlert;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Human handoff coordinates existing intake, links and command receipts under the alert mutex. */
final class ItControlRoomHandoffService
{
    public const OPERATION = 'ticket.control_room_handoff';

    public const SOURCE = 'control_room_handoff';

    public function __construct(
        private readonly ItWorkAccessService $work,
        private readonly ControlRoomAlertAccessService $alerts,
        private readonly ItTicketLinkService $links,
        private readonly ItTicketIntakeService $intake,
        private readonly ItTicketVersionService $versions,
    ) {}

    /** Opening recovery remains available after the operational alert is resolved. */
    public function canPrepare(ControlRoomAlert $alert, User $actor): bool
    {
        return $actor->approved_at !== null && $actor->canDo('it.view') && $actor->canDo('it.manage')
            && $actor->canDo('controlRoom.alerts.manage') && $this->alerts->canView($alert, $actor)
            && $alert->site_id !== null && in_array((int) $alert->site_id, $this->work->approvedSiteIds($actor), true);
    }

    /** Permission-safe discovery; never copy the operational alert's free text into technical work. */
    public function preview(ControlRoomAlert $alert, User $actor, string $search = ''): array
    {
        $actor = User::query()->findOrFail($actor->id);
        $alert = ControlRoomAlert::query()->findOrFail($alert->id);
        $this->authorize($alert, $actor);
        $existing = $this->linkedTickets($alert)->limit(25)->get();
        $visible = $existing->filter(fn (ItTicket $ticket): bool => $this->work->canView($actor, $ticket));
        $query = $this->work->applyWorkScope(ItTicket::query(), $actor)
            ->where('site_id', $alert->site_id)->where('is_organisation_wide', false)
            ->where('work_type', 'incident')->whereIn('status', ItTicket::OPEN_STATUSES)
            ->whereNull('merged_into_ticket_id');
        $search = mb_substr(trim($search), 0, 120);
        if ($search !== '') {
            $query->where(fn ($query) => $query->where('reference', 'like', '%'.$search.'%')
                ->orWhere('title', 'like', '%'.$search.'%'));
        }

        return [
            'viewer_user_id' => (int) $actor->id, 'alert_id' => (int) $alert->id,
            'alert_version' => $this->alertVersion($alert),
            'can_start' => in_array($alert->status, ControlRoomAlert::ACTIVE_STATUSES, true),
            'site' => ['id' => (int) $alert->site_id, 'name' => $alert->site?->name],
            'services' => ItService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
            'existing_work' => $visible->map(fn (ItTicket $ticket): array => $this->ticketData($ticket))->values()->all(),
            'has_existing_work' => $existing->isNotEmpty(),
            'candidates' => $query->orderByDesc('id')->limit(25)->get()
                ->map(fn (ItTicket $ticket): array => $this->ticketData($ticket))->all(),
        ];
    }

    public function execute(ControlRoomAlert $alert, User $actor, array $input): array
    {
        return DB::transaction(function () use ($alert, $actor, $input): array {
            [$alert, $actor] = $this->lockContext($alert, $actor);
            $this->validateIdentity($actor, $input);
            $data = Validator::make($input, [
                'action' => ['required', Rule::in(['create', 'link'])],
                'alert_version' => ['required', 'string', 'size:64'],
                'ticket_id' => ['required_if:action,link', 'nullable', 'integer', 'min:1'],
                'ticket_version' => ['required_if:action,link', 'nullable', 'integer', 'min:1'],
                'title' => ['required_if:action,create', 'nullable', 'string', 'max:255'],
                'description' => ['required_if:action,create', 'nullable', 'string', 'max:10000'],
                'category' => ['required_if:action,create', 'nullable', Rule::in(ItTicket::CATEGORIES)],
                'impact' => ['required_if:action,create', 'nullable', Rule::in(array_keys(ItTicketPriorityService::MATRIX))],
                'urgency' => ['required_if:action,create', 'nullable', Rule::in(array_keys(ItTicketPriorityService::MATRIX['site']))],
                'it_service_id' => ['nullable', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:2000'],
            ])->validate();
            if (trim($data['reason']) === '' || ($data['action'] === 'create' &&
                (trim($data['title']) === '' || trim($data['description']) === ''))) {
                throw ValidationException::withMessages(['reason' => 'Describe the technical work and why this handoff is needed.']);
            }
            $hash = hash('sha256', json_encode([(int) $alert->id, $data], JSON_THROW_ON_ERROR));
            $receipt = $this->receipts($actor, $input['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                $result = $this->result($alert, $actor, $receipt, true);
                if ($result['status'] !== 'cancelled' && ! hash_equals($receipt->request_hash, $hash)) {
                    throw new ItTicketCommandConflict;
                }

                return $result;
            }
            if (! hash_equals($this->alertVersion($alert), $data['alert_version'])) {
                throw ValidationException::withMessages(['alert_version' => 'The operational alert changed. Refresh the handoff before submitting again.']);
            }
            if (! in_array($alert->status, ControlRoomAlert::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages(['form' => 'Use an active operational alert for a new technical handoff.']);
            }
            $existing = $this->linkedTickets($alert, true)->lockForUpdate()->first();
            $changed = false;
            if ($existing) {
                abort_unless($this->work->canWork($actor, $existing, true), 404);
                if ($data['action'] === 'link' && (int) $data['ticket_id'] !== (int) $existing->id) {
                    throw ValidationException::withMessages(['ticket_id' => 'This alert already has linked IT work. Open that work to coordinate it.']);
                }
                $ticket = $existing;
                $outcome = 'existing';
            } else {
                if ($data['action'] === 'link') {
                    $ticket = ItTicket::query()->whereKey($data['ticket_id'])->lockForUpdate()->firstOrFail();
                    abort_unless($this->work->canWork($actor, $ticket, true), 404);
                    $this->versions->assertCurrent($ticket, (int) $data['ticket_version']);
                    if ((int) $ticket->site_id !== (int) $alert->site_id || $ticket->is_organisation_wide
                        || $ticket->work_type !== 'incident' || $ticket->isMerged()
                        || ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)) {
                        throw ValidationException::withMessages(['ticket_id' => 'Choose an open, original IT incident at this alert’s Site.']);
                    }
                } else {
                    $ticket = $this->intake->create($actor, [
                        'title' => trim($data['title']), 'description' => trim($data['description']),
                        'site_id' => (int) $alert->site_id, 'is_organisation_wide' => false,
                        'category' => $data['category'], 'work_type' => 'incident',
                        'impact' => $data['impact'], 'urgency' => $data['urgency'],
                        'it_service_id' => $data['it_service_id'] ?? null,
                    ]);
                }
                $this->links->link($ticket, $alert, 'source_alert', [
                    'source' => self::SOURCE, 'operation' => self::OPERATION, 'site_id' => (int) $alert->site_id,
                ], (int) $actor->id);
                $ticket->forceFill(['lock_version' => (int) $ticket->lock_version + 1])->save();
                ItTicketEvent::record($ticket, 'control_room_handoff', $actor->id, [
                    'alert_id' => (int) $alert->id, 'action' => $data['action'], 'reason' => trim($data['reason']),
                ]);
                AuditLogger::logOrFail('it.ticket.control_room_handoff', $ticket, [
                    'actor_id' => (int) $actor->id, 'alert_id' => (int) $alert->id,
                    'action' => $data['action'], 'reason' => trim($data['reason']), 'request_uuid' => $input['request_uuid'],
                ]);
                $changed = true;
                $outcome = $data['action'] === 'create' ? 'created' : 'linked';
            }
            $receipt = $this->receipts($actor, $input['request_uuid'])->create([
                'actor_user_id' => $actor->id, 'channel' => 'browser', 'operation' => self::OPERATION,
                'request_uuid' => strtolower($input['request_uuid']), 'request_hash' => $hash,
                'it_ticket_id' => $ticket->id, 'committed_at' => now(),
                'committed_ticket_version' => (int) $ticket->lock_version,
                'result_metadata' => ['state' => 'committed', 'alert_id' => (int) $alert->id,
                    'outcome' => $outcome, 'changed' => $changed],
            ]);

            return $this->result($alert, $actor, $receipt, false);
        }, 3);
    }

    /** Cancellation before commit leaves a tombstone; after commit it returns the historical outcome. */
    public function recover(ControlRoomAlert $alert, User $actor, array $identity, bool $cancel = false): array
    {
        return DB::transaction(function () use ($alert, $actor, $identity, $cancel): array {
            [$alert, $actor] = $this->lockContext($alert, $actor);
            $this->validateIdentity($actor, $identity);
            $receipt = $this->receipts($actor, $identity['request_uuid'])->lockForUpdate()->first();
            if (! $receipt && $cancel) {
                $receipt = ItTicketCommandReceipt::query()->create([
                    'actor_user_id' => $actor->id, 'channel' => 'browser', 'operation' => self::OPERATION,
                    'request_uuid' => strtolower($identity['request_uuid']), 'request_hash' => hash('sha256', 'cancelled'),
                    'result_metadata' => ['state' => 'cancelled', 'alert_id' => (int) $alert->id,
                        'cancelled_at' => now()->toIso8601String()],
                ]);
                AuditLogger::logOrFail('it.ticket.control_room_handoff_cancelled', $alert, [
                    'actor_id' => (int) $actor->id, 'request_uuid' => strtolower($identity['request_uuid']),
                ]);
            }
            if (! $receipt) {
                return ['status' => 'unconfirmed', 'data' => [
                    'viewer_user_id' => (int) $actor->id, 'alert_id' => (int) $alert->id,
                    'request_uuid' => strtolower($identity['request_uuid']),
                ]];
            }

            return $this->result($alert, $actor, $receipt, true);
        }, 3);
    }

    /** Caller serializes mutations against the canonical alert, including automatic intake. */
    public function linkedTickets(ControlRoomAlert $alert, bool $lock = false): Builder
    {
        return ItTicket::query()->where('site_id', $alert->site_id)->where('is_organisation_wide', false)
            ->whereHas('links', fn ($links) => $links->where('relationship', 'source_alert')
                ->where('linkable_type', $alert->getMorphClass())->where('linkable_id', $alert->id)
                // The outer ticket lock does not make this subquery a current read in MySQL.
                ->when($lock, fn ($query) => $query->lockForUpdate()))
            ->orderByRaw('case when status in (?, ?, ?) then 0 else 1 end', ItTicket::OPEN_STATUSES)
            ->orderByDesc('id');
    }

    private function lockContext(ControlRoomAlert $alert, User $actor): array
    {
        $actor = $this->versions->currentActor($actor, true);
        $alert = ControlRoomAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
        $this->authorize($alert, $actor, true);

        return [$alert, $actor];
    }

    private function authorize(ControlRoomAlert $alert, User $actor, bool $lock = false): void
    {
        abort_unless($actor->approved_at !== null && $actor->canDo('it.manage')
            && $actor->canDo('it.view') && $actor->canDo('controlRoom.alerts.manage'), 403);
        $this->alerts->assertCanView($alert, $actor);
        abort_unless($alert->site_id !== null && in_array((int) $alert->site_id, $this->work->approvedSiteIds($actor, $lock), true), 404);
    }

    private function validateIdentity(User $actor, array $input): void
    {
        Validator::make($input, ['request_uuid' => ['required', 'uuid'],
            'viewer_user_id' => ['required', 'integer', Rule::in([(int) $actor->id])]])->validate();
    }

    private function receipts(User $actor, string $uuid): Builder
    {
        return ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)->where('channel', 'browser')
            ->where('operation', self::OPERATION)->where('request_uuid', strtolower($uuid));
    }

    private function result(ControlRoomAlert $alert, User $actor, ItTicketCommandReceipt $receipt, bool $replayed): array
    {
        $meta = $receipt->result_metadata ?? [];
        abort_unless(($meta['alert_id'] ?? null) === (int) $alert->id, 404);
        $data = ['viewer_user_id' => (int) $actor->id, 'alert_id' => (int) $alert->id,
            'request_uuid' => $receipt->request_uuid, 'replayed' => $replayed];
        if (($meta['state'] ?? null) === 'cancelled' && $receipt->committed_at === null && is_string($meta['cancelled_at'] ?? null)) {
            return ['status' => 'cancelled', 'data' => [...$data, 'cancelled_at' => $meta['cancelled_at']]];
        }
        $ticket = $receipt->ticket;
        abort_unless($ticket && $receipt->committed_at && ($meta['state'] ?? null) === 'committed'
            && in_array($meta['outcome'] ?? null, ['created', 'linked', 'existing'], true) && is_bool($meta['changed'] ?? null)
            && $this->work->canWork($actor, $ticket, true), 404);

        return ['status' => 'committed', 'data' => [...$data, 'outcome' => $meta['outcome'],
            'changed' => $meta['changed'], 'ticket' => $this->ticketData($ticket)]];
    }

    private function ticketData(ItTicket $ticket): array
    {
        return ['id' => (int) $ticket->id, 'reference' => $ticket->reference, 'title' => $ticket->title,
            'status' => $ticket->status, 'version' => (int) $ticket->lock_version, 'href' => route('it.tickets.show', $ticket)];
    }

    private function alertVersion(ControlRoomAlert $alert): string
    {
        return hash('sha256', json_encode([$alert->id, $alert->site_id, $alert->status, $alert->severity,
            $alert->updated_at?->toISOString()], JSON_THROW_ON_ERROR));
    }
}
