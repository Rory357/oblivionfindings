<?php

namespace App\Services\Medication\Downtime;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationPaperEntry;
use App\Models\MedicationPaperStockEvidence;
use App\Models\User;
use App\Services\Medication\ControlledMedicationTransportWitnessService;
use App\Services\Medication\MedicationSecondPersonService;
use App\Services\Medication\WitnessPinService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Server-only bridge from retained signatures to the shared clinical writer. */
final class HistoricalPaperContext
{
    private function __construct(public readonly MedicationPaperEntry $entry) {}

    public static function fromEntry(int $id): self
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Historical paper posting requires its governing transaction.');
        }

        // The canonical authority prefix locks mutable evidence before paper rows.
        // This immutable read must not acquire a paper lock ahead of that prefix.
        return new self(MedicationPaperEntry::query()->whereKey($id)->firstOrFail());
    }

    public function assertBinding(Client $client, ClientMedication $order, int $giverId, array $data): void
    {
        abort_unless($this->entry->outcome !== 'given' || PaperDoseFacts::complete($this->entry->clinical_facts ?? [], $this->entry->stock_evidence ?? []), 422);
        abort_unless((int) $this->entry->client_id === (int) $client->id
            && (int) $this->entry->client_medication_id === (int) $order->id
            && (int) $order->client_id === (int) $client->id
            && (int) $this->entry->given_by === $giverId
            && ($data['client_request_uuid'] ?? null) === $this->entry->request_uuid
            && ($data['status'] ?? null) === $this->entry->outcome
            && Carbon::parse($data['administered_at'])->equalTo($this->entry->given_at)
            && PaperEntryService::orderFingerprint($order) === ($this->entry->snapshot['order_fingerprint'] ?? null), 409);
        abort_unless(PaperReconciliationRules::fingerprint($data) === PaperReconciliationRules::fingerprint(app(PaperAdministrationWriter::class)->canonicalData($this->entry)), 409);
        abort_unless($this->entry->confirmations()->where('kind', 'giver')->where('confirmed_by', $giverId)
            ->whereNotNull('confirmed_at')->exists(), 422);
    }

    /** Pack/count binding is always loaded from retained evidence, never HTTP flags. */
    public function stockEvidence(): array
    {
        $row = MedicationPaperStockEvidence::query()->where('paper_entry_id', $this->entry->id)->latest('id')->lockForUpdate()->firstOrFail();
        abort_unless(hash_equals($row->fingerprint, PaperReconciliationRules::fingerprint($row->evidence)), 409);
        $reviewer = app(DowntimeAccess::class)->lockActor(User::findOrFail($row->reviewed_by), (int) $this->entry->downtime->site_id);
        abort_unless(app(DowntimeAccess::class)->manages($reviewer) && $reviewer->canDo('medications.stock.update')
            && (! ($this->entry->snapshot['controlled'] ?? false) || $reviewer->canDo('medications.controlled.record')), 404);
        app(DowntimeAccess::class)->entry($reviewer, $this->entry->downtime, (int) $this->entry->id);

        return [...$row->evidence, 'settlement_id' => (int) $row->id, 'settlement_fingerprint' => $row->fingerprint,
            'paper_entry_id' => (int) $this->entry->id, 'occurred_at' => $this->entry->given_at->toIso8601String()];
    }

    /** A retained own-PIN signature is not reused as a live credential. */
    public function witnessValidation(User $giver, int $siteId, ?string $kind, Collection $users, Collection $shifts, CarbonInterface $at): array
    {
        $witnessId = $this->entry->witness_id;
        if ($witnessId === null) {
            return $kind === null ? ['success' => true] : ['success' => false, 'error_field' => 'witnessed_by',
                'error' => 'The signed paper needs an eligible second person and their own confirmation.'];
        }
        abort_unless($this->entry->confirmations()->where('kind', 'witness')->where('confirmed_by', $witnessId)
            ->where('method', WitnessPinService::METHOD)->whereNotNull('confirmed_at')->lockForUpdate()->exists(), 422);
        $controlled = (bool) ($this->entry->snapshot['controlled'] ?? false);
        $evidence = $controlled
            ? app(ControlledMedicationTransportWitnessService::class)->attestEligibility($giver, $siteId, (int) $witnessId, $at,
                lockedUsers: $users, lockedPresenceShifts: $shifts, historical: true)
            : app(MedicationSecondPersonService::class)->attestEligibility($giver, $siteId, (int) $witnessId, $at, $users, $shifts, historical: true);

        return ['success' => true, 'witnessed_by' => (int) $evidence['witness']->id,
            'witnessed_at' => Carbon::instance($at)->copy(), 'witness_method' => 'historical_paper_own_pin'];
    }
}
