<?php

namespace App\Services\Operations;

use App\Domain\Shifts\Handovers\OperationsHandoverIntent;
use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Services\CurrentAuthorizationReads;
use App\Services\ShiftHandoverService;
use App\Services\Medication\MedicationGovernanceScopeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

/** Operations-only opt in. Never bind this subclass as the shared/eMAR service. */
class OperationsCheckedHandoverService extends ShiftHandoverService
{
    private ?OperationsHandoverIntent $command = null;
    private ?array $source = null;
    private ?array $expected = null;
    private array $sourceIds = [];
    private ?array $initialRow = null;
    private ?array $previousRow = null;
    private bool $written = false;
    private array $auditProofs = [];

    public function __construct(
        OperationsCheckedHandoverTimeline $timeline,
        \App\Services\UserSiteAccessService $siteAccess,
        MedicationGovernanceScopeService $medication,
        \App\Services\AuthorizationEvidenceLockService $authorization,
    ) { parent::__construct($timeline, $siteAccess, $medication, $authorization); }

    /** No handover writer has been invoked. Existing witness credential governance is retained. */
    public function preflight(User $actor, OperationsHandoverIntent $intent, ?string $credential): array
    {
        $read = $this->current($actor, $intent->shiftId, $intent->handoverId);
        abort_unless(hash_equals($read['descriptor']['expected_source'], $intent->expectedSource)
            && $read['descriptor']['expected_version'] === $intent->expectedVersion && $read['descriptor']['can'][$intent->action] === true, 409);
        // Current record/read authority precedes private field or credential feedback.
        if ($credential !== null && (strlen($credential) > 255 || str_contains($credential, "\0"))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['cd_witness_credential' => 'Check the witness credential.']);
        }
        $intent->validateFields();
        if ($intent->action === 'submit') { return $read; }
        $data = $intent->data($credential);
        $incomingId = array_key_exists('incoming_shift_id', $data) ? $data['incoming_shift_id'] : $read['row']?->incoming_shift_id;
        $people = array_values(array_filter([$intent->fields['incoming_staff_id'] ?? null, $intent->fields['cd_witness_id'] ?? null]));
        $effectiveAt = now();
        [$client, $shift, $union] = $this->lockCanonicalOutgoingContext($intent->shiftId, null,
            [(int) $actor->id, ...$people], [$incomingId], $effectiveAt);
        $incoming = $this->resolveIncomingShift($shift, $incomingId, (bool) $data['submit'], lockedShifts: $union);
        $incomingStaff = $this->resolveIncomingStaffId($shift, $incoming,
            array_key_exists('incoming_staff_id', $data) ? $data['incoming_staff_id'] : (array_key_exists('incoming_shift_id', $data) ? null : $read['row']?->incoming_staff_id),
            (int) $client->site_id, verifyCurrentStaff: false);
        if ($data['submit'] && ($incoming === null || $incomingStaff === null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['incoming_shift_id' => 'Assign the exact current incoming Shift before submitting.']);
        }
        [$current, $users] = $this->lockCurrentHandoverAuthority($client, $shift, $actor,
            ['handovers.create', 'shifts.update', 'shifts.viewAssigned', 'shifts.manageAny', 'clients.update'],
            [$incomingStaff, ...$people], [$incomingStaff, $intent->fields['cd_witness_id'] ?? null]);
        $this->assertControlledMedicationDueAuthority($current, $data);
        $this->resolveCdVerification($data['cd_verification_input'], $current, $client,
            is_array($read['row']?->cd_verification) ? $read['row']->cd_verification : null, $users, $effectiveAt, $union);
        $this->assertRead($read);
        return $read;
    }

    public function assertRead(array $read): void
    {
        abort_unless($read['context'] === $this->context($read['ids'])
            && $read['rows'] === $this->rows('shift_handovers', 'outgoing_shift_id', [$read['ids']['shift']]), 409);
        $this->assertRawReadScope($this->rawActor($read['context'], $read['ids']['actor']), $read['context'], $read['ids']);
    }


    public function current(User $actor, int $shiftId, ?int $rowId = null): array
    {
        abort_unless(DB::transactionLevel() > 0 && DB::connection()->getPdo()->inTransaction(), 409);
        $actorId = (int) $actor->id;
        $rawShifts = $this->rows('shifts', 'id', [$shiftId]); abort_unless(count($rawShifts) === 1, 404);
        $rawShift = $rawShifts[0];
        abort_unless((int) ($rawShift['client_id'] ?? 0) > 0 && (int) ($rawShift['site_id'] ?? 0) > 0 && (int) ($rawShift['user_id'] ?? 0) > 0, 404);
        $rows = $this->rows('shift_handovers', 'outgoing_shift_id', [$shiftId]); abort_unless(count($rows) <= 1, 409);
        $rawRow = $rows[0] ?? null;
        if ($rawRow !== null) {
            abort_unless((int) $rawRow['client_id'] === (int) $rawShift['client_id']
                && (int) $rawRow['outgoing_staff_id'] === (int) $rawShift['user_id']
                && (int) $rawRow['version'] > 0 && in_array($rawRow['status'], [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_ACKNOWLEDGED], true), 404);
        }
        $ids = ['actor' => $actorId, 'shift' => $shiftId, 'client' => (int) $rawShift['client_id'], 'site' => (int) $rawShift['site_id'],
            'incoming' => ($rawRow['incoming_shift_id'] ?? null) === null ? [] : [(int) $rawRow['incoming_shift_id']],
            'people' => collect([$actorId, (int) $rawShift['user_id'], $rawRow['incoming_staff_id'] ?? null])->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all()];
        $context = $this->context($ids); // Baseline precedes every normal admission/retrieved callback.
        [$client, $shift] = $this->lockCanonicalOutgoingContext($shiftId, null, [(int) $actor->id], effectiveAt: now());
        abort_unless((int) $shift->id === $shiftId && (int) $shift->client_id === (int) $client->id
            && (int) $client->id === $ids['client'] && (int) $client->site_id === $ids['site'], 404);
        [$current] = $this->lockCurrentHandoverAuthority($client, $shift, $actor,
            ['handovers.create', 'handovers.viewAny', 'shifts.viewAny', 'shifts.viewAssigned', 'shifts.update', 'shifts.manageAny'],
            requireWriteAuthority: false);
        abort_unless($current->isApproved() && $this->canAccessWorkflow($current), 403);
        $row = $rows === [] ? null : $this->model(ShiftHandover::class, $rows[0]);
        abort_unless($rowId === null || $row && (int) $row->id === $rowId, 404);
        if ($row) {
            $this->siteAccess->assertCanAccessHandover($current, $row, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS);
            abort_unless($this->canViewAny($current) || $this->relatedToUser($row, $current), 404);
        } else {
            $this->assertActorCanAccessOutgoingHandover($shift, $current);
            abort_unless($this->canViewAny($current) || (int) $shift->user_id === (int) $current->id, 404);
        }
        $edit = $row ? $this->editPermission($row, $current) : ['editable' => false];
        $incomingReady = false;
        if ($row && $row->status === self::STATUS_DRAFT && $row->incoming_shift_id !== null) {
            try {
                $incoming = $this->resolveIncomingShift($shift, $row->incoming_shift_id, true);
                $incomingReady = $this->resolveIncomingStaffId($shift, $incoming, $row->incoming_staff_id, $ids['site']) !== null;
            } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) { $incomingReady = false; }
        }
        abort_unless($context === $this->context($ids) && $rows === $this->rows('shift_handovers', 'outgoing_shift_id', [$shiftId]), 409);
        $current = $this->rawActor($context, $actorId);
        $this->assertRawReadScope($current, $context, $ids);
        abort_unless($rawShift['status'] !== 'cancelled' && (int) $rawShift['user_id'] > 0, 404);
        $shift = $this->model(Shift::class, $rawShift);
        if ($row) {
            $row->setRelation('outgoingShift', $shift);
            $incomingRow = collect($context['shifts'])->first(fn ($item) => (int) $item['id'] === (int) $row->incoming_shift_id);
            $row->setRelation('incomingShift', $incomingRow ? $this->model(Shift::class, $incomingRow) : null);
            abort_unless($this->canViewAny($current) || $this->relatedToUser($row, $current), 404);
            $edit = $this->editPermission($row, $current);
        }
        $own = $current->canDo('shifts.manageAny') || (int) $shift->user_id === (int) $current->id;
        $descriptor = ['version' => 1, 'scope' => 'operations_handover_command_source', 'actor_id' => (int) $current->id, 'shift_id' => $shiftId,
            'handover_id' => $row ? (int) $row->id : null, 'expected_version' => $row ? (int) $row->version : null, 'status' => $row?->status,
            'expected_source' => hash('sha256', serialize(['context' => $context, 'row' => $rows])),
            'can' => ['create' => !$row && $own && ($current->canDo('handovers.create') || $current->canDo('shifts.update') || $current->canDo('shifts.manageAny')),
                'update' => $row && $own && $row->status === self::STATUS_DRAFT && $edit['editable'],
                'submit' => $row && $own && $row->status === self::STATUS_DRAFT && $incomingReady && $this->canSubmit($row, $current)]];
        abort_unless($context === $this->context($ids) && $rows === $this->rows('shift_handovers', 'outgoing_shift_id', [$shiftId]), 409);
        return ['descriptor' => $descriptor, 'actor' => $current, 'shift' => $shift, 'row' => $row, 'ids' => $ids, 'context' => $context, 'rows' => $rows];
    }

    /** Only the owning orchestrator calls this inside its physical root and application mutex. */
    public function executeChecked(User $actor, OperationsHandoverIntent $intent, ?string $credential): array
    {
        abort_unless($this->command === null, 409);
        $read = $this->current($actor, $intent->shiftId, $intent->handoverId);
        abort_unless(hash_equals($read['descriptor']['expected_source'], $intent->expectedSource)
            && $read['descriptor']['expected_version'] === $intent->expectedVersion && $read['descriptor']['can'][$intent->action] === true, 409);
        $this->command = $intent; $this->source = $read['context']; $this->sourceIds = $read['ids'];
        // Proposed recipients/witnesses enter the frozen command footprint before any shared writer callback.
        $this->sourceIds['incoming'] = collect([...$this->sourceIds['incoming'], $intent->fields['incoming_shift_id'] ?? null])->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $this->sourceIds['people'] = collect([...$this->sourceIds['people'], $intent->fields['incoming_staff_id'] ?? null, $intent->fields['cd_witness_id'] ?? null])->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $this->source = $this->context($this->sourceIds);
        $this->auditProofs = []; $this->timelineService->resetProofs();
        $this->initialRow = $read['rows'][0] ?? null; $this->previousRow = null; $this->expected = null; $this->written = false;
        try {
            $data = $intent->data($credential);
            $this->assertControlledMedicationDueAuthority($this->rawActor($this->source, $this->sourceIds['actor']), $data);
            if (($intent->fields['cd_result'] ?? null) !== null) {
                $rawActor = $this->rawActor($this->source, $this->sourceIds['actor']);
                abort_unless($rawActor->canDo('medications.controlled.view') && $rawActor->canDo('medications.controlled.record'), 404);
                $witnessId = $intent->fields['cd_witness_id'] ?? null; abort_unless(is_int($witnessId), 422);
                $witness = $this->rawActor($this->source, $witnessId);
                abort_unless($witnessId !== (int) $rawActor->id && $witness->isApproved() && $witness->canDo('medications.controlled.witness'), 404);
            }
            if ($intent->action === 'create') { $saved = parent::save($read['shift'], $read['actor'], $data)['handover']; }
            elseif ($intent->action === 'update') { $saved = parent::applyEdit($read['row'], $read['actor'], $data); }
            else { $saved = parent::submit($read['row'], $read['actor']); }
            abort_unless($this->written && $this->expected !== null, 409);
            $expected = $this->expected; $ids = $this->sourceIds; $context = $this->source; $shiftId = $intent->shiftId;
            $this->assertChecked($expected, $context, $ids, $shiftId);
            $proofs = [...array_values($this->auditProofs), ...array_values($this->timelineService->proofs())];
            foreach ($proofs as $proof) { OperationsHandoverRecords::verify($proof); }
            $row = $this->rows('shift_handovers', 'outgoing_shift_id', [$shiftId])[0];
            $actual = $this->model(ShiftHandover::class, $row);
            $receipt = ['handover_id' => (int) $actual->id, 'shift_id' => $shiftId, 'version' => (int) $actual->version,
                'status' => $actual->status, 'saved_at' => $actual->updated_at->copy()->utc()->format('Y-m-d\TH:i:s\Z')];
            abort_unless((int) $saved->id === $receipt['handover_id']
                && $receipt['status'] === ($intent->action === 'submit' || $intent->fields['submit'] ? self::STATUS_SUBMITTED : self::STATUS_DRAFT), 409);
            return ['receipt' => $receipt, 'intended_after_hash' => hash('sha256', OperationsHandoverIntent::json($expected)),
                'verify' => function () use ($expected, $context, $ids, $shiftId, $proofs): void {
                    foreach ($proofs as $proof) { OperationsHandoverRecords::verify($proof); }
                    $this->assertChecked($expected, $context, $ids, $shiftId);
                }];
        } finally { $this->command = null; $this->source = null; $this->sourceIds = []; $this->initialRow = null; $this->previousRow = null; $this->expected = null; $this->written = false; $this->auditProofs = []; $this->timelineService->resetProofs(); }
    }

    protected function persistHandover(ShiftHandover $handover): void
    {
        if ($this->command === null) { throw new \LogicException('Checked handover writer has no owned command.'); }
        $intent = $this->command;
        abort_unless((int) $handover->outgoing_shift_id === $intent->shiftId
            && ($intent->handoverId === null || (int) $handover->id === $intent->handoverId), 409);
        $outgoing = collect($this->source['shifts'])->first(fn ($row) => (int) $row['id'] === $intent->shiftId);
        abort_unless($outgoing && (int) $handover->client_id === $this->sourceIds['client']
            && (int) $handover->outgoing_staff_id === (int) $outgoing['user_id'], 409);
        if ($handover->incoming_shift_id !== null) {
            $incoming = collect($this->source['shifts'])->first(fn ($row) => (int) $row['id'] === (int) $handover->incoming_shift_id);
            abort_unless($incoming && $incoming['status'] !== 'cancelled'
                && (int) $incoming['client_id'] === $this->sourceIds['client'] && (int) $incoming['site_id'] === $this->sourceIds['site']
                && (int) $incoming['user_id'] > 0 && (int) $handover->incoming_staff_id === (int) $incoming['user_id'], 409);
        }
        if (!$this->written) {
            $rows = $this->rows('shift_handovers', 'outgoing_shift_id', [$intent->shiftId]);
            abort_unless($rows === ($this->initialRow === null ? [] : [$this->initialRow]), 409);
        } else { $this->assertChecked($this->expected, $this->source, $this->sourceIds, $intent->shiftId); }
        abort_unless($this->source === $this->context($this->sourceIds), 409);
        $this->assertPreparedIntent($handover);
        // Dedicated submit and combined submission get a new strict revision; shared/default callers are unchanged.
        if ($handover->status === self::STATUS_SUBMITTED && $handover->isDirty('status')) { $handover->version = (int) $handover->version + 1; }
        abort_unless((int) $handover->version > 0 && (int) $handover->version <= 4294967295, 409);
        $now = now()->startOfSecond(); $handover->setUpdatedAt($now);
        if (!$handover->exists) { $handover->setCreatedAt($now); }
        $expected = $this->projection($handover); $oldId = $handover->exists ? (int) $handover->id : null;
        abort_unless($handover->getConnection() === DB::connection() && $handover->save() === true && $handover->getConnection() === DB::connection(), 409);
        $rows = $this->rows('shift_handovers', 'outgoing_shift_id', [$intent->shiftId]); abort_unless(count($rows) === 1, 409);
        $actual = $this->model(ShiftHandover::class, $rows[0]);
        abort_unless($oldId === null || (int) $actual->id === $oldId, 409);
        // The generated ID is accepted only from the sole canonical new row. All prepared attributes existed before save observers.
        foreach ($expected as $field => $value) { abort_unless(array_key_exists($field, $this->projection($actual)) && $this->projection($actual)[$field] === $value, 409); }
        $expected['id'] = (int) $actual->id;
        $this->expected = $expected; $this->previousRow = $rows[0]; $this->written = true;
    }

    /** Retained fields come from the original raw row, never a retrieved listener's replacement. */
    private function assertPreparedIntent(ShiftHandover $handover): void
    {
        $intent = $this->command;
        $beforeRow = $this->written ? $this->previousRow : $this->initialRow;
        $actual = $this->projection($handover);
        $submitting = $handover->status === self::STATUS_SUBMITTED && $handover->isDirty('status');
        if ($beforeRow === null) {
            // Explicit care lifecycle defaults before normal saving observers.
            $handover->forceFill(['worker_notes' => null, 'submitted_at' => null, 'submitted_by' => null,
                'acknowledged_at' => null, 'acknowledged_by' => null]);
            abort_unless($handover->status === self::STATUS_DRAFT, 409);
        } else {
            $before = $this->projection($this->model(ShiftHandover::class, $beforeRow));
            $allowed = $submitting ? ['incoming_shift_id', 'incoming_staff_id', 'status', 'submitted_at', 'submitted_by', 'version', 'updated_at']
                : ['handover_notes', 'client_mood', 'incoming_shift_id', 'incoming_staff_id', 'cd_verification', 'worker_notes', 'version', 'locked_by', 'locked_at', 'updated_at'];
            if (! $submitting) {
                foreach (['medications_due', 'incidents_to_note', 'follow_up_items', 'tasks_pending'] as $key) {
                    if (array_key_exists($key.'_text', $intent->fields)) { $allowed[] = $key; }
                }
            }
            foreach (array_diff(array_keys($before), $allowed) as $key) {
                abort_unless(array_key_exists($key, $actual) && $actual[$key] === $before[$key], 409);
            }
            if (! $submitting) {
                $notes = $before['worker_notes'];
                if (is_array($notes) && $before['handover_notes'] !== $intent->fields['handover_notes']) {
                    $primary = collect($notes['people'])->firstWhere('client_id', $this->sourceIds['client']);
                    $notes['people'] = collect($notes['people'])->reject(fn ($entry) => (int) $entry['client_id'] === $this->sourceIds['client'])->values()->all();
                    $notes['people'][] = ['client_id' => $this->sourceIds['client'], 'notes' => $intent->fields['handover_notes'],
                        'no_updates' => false, 'follow_up_needed' => $primary['follow_up_needed'] ?? false];
                }
                abort_unless(OperationsHandoverIntent::json($handover->worker_notes) === OperationsHandoverIntent::json($notes), 409);
                $input = $intent->fields;
                $oldCd = $before['cd_verification'];
                $unchangedCd = ! array_filter([$input['cd_result'] ?? null, $input['cd_witness_id'] ?? null, $input['cd_notes'] ?? null])
                    || is_array($oldCd) && ($oldCd['result'] ?? null) === ($input['cd_result'] ?? null)
                        && ($oldCd['witness_id'] ?? null) === ($input['cd_witness_id'] ?? null)
                        && ($oldCd['notes'] ?? null) === (trim($input['cd_notes'] ?? '') ?: null);
                if ($unchangedCd) { abort_unless(OperationsHandoverIntent::json($handover->cd_verification) === OperationsHandoverIntent::json($oldCd), 409); }
            }
        }
        if ($submitting) { abort_unless((int) $handover->submitted_by === $this->sourceIds['actor'], 409); }
        elseif ($intent->action !== 'submit') { abort_unless($handover->handover_notes === $intent->fields['handover_notes'], 409); }
    }

    private function assertChecked(array $expected, array $context, array $ids, int $shiftId): void
    {
        abort_unless($context === $this->context($ids), 409);
        $this->assertRawReadScope($this->rawActor($context, $ids['actor']), $context, $ids);
        $rows = $this->rows('shift_handovers', 'outgoing_shift_id', [$shiftId]); abort_unless(count($rows) === 1, 409);
        $actual = $this->projection($this->model(ShiftHandover::class, $rows[0]));
        foreach ($expected as $field => $value) { abort_unless(array_key_exists($field, $actual) && $actual[$field] === $value, 409); }
    }

    private function context(array $ids): array
    {
        $shifts = $this->rows('shifts', 'id', [$ids['shift'], ...$ids['incoming']]);
        $observations = $this->rows('clinical_observations', 'shift_id', [$ids['shift']]);
        $people = collect([...$ids['people'], ...array_column($shifts, 'user_id'), ...array_column($observations, 'recorded_by')])->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $presence = array_values(array_filter($this->rows('shifts', 'user_id', $people),
            fn (array $row): bool => (int) $row['client_id'] === $ids['client'] && (int) $row['site_id'] === $ids['site'] && $row['status'] === 'in_progress'));
        $assignments = $this->rows('role_user', 'user_id', $people); $roleIds = array_values(array_unique(array_column($assignments, 'role_id')));
        $rolePermissions = $this->rows('role_permission', 'role_id', $roleIds); $overrides = $this->rows('permission_user', 'user_id', $people);
        $permissionIds = array_values(array_unique([...array_column($rolePermissions, 'permission_id'), ...array_column($overrides, 'permission_id')]));
        return ['shifts' => $shifts, 'presence' => $presence, 'observations' => $observations,
            'tasks' => $this->rows('shift_tasks', 'shift_id', [$ids['shift']]),
            'incidents' => $this->rows('client_incidents', 'shift_id', [$ids['shift']]),
            'medications' => $this->rows('client_medications', 'client_id', [$ids['client']]),
            'service_contexts' => $this->rows('service_contexts', 'id', array_values(array_filter(array_column($shifts, 'service_context_id')))),
            'clients' => $this->rows('clients', 'id', [$ids['client']]), 'sites' => $this->rows('sites', 'id', [$ids['site']]),
            'users' => $this->rows('users', 'id', $people), 'profiles' => $this->rows('hr_employee_profiles', 'user_id', $people),
            'witness_competencies' => $this->rows('medication_competency_assessments', 'user_id', $people),
            'witness_exemptions' => $this->rows('medication_competency_exemptions', 'user_id', $people),
            'witness_attendance' => array_values(array_filter($this->rows('hr_attendance_sessions', 'user_id', $people), fn ($row) => (int) $row['site_id'] === $ids['site'])),
            'witness_pin_rules' => $this->rows('app_settings', 'key', ['medications.witness_pin.max_attempts', 'medications.witness_pin.lockout_minutes', 'medications.witness_pin.renewal_months']),
            'roles' => $this->rows('roles', 'id', $roleIds), 'assignments' => $assignments, 'role_permissions' => $rolePermissions,
            'overrides' => $overrides, 'definitions' => $this->rows('permissions', 'id', $permissionIds)];
    }

    private function rawActor(array $context, int $actorId): User
    {
        $row = collect($context['users'])->first(fn ($row) => (int) $row['id'] === $actorId); abort_unless($row, 403);
        $actor = $this->model(User::class, $row);
        $permissions = collect($context['definitions'])->mapWithKeys(fn ($row) => [(int) $row['id'] => $this->model(Permission::class, $row)]);
        $roleIds = collect($context['assignments'])->where('user_id', $actorId)->pluck('role_id')->map(fn ($id) => (int) $id)->all();
        $roles = collect($context['roles'])->filter(fn ($row) => in_array((int) $row['id'], $roleIds, true))->map(function ($row) use ($context, $permissions): Role {
            $role = $this->model(Role::class, $row);
            $ids = collect($context['role_permissions'])->where('role_id', $row['id'])->pluck('permission_id')->map(fn ($id) => (int) $id)->all();
            abort_unless($permissions->only($ids)->count() === count($ids), 403);
            $role->setRelation('permissions', $permissions->only($ids)->values()); return $role;
        })->values();
        abort_unless($roles->count() === count($roleIds), 403);
        $overrides = collect($context['overrides'])->where('user_id', $actorId)->map(function ($row) use ($permissions): Permission {
            abort_unless($permissions->has((int) $row['permission_id']), 403);
            $permission = clone $permissions->get((int) $row['permission_id']); $pivot = new Pivot; $pivot->setRawAttributes($row, true);
            return $permission->setRelation('pivot', $pivot);
        })->values();
        return $actor->setRelation('roles', $roles)->setRelation('permissionOverrides', $overrides);
    }

    private function assertRawReadScope(User $actor, array $context, array $ids): void
    {
        abort_unless($actor->isApproved() && ! $actor->isExternalClinicianAccount()
            && ! $actor->hasRole('client', 'next_of_kin') && ! in_array($actor->role, ['client', 'next_of_kin'], true)
            && ($this->canViewAny($actor) || $actor->canDo('handovers.create') || $actor->canDo('shifts.viewAssigned') || $actor->canDo('shifts.update')), 403);
        $site = collect($context['sites'])->first(fn ($row) => (int) $row['id'] === $ids['site']);
        $profile = collect($context['profiles'])->first(fn ($row) => (int) $row['user_id'] === (int) $actor->id);
        abort_unless($site && (bool) $site['is_active'] && !(bool) $site['archived'] && $site['archived_at'] === null && $profile, 403);
        $today = now((string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')))->toDateString();
        $secondary = $profile['secondary_site_ids'] === null ? [] : json_decode($profile['secondary_site_ids'], true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_array($secondary) && (bool) $profile['is_active']
            && ($profile['start_date'] === null || substr($profile['start_date'], 0, 10) <= $today)
            && ($profile['end_date'] === null || substr($profile['end_date'], 0, 10) >= $today), 403);
        $bypass = collect(MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS)->contains(fn ($key) => $actor->canDo($key));
        abort_unless($bypass || in_array($ids['site'], array_map('intval', [$profile['primary_site_id'], ...$secondary]), true), 403);
    }

    protected function recordHandoverAudit(string $action, ShiftHandover $handover, array $meta): void
    {
        abort_unless($this->command !== null && $this->written, 409);
        $projected = $this->projection($handover);
        foreach ($this->expected as $key => $value) {
            abort_unless(array_key_exists($key, $projected) && $projected[$key] === $value, 409);
        }
        $safe = ['shift_id' => $this->command->shiftId, 'incoming_shift_id' => $handover->incoming_shift_id, 'status' => $handover->status];
        if (str_contains($action, '.cdVerification.')) {
            $safe['verification_result'] = $handover->cd_verification['result'] ?? null;
            $safe['witness_id'] = $handover->cd_verification['witness_id'] ?? null;
        }
        $audit = new \App\Models\AuditLog;
        $audit->forceFill(['user_id' => $this->sourceIds['actor'], 'client_id' => $this->sourceIds['client'],
            'action' => $action, 'auditable_type' => $handover->getMorphClass(), 'auditable_id' => (int) $handover->id,
            'meta' => $safe, 'ip_address' => null, 'user_agent' => null]);
        $this->auditProofs[] = OperationsHandoverRecords::write($audit);
    }

    private function rows(string $table, string $column, array $ids): array
    { return OperationsHandoverRecords::rows($table, $column, $ids); }
    private function model(string $class, array $row): Model
    { return OperationsHandoverRecords::model($class, $row); }
    private function projection(Model $model): array
    { return OperationsHandoverRecords::projection($model); }
}
