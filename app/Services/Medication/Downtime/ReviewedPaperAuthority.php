<?php

namespace App\Services\Medication\Downtime;

use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedication;
use App\Models\MedicationPaperEntry;
use App\Models\MedicationPaperRecoveryAuthorization;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** A dedicated paper authority; never an emergency grant or a live transport flag. */
final class ReviewedPaperAuthority
{
    private function __construct(private readonly MedicationPaperEntry $entry) {}

    public static function fromEntry(MedicationPaperEntry $entry): self
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Reviewed paper authority requires its governing transaction.');
        }

        return new self($entry);
    }

    public static function reviewerId(MedicationPaperEntry $entry): ?int
    {
        return MedicationPaperRecoveryAuthorization::where('paper_entry_id', $entry->id)->value('reviewed_by');
    }

    public static function available(MedicationPaperEntry $entry, User $giver): bool
    {
        if ((int) $entry->given_by !== (int) $giver->id) {
            return false;
        }
        $proof = MedicationPaperRecoveryAuthorization::where('paper_entry_id', $entry->id)->first();
        if (! $proof) {
            return false;
        }
        try {
            $reviewer = User::find($proof->reviewed_by);
            if (! $reviewer || ! app(DowntimeAccess::class)->manages($reviewer) || ! $reviewer->canDo('medications.administer.record')
                || (int) $reviewer->id === (int) $entry->given_by || ! $reviewer->isApproved()) {
                return false;
            }
            app(DowntimeAccess::class)->downtime($reviewer, (int) $entry->downtime_id);
            if (($entry->snapshot['controlled'] ?? false) && ! $reviewer->canDo('medications.controlled.record')) {
                return false;
            }
            app(DowntimeAccess::class)->entry($reviewer, $entry->downtime, (int) $entry->id);

            return self::proofMatches($proof, $entry) && self::historicalGrant($entry, (int) $proof->evidence['grant_id']) !== null;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    public static function historicalGrant(MedicationPaperEntry $entry, ?int $id = null, bool $lock = false): ?ClientBreakGlassAccess
    {
        $at = $entry->given_at->utc();
        $grant = ClientBreakGlassAccess::withTrashed()->where('client_id', $entry->client_id)->where('user_id', $entry->given_by)
            ->when($id !== null, fn ($q) => $q->whereKey($id))->where('created_at', '<=', $at)->where('expires_at', '>', $at)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>', $at))
            ->when($lock, fn ($q) => $q->lockForUpdate())->latest('created_at')->first();
        if (! $grant || ! in_array($grant->authorization_mode, ['self', 'co_sign'], true)
            || ! $grant->acknowledged_min_necessary || ! $grant->acknowledged_incident_report
            || ($grant->effectivePolicy()['reason_required'] && blank($grant->reason))
            || ($grant->authorization_mode === 'co_sign' && (! $grant->co_signed_by || (int) $grant->co_signed_by === (int) $entry->given_by))) {
            return null;
        }

        // Use the grant's retained policy, just as the canonical scope does.
        // A later application-policy change cannot rewrite historical authority.
        $duration = $grant->created_at->diffInMinutes($grant->expires_at, false);
        if ($duration < 5 || $duration > (int) $grant->effectivePolicy()['max_minutes']) {
            return null;
        }

        return $grant;
    }

    public static function entryHash(MedicationPaperEntry $entry): string
    {
        return PaperReconciliationRules::fingerprint($entry->only(['id', 'client_id', 'client_medication_id', 'given_by', 'witness_id', 'given_at', 'scheduled_for', 'request_uuid', 'request_fingerprint', 'snapshot', 'clinical_facts', 'stock_evidence', 'outcome', 'dose_identity']));
    }

    private static function proofMatches(MedicationPaperRecoveryAuthorization $proof, MedicationPaperEntry $entry): bool
    {
        return hash_equals($proof->fingerprint, PaperReconciliationRules::fingerprint($proof->evidence))
            && hash_equals($proof->evidence['entry_hash'] ?? '', self::entryHash($entry))
            && $entry->confirmations()->where('kind', 'giver')->where('confirmed_by', $entry->given_by)->whereNotNull('confirmed_at')->exists();
    }

    /** Called only inside the canonical Client/Order/Shift/Rule/User/Site lock prefix. */
    public function assertCurrent(Client $client, ClientMedication $order, User $giver, CarbonInterface $at, Collection $users): void
    {
        $entry = MedicationPaperEntry::whereKey($this->entry->id)->lockForUpdate()->firstOrFail();
        $proof = MedicationPaperRecoveryAuthorization::where('paper_entry_id', $entry->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $entry->client_id === (int) $client->id && (int) $entry->client_medication_id === (int) $order->id
            && (int) $entry->given_by === (int) $giver->id && $entry->given_at->equalTo($at) && self::proofMatches($proof, $entry), 409);
        $reviewer = $users->get((int) $proof->reviewed_by);
        abort_unless($reviewer && $reviewer->isApproved() && app(DowntimeAccess::class)->manages($reviewer)
            && $reviewer->canDo('medications.administer.record') && (int) $reviewer->id !== (int) $giver->id
            && (! $order->controlled_drug || $reviewer->canDo('medications.controlled.record')), 404);
        $reviewer = app(DowntimeAccess::class)->lockActor($reviewer, (int) $client->site_id);
        app(DowntimeAccess::class)->entry($reviewer, $entry->downtime, (int) $entry->id);
        abort_unless(self::historicalGrant($entry, (int) $proof->evidence['grant_id'], true), 422,
            'The retained server grant does not prove authority at the actual paper time.');
    }
}
