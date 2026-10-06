<?php

namespace App\Services\Medication\Downtime;

use App\Enums\Medication\NotGivenReason;
use App\Models\ClientMedication;
use App\Models\MedicationPaperEntry;
use App\Models\MedicationPaperStockEvidence;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\Stock\MedicationStockService;
use App\Services\MedicationRuleService;
use Carbon\Carbon;
use Closure;
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
        if ((int) $entry->given_by !== (int) $actor->id || ! $actor->canDo('medications.administer.record')) {
            return 'The actual giver must apply this entry with their own current medication recording authority.';
        }
        if (($order->controlled_drug || ($entry->snapshot['controlled'] ?? false)) && ! $actor->canDo('medications.controlled.record')) {
            return 'Current controlled-medicine recording authority is required.';
        }
        if (! $order->isAdministrable() || ! $order->active || $order->superseded_by !== null
            || PaperEntryService::orderFingerprint($order) !== ($entry->snapshot['order_fingerprint'] ?? null)
            || app(PaperEntryService::class)->requirementsFingerprint($order, false) !== ($entry->snapshot['requirements_fingerprint'] ?? null)) {
            return 'The order or its clinical requirements changed. Ask the clinical lead to review the signed paper; no current order is substituted.';
        }
        if (! in_array((int) $order->client_id, $this->scope->clientIdsWithCurrentAuthority($actor, [(int) $order->client_id], Carbon::instance($entry->given_at)), true)
            && ! ReviewedPaperAuthority::available($entry, $actor)) {
            return 'A covering assignment or usable emergency grant at the actual paper time could not be established. A separate authorized review must prove historical authority; expired access is never revived.';
        }
        if ($entry->outcome !== 'given') {
            if ($order->is_prn || ! in_array($entry->outcome, ['refused', 'withheld'], true) || blank($entry->notes)
                || $entry->downtime_dose_id === null || $entry->scheduled_for === null || $entry->witness_id !== null) {
                return 'A refused or withheld historical outcome needs the exact scheduled dose and its signed explanation, without a given-dose witness claim.';
            }
        } else {
            if (! PaperDoseFacts::complete($entry->clinical_facts ?? [], $entry->stock_evidence ?? [])) {
                return 'Historical physical stock disposition and count coverage need complete signed actual facts: '.implode('; ', PaperDoseFacts::missing($entry->clinical_facts ?? [], $entry->stock_evidence ?? [])).'. Unknown waste is never zero. The incomplete evidence is retained for clinical review.';
            }
            $currentCompetency = app(MedicationAdministratorCompetencyPolicy::class)->evaluate($actor, (int) $order->client->site_id, now());
            if (! $currentCompetency['allowed']) {
                return 'Current medication competency does not permit this paper posting. '.$currentCompetency['message'];
            }
            $settlement = MedicationPaperStockEvidence::query()->where('paper_entry_id', $entry->id)->latest('id')->first();
            if (! $settlement) {
                return 'The exact physical settlement needs an authorized stock review: deduct the retained pack quantities now, or link an explicitly covering closing count.';
            }
            $reviewer = User::find($settlement->reviewed_by);
            if (! $reviewer || ! $reviewer->isApproved() || ! app(DowntimeAccess::class)->manages($reviewer)
                || ! $reviewer->canDo('medications.stock.update') || ($order->controlled_drug && ! $reviewer->canDo('medications.controlled.record'))) {
                return 'The stock reviewer no longer has current authority. Keep the signed evidence and ask the clinical lead to review it.';
            }
            try {
                app(DowntimeAccess::class)->downtime($reviewer, (int) $entry->downtime_id);
                app(DowntimeAccess::class)->entry($reviewer, $entry->downtime, (int) $entry->id);
                // Replay rechecks the current reviewer, but must not require the
                // already-consumed pack revision to be physically deducted again.
                if (! $entry->posting()->exists()) {
                    app(MedicationStockService::class)->validateHistoricalEvidence($order,
                        [...$settlement->evidence, 'paper_entry_id' => (int) $entry->id, 'occurred_at' => $entry->given_at->toIso8601String()]);
                }
            } catch (HttpExceptionInterface) {
                return 'The stock reviewer no longer has current Site or person access. Keep the signed evidence for review.';
            } catch (ValidationException $e) {
                return implode(' ', array_merge(...array_values($e->errors())));
            }
        }

        return null;
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

    /** Server-owned facts from immutable paper; no submitted historical overrides. */
    public function canonicalData(MedicationPaperEntry $entry): array
    {
        $facts = [
            'scope_authorized' => true,
            'client_request_uuid' => $entry->request_uuid,
            'status' => $entry->outcome,
            'scheduled_for' => $entry->scheduled_for?->toIso8601String(),
            'administered_at' => $entry->given_at->toIso8601String(),
            'reason_code' => self::reasonCode($entry->outcome),
            'reason' => $entry->clinical_facts['prn_reason'] ?? $entry->notes,
            'notes' => trim((string) $entry->notes)."\nEntered from paper DT-".$entry->downtime_id
                .'; paper entry '.$entry->id.'; actual giver '.$entry->given_by.'; entered by '.$entry->entered_by
                .'; entered at '.$entry->created_at->toIso8601String(),
        ];
        if ($entry->outcome === 'given') {
            $facts += ['dose_given' => $entry->dose_on_paper, ...($entry->clinical_facts ?? []), ...$entry->observations,
                'witnessed_by' => $entry->witness_id, 'quantity_administered' => ($entry->stock_evidence['quantity_removed'] ?? null),
                'quantity_wasted' => ($entry->stock_evidence['quantity_wasted'] ?? null), 'waste_reason' => ($entry->stock_evidence['waste_reason'] ?? null)];
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
                }, authorizationUserIds: array_filter([$entry->witness_id, ReviewedPaperAuthority::reviewerId($entry), MedicationPaperStockEvidence::where('paper_entry_id', $entry->id)->latest('id')->value('reviewed_by')]),
                reviewedPaper: ReviewedPaperAuthority::available($entry, $actor) ? ReviewedPaperAuthority::fromEntry($entry) : null,
                historicalPaper: HistoricalPaperContext::fromEntry((int) $entry->id));
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

        return app(EnhancedMarService::class)->recordHistoricalPaperOutcome($entry, $decision);
    }

    public function recordBreakGlassUse(MedicationScopeDecision $decision, MedicationPaperEntry $entry): void
    {
        $this->scope->recordBreakGlassUse($decision, 'reconciled_paper_outcome', 'Paper entry '.$entry->id.'; '.$entry->outcome);
    }
}
