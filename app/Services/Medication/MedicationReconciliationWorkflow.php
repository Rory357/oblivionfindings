<?php

namespace App\Services\Medication;

use App\Events\MedicationReconciliationApplied;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationReconciliation;
use App\Models\RespiteMedicationReconciliation;
use App\Models\RespiteStay;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MarScheduleService;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Followups\MedicationFollowupService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MedicationReconciliationWorkflow
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly MedicationOrderWorkflow $orders,
        private readonly MedicationOrderLifecycleService $lifecycle,
    ) {}

    public function start(User $actor, int $clientId, array $input): MedicationReconciliation
    {
        return $this->forOrders($actor, $clientId, function (Client $client, User $actor) use ($input) {
            $validated = Validator::make($input, [
                'reason' => 'required|in:moving_in,hospital_discharge,respite_arriving,respite_leaving,house_move',
                'sources' => 'required|string|max:8000', 'respite_stay_id' => 'required_if:reason,respite_arriving,respite_leaving|nullable|integer',
                'source_medicines' => 'nullable|array|max:100', 'source_medicines.*.name' => 'required|string|max:255',
                'source_medicines.*.controlled' => 'required|boolean', 'source_medicines.*.notes' => 'nullable|string|max:4000',
            ])->validate();
            if (! empty($validated['respite_stay_id'])) {
                RespiteStay::query()->whereKey($validated['respite_stay_id'])->where('client_id', $client->id)->firstOrFail();
            }
            $reconciliation = MedicationReconciliation::query()->create([
                'client_id' => $client->id, 'reason' => $validated['reason'], 'sources' => $validated['sources'],
                'respite_stay_id' => $validated['respite_stay_id'] ?? null, 'created_by' => $actor->id,
            ]);
            $medications = ClientMedication::query()->current()->where('client_id', $client->id)
                ->where('state', '!=', 'ceased')->orderBy('id')->get();
            foreach ($medications as $medication) {
                // Create the full canonical set, even when some entries are
                // concealed. A restricted reader can never sign them off.
                $lastDose = ClientMedicationAdministration::query()->effectiveClinicalEvidence()
                    ->where('client_id', $client->id)->where('client_medication_id', $medication->id)
                    ->where('status', 'given')->where('administered_at', '<=', now())
                    ->orderByDesc('administered_at')->orderByDesc('id')->first();
                $next = MedicationDoseSlot::query()->where('client_medication_id', $medication->id)
                    ->whereNull('superseded_at')->whereNull('outcome_administration_id')
                    ->where('due_at', '>=', now())->orderBy('due_at')->first();
                $reconciliation->items()->create([
                    'client_medication_id' => $medication->id, 'medicine_name' => $medication->name,
                    'controlled' => $medication->controlled_drug, 'source_order' => $this->orders->payload($medication) + ['chart_version' => $medication->version],
                    'next_dose_at' => $next?->due_at,
                    'last_dose_evidence' => $lastDose !== null ? [
                        'source' => 'emar', 'administration_id' => $lastDose->id,
                        'given_at' => $lastDose->administered_at?->toIso8601String(),
                        'dose_given' => $lastDose->dose_given, 'recorded_by' => $lastDose->administered_by,
                    ] : ['source' => 'not_recorded'],
                ]);
            }
            foreach ($validated['source_medicines'] ?? [] as $source) {
                $this->orders->assertControlled($actor, (bool) $source['controlled']);
                $reconciliation->items()->create([
                    'medicine_name' => $source['name'], 'controlled' => $source['controlled'], 'notes' => $source['notes'] ?? null,
                    'last_dose_evidence' => ['source' => 'not_recorded'],
                ]);
            }
            AuditLogger::logOrFail('medication_reconciliation.started', $reconciliation, ['actor_id' => $actor->id, 'client_id' => $client->id]);

            return $reconciliation;
        });
    }

    public function save(User $actor, int $id, array $input): MedicationReconciliation
    {
        $submitted = MedicationReconciliation::query()->findOrFail($id);

        return $this->forOrders($actor, $submitted->client_id, function (Client $client, User $actor) use ($id, $input) {
            $record = MedicationReconciliation::query()->whereKey($id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            if ($record->signed_off_at !== null) {
                $this->invalid('reconciliation', 'This reconciliation is signed off. Start a new reconciliation to record later changes.');
            }
            if ($record->status === 'changes_applied') {
                $this->invalid('reconciliation', 'These decisions have already been applied. Sign off this reconciliation or start another for later changes.');
            }
            foreach ($input['items'] ?? [] as $index => $entry) {
                if (filled($entry['next_dose_at'] ?? null)) {
                    $input['items'][$index]['next_dose_at'] = $this->instant($entry['next_dose_at'], 'items.'.$index.'.next_dose_at');
                }
                if (filled($entry['external_last_dose']['given_at'] ?? null)) {
                    $input['items'][$index]['external_last_dose']['given_at'] = $this->instant($entry['external_last_dose']['given_at'], 'items.'.$index.'.external_last_dose.given_at');
                }
            }
            $validated = Validator::make($input, [
                'items' => 'required|array', 'items.*.id' => 'required|integer|distinct',
                'items.*.decision' => 'nullable|in:continue,change,stop,start,ask',
                'items.*.notes' => 'nullable|string|max:4000', 'items.*.next_dose_at' => 'nullable|date',
                'items.*.external_last_dose' => 'nullable|array',
                'items.*.external_last_dose.given_at' => 'required_with:items.*.external_last_dose|date|before_or_equal:now',
                'items.*.external_last_dose.source' => 'required_with:items.*.external_last_dose|string|max:500',
                'items.*.external_last_dose.dose_given' => 'required_with:items.*.external_last_dose|string|max:100',
            ])->validate();
            foreach ($validated['items'] as $entry) {
                $item = $record->items()->whereKey($entry['id'])->lockForUpdate()->firstOrFail();
                $this->orders->assertControlled($actor, $item->controlled);
                if ($item->applied_at !== null) {
                    $this->invalid('items', 'An applied medicine decision cannot be rewritten.');
                }
                if (in_array($entry['decision'] ?? null, ['continue', 'change', 'stop'], true) && $item->client_medication_id === null) {
                    $this->invalid('items', 'This medicine is not on our chart. Start an order or ask the prescriber.');
                }
                if (($entry['decision'] ?? null) === 'start' && $item->client_medication_id !== null) {
                    $this->invalid('items', 'This medicine already has an order. Enter a change instead.');
                }
                $item->fill(['decision' => $entry['decision'] ?? null, 'notes' => $entry['notes'] ?? null]);
                if (isset($entry['next_dose_at'])) {
                    $item->next_dose_at = $entry['next_dose_at'];
                }
                if (isset($entry['external_last_dose'])) {
                    // Keep eMAR evidence and the external source distinct.
                    $item->last_dose_evidence = array_merge($item->last_dose_evidence ?? [], ['external' => $entry['external_last_dose'] + ['recorded_by' => $actor->id, 'recorded_at' => now()->toIso8601String()]]);
                }
                $item->save();
            }
            $record->forceFill(['status' => 'matching'])->save();
            AuditLogger::logOrFail('medication_reconciliation.matched', $record, ['actor_id' => $actor->id]);

            return $record;
        });
    }

    /** Links to an explicitly entered proposed order; never creates a clinical decision. */
    public function apply(User $actor, int $id, array $input): MedicationReconciliation
    {
        $submitted = MedicationReconciliation::query()->findOrFail($id);

        return $this->forOrders($actor, $submitted->client_id, function (Client $client, User $actor) use ($id, $input) {
            $record = MedicationReconciliation::query()->whereKey($id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            if ($record->signed_off_at !== null) {
                $this->invalid('reconciliation', 'This reconciliation is signed off.');
            }
            $items = $record->items()->orderBy('id')->lockForUpdate()->get();
            if ($record->status === 'changes_applied') {
                return $record;
            }
            $newIds = [];
            foreach ($items->whereNull('client_medication_id')->where('decision', 'start') as $item) {
                $revision = MedicationOrderRevision::query()->canonicalVersion()->whereKey((int) ($input['revision_ids'][$item->id] ?? 0))->where('client_id', $client->id)->where('status', 'pending')->first();
                if ($revision !== null && OrderAllergyMatcher::normalise($revision->version->name) === OrderAllergyMatcher::normalise($item->medicine_name)) {
                    $newIds[] = (int) $revision->client_medication_id;
                }
            }
            $this->assertChartStillMatched($client, $items, $newIds);
            foreach ($items as $item) {
                $this->orders->assertControlled($actor, $item->controlled);
                if ($item->applied_at !== null) {
                    continue;
                }
                if ($item->decision === null) {
                    $this->invalid('items', 'Match every medicine before applying changes.');
                }
                if ($item->decision === 'continue' && $item->medication?->approval_status !== 'verified') {
                    $this->invalid('items', 'This chart entry is not checked. Enter its source and link the waiting order version before continuing it.');
                }
                if (in_array($item->decision, ['change', 'start'], true)) {
                    $revisionId = (int) ($input['revision_ids'][$item->id] ?? 0);
                    $revision = MedicationOrderRevision::query()->canonicalVersion()->whereKey($revisionId)
                        ->where('client_id', $client->id)->where('status', 'pending')
                        ->when($item->client_medication_id !== null, fn ($q) => $q->where('client_medication_id', $item->client_medication_id))
                        ->first();
                    if ($revision === null || $revision->medication->controlled_drug !== $item->controlled
                        || OrderAllergyMatcher::normalise($revision->version->name) !== OrderAllergyMatcher::normalise($item->medicine_name)) {
                        $this->invalid('revision_ids', 'Enter the new order or change from its source first, then link the waiting version here.');
                    }
                    $item->medication_order_revision_id = $revision->id;
                    $item->client_medication_id = $revision->client_medication_id;
                } elseif ($item->decision === 'stop') {
                    if (mb_strlen((string) $item->notes) > 255) {
                        $this->invalid('items', 'Keep the stop reason within 255 characters.');
                    }
                    $this->lifecycle->discontinue($actor, $item->medication, $this->orders->reason($item->notes ?? ''),
                        submittedClientId: $client->id, requestKey: 'reconciliation:'.$record->id.':'.$item->id);
                } elseif ($item->decision === 'ask') {
                    if ($item->next_dose_at === null) {
                        $this->invalid('items', 'Record when the next affected dose is due so the prescriber query has a deadline.');
                    }
                    if (blank($item->notes)) {
                        $this->invalid('items', 'Record what needs checking with the prescriber.');
                    }
                    app(MedicationFollowupService::class)->ensureForSource('reconciliation-query', $item->id, $client, $item->medication, null, null, $item->next_dose_at,
                        ['reconciliation_id' => $record->id, 'item_id' => $item->id, 'question' => $item->notes, 'source_url' => '/emar/prescriptions?view=reconciliation']);
                }
                if ($item->client_medication_id !== null) {
                    $appliedOrder = ClientMedication::query()->whereKey($item->client_medication_id)->where('client_id', $client->id)->firstOrFail();
                    $item->source_order = [...($item->source_order ?? []), 'applied_chart_version' => (int) $appliedOrder->version];
                }
                $item->applied_at = now();
                $item->save();
            }
            $record->forceFill(['status' => 'changes_applied', 'support_reassessment_required' => true])->save();
            event(new MedicationReconciliationApplied($client->id, $record->id, $actor->id));
            AuditLogger::logOrFail('medication_reconciliation.changes_applied', $record, ['actor_id' => $actor->id]);

            return $record;
        });
    }

    public function signOff(User $actor, int $id): MedicationReconciliation
    {
        $submitted = MedicationReconciliation::query()->findOrFail($id);

        return $this->forOrders($actor, $submitted->client_id, function (Client $client, User $actor) use ($id) {
            abort_unless($actor->canDo('medications.orders.verify'), 403);
            $record = MedicationReconciliation::query()->whereKey($id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            if ($record->signed_off_at !== null) {
                return $record;
            }
            $items = $record->items()->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $item) {
                $this->orders->assertControlled($actor, $item->controlled);
                if ($item->decision === null || $item->applied_at === null) {
                    $this->invalid('items', 'Match every medicine and apply the decisions before signing off.');
                }
            }
            if (! $this->appliedChartStillMatched($client, $items)) {
                $this->invalid('items', 'The chart changed after these decisions were applied. Start a fresh reconciliation and compare every current medicine.');
            }
            $record->forceFill(['status' => 'signed_off', 'signed_off_by' => $actor->id, 'signed_off_at' => now()])->save();
            $this->refreshRespiteForClient($client, $actor);
            AuditLogger::logOrFail('medication_reconciliation.signed_off', $record, ['actor_id' => $actor->id, 'client_id' => $client->id]);

            return $record;
        });
    }

    public function resolveQuery(User $actor, int $id, int $itemId, array $input): MedicationReconciliation
    {
        $submitted = MedicationReconciliation::query()->findOrFail($id);
        $input['confirmed_at'] = $this->instant($input['confirmed_at'] ?? null, 'confirmed_at');

        return $this->forOrders($actor, $submitted->client_id, function (Client $client, User $actor) use ($id, $itemId, $input) {
            abort_unless($actor->canDo('medications.orders.verify'), 403);
            $record = MedicationReconciliation::query()->whereKey($id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            $item = $record->items()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $this->orders->assertControlled($actor, $item->controlled);
            if ($item->decision !== 'ask' || $item->applied_at === null) {
                $this->invalid('item', 'This medicine does not have an applied prescriber query.');
            }
            if ($item->prescriber_query_resolved_at !== null) {
                $this->invalid('item', 'The response is already recorded. Start a new reconciliation for later changes.');
            }
            $response = Validator::make($input, ['prescriber' => 'required|string|max:255', 'method' => 'required|in:phone,written,in_person',
                'confirmed_at' => 'required|date|before_or_equal:now', 'instruction' => 'required|string|max:4000'])->validate();
            $item->forceFill(['prescriber_query_resolved_at' => now(), 'source_order' => [...($item->source_order ?? []), 'prescriber_response' => $response + ['recorded_by' => $actor->id]]])->save();
            $this->refreshRespiteForClient($client, $actor);
            AuditLogger::logOrFail('medication_reconciliation.query_resolved', $record, ['actor_id' => $actor->id, 'item_id' => $item->id]);

            return $record;
        }, afterDomain: function ($record, array &$events) use ($itemId, $actor) {
            app(MedicationFollowupService::class)->completeFromSource('reconciliation-query:'.$itemId, $actor, 'prescriber_response_recorded', ['reconciliation_id' => $record->id, 'item_id' => $itemId], $events);
        });
    }

    private function forOrders(User $actor, int $clientId, \Closure $callback, ?\Closure $afterDomain = null): mixed
    {
        abort_unless($actor->canDo('medications.orders.manage'), 403);

        return DB::transaction(function () use ($actor, $clientId, $callback, $afterDomain) {
            Client::query()->whereKey($clientId)->lockForUpdate()->firstOrFail();
            ClientMedication::query()->where('client_id', $clientId)->orderBy('id')->lockForUpdate()->get(['id']);
            $marker = (int) AuditLog::query()->max('id');

            return $this->orders->forClient($actor, $clientId, 'medications.orders.manage', function (Client $client, User $actor) use ($callback) {
                $this->access->assertReadable($actor, $client);

                return $callback($client, $actor);
            }, afterWrites: function ($result) use ($marker, $afterDomain) {
                if (! $result instanceof MedicationReconciliation) {
                    return;
                }
                $audits = AuditLog::query()->where('id', '>', $marker)->where('auditable_type', MedicationReconciliation::class)->where('auditable_id', $result->id)->orderBy('id')->get();
                $client = $result->client;
                $events = $audits->map(fn ($audit) => new MedicationEventData(
                    siteId: (int) $client->site_id, kind: str_replace('medication_reconciliation.', 'reconciliation.', $audit->action),
                    subjectType: 'medication_reconciliation', subjectId: (string) $result->id, actorId: (int) ($audit->user_id ?? $result->created_by),
                    occurredAt: CarbonImmutable::now('UTC'), summary: 'Medication reconciliation updated.',
                    facts: ['audit_id' => $audit->id, 'status' => $result->status, 'support_reassessment_required' => $result->support_reassessment_required],
                    clientId: (int) $client->id, controlled: $result->items()->where('controlled', true)->exists(),
                ))->all();
                if ($afterDomain !== null) {
                    $afterDomain($result, $events);
                }
                app(MedicationEventRecorder::class)->appendMany($events);
            });
        }, 5);
    }

    /** Called before event-chain locks; a checked change can release the Respite gate. */
    public function refreshRespiteForClient(Client $client, User $actor): void
    {
        $records = MedicationReconciliation::query()->where('client_id', $client->id)->whereNotNull('respite_stay_id')->whereNotNull('signed_off_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($records as $record) {
            $items = $record->items()->orderBy('id')->lockForUpdate()->get();
            $unresolved = ! $this->appliedChartStillMatched($client, $items)
                || $items->contains(fn ($item) => ($item->decision === 'ask' && $item->prescriber_query_resolved_at === null)
                || ($item->decision !== 'stop' && $item->client_medication_id !== null
                    && ! ClientMedication::query()->whereKey($item->client_medication_id)->where('client_id', $client->id)->where('state', '!=', 'ceased')->where('approval_status', 'verified')->exists())
                || ($item->medication_order_revision_id !== null && ! MedicationOrderRevision::query()->whereKey($item->medication_order_revision_id)
                    ->canonicalVersion()->where('client_id', $client->id)->where('client_medication_id', $item->client_medication_id)->whereIn('status', ['checked', 'checked_alone'])->exists()));
            $type = $record->reason === 'respite_leaving' ? 'discharge' : 'admission';
            $bridge = RespiteMedicationReconciliation::query()->firstOrNew(['stay_id' => $record->respite_stay_id, 'type' => $type]);
            // Do not overwrite a later reconciliation's bridge with older evidence.
            if ($bridge->exists && str_starts_with((string) $bridge->source, 'P04 reconciliation ') && (int) substr($bridge->source, 19) > (int) $record->id) {
                continue;
            }
            $bridge->fill(['status' => $unresolved ? 'in_progress' : 'completed', 'source' => 'P04 reconciliation '.$record->id,
                'reconciled_by_user_id' => $actor->id, 'reconciled_at' => $unresolved ? null : now(), 'updated_by' => $actor->id]);
            if (! $bridge->exists) {
                $bridge->created_by = $actor->id;
            }
            $bridge->save();
        }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    private function instant(mixed $value, string $key): string
    {
        if (! is_string($value)) {
            $this->invalid($key, 'Enter a valid NZ date and time.');
        }
        try {
            return app(MarScheduleService::class)->parseWorkerDateTime($value)->utc()->toIso8601String();
        } catch (\Throwable) {
            $this->invalid($key, 'Enter a valid NZ date and time.');
        }
    }

    private function assertChartStillMatched(Client $client, $items, array $newIds): void
    {
        $current = ClientMedication::query()->current()->where('client_id', $client->id)->where('state', '!=', 'ceased')->get()->keyBy('id');
        $capturedIds = $items->whereNotNull('client_medication_id')->pluck('client_medication_id')->map(fn ($id) => (int) $id);
        if ($current->keys()->diff($capturedIds->merge($newIds))->isNotEmpty()) {
            $this->invalid('items', 'The chart has another medicine. Start a fresh reconciliation so every current medicine is included.');
        }
        foreach ($items->whereNotNull('client_medication_id') as $item) {
            $medication = $current->get($item->client_medication_id);
            if ($medication === null || (int) data_get($item->source_order, 'chart_version') !== (int) $medication->version) {
                $this->invalid('items', 'The chart changed after this reconciliation started. Start a fresh reconciliation and compare the current versions.');
            }
        }
    }

    private function appliedChartStillMatched(Client $client, $items): bool
    {
        $orders = ClientMedication::query()->current()->where('client_id', $client->id)->get()->keyBy('id');
        $capturedIds = $items->whereNotNull('client_medication_id')->pluck('client_medication_id');
        if ($orders->where('state', '!=', 'ceased')->keys()->diff($capturedIds)->isNotEmpty()) {
            return false;
        }
        foreach ($items->whereNotNull('client_medication_id') as $item) {
            $order = $orders->get($item->client_medication_id);
            if ($order === null || ($item->decision === 'stop') !== ($order->state === 'ceased')) {
                return false;
            }
            $expectedVersion = (int) data_get($item->source_order, 'applied_chart_version', data_get($item->source_order, 'chart_version'));
            if ($item->medication_order_revision_id !== null) {
                $revision = MedicationOrderRevision::query()->canonicalVersion()->whereKey($item->medication_order_revision_id)
                    ->where('client_id', $client->id)->where('client_medication_id', $order->id)->with('version')->first();
                if ($revision === null) {
                    return false;
                }
                if (in_array($revision->status, ['checked', 'checked_alone'], true)) {
                    $expectedVersion = (int) $revision->version->version_number;
                }
            }
            if ((int) $order->version !== $expectedVersion) {
                return false;
            }
        }

        return true;
    }
}
