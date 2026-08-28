<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRefusalFollowup;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\MedicationIncidentIntegrationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RefusalFollowUpController extends Controller
{
    public function __construct(
        private readonly MedicationScopeDecisionService $medicationScope,
        private readonly MedicationGovernanceScopeService $governanceScope,
    ) {}

    /**
     * Store a new refusal/withholding follow-up record.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.record'), 403);

        $validated = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'client_medication_administration_id' => ['required', 'integer', 'min:1'],
            'reason_category' => ['required', 'in:personal_choice,side_effects,difficulty_swallowing,nausea,pain,cognitive,behavioural,sleeping,other'],
            'detailed_reason' => ['nullable', 'string', 'max:2000'],
            'client_capacity_at_time' => ['required', 'in:has_capacity,lacks_capacity,fluctuating,not_assessed'],
            'offered_alternative' => ['boolean'],
            'alternative_details' => ['nullable', 'string', 'max:1000'],
            'gp_notification_required' => ['boolean'],
            'family_notified' => ['boolean'],
            'follow_up_action' => ['nullable', 'string', 'max:2000'],
            'follow_up_due_at' => ['nullable', 'date'],
        ]);

        // Derive the authority target from the administration first; a forged
        // submitted client id must never redirect scope to another resident.
        $administrationSnapshot = ClientMedicationAdministration::query()
            ->whereKey($validated['client_medication_administration_id'])
            ->first([
                'id',
                'client_id',
                'client_medication_id',
                'is_correction',
                'corrected_of_id',
            ]);
        abort_unless(
            $administrationSnapshot !== null
            && (int) $administrationSnapshot->client_id === (int) $validated['client_id'],
            404,
        );

        return $this->withAssignedClient(
            $user,
            (int) $administrationSnapshot->client_id,
            function (MedicationScopeDecision $scope) use ($administrationSnapshot, $validated, $user) {
                $lockedMedication = $this->lockMedicationForAdministration($scope->client, $administrationSnapshot);
                $this->assertControlledMutationAuthority($user, $lockedMedication);
                [$rootAdministration, , $submittedAdministration, $effectiveAdministration] =
                    $this->lockAdministrationCluster($scope->client, $administrationSnapshot);
                abort_unless(
                    $submittedAdministration->is($rootAdministration)
                    || $submittedAdministration->is($effectiveAdministration),
                    404,
                );
                $this->assertAdministrationOwnership($effectiveAdministration, $scope->client, $lockedMedication);
                abort_unless(in_array($effectiveAdministration->status, ['refused', 'withheld'], true), 404);
                abort_unless(
                    ! $effectiveAdministration->is_correction
                    || $effectiveAdministration->correction_status === 'approved',
                    404,
                );

                $attributes = $validated;
                $attributes['client_id'] = $scope->client->id;
                $attributes['client_medication_administration_id'] = $rootAdministration->id;
                $attributes['created_by'] = $user->id;

                // If family was notified, record the timestamp.
                if (! empty($attributes['family_notified'])) {
                    $attributes['family_notified_at'] = now();
                }

                // Check for refusal cluster: 3+ refusals in 7 days for the same medication.
                $recentRefusals = ClientMedicationAdministration::query()
                    ->effectiveClinicalEvidence()
                    ->where('client_id', $scope->client->id)
                    ->where('client_medication_id', $effectiveAdministration->client_medication_id)
                    ->whereIn('status', ['refused', 'withheld'])
                    ->where('administered_at', '>=', now()->subDays(7))
                    ->count();

                if ($recentRefusals >= 3) {
                    $attributes['escalated_to_manager'] = true;
                    $attributes['escalated_at'] = now();
                    $attributes['gp_notification_required'] = true;

                    if (($lockedMedication->controlled_drug || $lockedMedication->high_risk)
                        && blank($attributes['follow_up_action'] ?? null)
                    ) {
                        throw ValidationException::withMessages([
                            'follow_up_action' => 'Record the immediate follow-up action taken before escalating a high-risk refusal pattern.',
                        ]);
                    }
                }

                $followup = MedicationRefusalFollowup::create($attributes);

                if (! empty($attributes['escalated_to_manager'])) {
                    app(MedicationIncidentIntegrationService::class)
                        ->handleRefusalEscalation($followup, $recentRefusals);
                }

                return redirect()->back()->with('success', 'Refusal follow-up recorded successfully.');
            },
        );
    }

    /**
     * Mark a follow-up as completed.
     */
    public function complete(Request $request, MedicationRefusalFollowup $followup)
    {
        $user = $this->correctionActor($request);

        return $this->withCanonicalFollowup($user, $followup, function (MedicationRefusalFollowup $lockedFollowup) use ($request, $user) {
            if ($lockedFollowup->follow_up_completed_at !== null) {
                return redirect()->back()->with('success', 'Follow-up was already completed.');
            }

            // Completion must record what was actually done/decided — a bare
            // timestamp left auditors unable to verify the resolution action.
            $validated = $request->validate([
                'outcome' => ['required', 'string', 'max:2000'],
            ]);

            $lockedFollowup->update([
                'follow_up_completed_at' => now(),
                'follow_up_completed_by' => $user->id,
                'follow_up_outcome' => $validated['outcome'],
            ]);

            app(MedicationIncidentIntegrationService::class)->resolveRefusalEscalation(
                $lockedFollowup,
                'Medication refusal follow-up completed.',
                $user->id
            );

            return redirect()->back()->with('success', 'Follow-up marked as completed.');
        });
    }

    /**
     * Record that the GP has been notified.
     */
    public function notifyGp(Request $request, MedicationRefusalFollowup $followup)
    {
        $user = $this->correctionActor($request);

        return $this->withCanonicalFollowup($user, $followup, function (MedicationRefusalFollowup $lockedFollowup) use ($request, $user) {
            if ($lockedFollowup->gp_notified_at !== null) {
                return redirect()->back()->with('success', 'GP notification was already recorded.');
            }

            $validated = $request->validate([
                'gp_response' => ['nullable', 'string', 'max:2000'],
            ]);

            $lockedFollowup->update([
                'gp_notified_at' => now(),
                'gp_notified_by' => $user->id,
                'gp_response' => $validated['gp_response'] ?? null,
            ]);

            return redirect()->back()->with('success', 'GP notification recorded.');
        });
    }

    private function correctionActor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.correct'), 403);

        return $user;
    }

    private function withCanonicalFollowup(
        User $user,
        MedicationRefusalFollowup $submittedFollowup,
        Closure $callback,
    ): mixed {
        $clientId = (int) $submittedFollowup->client_id;
        abort_unless($clientId > 0, 404);

        return $this->governanceScope->forClient(
            $user,
            $clientId,
            'medications.administer.correct',
            function (Client $client) use ($submittedFollowup, $callback, $user) {
                $administrationSnapshot = ClientMedicationAdministration::query()
                    ->whereKey($submittedFollowup->client_medication_administration_id)
                    ->where('client_id', $client->id)
                    ->first([
                        'id',
                        'client_id',
                        'client_medication_id',
                        'is_correction',
                        'corrected_of_id',
                    ]);
                abort_unless($administrationSnapshot !== null, 404);
                $lockedMedication = $this->lockMedicationForAdministration($client, $administrationSnapshot);
                $this->assertControlledMutationAuthority($user, $lockedMedication);

                [$rootAdministration, , , $effectiveAdministration] =
                    $this->lockAdministrationCluster($client, $administrationSnapshot);
                $this->assertAdministrationOwnership($effectiveAdministration, $client, $lockedMedication);
                abort_unless(in_array($effectiveAdministration->status, ['refused', 'withheld'], true), 404);
                abort_unless(
                    ! $effectiveAdministration->is_correction
                    || $effectiveAdministration->correction_status === 'approved',
                    404,
                );

                $followup = MedicationRefusalFollowup::query()
                    ->whereKey($submittedFollowup->getKey())
                    ->where('client_id', $client->id)
                    ->whereIn('client_medication_administration_id', array_values(array_unique([
                        (int) $rootAdministration->id,
                        (int) $administrationSnapshot->id,
                    ])))
                    ->lockForUpdate()
                    ->first();
                abort_unless($followup !== null, 404);

                return $callback($followup);
            },
        );
    }

    private function withAssignedClient(User $user, int $clientId, Closure $callback): mixed
    {
        $scopeEntered = false;

        try {
            return $this->medicationScope->forClient(
                $user,
                $clientId,
                now(),
                function (MedicationScopeDecision $scope) use ($callback, &$scopeEntered) {
                    $scopeEntered = true;

                    return $callback($scope);
                },
            );
        } catch (HttpExceptionInterface $exception) {
            // Permission was checked before scope resolution. A pre-callback
            // 403 therefore means no current assignment/break-glass authority;
            // conceal the direct object just like a foreign Site record.
            if (! $scopeEntered && $exception->getStatusCode() === 403) {
                abort(404, 'The requested medication action is not available.');
            }

            throw $exception;
        }
    }

    /**
     * @return array{0: ClientMedicationAdministration, 1: Collection<int, ClientMedicationAdministration>, 2: ClientMedicationAdministration, 3: ClientMedicationAdministration}
     */
    private function lockAdministrationCluster(
        Client $client,
        ClientMedicationAdministration $snapshot,
    ): array {
        $rootId = $snapshot->is_correction
            ? (int) $snapshot->corrected_of_id
            : (int) $snapshot->id;
        abort_unless($rootId > 0, 404);

        $root = ClientMedicationAdministration::query()
            ->whereKey($rootId)
            ->where('client_id', $client->id)
            ->where('client_medication_id', $snapshot->client_medication_id)
            ->where(function ($query): void {
                $query->where('is_correction', false)
                    ->orWhereNull('is_correction');
            })
            ->lockForUpdate()
            ->first();
        abort_unless($root !== null, 404);

        $corrections = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $root->id)
            ->where('client_id', $client->id)
            ->where('client_medication_id', $root->client_medication_id)
            ->where('is_correction', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
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

        return [$root, $corrections, $submitted, $approvedWinner ?? $root];
    }

    private function assertAdministrationOwnership(
        ClientMedicationAdministration $administration,
        Client $client,
        ClientMedication $lockedMedication,
    ): void {
        abort_unless((int) $administration->client_id === (int) $client->id, 404);
        abort_unless(
            (int) $administration->client_medication_id === (int) $lockedMedication->id,
            404,
        );
    }

    private function lockMedicationForAdministration(
        Client $client,
        ClientMedicationAdministration $administration,
    ): ClientMedication {
        $medication = ClientMedication::withTrashed()
            ->whereKey($administration->client_medication_id)
            ->where('client_id', $client->id)
            ->lockForUpdate()
            ->first();
        abort_unless($medication !== null, 404);

        return $medication;
    }

    private function assertControlledMutationAuthority(User $user, ClientMedication $medication): void
    {
        abort_unless(
            ! (bool) $medication->controlled_drug
            || $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
            404,
        );
    }
}
