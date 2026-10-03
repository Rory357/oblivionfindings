<?php

namespace App\Services\Medication\Downtime;

use App\Enums\Medication\NotGivenReason;
use App\Models\ClientMedication;
use App\Models\MedicationPaperEntry;
use App\Models\User;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\MedicationRuleService;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Integration adapter for P01/P07's canonical writer, never a second stock/register writer.
 * Historical second-person attestation requires a dedicated shared writer: a saved
 * confirmation is not a reusable live PIN and must never bypass that writer.
 */
class PaperAdministrationWriter
{
    public function __construct(private readonly MedicationRuleService $rules, private readonly MedicationScopeDecisionService $scope) {}

    public function availability(MedicationPaperEntry $entry, ClientMedication $order, User $actor): ?string
    {
        if ($order->controlled_drug || ($entry->snapshot['controlled'] ?? false)) {
            return 'Controlled paper reconciliation is not configured. A witnessed historical register writer and closing-count check are required. No register balance has changed.';
        }
        if (($entry->snapshot['second_person_required'] ?? false) || $entry->witness_id !== null) {
            return 'Historical second-person reconciliation is not configured. The signed paper evidence is kept; no eMAR dose has been posted.';
        }
        if ((int) $entry->given_by !== (int) $actor->id || ! $actor->canDo('medications.administer.record')) {
            return 'The person who gave the dose must apply this entry using their own recording authority. Their paper confirmation alone does not post a dose.';
        }
        if (! $order->isAdministrable() || ! $order->active || $order->superseded_by !== null
            || PaperEntryService::orderFingerprint($order) !== ($entry->snapshot['order_fingerprint'] ?? null)) {
            return 'The order changed or is no longer recordable. Historical order reconciliation is not configured; ask the clinical lead to review the signed paper.';
        }
        $currentRules = $this->rules->requirementsFor($order, lockForUpdate: DB::transactionLevel() > 0);
        if ($entry->outcome === 'given' && ($order->requiresWitness() || $currentRules['requires_countersign'])) {
            return 'This dose now needs a second person. Historical second-person reconciliation is not configured; no eMAR dose has been posted.';
        }
        if ($entry->outcome === 'given' && trim((string) $entry->dose_on_paper) !== trim((string) $order->dosage)) {
            return 'The dose on paper differs from the order text. Use the approved exception-recording workflow with the clinical lead; no amount is assumed.';
        }
        if ($entry->outcome !== 'given') {
            return 'The paper outcome maps explicitly to '.$this->reasonCode($entry->outcome).'. The canonical historical non-given adapter is not configured; the original paper outcome and notes are kept, and no refusal cause or clinical record is inferred.';
        }
        if ($order->is_prn) {
            return 'Historical as-needed reconciliation is not configured. Temporal safety checks alone do not establish historical recording authority or physical stock disposition; the signed paper evidence is kept and no eMAR dose has been posted.';
        }
        if (! in_array((int) $order->client_id, $this->scope->clientIdsWithCurrentAuthority($actor, [(int) $order->client_id], Carbon::instance($entry->given_at)), true)) {
            return 'A covering assignment or usable emergency grant at the actual paper time could not be established. The signed paper evidence is kept; no eMAR dose has been posted.';
        }

        return 'Historical physical stock disposition and count coverage have not been established by the shared recording adapter. The signed paper evidence is kept; no eMAR dose, current stock deduction or register movement has been made.';
    }

    /** Server-owned mapping of the selected paper outcome; never infer a refusal cause. */
    public static function reasonCode(string $outcome): ?string
    {
        return match ($outcome) {
            'refused' => NotGivenReason::Refused->value,
            'withheld' => NotGivenReason::Withheld->value,
            default => null,
        };
    }

    /** Integration facts only. Posting remains held until the shared historical adapter exists. */
    public function canonicalData(MedicationPaperEntry $entry): array
    {
        $facts = [
            'scope_authorized' => true,
            'client_request_uuid' => $entry->request_uuid,
            'status' => $entry->outcome,
            'scheduled_for' => $entry->scheduled_for?->toIso8601String(),
            'administered_at' => $entry->given_at->toIso8601String(),
            'reason_code' => self::reasonCode($entry->outcome),
            'reason' => $entry->notes,
            'notes' => trim((string) $entry->notes)."\nEntered from paper DT-".$entry->downtime_id
                .'; paper entry '.$entry->id.'; actual giver '.$entry->given_by.'; entered by '.$entry->entered_by
                .'; entered at '.$entry->created_at->toIso8601String(),
        ];
        if ($entry->outcome === 'given') {
            $facts += ['dose_given' => $entry->dose_on_paper, 'amount_mode' => 'as_ordered', ...$entry->observations];
        }

        return $facts;
    }

    /** Canonical authority locks precede the caller's paper-entry lock and final P09 append. */
    public function withinAuthority(MedicationPaperEntry $entry, ClientMedication $order, User $actor, Closure $callback): array
    {
        if ($reason = $this->availability($entry, $order, $actor)) {
            return ['success' => false, 'error' => $reason];
        }

        $inside = false;
        try {
            return $this->scope->forAdministration($actor, $order->client, $order,
                Carbon::instance($entry->given_at), $entry->scheduled_for ? Carbon::instance($entry->scheduled_for) : null,
                null, null, function (MedicationScopeDecision $decision) use (&$inside, $callback): array {
                    $inside = true;

                    return $callback($decision);
                });
        } catch (HttpExceptionInterface|ValidationException $e) {
            // Only authority resolution failures become a safe hold. Writer,
            // domain and event failures must roll the complete transaction back.
            if ($inside) {
                throw $e;
            }

            return ['success' => false, 'error' => 'The canonical recording checks could not establish current authority for this paper time and scheduled dose. The signed paper evidence is kept; no eMAR dose has been posted.'];
        }
    }

    public function postAuthorized(MedicationPaperEntry $entry, MedicationScopeDecision $decision): array
    {
        $order = $decision->medication;
        if (! $order || (int) $order->id !== (int) $entry->client_medication_id
            || (int) $decision->performer->id !== (int) $entry->given_by
            || $decision->lockedPresenceShifts === null || $decision->lockedPresenceEffectiveAt === null) {
            throw new \LogicException('Paper posting requires the canonical locked administration decision.');
        }
        if ($reason = $this->availability($entry, $order, $decision->performer)) {
            return ['success' => false, 'error' => $reason];
        }

        // P01/P06 must wire their canonical historical recorder here after
        // proving physical disposition/count coverage (or evidenced non-given
        // reasons). Today's ordinary FEFO writer is not a historical adapter.
        return ['success' => false, 'error' => 'The canonical historical paper recording adapter is not configured. Signed paper evidence is kept; no clinical or stock record has changed.'];
    }
}
