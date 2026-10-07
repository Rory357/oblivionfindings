<?php

namespace App\Services\Medication\ExternalClinical;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationProviderTransfer;
use App\Models\MedicationProviderTransferEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationOrderWorkflow;
use App\Services\Medication\MedicationReconciliationWorkflow;
use App\Services\Medication\MedicationRecordAccess;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProviderMedicationTransfers
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $records,
        private readonly MedicationOrderWorkflow $orders,
        private readonly MedicationReconciliationWorkflow $reconciliations,
    ) {}

    public function create(User $actor, array $input): MedicationProviderTransfer
    {
        return $this->internal($actor, (int) ($input['client_id'] ?? 0), function (Client $client, User $locked) use ($input) {
            $data = Validator::make($input, [
                'direction' => 'required|in:outgoing,incoming', 'provider_name' => 'required|string|max:255',
                'recipient_name' => 'required|string|max:255', 'purpose' => 'required|string|max:2000',
                'disclosure_basis' => 'required|string|max:2000', 'identity_evidence' => 'required|string|max:2000',
                'request_key' => 'required|string|max:100|regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
                'source_reference' => 'required_if:direction,incoming|nullable|string|max:2000',
                'identity_confirmed' => 'required_if:direction,incoming|accepted_if:direction,incoming',
                'source_snapshot' => 'required_if:direction,incoming|nullable|array',
            ])->validate();
            $hash = $this->digest([$client->id, $locked->id, $data]);
            $replay = MedicationProviderTransfer::query()->where('created_by', $locked->id)->where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($replay !== null) {
                abort_unless((int) $replay->client_id === (int) $client->id && hash_equals($replay->payload_sha256, $hash), 409, 'This request key belongs to different handover details.');
                $this->assertControlled($locked, $replay);

                return $replay;
            }
            $snapshot = $data['direction'] === 'outgoing' ? $this->snapshot($locked, $client) : $this->incoming($client, $data['source_snapshot']);
            $controlled = collect($snapshot['medications'])->contains(fn ($row) => (bool) ($row['prescription']['controlled_drug'] ?? false));
            $this->orders->assertControlled($locked, $controlled);
            $record = MedicationProviderTransfer::query()->create([
                'client_id' => $client->id, 'site_id' => $client->site_id, 'direction' => $data['direction'],
                'provider_name' => $data['provider_name'], 'recipient_name' => $data['recipient_name'],
                'purpose' => $data['purpose'], 'disclosure_basis' => $data['disclosure_basis'],
                'identity_evidence' => $data['identity_evidence'], 'source_reference' => $data['source_reference'] ?? null,
                'snapshot' => $snapshot, 'snapshot_sha256' => $this->digest($snapshot), 'controlled' => $controlled,
                'created_by' => $locked->id, 'request_key' => $data['request_key'], 'payload_sha256' => $hash,
            ]);
            AuditLogger::logOrFail('medications.transfer.created', $record, ['actor_id' => $locked->id, 'direction' => $record->direction, 'snapshot_sha256' => $record->snapshot_sha256]);

            return $record;
        });
    }

    public function transition(User $actor, int $id, array $input): MedicationProviderTransfer
    {
        $submitted = MedicationProviderTransfer::query()->findOrFail($id);
        // Reconciliation creates canonical clinical evidence under P04 authority;
        // all other lifecycle actions use the office site/person governance boundary.
        $callback = function (Client $client, User $locked) use ($id, $input) {
            abort_unless($locked->canDo('medications.transfers.manage'), 403);
            $this->records->assertReadable($locked, $client);
            $record = MedicationProviderTransfer::query()->whereKey($id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $record->site_id === (int) $client->site_id, 404);
            $this->assertControlled($locked, $record);
            $data = Validator::make($input, ['action' => 'required|in:review,receipt,start_reconciliation,complete,cancel',
                'expected_version' => 'required|integer|min:1', 'request_key' => 'required|string|max:100|regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
                'note' => 'required|string|max:2000', 'identity_confirmed' => 'required_if:action,review,start_reconciliation|accepted_if:action,review,start_reconciliation',
                'facts_checked' => 'required_if:action,review|accepted_if:action,review', 'recipient_confirmed' => 'required_if:action,review|accepted_if:action,review',
                'receipt_reference' => 'required_if:action,receipt|nullable|string|max:2000',
                'allergies_reviewed' => 'required_if:action,complete|accepted_if:action,complete',
                'allergy_review_reference' => 'required_if:action,complete|nullable|string|max:2000'])->validate();
            $hash = $this->digest([$locked->id, $data]);
            $replay = MedicationProviderTransferEvent::query()->where('transfer_id', $record->id)->where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($replay !== null) {
                abort_unless(hash_equals($replay->payload_sha256, $hash), 409, 'This request key belongs to a different handover action.');

                return $record;
            }
            if ($record->version !== (int) $data['expected_version']) {
                $this->invalid('expected_version', 'The handover changed. Reload before recording the next action.');
            }
            $evidence = ['note' => $data['note']];
            switch ($data['action']) {
                case 'review':
                    $this->requireState($record, ['draft']);
                    if ($record->direction === 'outgoing') {
                        $this->assertFresh($locked, $client, $record);
                    }
                    $record->reviewed_by = $locked->id;
                    $record->reviewed_at = now();
                    $record->status = 'reviewed';
                    $evidence += ['identity_confirmed' => true, 'facts_checked' => true, 'recipient_confirmed' => true, 'snapshot_sha256' => $record->snapshot_sha256];
                    break;
                case 'receipt':
                    $this->requireState($record, ['reviewed']);
                    $record->received_at = now();
                    $record->receipt_reference = $data['receipt_reference'];
                    $record->status = 'received';
                    $evidence['receipt_reference'] = $data['receipt_reference'];
                    break;
                case 'start_reconciliation':
                    $this->requireState($record, ['received']);
                    if ($record->direction !== 'incoming') {
                        $this->invalid('action', 'An outgoing handover is reconciled by the receiving provider.');
                    }
                    $source = 'Provider '.$record->provider_name.'; '.$record->source_reference.'; handover '.$record->id.'; digest '.$record->snapshot_sha256.'. Incoming facts remain unverified.';
                    $medicines = collect($record->snapshot['medications'])->map(fn ($row) => [
                        'name' => $row['prescription']['name'], 'controlled' => (bool) $row['prescription']['controlled_drug'],
                        'notes' => mb_substr('Unverified provider source: '.json_encode($row, JSON_THROW_ON_ERROR), 0, 4000),
                    ])->all();
                    $reconciliation = $this->reconciliations->start($locked, $client->id, ['reason' => 'moving_in', 'sources' => $source, 'source_medicines' => $medicines]);
                    // Keep all source details, including last dose and allergy facts,
                    // attributable and separate from canonical internal evidence.
                    $unlinked = $reconciliation->items()->whereNull('client_medication_id')->orderBy('id')->get();
                    foreach ($unlinked as $index => $item) {
                        $row = $record->snapshot['medications'][$index];
                        $item->forceFill(['source_order' => ['provider_transfer_id' => $record->id, 'verified' => false, 'provider_source' => $row],
                            'last_dose_evidence' => ['source' => 'unverified_provider', 'provider_transfer_id' => $record->id, 'external' => $row['last_dose'] ?? null],
                            // Seed the review/query deadline, retaining the provider's
                            // unverified provenance above; this creates no dose slot.
                            'next_dose_at' => filled($row['next_due_at'] ?? null) ? CarbonImmutable::parse($row['next_due_at'])->utc() : null])->save();
                    }
                    $record->reconciliation_id = $reconciliation->id;
                    $record->status = 'reconciliation_started';
                    $evidence += ['identity_confirmed' => true, 'reconciliation_id' => $reconciliation->id,
                        'unverified_allergies' => $record->snapshot['allergies']];
                    break;
                case 'complete':
                    $this->requireState($record, ['reconciliation_started']);
                    $reconciliation = $record->reconciliation()->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
                    if ($reconciliation->signed_off_at === null || $reconciliation->status !== 'signed_off') {
                        $this->invalid('action', 'Complete the canonical medication reconciliation before completing this handover.');
                    }
                    // Allergy review is explicitly recorded; no source allergy is
                    // silently marked as verified or inserted into the chart.
                    Validator::make($input, ['allergies_reviewed' => 'required|accepted', 'allergy_review_reference' => 'required|string|max:2000'])->validate();
                    $record->status = 'reconciled';
                    $evidence += ['reconciliation_id' => $reconciliation->id, 'allergies_reviewed' => true, 'allergy_review_reference' => $input['allergy_review_reference']];
                    break;
                case 'cancel':
                    $this->requireState($record, ['draft', 'reviewed', 'received']);
                    $record->status = 'cancelled';
                    break;
            }
            $record->version++;
            $record->save();
            $event = $record->events()->create(['actor_id' => $locked->id, 'action' => $data['action'], 'request_key' => $data['request_key'],
                'payload_sha256' => $hash, 'evidence' => $evidence, 'created_at' => now()]);
            AuditLogger::logOrFail('medications.transfer.'.$data['action'], $record, ['actor_id' => $locked->id, 'event_id' => $event->id, 'version' => $record->version]);

            return $record;
        };

        return ($input['action'] ?? '') === 'start_reconciliation'
            ? $this->orders->forClient($actor, $submitted->client_id, 'medications.orders.manage', $callback)
            : $this->internal($actor, $submitted->client_id, $callback);
    }

    public function reviewed(User $actor, int $id): MedicationProviderTransfer
    {
        $submitted = MedicationProviderTransfer::query()->findOrFail($id);

        return $this->internal($actor, $submitted->client_id, function (Client $client, User $locked) use ($id) {
            abort_unless($locked->canDo('medications.reports.export'), 403);
            $record = MedicationProviderTransfer::query()->whereKey($id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $record->site_id === (int) $client->site_id && $record->reviewed_at !== null
                && in_array($record->status, ['reviewed', 'received', 'reconciliation_started', 'reconciled'], true), 404);
            $this->assertControlled($locked, $record);
            abort_unless(hash_equals($record->snapshot_sha256, $this->digest($record->snapshot)), 409, 'The reviewed snapshot needs checking.');
            if ($record->direction === 'outgoing') {
                $this->assertFresh($locked, $client, $record);
            }
            AuditLogger::logOrFail('medications.transfer.disclosure_downloaded', $record, ['actor_id' => $locked->id, 'snapshot_sha256' => $record->snapshot_sha256]);

            return $record;
        });
    }

    public function packet(MedicationProviderTransfer $record): array
    {
        return ['format' => config('emar-external-clinical.packet_format'), 'format_version' => config('emar-external-clinical.packet_version'),
            'transfer_id' => $record->id, 'transfer_version' => $record->version, 'direction' => $record->direction, 'provider_name' => $record->provider_name,
            'recipient_name' => $record->recipient_name, 'purpose' => $record->purpose, 'disclosure_basis' => $record->disclosure_basis,
            'reviewed_at' => $record->reviewed_at?->toIso8601String(), 'snapshot_sha256' => $record->snapshot_sha256, 'snapshot' => $record->snapshot];
    }

    private function snapshot(User $actor, Client $client): array
    {
        $orders = ClientMedication::query()->current()->where('client_id', $client->id)->where('state', '!=', 'ceased')->orderBy('id')->lockForUpdate()->get();
        $this->orders->assertControlled($actor, $orders->contains(fn ($m) => (bool) $m->controlled_drug));
        $medicines = $orders->map(function (ClientMedication $order) use ($client) {
            $dose = CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => $reads->query(
                ClientMedicationAdministration::query()->effectiveClinicalEvidence()->where('client_id', $client->id)->where('client_medication_id', $order->id)
                    ->where('status', 'given')->where('administered_at', '<=', now())->orderByDesc('administered_at')->orderByDesc('id')
            )->first());
            $next = CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => $reads->query(
                MedicationDoseSlot::query()->where('client_medication_id', $order->id)->where('client_id', $client->id)
                    ->whereNull('superseded_at')->whereNull('outcome_administration_id')->where('due_at', '>=', now())->orderBy('due_at')
            )->first());

            return ['id' => $order->id, 'version' => $order->version, 'state' => $order->state, 'approval_status' => $order->approval_status,
                'prescription' => ExternalClinicalPrescription::normalise($this->orders->payload($order)), 'last_dose' => $dose ? ['given_at' => $dose->administered_at?->toIso8601String(), 'dose_given' => $dose->dose_given, 'source' => 'source_provider_emar'] : null,
                'next_due_at' => $next?->due_at?->toIso8601String()];
        })->all();
        $allergies = CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => array_map(
            fn (array $entry) => array_intersect_key($entry, array_flip(['allergen', 'reaction', 'severity', 'notes'])),
            app(ClientAllergyRecordService::class)->forClient($client, $reads)
        ));
        $facts = ['person' => ['name' => $client->full_name, 'date_of_birth' => $client->date_of_birth?->toDateString(), 'nhi_number' => $client->nhi_number],
            'medications' => $medicines, 'allergies' => $allergies];

        return ['captured_at' => now()->utc()->toIso8601String(), ...$facts, 'clinical_sha256' => $this->digest($facts),
            'limitations' => ['Source-provider evidence only; receiving provider must verify identity and reconcile medication and allergy facts.',
                'Pending or unchecked medication entries are labelled and never authority to administer.', 'No care discharge or external transmission occurs when this snapshot is exported.']];
    }

    private function incoming(Client $client, array $snapshot): array
    {
        $instantRules = ['bail', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', 'date'];
        $offsetMessage = 'Provide an ISO date and time with Z or an explicit UTC offset such as +13:00.';
        $data = Validator::make($snapshot, [
            'captured_at' => ['required', ...$instantRules, 'before_or_equal:now'], 'person' => 'required|array',
            'person.name' => 'required|string|max:255', 'person.date_of_birth' => 'required|date_format:Y-m-d', 'person.nhi_number' => 'nullable|string|max:20',
            'medications' => 'present|array|list|max:100', 'medications.*.prescription' => 'required|array',
            'medications.*.prescription.name' => 'required|string|max:255', 'medications.*.prescription.controlled_drug' => 'required|boolean',
            'medications.*.last_dose' => 'nullable|array', 'medications.*.next_due_at' => ['nullable', ...$instantRules],
            'allergies' => 'present|array|list|max:100', 'allergies.*.allergen' => 'required|string|max:255',
            'allergies.*.reaction' => 'nullable|string|max:2000', 'allergies.*.severity' => 'nullable|string|max:50', 'allergies.*.notes' => 'nullable|string|max:2000',
        ], ['captured_at.regex' => $offsetMessage, 'medications.*.next_due_at.regex' => $offsetMessage])->validate();
        if ($client->date_of_birth?->toDateString() !== $data['person']['date_of_birth']
            || mb_strtolower(trim($client->full_name)) !== mb_strtolower(trim($data['person']['name']))
            || (filled($data['person']['nhi_number'] ?? null) && trim((string) $client->nhi_number) !== trim($data['person']['nhi_number']))) {
            $this->invalid('source_snapshot.person', 'The provider identity does not match this person. Resolve the match before receiving medication facts.');
        }
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 256000) {
            $this->invalid('source_snapshot', 'The incoming source exceeds the supported handover size.');
        }
        // The portable contract carries only the minimum supported clinical facts.
        $data['person'] = array_intersect_key($data['person'], array_flip(['name', 'date_of_birth', 'nhi_number']));
        $data['medications'] = array_map(function (array $row) use ($instantRules, $offsetMessage): array {
            $prescription = $this->orders->proposedPrescription($row['prescription']);
            $dose = isset($row['last_dose']) ? Validator::make($row['last_dose'], [
                'given_at' => ['required', ...$instantRules, 'before_or_equal:now'], 'dose_given' => 'required|string|max:100',
                'source' => 'required|string|max:500',
            ], ['given_at.regex' => $offsetMessage])->validate() : null;
            if ($dose !== null) {
                $dose['given_at'] = $this->utcInstant($dose['given_at']);
            }

            return ['prescription' => $prescription, 'last_dose' => $dose,
                'next_due_at' => filled($row['next_due_at'] ?? null) ? $this->utcInstant($row['next_due_at']) : null, 'verified' => false];
        }, $snapshot['medications']);
        $data['allergies'] = array_map(fn (array $row) => array_intersect_key($row, array_flip(['allergen', 'reaction', 'severity', 'notes'])), $data['allergies']);
        $data['captured_at'] = $this->utcInstant($data['captured_at']);
        $data['limitations'] = ['Unverified provider source. Every medicine and allergy requires internal review.'];
        $data['verified'] = false;

        return $data;
    }

    /** Normalize only validated offset-bearing instants; calendar dates keep their separate contract. */
    private function utcInstant(string $input): string
    {
        $instant = CarbonImmutable::parse($input)->utc();

        return $instant->format($instant->micro === 0 ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP');
    }

    private function assertFresh(User $actor, Client $client, MedicationProviderTransfer $record): void
    {
        $current = $this->snapshot($actor, $client);
        if (! hash_equals((string) ($record->snapshot['clinical_sha256'] ?? ''), $current['clinical_sha256'])) {
            $this->invalid('snapshot', 'The medication, dose or allergy facts changed. Create and review a fresh handover.');
        }
    }

    public function assertControlled(User $actor, MedicationProviderTransfer $record): void
    {
        $this->orders->assertControlled($actor, (bool) $record->controlled);
    }

    private function internal(User $actor, int $clientId, Closure $callback): mixed
    {
        return $this->scope->forClient($actor, $clientId, 'medications.transfers.manage', function (Client $client, User $locked) use ($callback) {
            $this->records->assertReadable($locked, $client);

            return $callback($client, $locked);
        });
    }

    private function requireState(MedicationProviderTransfer $record, array $allowed): void
    {
        if (! in_array($record->status, $allowed, true)) {
            $this->invalid('action', 'This handover cannot take that action at its current stage.');
        }
    }

    private function digest(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
