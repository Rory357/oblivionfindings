<?php

namespace App\Services\Medication\Reviews;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationReview;
use App\Models\MedicationReviewEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Support\WorkerClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Review evidence and accountable work only. Orders are changed exclusively through P04. */
final class MedicationReviewWorkflow
{
    public const MANAGE = 'medications.reviews.manage';

    public const TRIGGERS = ['hospital', 'fall', 'error', 'health', 'asked', 'refusals', 'other'];

    public const MOVE_REASONS = ['clinician', 'unwell', 'hospital', 'whanau', 'other'];

    public const OUTCOMES = ['continue', 'change', 'stop', 'swap', 'watch'];

    public const CLINICIAN_ROLES = ['GP', 'Pharmacist', 'Nurse practitioner', 'Specialist'];

    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly MedicationReviewCadence $cadence,
    ) {}

    public function book(User $actor, array $input): MedicationReview
    {
        abort_unless($actor->canDo(self::MANAGE), 403);
        $clientId = (int) ($input['client_id'] ?? 0);
        // Resolve ownership before validating user-controlled clinical fields.
        $this->access->client($actor, $clientId);

        return $this->forClient($actor, $clientId, self::MANAGE,
            function (Client $client, User $lockedActor) use ($input): MedicationReview {
                $this->access->assertReadable($lockedActor, $client);
                $this->assertActive($client);
                $data = Validator::make($input, [
                    'request_uuid' => ['nullable', 'uuid'],
                    'review_type' => ['required', Rule::in(['regular', 'triggered'])],
                    'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.WorkerClock::today()->toDateString()],
                    'owner_id' => ['required', 'integer', 'min:1'],
                    'trigger_code' => ['required_if:review_type,triggered', 'nullable', Rule::in(self::TRIGGERS)],
                    'trigger_reason' => ['required_if:trigger_code,other', 'nullable', 'string', 'max:2000'],
                    ...$this->appointmentRules(),
                ])->validate();
                if (! empty($data['request_uuid'])) {
                    $existing = MedicationReview::query()->where('booking_request_uuid', $data['request_uuid'])->first();
                    if ($existing !== null) {
                        abort_unless((int) $existing->client_id === (int) $client->id, 404);
                        $same = $existing->review_type === $data['review_type'] && $existing->scheduled_date->toDateString() === $data['scheduled_date'] && (int) $existing->owner_id === (int) $data['owner_id'];
                        foreach (['trigger_code', 'trigger_reason', 'appointment_date', 'appointment_time', 'appointment_location', 'reviewer_name', 'reviewer_role', 'clinician_practice'] as $field) {
                            $stored = $field === 'appointment_date' ? $existing->appointment_date?->toDateString() : $existing->{$field};
                            $expected = in_array($field, ['trigger_code', 'trigger_reason'], true) && $data['review_type'] === 'regular' ? null : ($data[$field] ?? null);
                            $same = $same && $stored === $expected;
                        }
                        abort_unless($same, 409, 'This booking request has already been used.');

                        return $existing;
                    }
                }
                $this->assertOwner($lockedActor, $client, (int) $data['owner_id']);
                if ($data['review_type'] === 'regular' && $this->regularOpen($client)->exists()) {
                    throw ValidationException::withMessages(['review_type' => 'A regular review is already booked. Move that review instead.']);
                }
                $review = MedicationReview::query()->create([
                    'client_id' => $client->id, 'booking_request_uuid' => $data['request_uuid'] ?? null, 'review_type' => $data['review_type'], 'status' => 'scheduled',
                    'scheduled_date' => $data['scheduled_date'], 'owner_id' => $data['owner_id'], 'requested_by' => $lockedActor->id,
                    'trigger_code' => $data['review_type'] === 'triggered' ? ($data['trigger_code'] ?? null) : null,
                    'trigger_reason' => $data['review_type'] === 'triggered' ? ($data['trigger_reason'] ?? null) : null,
                    ...collect($data)->only(array_keys($this->appointmentRules()))->all(),
                ]);
                $this->event($review, $lockedActor, 'booked', ['due_date' => $data['scheduled_date'], 'owner_id' => $data['owner_id'], 'kind' => $data['review_type']]);
                $this->syncNextDate($client);
                $this->recordChain($client, $lockedActor, 'booked', $review);

                return $review;
            }, authorizationUserIds: [(int) ($input['owner_id'] ?? 0)]);
    }

    public function move(User $actor, MedicationReview $review, array $input): MedicationReview
    {
        return $this->mutate($actor, $review, $input, function (Client $client, MedicationReview $locked, User $actor) use ($input): void {
            $this->assertOpen($locked);
            $this->assertActive($client);
            $data = Validator::make($input, [
                'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.WorkerClock::today()->toDateString()],
                'reason_code' => ['required', Rule::in(self::MOVE_REASONS)],
                'reason' => ['required', 'string', 'min:3', 'max:2000'],
            ])->validate();
            $before = $locked->scheduled_date->toDateString();
            if ($before === $data['scheduled_date']) {
                throw ValidationException::withMessages(['scheduled_date' => 'Choose a different date.']);
            }
            $locked->scheduled_date = $data['scheduled_date'];
            $this->event($locked, $actor, 'moved', ['from_date' => $before, 'to_date' => $data['scheduled_date'], 'reason_code' => $data['reason_code'], 'reason' => $data['reason']]);
            $locked->save();
            $this->syncNextDate($client);
        });
    }

    public function appointment(User $actor, MedicationReview $review, array $input): MedicationReview
    {
        return $this->mutate($actor, $review, $input, function (Client $client, MedicationReview $locked, User $actor) use ($input): void {
            $this->assertOpen($locked);
            $this->assertActive($client);
            $data = Validator::make($input, $this->appointmentRules(required: true))->validate();
            $this->event($locked, $actor, 'appointment', ['before' => $locked->only(array_keys($this->appointmentRules())), 'after' => $data]);
            $locked->fill($data)->save();
        });
    }

    public function cancel(User $actor, MedicationReview $review, array $input): MedicationReview
    {
        return $this->mutate($actor, $review, $input, function (Client $client, MedicationReview $locked, User $actor) use ($input): void {
            $this->assertOpen($locked);
            if ($this->isRegular($locked)) {
                throw ValidationException::withMessages(['reason' => 'Regular reviews are moved, not cancelled.']);
            }
            $data = Validator::make($input, ['reason' => ['required', 'string', 'min:3', 'max:2000']])->validate();
            $locked->status = 'cancelled';
            $this->event($locked, $actor, 'cancelled', ['reason' => $data['reason']]);
            $locked->save();
            $this->syncNextDate($client);
        });
    }

    public function complete(User $actor, MedicationReview $review, array $input, ?UploadedFile $source = null): MedicationReview
    {
        $storedPath = null;
        try {
            return $this->mutate($actor, $review, $input, function (Client $client, MedicationReview $locked, User $actor) use ($input, $source, &$storedPath): void {
                $this->assertOpen($locked);
                $this->assertActive($client);
                $data = Validator::make([...$input, 'source' => $source], [
                    'completed_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.WorkerClock::today()->toDateString()],
                    'completed_time' => ['nullable', 'date_format:H:i'],
                    'reviewer_registration_number' => ['nullable', 'string', 'max:100'],
                    'clinician_practice' => ['nullable', 'string', 'max:255'],
                    'reviewer_name' => ['required', 'string', 'max:255'],
                    'reviewer_role' => ['required', Rule::in(self::CLINICIAN_ROLES)],
                    'review_location' => ['required', Rule::in(['house', 'practice', 'phone', 'video'])],
                    'participants' => ['required', 'array:person,person_reason,whanau,whanau_detail'],
                    'participants.person' => ['required', Rule::in(['took', 'not'])],
                    'participants.person_reason' => ['required_if:participants.person,not', 'nullable', 'string', 'max:2000'],
                    'participants.whanau' => ['required', Rule::in(['took', 'told', 'none'])],
                    'participants.whanau_detail' => ['required', 'string', 'max:2000'],
                    'clinical_summary' => ['required', 'string', 'max:10000'],
                    'drug_burden_index' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
                    'falls_last_quarter' => ['nullable', 'integer', 'min:0', 'max:999'],
                    'items' => ['present', 'array', 'max:500'],
                    'items.*.client_medication_id' => ['required', 'integer', 'distinct'],
                    'items.*.outcome' => ['required', Rule::in(self::OUTCOMES)],
                    'items.*.recommendation' => ['nullable', 'string', 'max:4000'],
                    'items.*.watch_text' => ['nullable', 'string', 'max:4000'],
                    'items.*.watch_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.WorkerClock::today()->toDateString()],
                    'new_medicine' => ['nullable', 'array:name,recommendation,controlled'],
                    'new_medicine.name' => ['required_with:new_medicine', 'string', 'max:255'],
                    'new_medicine.recommendation' => ['required_with:new_medicine', 'string', 'max:4000'],
                    'new_medicine.controlled' => ['nullable', 'boolean'],
                    'earlier_review_date' => ['nullable', 'date_format:Y-m-d', 'after:'.WorkerClock::today()->toDateString()],
                    'source' => $this->sourceRules(),
                ])->validate();
                $orders = ClientMedication::query()->current()->active()->where('client_id', $client->id)->lockForUpdate()->get();
                $posted = collect($data['items'])->keyBy('client_medication_id');
                // Foreign, deleted and concealed order IDs share direct-object denial.
                foreach ($posted->keys() as $orderId) {
                    $order = $orders->firstWhere('id', (int) $orderId);
                    abort_unless($order !== null && (! $order->controlled_drug || $actor->canDo('medications.controlled.view')), 404);
                }
                $visibleIds = $orders->filter(fn ($order) => ! $order->controlled_drug || $actor->canDo('medications.controlled.view'))->pluck('id');
                if ($posted->keys()->diff($visibleIds)->isNotEmpty() || $visibleIds->diff($posted->keys())->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => 'The current orders changed. Reload the review and record an outcome for each visible order.']);
                }
                foreach ($orders as $order) {
                    $hidden = $order->controlled_drug && ! $actor->canDo('medications.controlled.view');
                    $outcome = $hidden ? ['outcome' => 'pending_controlled'] : $this->validatedOutcome($posted->get($order->id));
                    $version = MedicationOrderVersion::query()->where('client_medication_id', $order->id)->where('client_id', $client->id)
                        ->where('version_number', $order->version ?? 1)->first();
                    $locked->items()->create([
                        'client_id' => $client->id, 'client_medication_id' => $order->id,
                        'order_version_id' => $version?->id, 'name_snapshot' => $order->name,
                        'controlled_snapshot' => (bool) $order->controlled_drug, ...$outcome,
                    ]);
                }
                foreach ($locked->items()->get() as $item) {
                    app(MedicationReviewFollowupAdapter::class)->ensure($client, $locked, $item);
                }
                if (! empty($data['new_medicine'])) {
                    $new = $data['new_medicine'];
                    if (! empty($new['controlled'])) {
                        abort_unless($actor->canDo('medications.controlled.view'), 404);
                    }
                    $locked->items()->create(['client_id' => $client->id, 'client_medication_id' => null,
                        'name_snapshot' => $new['name'], 'controlled_snapshot' => (bool) ($new['controlled'] ?? false),
                        'classification_pending' => true, 'outcome' => 'start', 'recommendation' => $new['recommendation']]);
                }
                $locked->fill(collect($data)->only(['completed_date', 'completed_time', 'reviewer_name', 'reviewer_role', 'reviewer_registration_number', 'clinician_practice', 'review_location', 'participants', 'clinical_summary', 'drug_burden_index', 'falls_last_quarter'])->all());
                if (! empty($data['completed_time'])) {
                    $happened = WorkerClock::toUtc($data['completed_date'].' '.$data['completed_time']);
                    if ($happened->isFuture() || $happened->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('Y-m-d H:i') !== $data['completed_date'].' '.$data['completed_time']) {
                        throw ValidationException::withMessages(['completed_time' => 'Choose a valid past NZ date and time.']);
                    }
                    $locked->happened_at = $happened;
                }
                $locked->status = 'completed';
                $locked->completed_by = $actor->id;
                if ($source !== null) {
                    if ($storedPath !== null) {
                        Storage::disk('local')->delete($storedPath);
                    }
                    $storedPath = $source->store('medication-reviews/'.$locked->id, 'local');
                    abort_unless(is_string($storedPath) && $storedPath !== '', 503, 'The source file could not be saved. Keep your draft and try again.');
                    $locked->fill(['source_path' => $storedPath, 'source_name' => $source->getClientOriginalName(), 'source_mime' => $source->getMimeType(), 'source_size' => $source->getSize()]);
                }
                $locked->save();
                $next = null;
                $defaultDate = Carbon::createFromFormat('!Y-m-d', $data['completed_date'], config('app.worker_timezone', 'Pacific/Auckland'))
                    ->addMonthsNoOverflow($this->cadence->forClient($client)['months'])->toDateString();
                $earlier = $data['earlier_review_date'] ?? null;
                if ($this->isRegular($locked) || $earlier !== null) {
                    $next = $this->regularOpen($client)->lockForUpdate()->first();
                    $ordinaryDate = $next?->scheduled_date?->toDateString() ?? $defaultDate;
                    if ($earlier !== null && $earlier >= $ordinaryDate) {
                        throw ValidationException::withMessages(['earlier_review_date' => 'Choose a date earlier than the next regular review.']);
                    }
                    if ($next === null) {
                        $next = $this->automaticReview($client, $actor, $locked->owner_id ?? $actor->id, $earlier ?? $defaultDate, 'regular', $locked->id);
                    } elseif ($earlier !== null) {
                        $from = $next->scheduled_date->toDateString();
                        $next->forceFill(['scheduled_date' => $earlier, 'revision' => $next->revision + 1])->save();
                        $this->event($next, $actor, 'moved', ['from_date' => $from, 'to_date' => $earlier, 'reason_code' => 'clinician',
                            'reason' => 'The clinician asked for an earlier review at review '.$locked->id, 'from_review_id' => $locked->id]);
                    }
                    $locked->next_review_date = $next->scheduled_date;
                    $locked->save();
                }
                $this->event($locked, $actor, 'completed', ['completed_date' => $data['completed_date'],
                    'clinician' => $data['reviewer_name'], 'role' => $data['reviewer_role'], 'participants' => $data['participants'],
                    'summary' => $data['clinical_summary'], 'items' => $locked->items()->get()->map->only(['id', 'client_medication_id', 'order_version_id', 'name_snapshot', 'controlled_snapshot', 'outcome', 'recommendation', 'watch_text', 'watch_until'])->all(),
                    'source' => $locked->only(['source_path', 'source_name', 'source_mime', 'source_size']),
                    'next_review_id' => $next?->id, 'next_date' => $next?->scheduled_date?->toDateString()]);
                $this->syncNextDate($client);
            });
        } catch (\Throwable $e) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }
    }

    public function addOutcome(User $actor, MedicationReview $review, int $itemId, array $input): MedicationReview
    {
        return $this->mutate($actor, $review, $input, function (Client $client, MedicationReview $locked, User $actor) use ($itemId, $input): void {
            abort_unless($locked->status === 'completed' && $actor->canDo('medications.controlled.view'), 404);
            $item = $locked->items()->where('client_id', $client->id)->whereKey($itemId)->lockForUpdate()->firstOrFail();
            if ($item->outcome !== 'pending_controlled') {
                throw ValidationException::withMessages(['outcome' => 'This outcome has already been recorded.']);
            }
            $data = Validator::make($input, [
                'outcome' => ['required', Rule::in(self::OUTCOMES)], 'recommendation' => ['nullable', 'string', 'max:4000'],
                'watch_text' => ['nullable', 'string', 'max:4000'],
                'watch_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.WorkerClock::today()->toDateString()],
            ])->validate();
            $item->fill($this->validatedOutcome($data))->save();
            app(MedicationReviewFollowupAdapter::class)->ensure($client, $locked, $item);
            $this->event($locked, $actor, 'outcome_added', ['item_id' => $item->id, 'outcome' => $item->outcome, 'recommendation' => $item->recommendation, 'watch_text' => $item->watch_text, 'watch_until' => $item->watch_until?->toDateString()]);
        });
    }

    public function decide(User $actor, MedicationReview $review, int $itemId, array $input, ?UploadedFile $source = null): MedicationReview
    {
        $storedPath = null;
        try {
            return $this->mutate($actor, $review, $input, function (Client $client, MedicationReview $locked, User $actor) use ($itemId, $input, $source, &$storedPath): void {
                $item = $locked->items()->where('client_id', $client->id)->whereKey($itemId)->lockForUpdate()->firstOrFail();
                abort_if($item->isRestricted() && ! $actor->canDo('medications.controlled.view'), 404);
                if ($locked->status !== 'completed' || ! $item->isChange() || $item->linked_order_version_id !== null) {
                    throw ValidationException::withMessages(['state' => 'The prescriber decision cannot be changed at this stage.']);
                }
                $decisionInput = $input;
                $decisionAt = null;
                if (is_string($input['decision_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $input['decision_date'])) {
                    $decisionAt = WorkerClock::toUtc($input['decision_date']);
                    if ($decisionAt->isFuture() || $decisionAt->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('Y-m-d\TH:i') !== $input['decision_date']) {
                        throw ValidationException::withMessages(['decision_date' => 'Choose a valid past local date and time.']);
                    }
                    $decisionInput['decision_date'] = substr($input['decision_date'], 0, 10);
                }
                $data = Validator::make([...$decisionInput, 'source' => $source], [
                    'state' => ['required', Rule::in(['waiting', 'agreed', 'not_agreed'])],
                    'prescriber_name' => ['required', 'string', 'max:255'],
                    'decision_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.WorkerClock::today()->toDateString(), 'after_or_equal:'.$locked->completed_date->toDateString()],
                    'method' => ['required_unless:state,waiting', 'nullable', Rule::in(['writing', 'phone', 'review', 'person'])],
                    'note' => ['required_if:state,not_agreed', 'nullable', 'string', 'max:4000'],
                    'source' => [...$this->sourceRules(), 'required_if:method,writing'],
                ])->validate();
                if (($data['method'] ?? null) === 'review' && ($data['prescriber_name'] !== $locked->reviewer_name || ! in_array($locked->reviewer_role, ['GP', 'Nurse practitioner', 'Specialist'], true))) {
                    throw ValidationException::withMessages(['method' => 'At the review can be used only for the prescriber who conducted it.']);
                }
                $before = $item->only(['decision', 'prescriber_name', 'decision_date', 'decision_method', 'decision_note', 'decision_source_path']);
                $item->fill(['decision' => $data['state'], 'prescriber_name' => $data['prescriber_name'], 'decision_date' => $data['decision_date'], 'decision_method' => $data['method'] ?? null, 'decision_note' => $data['note'] ?? null, 'decision_time' => $decisionAt ? substr($input['decision_date'], 11, 5) : null, 'decision_at' => $decisionAt]);
                // Retain earlier files in the immutable event, never delete prior source evidence.
                if ($source !== null) {
                    if ($storedPath !== null) {
                        Storage::disk('local')->delete($storedPath);
                    }
                    $storedPath = $source->store('medication-reviews/'.$locked->id.'/decisions', 'local');
                    abort_unless(is_string($storedPath) && $storedPath !== '', 503, 'The source file could not be saved. Keep your draft and try again.');
                    $item->fill(['decision_source_path' => $storedPath, 'decision_source_name' => $source->getClientOriginalName(), 'decision_source_mime' => $source->getMimeType(), 'decision_source_size' => $source->getSize()]);
                }
                $item->save();
                $this->event($locked, $actor, 'prescriber_decision', ['item_id' => $item->id, 'before' => $before, 'after' => $item->only(['decision', 'prescriber_name', 'decision_date', 'decision_method', 'decision_note', 'decision_source_path', 'decision_source_name'])]);
            });
        } catch (\Throwable $e) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }
    }

    public function interval(User $actor, Client $submittedClient, array $input): array
    {
        $this->access->client($actor, $submittedClient->id);

        return $this->forClient($actor, $submittedClient->id, self::MANAGE, function (Client $client, User $lockedActor) use ($input): array {
            $this->access->assertReadable($lockedActor, $client);
            $this->assertActive($client);
            $data = Validator::make($input, ['months' => ['present', 'nullable', 'integer', 'between:1,12'], 'reason' => ['required', 'string', 'min:3', 'max:2000']])->validate();
            $before = $client->medication_review_interval_months;
            $client->forceFill(['medication_review_interval_months' => $data['months']])->save();
            $event = MedicationReviewEvent::query()->create(['review_id' => null, 'client_id' => $client->id, 'actor_id' => $lockedActor->id, 'event' => 'interval_changed', 'details' => ['from_months' => $before, 'to_months' => $data['months'], 'reason' => $data['reason']], 'created_at' => now()]);
            AuditLogger::logOrFail('medications.review_interval.updated', $client, ['actor_id' => $lockedActor->id, 'event_id' => $event->id]);
            $result = $this->cadence->forClient($client);
            $this->recordChain($client, $lockedActor, 'interval_changed', null);

            return $result;
        });
    }

    /** System lifecycle hook. Away does not close or postpone a review. */
    public function closeForDeparture(Client $submittedClient): void
    {
        DB::transaction(function () use ($submittedClient): void {
            $client = Client::query()->whereKey($submittedClient->id)->lockForUpdate()->first();
            if ($client === null || ! in_array($client->status, ['inactive', 'discharged', 'deceased'], true)) {
                return;
            }
            foreach (MedicationReview::query()->where('client_id', $client->id)->whereIn('status', ['scheduled', 'overdue', 'in_progress'])->lockForUpdate()->get() as $review) {
                $review->forceFill(['status' => 'closed', 'revision' => $review->revision + 1])->save();
                $this->event($review, null, 'closed', ['reason' => 'The person left the service', 'service_status' => $client->status]);
            }
            $this->syncNextDate($client);
            $this->recordChain($client, null, 'closed', null);
        }, 5);
    }

    public function isRegular(MedicationReview $review): bool
    {
        return in_array(strtolower((string) $review->review_type), ['regular', 'routine'], true);
    }

    private function forClient(User $actor, int $clientId, string $capability, Closure $callback, array $authorizationUserIds = []): mixed
    {
        return DB::transaction(fn () => $this->scope->forClient($actor, $clientId, $capability, $callback, $authorizationUserIds), 5);
    }

    private function forClientRecord(User $actor, MedicationReview $review, string $capability, Closure $callback): mixed
    {
        return DB::transaction(fn () => $this->scope->forClientRecord($actor, $review, $capability, $callback), 5);
    }

    private function mutate(User $actor, MedicationReview $review, array $input, Closure $callback): MedicationReview
    {
        abort_unless($actor->canDo(self::MANAGE), 403);
        $this->access->client($actor, (int) $review->client_id);

        return $this->forClientRecord($actor, $review, self::MANAGE, function (Client $client, MedicationReview $locked, User $actor) use ($input, $callback): MedicationReview {
            $this->access->assertReadable($actor, $client);
            $revision = Validator::make($input, ['revision' => ['required', 'integer', 'min:1']])->validate()['revision'];
            abort_unless((int) $locked->revision === (int) $revision, 409, 'This review changed while you were editing. Reload it and keep your notes.');
            $callback($client, $locked, $actor);
            $locked->forceFill(['revision' => $locked->revision + 1])->save();
            $locked->refresh();
            $lastEvent = $locked->events()->reorder()->latest('id')->first();
            $this->recordChain($client, $actor, $lastEvent?->event ?? 'updated', $locked);

            return $locked;
        });
    }

    private function validatedOutcome(array $data): array
    {
        $outcome = $data['outcome'];
        if (in_array($outcome, ['change', 'stop', 'swap'], true) && blank($data['recommendation'] ?? null)) {
            throw ValidationException::withMessages(['items' => 'Describe each recommended change in the clinician’s words.']);
        }
        if ($outcome === 'watch' && (blank($data['watch_text'] ?? null) || blank($data['watch_until'] ?? null))) {
            throw ValidationException::withMessages(['items' => 'Say what to watch and when the follow-up is due.']);
        }

        return ['outcome' => $outcome, 'recommendation' => in_array($outcome, ['change', 'stop', 'swap'], true) ? trim($data['recommendation']) : null,
            'watch_text' => $outcome === 'watch' ? trim($data['watch_text']) : null, 'watch_until' => $outcome === 'watch' ? $data['watch_until'] : null];
    }

    private function assertOwner(User $actor, Client $client, int $ownerId): void
    {
        $candidate = User::query()->find($ownerId);
        $eligible = $candidate !== null && $candidate->canDo(self::MANAGE)
            && $this->scope->staffPicker([(int) $client->site_id])->contains(fn (array $row) => (int) $row['id'] === $ownerId);
        if (! $eligible) {
            throw ValidationException::withMessages(['owner_id' => 'Choose a current review owner at this person’s house.']);
        }
    }

    private function assertActive(Client $client): void
    {
        if (in_array($client->status, ['inactive', 'discharged', 'deceased'], true)) {
            throw ValidationException::withMessages(['client_id' => 'This person has left the service. Their review history remains available.']);
        }
    }

    private function assertOpen(MedicationReview $review): void
    {
        if (! in_array($review->status, ['scheduled', 'overdue', 'in_progress'], true)) {
            throw ValidationException::withMessages(['review' => 'This review is already recorded or closed.']);
        }
    }

    private function regularOpen(Client $client)
    {
        return MedicationReview::query()->where('client_id', $client->id)->whereIn('review_type', ['regular', 'routine', 'Routine'])
            ->whereIn('status', ['scheduled', 'overdue', 'in_progress'])->orderBy('scheduled_date');
    }

    private function automaticReview(Client $client, User $actor, int $ownerId, string $date, string $kind, int $sourceId): MedicationReview
    {
        $next = MedicationReview::query()->create(['client_id' => $client->id, 'review_type' => $kind,
            'status' => 'scheduled', 'scheduled_date' => $date, 'owner_id' => $ownerId, 'requested_by' => $actor->id,
            'trigger_code' => $kind === 'triggered' ? 'asked' : null,
            'trigger_reason' => $kind === 'triggered' ? 'The clinician asked for an earlier review' : null]);
        $this->event($next, $actor, 'booked', ['due_date' => $date, 'kind' => $kind, 'owner_id' => $ownerId, 'from_review_id' => $sourceId, 'automatic' => true]);

        return $next;
    }

    private function syncNextDate(Client $client): void
    {
        $date = $this->regularOpen($client)->min('scheduled_date');
        $client->forceFill(['next_chart_review_date' => $date])->save();
        MedicationDashboardAlert::query()->where('client_id', $client->id)->whereIn('alert_type', ['chart_review_due', 'medication_review_due'])
            ->whereIn('status', ['active', 'acknowledged'])->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_notes' => 'The review schedule changed']);
    }

    private function event(MedicationReview $review, ?User $actor, string $event, array $details): void
    {
        $row = MedicationReviewEvent::query()->create(['review_id' => $review->id, 'client_id' => $review->client_id,
            'actor_id' => $actor?->id, 'event' => $event, 'details' => $details, 'created_at' => now()]);
        AuditLogger::logOrFail('medications.review.'.$event, $review, ['actor_id' => $actor?->id, 'event_id' => $row->id], systemActor: $actor === null);
    }

    /** Called last, after all domain evidence, revisions, follow-ups and locks. */
    private function recordChain(Client $client, ?User $actor, string $kind, ?MedicationReview $review): void
    {
        app(MedicationEventRecorder::class)->append(new MedicationEventData(
            siteId: (int) $client->site_id, kind: 'review.'.$kind,
            subjectType: $review ? 'medication_review' : 'medication_review_cadence',
            subjectId: (string) ($review?->id ?? $client->id), actorId: $actor?->id,
            occurredAt: CarbonImmutable::now(), summary: 'Medication review '.str_replace('_', ' ', $kind),
            facts: ['revision' => $review?->revision, 'review_id' => $review?->id], clientId: (int) $client->id,
            controlled: $review ? $review->items()->get()->contains(fn ($item) => $item->isRestricted()) : false,
        ));
    }

    private function appointmentRules(bool $required = false): array
    {
        return ['appointment_date' => [$required ? 'required' : 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.WorkerClock::today()->toDateString()],
            'appointment_time' => ['nullable', 'date_format:H:i', 'required_with:appointment_date'],
            'appointment_location' => ['nullable', 'string', 'max:255'],
            'reviewer_name' => ['nullable', 'string', 'max:255'],
            'reviewer_role' => ['nullable', Rule::in(self::CLINICIAN_ROLES)],
            'clinician_practice' => ['nullable', 'string', 'max:255']];
    }

    public function sourceRules(): array
    {
        return ['nullable', 'file', 'mimetypes:application/pdf,image/png,image/jpeg,image/webp', 'max:10240'];
    }
}
