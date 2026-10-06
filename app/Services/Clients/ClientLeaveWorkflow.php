<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientLeaveRequest;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Timeline\TimelineEmitter;
use App\Support\WorkerClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Planned leave and actual presence are separate facts; transitions never erase history. */
final class ClientLeaveWorkflow
{
    public function create(User $actor, Client $client, array $input): ClientLeaveRequest
    {
        $input = Validator::make($input, [
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'destination' => ['nullable', 'string', 'max:255'],
            'support_required' => ['nullable', 'string', 'max:5000'],
            'risks_and_mitigations' => ['nullable', 'string', 'max:5000'],
            'emergency_contact' => ['nullable', 'string', 'max:5000'],
            'approval_notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(['requested', 'approved', 'declined'])],
        ])->validate();

        return DB::transaction(function () use ($actor, $client, $input): ClientLeaveRequest {
            [$client, $actor] = $this->lockAuthority($client, $actor);
            $status = $input['status'] ?? 'requested';
            $leave = ClientLeaveRequest::create([...$input, 'client_id' => $client->id, 'requested_by' => $actor->id,
                'status' => $status, 'version' => 1,
                'approved_by' => $status === 'approved' ? $actor->id : null,
                'approved_at' => $status === 'approved' ? now() : null]);
            $this->record($leave, $client, $actor, 'created', null);

            return $leave;
        }, 5);
    }

    public function transition(User $actor, Client $client, ClientLeaveRequest $leave, array $input): ClientLeaveRequest
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $explicitOffset = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value instanceof DateTimeInterface && (! is_string($value) || preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value) !== 1)) {
                $fail('Choose an actual time with its timezone offset.');
            }
        };
        $input = Validator::make($input, [
            'action' => ['required', Rule::in(['approve', 'decline', 'depart', 'return', 'withdraw'])],
            'version' => ['required', 'integer', 'min:1'],
            'occurred_at' => ['required_if:action,depart,return', 'prohibited_unless:action,depart,return', 'nullable', 'date',
                $explicitOffset],
            'returned_at' => ['prohibited_unless:action,depart', 'nullable', 'date', $explicitOffset],
            'reason' => ['required_if:action,withdraw', 'nullable', 'string', 'min:5', 'max:5000'],
            'approval_notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $client, $leave, $input): ClientLeaveRequest {
            [$client, $actor] = $this->lockAuthority($client, $actor);
            $leave = ClientLeaveRequest::query()->where('client_id', $client->id)->lockForUpdate()->findOrFail($leave->id);
            if ((int) $leave->version !== (int) $input['version']) {
                throw ValidationException::withMessages(['version' => 'This leave changed. Reload it before recording another action.']);
            }
            $action = $input['action'];
            if (! in_array($action, $leave->allowedActions(), true)) {
                throw ValidationException::withMessages(['action' => 'This action is not available for the current leave record.']);
            }
            $at = WorkerClock::toUtc($input['occurred_at'] ?? null);
            if ($at !== null && $at->isFuture()) {
                throw ValidationException::withMessages(['occurred_at' => 'Record an actual time, not a future plan.']);
            }
            $returnedAt = WorkerClock::toUtc($input['returned_at'] ?? null);
            if ($returnedAt !== null && ($returnedAt->isFuture() || $returnedAt->lessThan($at))) {
                throw ValidationException::withMessages(['returned_at' => 'The actual return must be at or after departure and must not be in the future.']);
            }
            if ($action === 'depart') {
                // Compare both half-open boundaries. An omitted return remains
                // unbounded, so it cannot silently span a later recorded leave.
                $conflict = ($returnedAt === null || $returnedAt->greaterThan($at)) && ClientLeaveRequest::query()->where('client_id', $client->id)->whereKeyNot($leave->id)
                    ->whereNotNull('departed_at')->whereNull('withdrawn_at')
                    ->whereIn('status', ['approved', 'completed'])
                    ->when($returnedAt !== null, fn ($q) => $q->where('departed_at', '<', $returnedAt))
                    ->where(fn ($q) => $q->whereNull('returned_at')->orWhereColumn('returned_at', '>', 'departed_at'))
                    ->where(fn ($q) => $q->whereNull('returned_at')->orWhere('returned_at', '>', $at))->lockForUpdate()->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['occurred_at' => 'These actual times overlap another recorded leave. Check the times; for leave that has ended, record departure and return together.']);
                }
                $leave->forceFill(['departed_at' => $at, 'departed_by' => $actor->id]);
                if ($returnedAt !== null) {
                    $leave->forceFill(['returned_at' => $returnedAt, 'returned_by' => $actor->id, 'status' => 'completed']);
                }
            } elseif ($action === 'return') {
                $departure = $leave->getRawOriginal('departed_at') === null ? null : CarbonImmutable::parse($leave->getRawOriginal('departed_at'), 'UTC');
                if ($departure === null || $at->lessThan($departure)) {
                    throw ValidationException::withMessages(['occurred_at' => 'The actual return must be at or after the recorded departure.']);
                }
                $leave->forceFill(['returned_at' => $at, 'returned_by' => $actor->id, 'status' => 'completed']);
            } elseif ($action === 'withdraw') {
                $leave->forceFill(['status' => 'cancelled', 'withdrawn_at' => now(), 'withdrawn_by' => $actor->id,
                    'withdrawal_reason' => trim($input['reason'])]);
            } elseif ($action === 'approve') {
                $leave->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id,
                    'approval_notes' => $input['approval_notes'] ?? null]);
            } else {
                $leave->forceFill(['status' => 'declined', 'approval_notes' => $input['approval_notes'] ?? null]);
            }
            $leave->version = ((int) $leave->version) + 1;
            $leave->save();
            $this->record($leave, $client, $actor, $action, $input['reason'] ?? null, $at);
            if ($action === 'depart' && $returnedAt !== null) {
                $this->record($leave, $client, $actor, 'return', null, $returnedAt);
            }

            return $leave;
        }, 5);
    }

    private function lockAuthority(Client $client, User $actor): array
    {
        $client = Client::query()->lockForUpdate()->findOrFail($client->id);
        $actor = app(AuthorizationEvidenceLockService::class)->lockForUser($actor, ['clients.update', 'clinical.accessAllSites', 'sites.viewAll']);
        abort_unless($actor->approved_at !== null, 403);
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($client, $actor): void {
            abort_unless(app(ClientPolicy::class)->updateFromCurrentEvidence($actor, $client, $reads), 403);
        });

        return [$client, $actor];
    }

    private function record(ClientLeaveRequest $leave, Client $client, User $actor, string $action, ?string $reason, mixed $at = null): void
    {
        $facts = ['action' => $action, 'version' => (int) $leave->version, 'reason' => $reason,
            'departed_at' => $leave->departed_at?->toISOString(), 'returned_at' => $leave->returned_at?->toISOString(),
            'withdrawn_at' => $leave->withdrawn_at?->toISOString()];
        AuditLogger::logOrFail('clientleave.transition', $leave, ['actor_id' => (int) $actor->id] + $facts);
        // Each immutable action happens at most once. Separate types preserve the
        // timeline's unique (type, source_type, source_id) ownership contract.
        app(TimelineEmitter::class)->record(['type' => 'leave_transition_'.$action, 'source_type' => ClientLeaveRequest::class,
            'source_id' => $leave->id, 'occurred_at' => $at ?? now(), 'actor_user_id' => $actor->id,
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'subject' => 'Leave '.$action, 'body' => $reason, 'meta' => $facts, 'visibility' => 'internal', 'created_by' => $actor->id]);
    }
}
