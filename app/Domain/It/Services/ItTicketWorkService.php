<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItTicketDraftPurpose;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\ItStaffDirectory;
use App\Models\ItTicket;
use App\Models\ItTicketBooking;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\ItTicketCost;
use App\Models\ItTicketTimeEntry;
use App\Models\ItTicketWorkProfile;
use App\Models\ItTicketWorkRevision;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Internal work extends the canonical ticket aggregate; nothing here grants participation. */
final class ItTicketWorkService
{
    public function __construct(private readonly ItWorkAccessService $access, private readonly ItTicketVersionService $versions, private readonly ItTicketWorkTime $time) {}

    public function ready(): bool
    {
        foreach (['it_ticket_work_profiles', 'it_ticket_bookings', 'it_ticket_time_entries', 'it_ticket_costs', 'it_ticket_work_revisions'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    public function guard(ItTicket $ticket, User $actor, bool $mutation = true): void
    {
        abort_unless($this->access->canWork($actor, $ticket), 404);
        if (! $this->ready()) {
            $this->invalid('work', 'Ticket work setup is incomplete. Your draft is retained; ask an administrator to finish the migration.');
        }
        if ($mutation && ($ticket->isMerged() || ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true))) {
            $this->invalid('work', 'Reopen the original ticket before changing its work records. Merged tickets are read-only.');
        }
    }

    /** All secondary commands use an immutable receipt, current actor and parent version. */
    public function execute(ItTicket $ticket, User $actor, array $input): array
    {
        Validator::make($input, ['actor_user_id' => ['required', 'integer'], 'expected_version' => ['required', 'integer', 'min:1'],
            'request_uuid' => ['required', 'uuid'], 'operation' => ['required', Rule::in(['context', 'book', 'booking', 'cost', 'correct_time', 'correct_note', 'review'])],
            'payload' => ['required', 'array']])->validate();

        return DB::transaction(function () use ($ticket, $actor, $input) {
            $ticket = ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $actor = $this->versions->currentActor($actor);
            abort_unless((int) $input['actor_user_id'] === (int) $actor->id, 403);
            $this->guard($ticket, $actor, false);
            $hash = hash_hmac('sha256', json_encode([$ticket->id, $input['operation'], $input['expected_version'], $input['payload']], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $receipt = ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)->where('channel', 'browser')
                ->where('operation', 'work.'.$input['operation'])->where('request_uuid', $input['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                if ((int) $receipt->it_ticket_id === (int) $ticket->id && ($receipt->result_metadata['state'] ?? null) === 'cancelled') {
                    return ['status' => 'cancelled', 'request_uuid' => $receipt->request_uuid, 'replayed' => true];
                }
                if ((int) $receipt->it_ticket_id !== (int) $ticket->id || ! hash_equals($receipt->request_hash, $hash)) {
                    throw new ItTicketCommandConflict;
                }
                abort_unless($receipt->committed_at, 409);

                return ['status' => 'committed', 'request_uuid' => $receipt->request_uuid, 'lock_version' => $receipt->committed_ticket_version, 'replayed' => true, 'draft' => $receipt->result_metadata['draft'] ?? null];
            }
            $this->guard($ticket, $actor);
            $this->versions->assertCurrent($ticket, (int) $input['expected_version']);
            $consumed = null;
            if (isset($input['draft_uuid'])) {
                $saved = app(ItTicketDraftService::class)->resume($actor, $input['draft_uuid']);
                $form = json_decode($saved['payload']['fields']['work_form'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
                $original = $form['pending'] ?? null;
                if (! is_array($original) || ($original['request_uuid'] ?? null) !== $input['request_uuid'] || ($original['actor_user_id'] ?? null) !== (int) $actor->id
                    || ($original['operation'] ?? null) !== $input['operation'] || ($original['expected_version'] ?? null) !== (int) $input['expected_version']
                    || ($original['payload'] ?? null) !== $input['payload']) {
                    $this->invalid('work', 'Save these exact work fields to their draft before submitting.');
                }
                app(ItTicketDraftService::class)->consumeFromInput($actor, $input, ItTicketDraftPurpose::TicketWork, (int) $ticket->id);
                $consumed = ['draft_uuid' => $input['draft_uuid'], 'submitted_revision' => (int) $input['draft_revision'], 'revision' => (int) $input['draft_revision'] + 1, 'state' => 'consumed'];
            }
            $payload = $input['payload'];
            match ($input['operation']) {
                'context' => $this->saveContext($ticket, $actor, $payload),
                'book' => app(ItTicketBookingService::class)->create($ticket, $actor, $payload),
                'booking' => app(ItTicketBookingService::class)->change($ticket, $actor, $payload),
                'cost' => $this->cost($ticket, $actor, $payload),
                'correct_time' => $this->correctTime($ticket, $actor, $payload),
                'correct_note' => $this->correctNote($ticket, $actor, $payload),
                'review' => $this->review($ticket, $actor, $payload),
            };
            $this->versions->advance($ticket);
            ItTicketCommandReceipt::create(['actor_user_id' => $actor->id, 'channel' => 'browser', 'operation' => 'work.'.$input['operation'],
                'request_uuid' => $input['request_uuid'], 'request_hash' => $hash, 'it_ticket_id' => $ticket->id,
                'committed_ticket_version' => $ticket->lock_version, 'committed_at' => now(), 'result_metadata' => ['state' => 'committed', 'draft' => $consumed]]);

            return ['status' => 'committed', 'request_uuid' => $input['request_uuid'], 'lock_version' => $ticket->lock_version, 'replayed' => false, 'draft' => $consumed];
        }, 3);
    }

    /** Called inside the existing comment command transaction, before its receipt commits. */
    public function addToComment(ItTicket $ticket, User $actor, ItTicketComment $comment, string $json): void
    {
        $this->guard($ticket, $actor);
        $work = $this->decode($json);
        Validator::make($work, [
            'periods' => ['sometimes', 'array', 'max:48'], 'periods.*' => ['array:starts_at,ends_at,break_minutes,work_type,after_hours'],
            'technician_user_id' => ['sometimes', 'integer', 'min:1'], 'booking_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'approver_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'require_approval' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'nullable', Rule::in(['in_progress', 'waiting', 'resolved'])],
            'follow_up' => ['sometimes', 'nullable', 'array:owner_user_id,owner_name,due_at,action,waiting_party,reason'],
            'recipient_user_ids' => ['sometimes', 'array', 'min:1', 'max:100'], 'recipient_user_ids.*' => ['integer', 'distinct'],
        ])->validate();
        if (isset($work['timer'])) {
            $this->invalid('work.timer', 'Stop the timer and review its actual periods before saving.');
        }
        $technician = $this->technician((int) ($work['technician_user_id'] ?? $actor->id), $ticket);
        $periods = $work['periods'] ?? [];
        $booking = isset($work['booking_id']) ? ItTicketBooking::where('ticket_id', $ticket->id)->findOrFail($work['booking_id']) : null;
        if ($booking && ((int) $booking->technician_user_id !== (int) $technician->id || $booking->status !== 'accepted' || $periods === [])) {
            $this->invalid('work.booking_id', 'Only an accepted booking for this technician can be completed, with actual time and a work note.');
        }
        $details = $this->details($ticket);
        foreach ($periods as $index => $period) {
            $values = $this->time->period($period, 'work.periods.'.$index);
            $this->noTimeOverlap($technician, $values);
            $requires = (bool) ($work['require_approval'] ?? false) || ($values['after_hours'] && ($details['review_after_hours'] ?? false));
            $approver = $requires ? $this->approver($ticket, $actor, $work['approver_user_id'] ?? null, (int) $technician->id) : null;
            $entry = ItTicketTimeEntry::create([...$values, 'ticket_id' => $ticket->id, 'comment_id' => $comment->id,
                'booking_id' => $booking?->id, 'technician_user_id' => $technician->id, 'recorded_by' => $actor->id,
                'hourly_rate_cents' => $details[$values['after_hours'] ? 'after_hours_rate_cents' : 'standard_rate_cents'] ?? null,
                'approval_status' => $requires ? 'pending' : 'not_required', 'approver_user_id' => $approver?->id]);
            $this->record($ticket, $actor, 'time', $entry->id, 'created', null, $entry->toArray(), 'Recorded with note');
        }
        if ($booking) {
            $before = $booking->toArray();
            $booking->update(['status' => 'completed']);
            $this->record($ticket, $actor, 'booking', $booking->id, 'completed', $before, $booking->toArray(), 'Completed with actual work note '.$comment->id);
        }
        if (isset($work['follow_up'])) {
            $this->followUp($ticket, $actor, $work['follow_up']);
        }
        if (! empty($work['status'])) {
            $status = $work['status'];
            $follow = $work['follow_up'] ?? [];
            if ($status === 'resolved') {
                $resolution = Validator::make($work, ['resolution_code' => ['required', 'string'], 'resolution_summary' => ['required', 'string', 'max:5000'], 'resolution_verification' => ['required', 'string', 'max:5000']])->validate();
            }
            $target = match ($status) {
                'in_progress' => in_array($ticket->work_type, ['service_request', 'security_request'], true) ? ItWorkflowState::Fulfilling : ItWorkflowState::InProgress,
                'waiting' => ItWorkflowState::Waiting,
                'resolved' => in_array($ticket->work_type, ['service_request', 'security_request'], true) ? ItWorkflowState::Fulfilled : ItWorkflowState::Resolved,
            };
            $changed = app(ItWorkTransitionService::class)->transition($ticket, new ItTransitionInput(actor: $actor, to: $target,
                reason: $follow['reason'] ?? 'Updated with work note', waitingParty: $follow['waiting_party'] ?? null,
                nextAction: $follow['action'] ?? null, source: 'workspace', expectedVersion: (int) $ticket->lock_version,
                resolutionCode: $resolution['resolution_code'] ?? null, resolutionSummary: $resolution['resolution_summary'] ?? null,
                resolutionVerification: $resolution['resolution_verification'] ?? null));
            $ticket->setRawAttributes($changed->getAttributes(), true);
        }
        $this->versions->advance($ticket);
    }

    public function decode(string $json): array
    {
        Validator::make(['work_payload' => $json], ['work_payload' => ['required', 'string', 'max:60000', 'json']])->validate();
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_is_list($value)) {
            $this->invalid('work_payload', 'Review the work note fields.');
        }

        return $value;
    }

    public function details(ItTicket $ticket): array
    {
        return ItTicketWorkProfile::where('ticket_id', $ticket->id)->first()?->details ?? [];
    }

    public function technician(int $id, ItTicket $ticket): User
    {
        $user = User::whereKey($id)->lockForUpdate()->first();
        if (! $user || $user->approved_at === null || ! $this->access->canWork($user, $ticket)) {
            $this->invalid('technician_user_id', 'Choose a technician currently allowed to work this ticket.');
        }

        return $user;
    }

    public function person(int $id, ItTicket $ticket): User
    {
        $person = User::query()->staff()->whereNotNull('approved_at')->find($id);
        if (! $person || (! $ticket->is_organisation_wide && ! in_array((int) $ticket->site_id, $this->access->approvedSiteIds($person), true))) {
            $this->invalid('person', 'Choose an approved staff member in this ticket’s site.');
        }

        return $person;
    }

    public function recipients(ItTicket $ticket, User $actor, string $json): ?Collection
    {
        $work = $this->decode($json);
        if (! array_key_exists('recipient_user_ids', $work)) {
            return null;
        }
        $allowed = ItStaffDirectory::watchersForTicket($ticket)->reject(fn ($u) => (int) $u->id === (int) $actor->id)->keyBy('id');
        $ids = $work['recipient_user_ids'];
        if (! is_array($ids) || $ids === [] || count($ids) > 100) {
            $this->invalid('work.recipient_user_ids', 'Select at least one currently eligible recipient.');
        }
        foreach ($ids as $id) {
            if (! is_int($id) || ! $allowed->has($id)) {
                $this->invalid('work.recipient_user_ids', 'A selected recipient no longer has access. Review recipients before saving.');
            }
        }

        return $allowed->only($ids)->values();
    }

    private function noTimeOverlap(User $technician, array $period, ?int $except = null): void
    {
        if (ItTicketTimeEntry::where('technician_user_id', $technician->id)->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('starts_at', '<', $period['ends_at'])->where('ends_at', '>', $period['starts_at'])->exists()) {
            $this->invalid('work.periods', 'This technician already has actual time in this period. Review the times; private ticket details are not disclosed.');
        }
    }

    private function approver(ItTicket $ticket, User $actor, ?int $id, ?int $technician = null): User
    {
        if (! $id || in_array($id, [(int) $actor->id, $technician], true)) {
            $this->invalid('approver_user_id', 'Choose another eligible technician to review this work. You cannot approve your own work.');
        }

        return $this->technician($id, $ticket);
    }

    private function followUp(ItTicket $ticket, User $actor, array $input): void
    {
        $safe = Validator::make($input, ['owner_user_id' => ['required', 'integer', 'min:1'], 'due_at' => ['required', 'string'],
            'action' => ['required', 'string', 'max:2000'], 'waiting_party' => ['nullable', Rule::in(['requester', 'vendor', 'approver', 'team', 'change', 'other'])],
            'reason' => ['nullable', 'string', 'max:1000']])->validate();
        $this->technician((int) $safe['owner_user_id'], $ticket);
        $safe['due_at'] = $this->time->instant($safe['due_at'], 'work.follow_up.due_at')->toIso8601String();
        $profile = ItTicketWorkProfile::firstOrCreate(['ticket_id' => $ticket->id]);
        $before = $profile->details ?? [];
        $profile->update(['details' => [...$before, 'follow_up' => $safe]]);
        $ticket->forceFill(['next_action' => $safe['action'], 'due_at' => $safe['due_at']])->save();
        $this->record($ticket, $actor, 'context', $profile->id, 'follow_up', $before['follow_up'] ?? null, $safe, $safe['reason'] ?? 'Follow-up recorded');
    }

    private function saveContext(ItTicket $ticket, User $actor, array $input): void
    {
        $reason = $this->reason($input);
        $rules = ['requester_user_id' => ['nullable', 'integer'], 'affected_user_id' => ['nullable', 'integer'], 'alternate_user_id' => ['nullable', 'integer'],
            'affected_count' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'review_after_hours' => ['sometimes', 'boolean'],
            'cost_review_threshold_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'standard_rate_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'], 'after_hours_rate_cents' => ['nullable', 'integer', 'min:0', 'max:10000000']];
        foreach (['preferred_channel', 'callback_window', 'location', 'onset', 'impact_summary', 'workaround', 'access_instructions', 'already_tried', 'vendor_reference', 'support_reference'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:2000'];
        }
        $safe = Validator::make($input, $rules)->validate();
        foreach (['requester_user_id', 'affected_user_id', 'alternate_user_id'] as $key) {
            if (! empty($safe[$key])) {
                $this->person((int) $safe[$key], $ticket);
            }
        }
        $profile = ItTicketWorkProfile::firstOrCreate(['ticket_id' => $ticket->id]);
        $before = $profile->details ?? [];
        $profile->update(['details' => [...$before, ...$safe]]);
        $before = [...$before, 'requester_user_id' => $ticket->requester_user_id, 'affected_user_id' => $ticket->requested_for_user_id];
        $changes = [];
        if (! empty($safe['requester_user_id'])) {
            $changes['requester_user_id'] = $safe['requester_user_id'];
        }
        if (array_key_exists('affected_user_id', $safe)) {
            $changes['requested_for_user_id'] = $safe['affected_user_id'];
        }
        if ($changes) {
            $ticket->forceFill($changes)->save();
        }
        $this->record($ticket, $actor, 'context', $profile->id, 'updated', $before, $profile->details, $reason);
    }

    private function cost(ItTicket $ticket, User $actor, array $input): void
    {
        $safe = Validator::make($input, ['id' => ['nullable', 'integer'], 'kind' => ['required', Rule::in(['part', 'expense', 'travel_expense'])],
            'incurred_on' => ['required', 'date_format:Y-m-d'], 'description' => ['required', 'string', 'max:2000'],
            'quantity_hundredths' => ['required', 'integer', 'min:1', 'max:1000000'], 'unit_cost_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'reference' => ['nullable', 'string', 'max:1000'], 'require_approval' => ['sometimes', 'boolean'], 'approver_user_id' => ['nullable', 'integer']])->validate();
        $cost = isset($safe['id']) ? ItTicketCost::where('ticket_id', $ticket->id)->findOrFail($safe['id']) : new ItTicketCost(['ticket_id' => $ticket->id, 'recorded_by' => $actor->id]);
        if ($safe['incurred_on'] > CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString()) {
            $this->invalid('incurred_on', 'Record a cost on or before today in the worker timezone.');
        }
        if ($cost->exists) {
            $this->editableRecord($cost, $actor);
        }
        $before = $cost->exists ? $cost->toArray() : null;
        $reason = $cost->exists ? $this->reason($input) : 'Cost recorded';
        $total = intdiv($safe['quantity_hundredths'] * $safe['unit_cost_cents'] + 50, 100);
        $threshold = $this->details($ticket)['cost_review_threshold_cents'] ?? null;
        $requires = (bool) ($safe['require_approval'] ?? false) || ($threshold !== null && $total >= $threshold) || in_array($cost->approval_status, ['pending', 'changes_requested'], true);
        $approver = $requires ? $this->approver($ticket, $actor, $safe['approver_user_id'] ?? $cost->approver_user_id) : null;
        $cost->fill(['kind' => $safe['kind'], 'incurred_on' => $safe['incurred_on'], 'quantity_hundredths' => $safe['quantity_hundredths'],
            'unit_cost_cents' => $safe['unit_cost_cents'], 'total_cents' => $total, 'approval_status' => $requires ? 'pending' : 'not_required',
            'approver_user_id' => $approver?->id, 'details' => ['description' => $safe['description'], 'reference' => $safe['reference'] ?? null]])->save();
        $this->record($ticket, $actor, 'cost', $cost->id, $before ? 'corrected' : 'created', $before, $cost->toArray(), $reason);
    }

    private function correctTime(ItTicket $ticket, User $actor, array $input): void
    {
        $entry = ItTicketTimeEntry::where('ticket_id', $ticket->id)->findOrFail($input['id'] ?? null);
        $this->editableRecord($entry, $actor);
        $reason = $this->reason($input);
        $technician = $this->technician((int) $entry->technician_user_id, $ticket);
        $values = $this->time->period($input);
        $this->noTimeOverlap($technician, $values, $entry->id);
        $before = $entry->toArray();
        $needsReview = in_array($entry->approval_status, ['pending', 'changes_requested'], true) || ($values['after_hours'] && ($this->details($ticket)['review_after_hours'] ?? false));
        $approver = $needsReview ? $this->approver($ticket, $actor, $input['approver_user_id'] ?? $entry->approver_user_id, (int) $technician->id) : null;
        $rate = $values['after_hours'] === $entry->after_hours ? $entry->hourly_rate_cents : ($this->details($ticket)[$values['after_hours'] ? 'after_hours_rate_cents' : 'standard_rate_cents'] ?? null);
        $entry->update([...$values, 'hourly_rate_cents' => $rate, 'approval_status' => $needsReview ? 'pending' : 'not_required', 'approver_user_id' => $approver?->id]);
        $this->record($ticket, $actor, 'time', $entry->id, 'corrected', $before, $entry->toArray(), $reason);
    }

    private function correctNote(ItTicket $ticket, User $actor, array $input): void
    {
        $note = ItTicketComment::where('ticket_id', $ticket->id)->findOrFail($input['id'] ?? null);
        abort_unless($note->is_internal && (int) $note->author_user_id === (int) $actor->id, 403);
        if (ItTicketTimeEntry::where('comment_id', $note->id)->where('approval_status', 'approved')->exists()) {
            $this->invalid('body', 'Ask the assigned reviewer to return the approved time for correction first.');
        }
        $body = Validator::make($input, ['body' => ['required', 'string', 'max:5000']])->validate()['body'];
        $reason = $this->reason($input);
        $before = $note->body;
        $note->update(['body' => trim($body)]);
        $this->record($ticket, $actor, 'note', $note->id, 'corrected', $before, $note->body, $reason);
    }

    private function editableRecord(Model $record, User $actor): void
    {
        abort_unless((int) $record->recorded_by === (int) $actor->id || (int) $record->getAttribute('technician_user_id') === (int) $actor->id, 403);
        if ($record->approval_status === 'approved') {
            $this->invalid('work', 'This record is approved. Ask its assigned reviewer to return it for correction first.');
        }
    }

    private function review(ItTicket $ticket, User $actor, array $input): void
    {
        Validator::make($input, ['type' => ['required', Rule::in(['time', 'cost'])], 'decision' => ['required', Rule::in(['approved', 'changes_requested', 'request_correction'])]])->validate();
        $record = ($input['type'] === 'time' ? ItTicketTimeEntry::query() : ItTicketCost::query())->where('ticket_id', $ticket->id)->findOrFail($input['id'] ?? null);
        $reason = $this->reason($input);
        if ($input['decision'] === 'request_correction') {
            abort_unless((int) $record->recorded_by === (int) $actor->id || (int) $record->getAttribute('technician_user_id') === (int) $actor->id, 403);
            if ($record->approval_status !== 'approved') {
                $this->invalid('decision', 'Only approved work needs a correction request.');
            }
            $this->record($ticket, $actor, $input['type'], $record->id, 'correction_requested', $record->toArray(), $record->toArray(), $reason);

            return;
        }
        abort_unless((int) $record->approver_user_id === (int) $actor->id && (int) $record->recorded_by !== (int) $actor->id && (int) $record->getAttribute('technician_user_id') !== (int) $actor->id, 403);
        if (! in_array($record->approval_status, ['pending', 'approved'], true) || ($record->approval_status === 'approved' && $input['decision'] !== 'changes_requested')) {
            $this->invalid('decision', 'This review has already been decided.');
        }
        $before = $record->toArray();
        $record->update(['approval_status' => $input['decision']]);
        $this->record($ticket, $actor, $input['type'], $record->id, 'reviewed', $before, $record->toArray(), $reason);
    }

    public function guardSettlement(ItTicket $ticket): void
    {
        if (! $this->ready()) {
            return;
        } // Existing tickets continue to work during additive rollout.
        foreach ([ItTicketTimeEntry::class, ItTicketCost::class] as $model) {
            if ($model::where('ticket_id', $ticket->id)->whereIn('approval_status', ['pending', 'changes_requested'])->exists()) {
                $this->invalid('work', 'Required time or cost reviews must be approved before resolving or closing this ticket.');
            }
        }
        if (ItTicketBooking::where('ticket_id', $ticket->id)->whereIn('status', ['requested', 'accepted'])->exists()) {
            $this->invalid('work', 'Complete or cancel the remaining technician bookings before resolving or closing this ticket.');
        }
    }

    public function record(ItTicket $ticket, User $actor, string $type, int $id, string $action, mixed $before, mixed $after, string $reason): void
    {
        ItTicketWorkRevision::create(['ticket_id' => $ticket->id, 'actor_user_id' => $actor->id, 'record_type' => $type, 'record_id' => $id,
            'action' => $action, 'evidence' => ['before' => $before, 'after' => $after, 'reason' => $reason]]);
        AuditLogger::logOrFail('it.ticket.work.'.$action, $ticket, ['actor_id' => $actor->id, 'record_type' => $type, 'record_id' => $id, 'reason_recorded' => true]);
    }

    public function reason(array $input): string
    {
        return trim(Validator::make($input, ['reason' => ['required', 'string', 'min:5', 'max:2000']])->validate()['reason']);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
