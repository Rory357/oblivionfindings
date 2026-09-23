<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetDocumentSet;
use App\Models\FleetServiceSchedule;
use App\Models\FleetVehicleComplianceRecord;
use App\Models\FleetVehicleReminder;
use App\Models\FleetVehicleReminderEvent;
use App\Models\FleetWorkOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * In-app vehicle follow-ups. A reminder is a prompt with an owner, never a
 * booking hold, and completing it never completes the obligation it points
 * at (a renewal, a service, missing evidence).
 */
class VehicleReminderService
{
    /** Owning records a reminder may point at, all checked to belong to the vehicle. */
    public const SOURCES = ['vehicle', 'document_set', 'service_schedule', 'compliance_record', 'work_order'];

    public const ACTIONS = ['acknowledge', 'complete', 'pause', 'resume', 'snooze'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly VehicleStaffDirectory $staff,
    ) {}

    public function canManage(User $actor): bool
    {
        return $actor->canDo('fleet.manage');
    }

    /** @param array<string,mixed> $data */
    public function create(User $actor, int $assetId, array $data, string $requestKey): FleetVehicleReminder
    {
        return DB::transaction(function () use ($actor, $assetId, $data, $requestKey): FleetVehicleReminder {
            [$current, $asset] = $this->resolve($actor, $assetId);
            self::assertKey($requestKey);
            $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $current->id, 'asset' => (int) $asset->id, 'data' => $data]);
            $prior = FleetVehicleReminder::query()->where('asset_id', $asset->id)->where('request_key', $requestKey)->first();
            if ($prior) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409, 'This request was already used for a different reminder.');

                return $prior;
            }
            $values = $this->validated($asset, $data, null);
            $reminder = FleetVehicleReminder::query()->create([
                ...$values,
                'asset_id' => $asset->id,
                'state' => 'scheduled',
                'lock_version' => 1,
                'created_by_user_id' => $current->id,
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
            ]);
            $this->event($reminder, $current, 'created', null, null, $this->snapshot($reminder), $requestKey, $fingerprint);

            return $reminder;
        }, 3);
    }

    /** Edit or reschedule, keeping the same reminder identity and history. @param array<string,mixed> $data */
    public function update(User $actor, int $assetId, int $reminderId, array $data, int $expectedVersion, string $requestKey): FleetVehicleReminder
    {
        return DB::transaction(function () use ($actor, $assetId, $reminderId, $data, $expectedVersion, $requestKey): FleetVehicleReminder {
            [$current, $asset] = $this->resolve($actor, $assetId);
            self::assertKey($requestKey);
            $reminder = $this->lockReminder($asset, $reminderId);
            $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $current->id, 'reminder' => $reminderId, 'data' => $data]);
            if ($replay = $this->replay($reminder, $requestKey, $fingerprint)) {
                return $replay;
            }
            abort_unless($reminder->lock_version === $expectedVersion, 409, 'This reminder changed while you were editing. Reload before saving.');
            if (trim((string) ($data['reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['reason' => 'Record the reason for this change.']);
            }
            $values = $this->validated($asset, $data, $reminder);
            $before = $this->snapshot($reminder);
            $timingChanged = ! $reminder->due_at->equalTo($values['due_at']);
            $reminder->forceFill([
                ...$values,
                // Acknowledgement belongs to the old time; a new time needs a new one.
                'state' => $reminder->state === 'acknowledged' && $timingChanged ? 'scheduled' : $reminder->state,
                'lock_version' => $reminder->lock_version + 1,
            ])->save();
            $this->event($reminder, $current, 'updated', (string) $data['reason'], $before, $this->snapshot($reminder), $requestKey, $fingerprint);

            return $reminder;
        }, 3);
    }

    public function act(User $actor, int $assetId, int $reminderId, string $action, string $note, int $expectedVersion, string $requestKey, ?string $remindLocal = null, ?string $remindOffset = null): FleetVehicleReminder
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);

        return DB::transaction(function () use ($actor, $assetId, $reminderId, $action, $note, $expectedVersion, $requestKey, $remindLocal, $remindOffset): FleetVehicleReminder {
            [$current, $asset] = $this->resolve($actor, $assetId);
            self::assertKey($requestKey);
            $reminder = $this->lockReminder($asset, $reminderId);
            $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $current->id, 'reminder' => $reminderId, 'action' => $action,
                'note' => trim($note), 'remind_local' => $action === 'snooze' ? $remindLocal : null]);
            if ($replay = $this->replay($reminder, $requestKey, $fingerprint)) {
                return $replay;
            }
            abort_unless($reminder->lock_version === $expectedVersion, 409, 'This reminder changed while you were editing. Reload before saving.');
            if (trim($note) === '') {
                throw ValidationException::withMessages(['note' => $action === 'complete' ? 'Record the outcome and next action.' : 'Record a reason or owner note.']);
            }
            $allowed = match ($action) {
                'acknowledge' => $reminder->state === 'scheduled',
                'complete', 'pause', 'snooze' => $reminder->isOpen(),
                'resume' => $reminder->state === 'paused',
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['note' => 'This reminder can\'t be '.self::pastTense($action).' in its current state.']);
            }
            $snoozeTo = null;
            if ($action === 'snooze') {
                try {
                    $snoozeTo = CarbonImmutable::parse(MaintenanceLocalTime::toUtc((string) $remindLocal, $remindOffset), 'UTC');
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['remind_local' => collect($exception->errors())->flatten()->first()]);
                }
                if (! $snoozeTo->greaterThan(now()) || ! $snoozeTo->greaterThan($reminder->due_at)) {
                    throw ValidationException::withMessages(['remind_local' => 'Choose a time after the current reminder and after now.']);
                }
            }
            $before = $this->snapshot($reminder);
            $changes = match ($action) {
                'acknowledge' => ['state' => 'acknowledged'],
                'pause' => ['state' => 'paused'],
                'resume' => ['state' => 'scheduled'],
                // Only the follow-up moves; its linked obligation is unchanged.
                'snooze' => ['state' => 'scheduled', 'due_at' => $snoozeTo],
                // A repeating reminder moves on by whole calendar months from
                // its own due time; a one-off is completed.
                'complete' => $reminder->repeat_months > 0
                    ? ['state' => 'scheduled', 'due_at' => $this->nextOccurrence($reminder)]
                    : ['state' => 'completed', 'completed_at' => now()],
            };
            $reminder->forceFill([...$changes, 'lock_version' => $reminder->lock_version + 1])->save();
            $this->event($reminder, $current, $action, trim($note), $before, $this->snapshot($reminder), $requestKey, $fingerprint);

            return $reminder;
        }, 3);
    }

    /**
     * Create, move or pause the single renewal reminder of a document set.
     * Called inside the document command's transaction, after its locks.
     *
     * @param  array<string,mixed>|null  $plan  null pauses an existing reminder
     */
    public function syncDocumentRenewal(User $actor, Asset $asset, AssetDocumentSet $set, ?array $plan, string $requestKey): ?FleetVehicleReminder
    {
        $existing = FleetVehicleReminder::query()->where('asset_id', $asset->id)
            ->where('source_type', 'document_set')->where('source_id', $set->id)
            ->whereIn('state', ['scheduled', 'acknowledged', 'paused'])->orderByDesc('id')->lockForUpdate()->first();
        if ($plan === null) {
            if ($existing && $existing->isOpen()) {
                $before = $this->snapshot($existing);
                $existing->forceFill(['state' => 'paused', 'lock_version' => $existing->lock_version + 1])->save();
                $this->event($existing, $actor, 'pause', 'Renewal reminder paused from the document.', $before, $this->snapshot($existing), $requestKey.':renewal', null);
            }

            return $existing;
        }
        try {
            $values = $this->validated($asset, [
                ...$plan,
                'title' => $set->category.' renewal',
                'action_text' => $plan['action_text'] ?? 'Renew '.mb_strtolower($set->category).' and upload the new document.',
                'source_type' => 'document_set',
                'source_id' => $set->id,
                'repeat_months' => 0,
            ], $existing);
        } catch (ValidationException $exception) {
            // The document form nests its reminder fields under "reminder.".
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ['reminder.'.$field => $messages])->all());
        }
        if ($set->expires_on && $values['due_at']->greaterThan(CarbonImmutable::parse($set->expires_on->toDateString().' 23:59', config('app.worker_timezone', 'Pacific/Auckland')))) {
            throw ValidationException::withMessages(['reminder.remind_local' => 'The renewal reminder must be on or before expiry. For an expired document, add a follow-up from Reminders.']);
        }
        if (! $existing) {
            $reminder = FleetVehicleReminder::query()->create([
                ...$values, 'asset_id' => $asset->id, 'state' => 'scheduled', 'lock_version' => 1,
                'created_by_user_id' => $actor->id, 'request_key' => $requestKey.':renewal',
            ]);
            $this->event($reminder, $actor, 'created', null, null, $this->snapshot($reminder), $requestKey.':renewal', null);

            return $reminder;
        }
        $before = $this->snapshot($existing);
        $timingChanged = ! $existing->due_at->equalTo($values['due_at']);
        $existing->forceFill([
            ...$values,
            'state' => $existing->state === 'acknowledged' && ! $timingChanged ? 'acknowledged' : 'scheduled',
            'lock_version' => $existing->lock_version + 1,
        ])->save();
        $this->event($existing, $actor, 'updated', 'Renewal follows the document details.', $before, $this->snapshot($existing), $requestKey.':renewal', null);

        return $existing;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function validated(Asset $asset, array $data, ?FleetVehicleReminder $existing): array
    {
        Validator::make($data, [
            'title' => ['required', 'string', 'max:120'],
            'action_text' => ['required', 'string', 'max:2000'],
            'source_type' => ['nullable', 'in:'.implode(',', self::SOURCES)],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'remind_local' => ['required', 'string'],
            'remind_offset' => ['nullable', 'string', 'max:6'],
            'repeat_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'owner_user_id' => ['required', 'integer', 'min:1'],
            'backup_user_id' => ['nullable', 'integer', 'min:1'],
        ], [], [
            'title' => 'reminder title', 'action_text' => 'action to take', 'remind_local' => 'reminder time',
            'owner_user_id' => 'reminder owner', 'backup_user_id' => 'backup owner',
        ])->validate();

        try {
            $dueAt = CarbonImmutable::parse(MaintenanceLocalTime::toUtc((string) $data['remind_local'], $data['remind_offset'] ?? null), 'UTC');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['remind_local' => collect($exception->errors())->flatten()->first()]);
        }
        $timeChanged = ! $existing || ! $existing->due_at->equalTo($dueAt);
        if ($timeChanged && ! $dueAt->greaterThan(now())) {
            throw ValidationException::withMessages(['remind_local' => 'Choose a future reminder time.']);
        }
        foreach (['owner_user_id' => 'reminder owner', 'backup_user_id' => 'backup owner'] as $field => $label) {
            if (! empty($data[$field]) && ! $this->staff->isCandidate($asset, (int) $data[$field])) {
                throw ValidationException::withMessages([$field => "Choose a current staff member at this vehicle's site as the {$label}."]);
            }
        }
        if (! empty($data['backup_user_id']) && (int) $data['backup_user_id'] === (int) $data['owner_user_id']) {
            throw ValidationException::withMessages(['backup_user_id' => 'Choose a different person as the backup owner.']);
        }
        $sourceType = $data['source_type'] ?? 'vehicle';
        $sourceId = $sourceType === 'vehicle' ? null : (int) ($data['source_id'] ?? 0);
        if ($sourceType !== 'vehicle' && ! $this->sourceBelongs($asset, $sourceType, $sourceId)) {
            throw ValidationException::withMessages(['source_id' => 'Choose a record that belongs to this vehicle.']);
        }

        return [
            'title' => trim((string) $data['title']),
            'action_text' => trim((string) $data['action_text']),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'due_at' => $dueAt,
            'repeat_months' => (int) ($data['repeat_months'] ?? 0),
            'owner_user_id' => (int) $data['owner_user_id'],
            'backup_user_id' => empty($data['backup_user_id']) ? null : (int) $data['backup_user_id'],
        ];
    }

    private function sourceBelongs(Asset $asset, string $type, int $id): bool
    {
        return $id > 0 && match ($type) {
            'document_set' => AssetDocumentSet::query()->whereKey($id)->where('asset_id', $asset->id)->exists(),
            'service_schedule' => FleetServiceSchedule::query()->whereKey($id)->where('asset_id', $asset->id)->exists(),
            'compliance_record' => FleetVehicleComplianceRecord::query()->whereKey($id)->where('asset_id', $asset->id)->exists(),
            'work_order' => FleetWorkOrder::query()->whereKey($id)->where('asset_id', $asset->id)->exists(),
            default => false,
        };
    }

    private function nextOccurrence(FleetVehicleReminder $reminder): CarbonImmutable
    {
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $local = CarbonImmutable::instance($reminder->due_at)->setTimezone($zone);
        do {
            $local = $local->addMonthsNoOverflow($reminder->repeat_months);
        } while ($local->lessThanOrEqualTo(now()));

        return $local->utc();
    }

    /** @return array{0: User, 1: Asset} */
    private function resolve(User $actor, int $assetId): array
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($this->canManage($current), 403);
        $asset = $this->access->assignableVehicle($current, $assetId, true) ?? abort(404);

        return [$current, $asset];
    }

    private function lockReminder(Asset $asset, int $reminderId): FleetVehicleReminder
    {
        return FleetVehicleReminder::query()->whereKey($reminderId)->where('asset_id', $asset->id)->lockForUpdate()->first() ?? abort(404);
    }

    private function replay(FleetVehicleReminder $reminder, string $requestKey, string $fingerprint): ?FleetVehicleReminder
    {
        $prior = FleetVehicleReminderEvent::query()->where('reminder_id', $reminder->id)->where('request_key', $requestKey)->first();
        if (! $prior) {
            return null;
        }
        abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409, 'This request was already used for a different change.');

        return $reminder;
    }

    /** @return array<string,mixed> */
    private function snapshot(FleetVehicleReminder $reminder): array
    {
        return [
            'title' => $reminder->title, 'state' => $reminder->state, 'due_at' => $reminder->due_at?->toIso8601String(),
            'repeat_months' => $reminder->repeat_months, 'owner_user_id' => $reminder->owner_user_id,
            'backup_user_id' => $reminder->backup_user_id, 'source_type' => $reminder->source_type, 'source_id' => $reminder->source_id,
        ];
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    private function event(FleetVehicleReminder $reminder, User $actor, string $action, ?string $note, ?array $before, ?array $after, ?string $requestKey, ?string $fingerprint): void
    {
        FleetVehicleReminderEvent::query()->create([
            'reminder_id' => $reminder->id, 'action' => $action, 'actor_user_id' => $actor->id, 'note' => $note,
            'before_json' => $before, 'after_json' => $after, 'request_key' => $requestKey,
            'request_fingerprint' => $fingerprint, 'occurred_at' => now(),
        ]);
    }

    private static function assertKey(string $requestKey): void
    {
        if (trim($requestKey) === '' || mb_strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['request_key' => 'Provide an idempotency key of at most 100 characters.']);
        }
    }

    private static function pastTense(string $action): string
    {
        return ['acknowledge' => 'acknowledged', 'complete' => 'completed', 'pause' => 'paused', 'resume' => 'resumed', 'snooze' => 'snoozed'][$action];
    }
}
