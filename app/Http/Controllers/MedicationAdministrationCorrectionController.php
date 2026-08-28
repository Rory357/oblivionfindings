<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRefusalFollowup;
use App\Models\MedicationRound;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\UserSiteAccessService;
use App\Support\EmarUrl;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MedicationAdministrationCorrectionController extends Controller
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $governanceScope,
    ) {}

    public function approve(Request $request, ClientMedicationAdministration $correction)
    {
        $user = $this->correctionActor($request);

        return $this->withCanonicalCorrection($user, $correction, function (
            ClientMedicationAdministration $lockedCorrection,
            ClientMedicationAdministration $original,
            ?ClientMedication $medication,
            ClientMedicationAdministration $effectiveAdministration,
            Collection $corrections,
            ?MedicationRound $round,
        ) use ($user) {
            $this->assertPendingCorrection($lockedCorrection);
            $this->assertControlledStockNeutralCorrection(
                $medication,
                (string) $effectiveAdministration->status,
                (string) $lockedCorrection->status,
            );
            $this->assertControlledClinicalProvenance($medication, $lockedCorrection);
            $cancelsRefusalFollowups = in_array($effectiveAdministration->status, ['refused', 'withheld'], true)
                && ! in_array($lockedCorrection->status, ['refused', 'withheld'], true);

            // Two-person rule: the person who raised the correction cannot approve
            // their own — approval must be an independent check.
            $requesterId = $lockedCorrection->correction_requested_by
                ?? $lockedCorrection->administered_by;
            if ((int) $requesterId === (int) $user->id) {
                return back()->with('error', 'A correction must be approved by someone other than the person who raised it.');
            }

            $approvedAt = now();
            $siblings = $corrections->filter(fn (ClientMedicationAdministration $candidate): bool => ! $candidate->is($lockedCorrection)
                && in_array($candidate->correction_status, ['pending', 'approved'], true));

            foreach ($siblings as $sibling) {
                $sibling->update([
                    'correction_status' => 'rejected',
                    'correction_approved_by' => $user->id,
                    'correction_approved_at' => $approvedAt,
                    'correction_rejection_reason' => 'Superseded by the approved correction for this administration.',
                ]);
                app(MedicationIncidentIntegrationService::class)->resolveUnsafeCorrection(
                    $sibling,
                    'Unsafe medication correction superseded by the approved correction.',
                    $user->id,
                );
            }

            $approval = [
                'correction_status' => 'approved',
                'correction_approved_by' => $user->id,
                'correction_approved_at' => $approvedAt,
            ];
            if ($round !== null && $lockedCorrection->medication_round_id === null) {
                $approval['medication_round_id'] = $round->id;
            }
            $lockedCorrection->update($approval);

            $round?->updateCounts();

            if ($cancelsRefusalFollowups) {
                $this->cancelRefusalFollowups(
                    $original,
                    $corrections,
                    $lockedCorrection,
                    $user,
                );
            }

            app(MedicationIncidentIntegrationService::class)->resolveUnsafeCorrection(
                $lockedCorrection,
                'Unsafe medication correction approved.',
                $user->id
            );

            return back()->with('success', 'Correction approved.');
        });
    }

    public function reject(Request $request, ClientMedicationAdministration $correction)
    {
        $user = $this->correctionActor($request);

        return $this->withCanonicalCorrection($user, $correction, function (ClientMedicationAdministration $lockedCorrection) use ($request, $user) {
            $this->assertPendingCorrection($lockedCorrection);
            $validated = $request->validate(['reason' => 'required|string|max:1000']);

            $lockedCorrection->update([
                'correction_status' => 'rejected',
                'correction_approved_by' => $user->id,
                'correction_approved_at' => now(),
                'correction_rejection_reason' => $validated['reason'],
            ]);

            app(MedicationIncidentIntegrationService::class)->resolveUnsafeCorrection(
                $lockedCorrection,
                'Unsafe medication correction rejected.',
                $user->id
            );

            return back()->with('success', 'Correction rejected.');
        });
    }

    public function store(Request $request, Client $client, ClientMedicationAdministration $administration)
    {
        $user = $this->correctionActor($request);

        // Resolve the nested owner before applying Site authority so a forged
        // client/administration pair is indistinguishable from a missing row.
        abort_unless((int) $administration->client_id === (int) $client->id, 404);

        return $this->governanceScope->forClient(
            $user,
            (int) $client->id,
            'medications.administer.correct',
            function (Client $canonicalClient) use ($request, $user, $administration) {
                $administrationSnapshot = $this->administrationSnapshot($canonicalClient, $administration);
                $lockedMedication = $this->lockMedicationForAdministration($canonicalClient, $administrationSnapshot);
                $this->assertControlledMutationAuthority($user, $lockedMedication);
                [$rootAdministration, $corrections, $submittedAdministration, $effectiveAdministration] =
                    $this->lockAdministrationCluster($canonicalClient, $administrationSnapshot);
                $this->assertAdministrationOwnership($rootAdministration, $canonicalClient, $lockedMedication);
                $this->assertAdministrationOwnership($effectiveAdministration, $canonicalClient, $lockedMedication);
                abort_unless(
                    $submittedAdministration->is($rootAdministration)
                    || $submittedAdministration->is($effectiveAdministration),
                    404,
                );

                $pendingSibling = $corrections
                    ->first(fn (ClientMedicationAdministration $candidate): bool => $candidate->correction_status === 'pending');
                if ($pendingSibling !== null) {
                    return back()->with('error', 'A correction for this administration is already awaiting approval.');
                }

                // Guardrail: allow quick edits within 30 minutes, otherwise require a correction reason.
                $data = $request->validate([
                    'status' => ['required', 'in:given,refused,missed,withheld'],
                    'reason' => ['nullable', 'string', 'max:255'],
                    'dose_given' => ['nullable', 'string', 'max:255'],
                    'administered_at' => ['nullable', 'date'],
                    'notes' => ['nullable', 'string'],
                    'correction_reason' => ['nullable', 'string', 'max:255'],
                ]);
                $this->assertControlledStockNeutralCorrection(
                    $lockedMedication,
                    (string) $effectiveAdministration->status,
                    (string) $data['status'],
                );
                $this->assertCorrectionClinicalIntegrity($lockedMedication, $effectiveAdministration, $data);
                $this->assertControlledClinicalProvenance($lockedMedication, $effectiveAdministration);

                $windowAnchor = $rootAdministration->administered_at
                    ?? $rootAdministration->updated_at
                    ?? $rootAdministration->created_at;
                $minutesSince = $windowAnchor ? $windowAnchor->diffInMinutes(now()) : 999999;
                if ($minutesSince > 30 && empty($data['correction_reason'])) {
                    return back()->withInput()->with('error', 'Please provide a correction reason (outside the 30-minute edit window).');
                }

                $correction = $effectiveAdministration->replicate([
                    'id',
                    'client_request_uuid',
                    'correction_requested_by',
                    'correction_status',
                    'correction_approved_by',
                    'correction_approved_at',
                    'correction_rejection_reason',
                    'deleted_at',
                    'created_at',
                    'updated_at',
                ]);
                $correction->is_correction = true;
                $correction->corrected_of_id = $rootAdministration->id;
                $correction->correction_reason = $data['correction_reason'] ?? null;
                $correction->status = $data['status'];
                $correction->reason = array_key_exists('reason', $data)
                    ? $data['reason']
                    : $effectiveAdministration->reason;
                $correction->dose_given = array_key_exists('dose_given', $data)
                    ? $data['dose_given']
                    : $effectiveAdministration->dose_given;
                $correction->administered_at = $data['administered_at'] ?? $effectiveAdministration->administered_at ?? now();
                $correction->notes = array_key_exists('notes', $data)
                    ? $data['notes']
                    : $effectiveAdministration->notes;
                if ($correction->status !== 'given') {
                    foreach (ClientMedicationAdministration::ADMINISTRATION_ONLY_EVIDENCE_FIELDS as $field) {
                        $correction->{$field} = null;
                    }
                }
                $correction->correction_requested_by = $user->id;
                $correction->correction_status = 'pending';
                $correction->save();

                app(MedicationIncidentIntegrationService::class)->handleUnsafeCorrection(
                    $rootAdministration,
                    $data,
                    $user->id,
                    $correction
                );

                $notification = new AppEventNotification([
                    'kind' => 'crud',
                    'action' => 'created',
                    'entity' => 'medication correction (pending approval)',
                    'entity_id' => $correction->id,
                    'client_id' => $canonicalClient->id,
                    'event_key' => 'medication_correction_pending_approval.created',
                    'title' => 'Medication correction pending approval',
                    'url' => url(EmarUrl::mar($canonicalClient)),
                    'actor' => ['id' => $user->id, 'name' => $user->name],
                ]);
                $this->correctionRecipients(
                    $user,
                    $canonicalClient,
                    (bool) $lockedMedication?->controlled_drug,
                )->each(fn (User $recipient) => $recipient->notify($notification));

                return back()->with('success', 'Correction submitted for approval.');
            },
        );
    }

    private function correctionActor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.correct'), 403);

        return $user;
    }

    private function withCanonicalCorrection(
        User $user,
        ClientMedicationAdministration $submittedCorrection,
        Closure $callback,
    ): mixed {
        $clientId = (int) $submittedCorrection->client_id;
        abort_unless($clientId > 0, 404);

        return $this->governanceScope->forClient(
            $user,
            $clientId,
            'medications.administer.correct',
            function (Client $client) use ($submittedCorrection, $callback, $user) {
                $administrationSnapshot = $this->administrationSnapshot($client, $submittedCorrection);
                $lockedMedication = $this->lockMedicationForAdministration($client, $administrationSnapshot);
                $this->assertControlledMutationAuthority($user, $lockedMedication);
                [$original, $corrections, $correction, $effectiveAdministration, $round] =
                    $this->lockAdministrationCluster($client, $administrationSnapshot);
                abort_unless(! $correction->is($original), 404);
                $this->assertAdministrationOwnership($correction, $client, $lockedMedication);

                return $callback(
                    $correction,
                    $original,
                    $lockedMedication,
                    $effectiveAdministration,
                    $corrections,
                    $round,
                );
            },
        );
    }

    private function administrationSnapshot(
        Client $client,
        ClientMedicationAdministration $submittedAdministration,
    ): ClientMedicationAdministration {
        $snapshot = ClientMedicationAdministration::query()
            ->whereKey($submittedAdministration->getKey())
            ->where('client_id', $client->id)
            ->first([
                'id',
                'client_id',
                'client_medication_id',
                'is_correction',
                'corrected_of_id',
            ]);
        abort_unless($snapshot !== null, 404);

        return $snapshot;
    }

    /**
     * Lock the immutable original before every child, then resolve the single
     * deterministic approved winner that currently represents the event.
     *
     * @return array{0: ClientMedicationAdministration, 1: Collection<int, ClientMedicationAdministration>, 2: ClientMedicationAdministration, 3: ClientMedicationAdministration, 4: MedicationRound|null}
     */
    private function lockAdministrationCluster(
        Client $client,
        ClientMedicationAdministration $snapshot,
    ): array {
        $rootId = $snapshot->is_correction
            ? (int) $snapshot->corrected_of_id
            : (int) $snapshot->id;
        abort_unless($rootId > 0, 404);

        // Discover only the candidate identity under the already-held Client
        // and medication mutex. The round itself is locked before any row in
        // the administration cluster, then membership is revalidated below.
        $round = $this->lockCanonicalAdministrationRound($client, $snapshot, $rootId);

        $rootQuery = ClientMedicationAdministration::query()
            ->whereKey($rootId)
            ->where('client_id', $client->id)
            ->where(function ($query): void {
                $query->where('is_correction', false)
                    ->orWhereNull('is_correction');
            });
        $snapshot->client_medication_id === null
            ? $rootQuery->whereNull('client_medication_id')
            : $rootQuery->where('client_medication_id', $snapshot->client_medication_id);
        $root = $rootQuery->lockForUpdate()->first();
        abort_unless($root !== null, 404);

        $correctionQuery = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $root->id)
            ->where('client_id', $client->id)
            ->where('is_correction', true);
        $root->client_medication_id === null
            ? $correctionQuery->whereNull('client_medication_id')
            : $correctionQuery->where('client_medication_id', $root->client_medication_id);
        $corrections = $correctionQuery
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->assertCanonicalRoundMembership($round, $root, $corrections);

        $submitted = $snapshot->is($root)
            ? $root
            : $corrections->first(fn (ClientMedicationAdministration $candidate): bool => $candidate->is($snapshot));
        abort_unless($submitted instanceof ClientMedicationAdministration, 404);

        $approvedWinner = $corrections
            ->filter(fn (ClientMedicationAdministration $candidate): bool => $candidate->correction_status === 'approved')
            ->sort(function (ClientMedicationAdministration $left, ClientMedicationAdministration $right): int {
                $leftApprovedAt = $left->correction_approved_at?->getTimestamp() ?? PHP_INT_MIN;
                $rightApprovedAt = $right->correction_approved_at?->getTimestamp() ?? PHP_INT_MIN;

                return $leftApprovedAt === $rightApprovedAt
                    ? (int) $right->id <=> (int) $left->id
                    : $rightApprovedAt <=> $leftApprovedAt;
            })
            ->first();

        return [$root, $corrections, $submitted, $approvedWinner ?? $root, $round];
    }

    private function lockCanonicalAdministrationRound(
        Client $client,
        ClientMedicationAdministration $snapshot,
        int $rootId,
    ): ?MedicationRound {
        $roundIdsQuery = ClientMedicationAdministration::query()
            ->where('client_id', $client->id)
            ->where(function ($cluster) use ($rootId): void {
                $cluster->where('id', $rootId)
                    ->orWhere('corrected_of_id', $rootId);
            });
        $snapshot->client_medication_id === null
            ? $roundIdsQuery->whereNull('client_medication_id')
            : $roundIdsQuery->where('client_medication_id', $snapshot->client_medication_id);

        $roundIds = $roundIdsQuery
            ->whereNotNull('medication_round_id')
            ->pluck('medication_round_id')
            ->map(fn ($roundId): int => (int) $roundId)
            ->unique()
            ->values();
        if ($roundIds->isEmpty()) {
            return null;
        }
        if ($roundIds->count() !== 1 || (int) $roundIds->first() <= 0) {
            $this->throwMedicationRoundConflict();
        }

        $roundQuery = MedicationRound::query()
            ->whereKey((int) $roundIds->first())
            ->where('site_id', $client->site_id)
            ->where(function ($context) use ($client): void {
                $context->whereNull('service_context_id');
                if ($client->service_context_id !== null) {
                    $context->orWhere('service_context_id', $client->service_context_id);
                }
            });
        $round = $roundQuery->lockForUpdate()->first();
        if ($round === null) {
            $this->throwMedicationRoundConflict();
        }

        return $round;
    }

    /** @param Collection<int, ClientMedicationAdministration> $corrections */
    private function assertCanonicalRoundMembership(
        ?MedicationRound $round,
        ClientMedicationAdministration $root,
        Collection $corrections,
    ): void {
        $roundIds = collect([$root])
            ->concat($corrections)
            ->pluck('medication_round_id')
            ->filter(fn ($roundId): bool => $roundId !== null)
            ->map(fn ($roundId): int => (int) $roundId)
            ->unique()
            ->values();

        if (($round === null && $roundIds->isNotEmpty())
            || ($round !== null
                && ($roundIds->count() !== 1 || (int) $roundIds->first() !== (int) $round->id))) {
            $this->throwMedicationRoundConflict();
        }
    }

    private function throwMedicationRoundConflict(): never
    {
        throw ValidationException::withMessages([
            'medication_round_id' => 'The medication administration correction has conflicting round evidence and cannot be changed.',
        ])->status(409);
    }

    private function assertPendingCorrection(ClientMedicationAdministration $correction): void
    {
        abort_unless($correction->is_correction && $correction->correction_status === 'pending', 404);
        abort_unless($correction->corrected_of_id !== null, 404);
    }

    /** @param Collection<int, ClientMedicationAdministration> $corrections */
    private function cancelRefusalFollowups(
        ClientMedicationAdministration $original,
        Collection $corrections,
        ClientMedicationAdministration $approvedCorrection,
        User $approvedBy,
    ): void {
        $clusterIds = $corrections
            ->pluck('id')
            ->push($original->id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $followups = MedicationRefusalFollowup::query()
            ->where('client_id', $original->client_id)
            ->whereIn('client_medication_administration_id', $clusterIds)
            ->whereNull('follow_up_completed_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($followups->isEmpty()) {
            return;
        }

        $completedAt = now();
        $outcome = sprintf(
            'Cancelled automatically: approved correction #%d changed the effective administration outcome to %s.',
            $approvedCorrection->id,
            str_replace('_', ' ', (string) $approvedCorrection->status),
        );
        $integration = app(MedicationIncidentIntegrationService::class);

        foreach ($followups as $followup) {
            $followup->update([
                'follow_up_completed_at' => $completedAt,
                'follow_up_completed_by' => $approvedBy->id,
                'follow_up_outcome' => $outcome,
            ]);
            $integration->resolveRefusalEscalation(
                $followup,
                'Medication refusal follow-up cancelled after an approved correction changed the effective outcome.',
                $approvedBy->id,
            );
        }
    }

    private function assertAdministrationOwnership(
        ClientMedicationAdministration $administration,
        Client $client,
        ?ClientMedication $lockedMedication,
    ): void {
        abort_unless((int) $administration->client_id === (int) $client->id, 404);

        if ($administration->client_medication_id !== null) {
            abort_unless(
                $lockedMedication !== null
                && (int) $lockedMedication->id === (int) $administration->client_medication_id,
                404,
            );
        } else {
            abort_unless($lockedMedication === null, 404);
        }
    }

    private function lockMedicationForAdministration(
        Client $client,
        ClientMedicationAdministration $administration,
    ): ?ClientMedication {
        if ($administration->client_medication_id === null) {
            return null;
        }

        $medication = ClientMedication::withTrashed()
            ->whereKey($administration->client_medication_id)
            ->where('client_id', $client->id)
            ->lockForUpdate()
            ->first();
        abort_unless($medication !== null, 404);

        return $medication;
    }

    private function assertControlledMutationAuthority(User $user, ?ClientMedication $medication): void
    {
        abort_unless(
            $medication === null
            || ! (bool) $medication->controlled_drug
            || $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
            404,
        );
    }

    private function assertControlledStockNeutralCorrection(
        ?ClientMedication $medication,
        string $originalStatus,
        string $correctedStatus,
    ): void {
        if (! $medication?->controlled_drug
            || ($originalStatus === 'given') === ($correctedStatus === 'given')) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => 'A controlled-drug correction cannot change whether stock was administered. Use the witnessed controlled-drug reconciliation workflow.',
        ]);
    }

    private function assertControlledClinicalProvenance(
        ?ClientMedication $medication,
        ClientMedicationAdministration $administration,
    ): void {
        if (! $medication?->controlled_drug
            || $administration->witnessed_by === null
            || (int) $administration->administered_by !== (int) $administration->witnessed_by) {
            return;
        }

        throw ValidationException::withMessages([
            'administration' => 'Controlled medication evidence must retain an administering worker distinct from its witness.',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function assertCorrectionClinicalIntegrity(
        ?ClientMedication $medication,
        ClientMedicationAdministration $effectiveAdministration,
        array $data,
    ): void {
        $currentStatus = (string) $effectiveAdministration->status;
        $correctedStatus = (string) $data['status'];

        if ($currentStatus !== 'given' && $correctedStatus === 'given') {
            throw ValidationException::withMessages([
                'status' => 'A correction cannot create a given administration without the governed administration workflow and its required clinical evidence.',
            ]);
        }
        if (! $medication?->controlled_drug || $currentStatus !== 'given' || $correctedStatus !== 'given') {
            return;
        }

        if (array_key_exists('dose_given', $data)) {
            $submittedDose = $data['dose_given'] === null ? null : trim((string) $data['dose_given']);
            $recordedDose = $effectiveAdministration->dose_given === null
                ? null
                : trim((string) $effectiveAdministration->dose_given);
            if ($submittedDose !== $recordedDose) {
                throw ValidationException::withMessages([
                    'dose_given' => 'A controlled medication correction cannot change the recorded dose without witnessed register reconciliation.',
                ]);
            }
        }

        if (array_key_exists('administered_at', $data) && $data['administered_at'] !== null) {
            $recordedAt = $effectiveAdministration->administered_at;
            if ($recordedAt === null || ! Carbon::parse($data['administered_at'])->utc()->equalTo($recordedAt->copy()->utc())) {
                throw ValidationException::withMessages([
                    'administered_at' => 'A controlled medication correction cannot change the administration time without witnessed register reconciliation.',
                ]);
            }
        }
    }

    /** @return Collection<int, User> */
    private function correctionRecipients(User $actor, Client $client, bool $controlled)
    {
        $siteId = is_numeric($client->site_id) ? (int) $client->site_id : 0;
        if ($siteId <= 0) {
            return collect();
        }

        $siteAccess = app(UserSiteAccessService::class);

        return User::query()
            ->where('id', '!=', $actor->id)
            ->whereNotNull('approved_at')
            ->get()
            ->filter(function (User $recipient) use ($controlled, $siteAccess, $siteId): bool {
                if (! $recipient->canDo('medications.administer.correct')) {
                    return false;
                }
                if ($controlled
                    && (! $recipient->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                        || ! $recipient->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY))) {
                    return false;
                }

                return in_array(
                    $siteId,
                    $siteAccess->accessibleSiteIds(
                        $recipient,
                        MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
                    ),
                    true,
                );
            })
            ->values();
    }
}
