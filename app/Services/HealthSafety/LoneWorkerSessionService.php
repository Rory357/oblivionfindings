<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\LoneWorkerCommandReceipt;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Reviewed source commands; response ownership and closure remain in Control Room. */
class LoneWorkerSessionService
{
    public function __construct(private readonly LoneWorkerSessionAccessService $access, private readonly LoneWorkerEmergencyDeliveryService $delivery) {}

    public function create(User $actor, array $data, bool $own = false): LoneWorkerSession
    {
        return $this->transaction(fn () => $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($data, $own) {
            abort_unless($own || ($current->canDo('hazards.view') && $current->canDo('hazards.manage')), 403);
            $validated = $this->planning($data, true, $own);
            Validator::make($data, ['idempotency_key' => ['required', 'uuid']])->validate();
            if (array_key_exists('client_id', $validated) && $validated['client_id'] === null) {
                unset($validated['client_id']);
            }
            $fingerprint = $this->fingerprint(['start', $current->id, $own, $validated]);
            $existing = LoneWorkerSession::withTrashed()->where('creation_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                $this->access->assertRecord($existing, $current, $sites, ! $own, $own);

                return $this->replay($existing, $current, $data['idempotency_key'], $fingerprint, $reads);
            }
            if (Carbon::parse($validated['expected_end_at'])->lte(now())) {
                throw ValidationException::withMessages(['expected_end_at' => 'The expected end must be later than the current time.']);
            }
            $shift = ! empty($validated['shift_id']) ? Shift::query()->whereKey($validated['shift_id'])->lockForUpdate()->firstOrFail() : null;
            $workerId = $own ? (int) $current->id : (int) $validated['user_id'];
            if ($own && isset($validated['user_id'])) {
                abort_unless((int) $validated['user_id'] === (int) $current->id, 403);
            }
            $clientId = $own || ($shift && ! isset($validated['client_id'])) ? $shift?->client_id : ($validated['client_id'] ?? null);
            if ($own && isset($validated['client_id'])) {
                abort_unless($validated['client_id'] === $clientId, 403);
            }
            $client = $clientId !== null ? $reads->query(Client::query())->findOrFail($clientId) : null;
            $shiftClient = $shift?->client_id !== null ? $reads->query(Client::query())->findOrFail($shift->client_id) : null;
            if ($shift && $shiftClient && $shift->site_id !== null && (int) $shift->site_id !== (int) $shiftClient->site_id) {
                abort_unless(in_array((int) $shift->site_id, $sites, true) && in_array((int) $shiftClient->site_id, $sites, true), 403);
                throw ValidationException::withMessages(['shift_id' => 'The rostered Shift has conflicting Site and Client provenance.']);
            }
            if ($shift && ! $own && ($shift->client_id === null ? $clientId !== null : (int) $shift->client_id !== (int) $clientId)) {
                throw ValidationException::withMessages(['client_id' => 'The selected Client must exactly match the rostered Shift.']);
            }
            $siteId = $validated['site_id'] ?? $shift?->site_id ?? $shiftClient?->site_id ?? $client?->site_id;
            if (! $siteId) {
                throw ValidationException::withMessages(['site_id' => 'Select an approved Site or exact rostered Shift.']);
            }
            $worker = $this->access->eligibleWorkerAtSite($workerId, (int) $siteId);
            $row = new LoneWorkerSession;
            $row->forceFill([...$validated, 'user_id' => $worker->id, 'site_id' => $siteId, 'client_id' => $clientId, 'shift_id' => $shift?->id,
                'started_at' => now(), 'last_check_in_at' => null, 'status' => 'active', 'lock_version' => 1,
                'check_in_interval_minutes' => $validated['check_in_interval_minutes'] ?? 60, 'created_by' => $current->id, 'updated_by' => $current->id,
                'creation_key' => $data['idempotency_key'], 'creation_fingerprint' => $fingerprint]);
            $this->access->assertRecord($row, $current, $sites, ! $own, $own);
            if ($own) {
                $this->access->assertOwnActiveShift($row, $current, $reads);
            }
            if ($shift && LoneWorkerSession::query()->where('shift_id', $shift->id)->whereNull('ended_at')->whereIn('status', ['active', 'overdue', 'emergency'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['shift_id' => 'This Shift already has a live monitoring session. Open its current record.']);
            }
            $row->saveQuietly();
            if (array_key_exists('planned_location', $validated)) {
                $this->setPlannedLocation($row, $current, $validated['planned_location'], $reads);
                $row->saveQuietly();
            }
            $row->refresh();
            $auditId = $this->audit($row, $current, 'start', $data['idempotency_key']);
            $this->receipt($row, $current, 'start', $data['idempotency_key'], $fingerprint, $auditId);

            return $row;
        }));
    }

    public function update(LoneWorkerSession $session, User $actor, array $data): LoneWorkerSession
    {
        return $this->command($session, $actor, 'edit', 'update', $data, function ($row, $current, $reads) use ($data) {
            $validated = $this->planning($data, false);
            if (Carbon::parse($validated['expected_end_at'])->lt($row->started_at)) {
                throw ValidationException::withMessages(['expected_end_at' => 'The expected end cannot precede the recorded start.']);
            }
            $plan = Arr::pull($validated, 'planned_location');
            $row->forceFill([...$validated, 'status' => 'active']);
            if (array_key_exists('planned_location', $data)) {
                $this->setPlannedLocation($row, $current, $plan, $reads);
            }

            return [];
        });
    }

    public function checkIn(LoneWorkerSession $session, User $actor, array $data): LoneWorkerSession
    {
        return $this->command($session, $actor, 'check_in', 'checkIn', $data, function ($row, $current) use ($data) {
            $validated = Validator::make($data, ['status' => ['nullable', Rule::in(['ok', 'concern', 'emergency'])], 'notes' => ['nullable', 'string', 'max:1000'], 'location_lat' => ['nullable', 'numeric', 'between:-90,90'], 'location_lng' => ['nullable', 'numeric', 'between:-180,180']])->validate();
            $instant = now();
            $check = $row->checkIns()->create([...$validated, 'status' => $validated['status'] ?? 'ok', 'checked_in_at' => $instant, 'received_at' => $instant, 'recorded_by_user_id' => $current->id]);
            $row->forceFill(['last_check_in_at' => $instant, 'status' => 'active']);
            if ($check->status === 'emergency') {
                $this->emergency($row, $validated['notes'] ?? null);
            }

            return ['check_in_id' => $check->id, 'check_in_snapshot' => ['status' => $check->status, 'notes' => $check->notes, 'checked_in_at' => $instant->toIso8601String(), 'received_at' => $instant->toIso8601String(), 'recorded_by_user_id' => $current->id]];
        });
    }

    public function panic(LoneWorkerSession $session, User $actor, array $data): LoneWorkerSession
    {
        return $this->command($session, $actor, 'panic', 'emergency', $data, function ($row, $current, $reads) use ($data) {
            $validated = Validator::make($data, ['emergency_notes' => ['nullable', 'string', 'max:2000']])->validate();
            if ($row->status === 'emergency') {
                throw ValidationException::withMessages(['status' => 'An emergency request is already recorded. Review its delivery and response before recording another episode.']);
            }
            $this->emergency($row, $validated['emergency_notes'] ?? null);

            return [];
        });
    }

    public function end(LoneWorkerSession $session, User $actor, array $data, bool $own = false): LoneWorkerSession
    {
        return $this->command($session, $actor, 'end', 'end', $data, function ($row, $current, $reads) use ($own) {
            if ($own) {
                $this->access->assertOwnActiveShift($row, $current, $reads);
            }
            $row->forceFill(['status' => 'completed', 'ended_at' => now()]);

            return [];
        }, $own);
    }

    public function remove(LoneWorkerSession $session, User $actor, array $data): LoneWorkerSession
    {
        return $this->command($session, $actor, 'remove', 'delete', $data, function ($row) {
            $row->forceFill(['deleted_at' => now()]);

            return [];
        });
    }

    /** Called inside the existing canonical alert/device lock transaction. */
    public function external(LoneWorkerSession $session, User $actor, array $data, string $capability, string $command, callable $operation): LoneWorkerSession
    {
        return $this->command($session, $actor, $capability, $command, $data, $operation);
    }

    private function emergency(LoneWorkerSession $row, ?string $notes): void
    {
        $row->forceFill(['status' => 'emergency', 'emergency_triggered_at' => now(), 'emergency_notes' => $notes, 'emergency_episode_number' => (int) $row->emergency_episode_number + 1]);
    }

    private function command(LoneWorkerSession $source, User $actor, string $capability, string $command, array $data, callable $operation, bool $own = false): LoneWorkerSession
    {
        return $this->transaction(function () use ($source, $actor, $capability, $command, $data, $operation, $own) {
            $row = LoneWorkerSession::withTrashed()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($row, $capability, $command, $data, $operation, $own) {
                $managerCommand = in_array($capability, ['edit', 'remove', 'response', 'locate', 'acknowledge_panic'], true) || ($capability === 'end' && ! $own);
                if ($managerCommand) {
                    abort_unless($current->canDo('hazards.view') && $current->canDo('hazards.manage'), 403);
                }
                $this->access->assertRecord($row, $current, $sites, $managerCommand, $own);
                abort_unless($managerCommand || (int) $row->user_id === (int) $current->id || $current->canDo('hazards.manage'), 403);
                Validator::make($data, ['expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']])->validate();
                if (array_key_exists('planned_location', $data)) {
                    $data['planned_location'] = $this->plannedInput($data['planned_location']);
                }
                $fingerprint = $this->fingerprint([$row->id, $current->id, $command, $own, Arr::only($data, ['expected_version', 'expected_end_at', 'check_in_interval_minutes', 'activity_description', 'location', 'location_lat', 'location_lng', 'status', 'notes', 'emergency_notes', 'resolution_notes', 'alert_id', 'planned_location'])]);
                if ($reads->query(LoneWorkerCommandReceipt::query())->where('idempotency_key', $data['idempotency_key'])->exists()) {
                    return $this->replay($row, $current, $data['idempotency_key'], $fingerprint, $reads);
                }
                if ((int) $data['expected_version'] !== (int) $row->lock_version) {
                    throw ValidationException::withMessages(['expected_version' => 'This session changed. Review its current record before retrying.']);
                }
                abort_if($row->trashed(), 404);
                $routine = in_array($row->status, ['active', 'overdue'], true) && $row->ended_at === null;
                if ((in_array($capability, ['edit', 'check_in', 'end', 'panic'], true) && ! $routine) || ($capability === 'remove' && $row->status !== 'completed')) {
                    throw ValidationException::withMessages(['status' => 'This command is unavailable in the current session state. Review the recorded response before continuing.']);
                }
                if ($capability === 'response') {
                    abort_unless($current->canDo('controlRoom.alerts.manage'), 403);
                } else {
                    abort_unless($this->access->commandCan($row, $current)[$capability] ?? false, 403);
                }
                $before = $row->status;
                $previousEpisode = (int) $row->emergency_episode_number;
                $meta = $operation($row, $current, $reads);
                $row->forceFill(['lock_version' => $row->lock_version + 1, 'updated_by' => $current->id])->saveQuietly();
                if ((int) $row->emergency_episode_number > $previousEpisode) {
                    $meta['episode_id'] = $this->delivery->stage($row, $current)->id;
                }
                $auditId = $this->audit($row, $current, $command, $data['idempotency_key'], ['from' => $before, 'to' => $row->status, ...$meta]);
                $this->receipt($row, $current, $command, $data['idempotency_key'], $fingerprint, $auditId, $meta);
                $row->setRelation('commandResult', $meta);

                return $row;
            });
        });
    }

    private function replay(LoneWorkerSession $row, User $actor, string $key, string $fingerprint, CurrentAuthorizationReads $reads): LoneWorkerSession
    {
        $receipt = $reads->query(LoneWorkerCommandReceipt::query())->where('idempotency_key', $key)->first();
        if (! $receipt || $receipt->fingerprint !== $fingerprint || (int) $receipt->user_id !== (int) $actor->id || (int) $receipt->lone_worker_session_id !== (int) $row->id) {
            throw ValidationException::withMessages(['idempotency_key' => 'This command key belongs to a different actor, session or payload.']);
        }
        $confirmed = clone $row;
        $confirmed->setRawAttributes($receipt->confirmed_result['record'], true);
        $confirmed->setRelation('commandResult', $receipt->confirmed_result['result'] ?? []);

        return $confirmed;
    }

    private function receipt(LoneWorkerSession $row, User $actor, string $command, string $key, string $fingerprint, int $auditId, array $meta = []): void
    {
        LoneWorkerCommandReceipt::create(['lone_worker_session_id' => $row->id, 'user_id' => $actor->id, 'audit_log_id' => $auditId,
            'command' => $command, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'confirmed_result' => ['record' => $row->getAttributes(), 'result' => $meta]]);
    }

    private function audit(LoneWorkerSession $row, User $actor, string $command, string $key, array $meta = []): int
    {
        $request = clone request();
        $request->setUserResolver(fn () => $actor);
        $snapshot = $row->only(['user_id', 'site_id', 'client_id', 'shift_id', 'status', 'lock_version', 'check_in_interval_minutes', 'activity_description', 'location', 'emergency_notes', 'emergency_episode_number', 'planned_location']);
        foreach (['started_at', 'expected_end_at', 'ended_at', 'last_check_in_at', 'emergency_triggered_at'] as $field) {
            $snapshot[$field] = $row->{$field}?->toIso8601String();
        }
        $action = 'healthSafety.loneWorker.session.'.$command;
        AuditLogger::logOrFail($action, $row, ['actor_id' => $actor->id, 'idempotency_key' => $key, 'snapshot' => $snapshot, ...$meta], $request);

        return (int) AuditLog::query()->where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id)->where('action', $action)->where('meta->idempotency_key', $key)->latest('id')->firstOrFail()->id;
    }

    private function planning(array $data, bool $create, bool $own = false): array
    {
        $rules = ['expected_end_at' => ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', 'date'],
            'check_in_interval_minutes' => ['nullable', 'integer', 'min:15', 'max:480'], 'activity_description' => ['nullable', 'string', 'max:2000'], 'location' => ['nullable', 'string', 'max:500'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'], 'location_lng' => ['nullable', 'numeric', 'between:-180,180']];
        if ($create) {
            $rules = [...$rules, 'user_id' => [$own ? 'nullable' : 'required', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1'], 'client_id' => ['nullable', 'integer', 'min:1'], 'shift_id' => [$own ? 'required' : 'nullable', 'integer', 'min:1']];
        }
        $validated = Validator::make($data, $rules)->validate();
        foreach (['user_id', 'site_id', 'client_id', 'shift_id', 'check_in_interval_minutes'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = (int) $validated[$field];
            }
        }
        $validated['expected_end_at'] = Carbon::parse($validated['expected_end_at'])->utc()->format('Y-m-d H:i:s');
        if (array_key_exists('planned_location', $data)) {
            $validated['planned_location'] = $this->plannedInput($data['planned_location']);
        }

        return $validated;
    }

    /** User choice is separate from any observed worker location. */
    private function plannedInput(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $plan = Validator::make(['planned_location' => $input], [
            'planned_location' => ['required', 'array:method,description,latitude,longitude'],
            'planned_location.method' => ['required', Rule::in(['manual', 'selected_address', 'site_address', 'shift_address'])],
            'planned_location.description' => ['nullable', 'string', 'max:500'],
            'planned_location.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'planned_location.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ])->validate()['planned_location'];
        $latitude = isset($plan['latitude']) ? round((float) $plan['latitude'], 7) : null;
        $longitude = isset($plan['longitude']) ? round((float) $plan['longitude'], 7) : null;
        if (($latitude === null) !== ($longitude === null)) {
            throw ValidationException::withMessages(['planned_location' => 'Supply both planned coordinates, or leave both unknown.']);
        }
        $description = isset($plan['description']) ? trim($plan['description']) : null;
        $description = $description ?? '';
        if (in_array($plan['method'], ['manual', 'selected_address'], true) && $latitude === null) {
            throw ValidationException::withMessages(['planned_location' => 'Supply both coordinates for the manually chosen planned location.']);
        }

        return ['method' => $plan['method'], 'description' => $description, 'latitude' => $latitude, 'longitude' => $longitude];
    }

    private function setPlannedLocation(LoneWorkerSession $row, User $actor, ?array $input, CurrentAuthorizationReads $reads): void
    {
        if ($input === null) {
            $row->forceFill(['planned_location' => null]);

            return;
        }
        $plan = $input;
        $siteId = null;
        $shiftId = null;
        if (in_array($plan['method'], ['site_address', 'shift_address'], true)) {
            $siteId = $this->access->assertSource($row, $reads);
            if ($plan['method'] === 'site_address') {
                $site = $reads->query(Site::query()->lockForUpdate())->findOrFail($siteId);
                $plan['description'] = collect([$site->address_line_1, $site->suburb, $site->city, $site->postcode])->filter()->implode(', ') ?: $site->name;
                $plan['latitude'] = $site->latitude === null ? null : round((float) $site->latitude, 7);
                $plan['longitude'] = $site->longitude === null ? null : round((float) $site->longitude, 7);
                if ($plan['latitude'] === null || $plan['longitude'] === null || ! is_finite($plan['latitude']) || ! is_finite($plan['longitude'])
                    || $plan['latitude'] < -90 || $plan['latitude'] > 90 || $plan['longitude'] < -180 || $plan['longitude'] > 180) {
                    $plan['latitude'] = $plan['longitude'] = null;
                }
            } else {
                if ($row->shift_id === null) {
                    throw ValidationException::withMessages(['planned_location' => 'This session has no exact Shift address to select.']);
                }
                $shift = $reads->query(Shift::query()->lockForUpdate())->findOrFail($row->shift_id);
                $shiftId = (int) $shift->id;
                $plan['description'] = $shift->location;
                $plan['latitude'] = $plan['longitude'] = null;
                if (blank($plan['description'])) {
                    throw ValidationException::withMessages(['planned_location' => 'The exact Shift has no recorded planned address.']);
                }
            }
        }
        Validator::make(['planned_location' => $plan], ['planned_location.description' => ['present', 'string', 'max:500']])->validate();
        $row->forceFill(['planned_location' => ['kind' => 'planned', ...$plan, 'recorded_at' => now()->toIso8601String(),
            'recorded_by_user_id' => (int) $actor->id, 'source_site_id' => $siteId, 'source_shift_id' => $shiftId]]);
    }

    /** Safe proof of an exact confirmed command, reauthorized for the current source viewer. */
    public function confirmationForViewer(User $viewer, mixed $candidate): ?array
    {
        if (! is_array($candidate) || ! is_int($candidate['id'] ?? null) || $candidate['id'] < 1 || ! is_string($candidate['idempotency_key'] ?? null)
            || ! Str::isUuid($candidate['idempotency_key']) || ! is_int($candidate['lock_version'] ?? null)) {
            return null;
        }
        try {
            return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($candidate) {
                $row = $reads->query(LoneWorkerSession::withTrashed())->find($candidate['id']);
                if (! $row) {
                    return null;
                }
                $this->access->assertRecord($row, $current, $sites, $row->trashed());
                $receipt = $reads->query(LoneWorkerCommandReceipt::query())->where('idempotency_key', $candidate['idempotency_key'])->where('lone_worker_session_id', $row->id)->where('user_id', $current->id)->first();
                $version = data_get($receipt?->confirmed_result, 'record.lock_version');
                if (! $receipt || (int) $version !== $candidate['lock_version']) {
                    return null;
                }

                return ['id' => (int) $row->id, 'lock_version' => (int) $version, 'idempotency_key' => $receipt->idempotency_key,
                    'command' => $receipt->command, 'removed' => $receipt->command === 'delete' && data_get($receipt->confirmed_result, 'record.deleted_at') !== null];
            });
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return null;
            }
            throw $error;
        }
    }

    private function fingerprint(array $parts): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($parts), JSON_THROW_ON_ERROR));
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation, 3);
        } catch (\Illuminate\Database\DeadlockException) {
                // Inside a transaction Laravel raises a deadlock (1213) as DeadlockException, not QueryException.
                throw ValidationException::withMessages(['expected_version' => 'This session or its authority changed concurrently. Review the current record.']);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['expected_version' => 'This session or its authority changed concurrently. Review the current record.']);
            }
            throw $exception;
        }
    }
}
