<?php

namespace App\Http\Controllers\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BreakGlassAccessEvent;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientDocument;
use App\Models\ClientIncident;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationAlert;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationDestruction;
use App\Models\MedicationInteraction;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationPrnEffectiveness;
use App\Models\MedicationReview;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\MedicationScheduledStockCount;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSyringeDriver;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DoseSchedulingService;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\Emar\ShiftMedicationSnapshotService;
use App\Services\GuidedRoundService;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationOrderLifecycleService;
use App\Services\Medication\MedicationRoundGenerationService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MedicationOverviewService;
use App\Services\MedicationRuleService;
use App\Services\MedicationScanVerificationService;
use App\Services\Operations\HandoverPresenter;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use App\Support\EmarUrl;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmarController extends Controller
{
    use HandlesMedicationSync;

    private const PRESCRIPTION_READ_BACK_ATTEMPT_LIMIT = 5;

    private const PRESCRIPTION_READ_BACK_DECAY_SECONDS = 300;

    private const PRESCRIPTION_READ_BACK_FAILURE = 'The witness credential could not be verified.';

    public function __construct(
        protected ShiftHandoverService $handoverService,
        protected MedicationScanVerificationService $scanVerificationService,
        protected MedsBoardPayloadService $boardPayload,
        protected MedicationScopeDecisionService $medicationScope,
        protected MedicationOrderLifecycleService $medicationOrderLifecycle,
        protected MedicationGovernanceScopeService $governanceScope,
        protected MedicationRoundGenerationService $roundGeneration,
    ) {}

    // ─── Helpers ──────────────────────────────────────────

    private function buildMedicationPermissions(?User $user): array
    {
        return [
            'record' => (bool) $user && $user->canDo('medications.administer.record'),
            'record_controlled' => (bool) $user && $user->canDo('medications.controlled.record'),
            'correct' => (bool) $user && $user->canDo('medications.administer.correct'),
            'verify_orders' => $this->canVerifyMedicationOrders($user),
            'manage_settings' => (bool) $user && $user->canDo('medications.settings.manage'),
            'manage_inr' => (bool) $user && $user->canDo('medications.orders.manage'),
            'manage_syringe_drivers' => (bool) $user && (
                $user->canDo('medications.orders.manage')
                || $user->canDo('medications.administer.record')
            ),
            'manage_allergies' => (bool) $user && $user->canDo('clients.update'),
            'manage_interactions' => (bool) $user && $user->canDo('medications.administer.correct'),
            'manage_stock' => (bool) $user && $user->canDo('medications.stock.update'),
            'view_controlled' => (bool) $user && $user->canDo('medications.controlled.view'),
            'revoke_break_glass' => (bool) $user && ($user->canDo('medications.breakglass') || $user->canDo('medications.audit.view')),
            'export_reports' => (bool) $user && (
                $user->canDo('medications.reports.export')
                || $user->canDo('reports.viewAny')
            ),
        ];
    }

    private function canVerifyMedicationOrders(?User $user): bool
    {
        return (bool) $user && $user->canDo('medications.orders.verify');
    }

    private function addMedicationStockBalanceOrFail(
        int|float|string $currentBalance,
        int|float|string $quantity,
        string $field,
    ): string {
        try {
            return MedicationStockQuantity::add($currentBalance, $quantity);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                $field => 'The resulting medication stock balance exceeds the supported maximum of '
                    .MedicationStockQuantity::DECIMAL_12_2_MAX.'.',
            ]);
        }
    }

    private function assertCurrentStaffAtSite(?int $userId, ?int $siteId): void
    {
        if ($userId === null) {
            return;
        }

        abort_unless($siteId !== null, 404);
        $this->assertCurrentStaffWithinSites($userId, [$siteId]);
    }

    /** @param array<int, int> $siteIds */
    private function assertCurrentStaffWithinSites(int $userId, array $siteIds): void
    {
        abort_unless(
            $userId > 0
            && $this->governanceScope->staffPicker($siteIds)
                ->contains(fn ($staff) => (int) data_get($staff, 'id') === $userId),
            404,
        );
    }

    private function lockCurrentMedicationOrderStaff(
        User $actor,
        Client $client,
        int $submittedUserId,
        string $field,
        bool $mustDifferFromActor = false,
    ): User {
        $lockedUsers = $this->governanceScope->lockControlledWitnessUsers([
            (int) $actor->id,
            $submittedUserId,
        ]);
        $staff = $lockedUsers->get($submittedUserId);
        abort_unless(
            $staff instanceof User
            && $staff->approved_at !== null
            && ! in_array($staff->role, ['client', 'next_of_kin'], true)
            && ! $staff->roles()->whereIn('name', ['client', 'next_of_kin'])->exists(),
            404,
        );

        $profile = HrEmployeeProfile::withTrashed()
            ->where('user_id', $submittedUserId)
            ->lockForUpdate()
            ->first();
        $calendarDate = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        abort_unless(
            $profile !== null
            && ! $profile->trashed()
            && $profile->is_active
            && ($profile->start_date === null || $profile->start_date->toDateString() <= $calendarDate)
            && ($profile->end_date === null || $profile->end_date->toDateString() >= $calendarDate)
            && collect([
                $profile->primary_site_id,
                ...(is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : []),
            ])->contains(fn (mixed $siteId): bool => (int) $siteId === (int) $client->site_id),
            404,
        );

        if ($mustDifferFromActor && $submittedUserId === (int) $actor->id) {
            throw ValidationException::withMessages([
                $field => 'Choose a different current Site staff member for this attestation.',
            ]);
        }

        return $staff;
    }

    private function prescriptionReadBackRateLimitKey(User $actor, User $witness, Client $client): string
    {
        return implode(':', [
            'medications',
            'prescriber-order-read-back',
            (int) $actor->id,
            (int) $witness->id,
            (int) $client->site_id,
        ]);
    }

    private function rejectPrescriptionReadBackCredential(
        User $actor,
        User $witness,
        Client $client,
        string $outcome,
        int $attempts,
    ): never {
        Log::warning('Medication prescriber-order read-back witness credential rejected.', [
            'security_event' => 'medication_prescriber_order_read_back_credential_rejected',
            'outcome' => $outcome,
            'actor_id' => (int) $actor->id,
            'witness_id' => (int) $witness->id,
            'client_id' => (int) $client->id,
            'site_id' => (int) $client->site_id,
            'attempts' => $attempts,
            'attempt_limit' => self::PRESCRIPTION_READ_BACK_ATTEMPT_LIMIT,
        ]);

        throw ValidationException::withMessages([
            'read_back_witness_credential' => self::PRESCRIPTION_READ_BACK_FAILURE,
        ]);
    }

    private function assertPrescriptionNotExpired(
        MedicationPrescriberOrder $order,
        string $errorKey,
    ): void {
        if ($order->isExpired(now())) {
            throw ValidationException::withMessages([
                $errorKey => 'This prescriber order has expired and is read-only.',
            ]);
        }
    }

    private function assertPrescriptionChronology(
        MedicationPrescriberOrder $order,
        string $errorKey,
    ): void {
        $orderDate = $order->order_date?->toDateString();
        $effectiveDate = $order->effective_date?->toDateString();
        $expiryDate = $order->expiry_date?->toDateString();
        $minimumExpiryDate = $effectiveDate ?? $orderDate;

        if ($orderDate === null
            || ($effectiveDate !== null && $effectiveDate < $orderDate)
            || ($expiryDate !== null
                && ($minimumExpiryDate === null || $expiryDate < $minimumExpiryDate))) {
            throw ValidationException::withMessages([
                $errorKey => 'This prescriber order has invalid date chronology and cannot be actioned.',
            ]);
        }
    }

    private function assertCeaseOrderEffective(
        MedicationPrescriberOrder $order,
        string $errorKey,
    ): void {
        if ($order->order_type !== 'cease' || $order->effective_date === null) {
            return;
        }

        $workerDate = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        if ($order->effective_date->toDateString() > $workerDate) {
            throw ValidationException::withMessages([
                $errorKey => 'This cease order cannot be actioned before its effective date.',
            ]);
        }
    }

    private function buildClientMedicationContext(Client $client): array
    {
        $client->loadMissing([
            'medicalProfile',
            'conditions',
            'emergencyContacts',
        ]);

        return [
            'profile' => $client->medicalProfile ? [
                'medical_history' => $client->medicalProfile->medical_history,
                'mental_health_history' => $client->medicalProfile->mental_health_history,
                'surgical_history' => $client->medicalProfile->surgical_history,
                'gp_name' => $client->medicalProfile->gp_name,
                'gp_practice' => $client->medicalProfile->gp_practice,
                'gp_phone' => $client->medicalProfile->gp_phone,
                'hospital_preference' => $client->medicalProfile->hospital_preference,
                'blood_type' => $client->medicalProfile->blood_type,
                'organ_donor' => (bool) $client->medicalProfile->organ_donor,
                'immunisation_notes' => $client->medicalProfile->immunisation_notes,
                'disabilities' => $client->medicalProfile->disabilities ?? [],
                'allergies' => $client->medicalProfile->allergies ?? [],
                'notes' => $client->medicalProfile->notes,
            ] : null,
            'conditions' => $client->conditions
                ->map(fn ($condition) => [
                    'id' => $condition->id,
                    'label' => $condition->label,
                    'severity' => $condition->severity,
                    'notes' => $condition->notes,
                ])
                ->values()
                ->all(),
            'emergency_contacts' => $client->emergencyContacts
                ->sortBy('contact_order')
                ->map(fn ($contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'relationship' => $contact->relationship,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'notes' => $contact->notes,
                    'preferred_method' => $contact->preferred_method,
                    'availability' => $contact->availability,
                    'authorised_health_info' => (bool) $contact->authorised_health_info,
                ])
                ->values()
                ->all(),
            'medication_charts' => $this->getMedicationCharts($client),
        ];
    }

    private function getMedicationCharts(Client $client): array
    {
        return ClientDocument::query()
            ->with('uploadedBy:id,name')
            ->where('client_id', $client->id)
            ->where('category', 'med_chart')
            ->latest()
            ->get()
            ->map(fn (ClientDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'original_name' => $document->original_name,
                'version' => $document->version,
                'effective_date' => $document->effective_date?->toDateString(),
                'expiry_date' => $document->expiry_date?->toDateString(),
                'notes' => $document->notes,
                'mime_type' => $document->mime_type,
                'uploaded_at' => $document->created_at?->toIso8601String(),
                'uploaded_by' => $document->uploadedBy?->name,
                'download_url' => route('clients.documents.download', ['client' => $client->id, 'document' => $document->id]),
            ])
            ->values()
            ->all();
    }

    private function getBreakGlassState(Client $client): array
    {
        $currentUser = request()->user();

        $accesses = ClientBreakGlassAccess::query()
            ->with('user:id,name')
            ->where('client_id', $client->id)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get()
            ->map(fn (ClientBreakGlassAccess $access) => [
                'id' => $access->id,
                'user_id' => $access->user_id,
                'user_name' => $access->user?->name,
                'reason' => $access->reason,
                'expires_at' => $access->expires_at?->toIso8601String(),
                'is_current_user' => (int) $access->user_id === (int) $currentUser?->id,
            ])
            ->values()
            ->all();

        return [
            'active' => ! empty($accesses),
            'accesses' => $accesses,
        ];
    }

    private function getPendingCorrections(Client $client, bool $includeControlled): array
    {
        $query = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query(),
            $client->site_id ? [(int) $client->site_id] : [],
            false,
        );
        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query
            ->with([
                'medication:id,name,dosage,deleted_at',
                'correctionRequestedBy:id,name',
                'administeredBy:id,name',
                'attachments.uploadedBy:id,name',
            ])
            ->where('client_id', $client->id)
            ->where('is_correction', true)
            ->where('correction_status', 'pending')
            ->latest('created_at')
            ->limit(25)
            ->get()
            ->map(fn (ClientMedicationAdministration $administration) => [
                'id' => $administration->id,
                'original_administration_id' => $administration->corrected_of_id,
                'medication_name' => $administration->medication?->historicalDisplayName() ?? 'Unknown',
                'status' => $administration->status,
                'dose_given' => $administration->dose_given,
                'reason' => $administration->reason,
                'notes' => $administration->notes,
                'correction_reason' => $administration->correction_reason,
                'submitted_by' => $administration->correctionRequestedBy?->name
                    ?? $administration->administeredBy?->name,
                'submitted_at' => $administration->created_at?->toIso8601String(),
                'administered_at' => $administration->administered_at?->toIso8601String(),
                'attachments' => $administration->attachments
                    ->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'file_name' => $attachment->file_name,
                        'mime_type' => $attachment->mime_type,
                        'file_size' => $attachment->file_size,
                        'formatted_size' => $attachment->formatted_size,
                        'description' => $attachment->description,
                        'uploaded_at' => $attachment->created_at?->toIso8601String(),
                        'uploaded_by' => $attachment->uploadedBy?->name,
                        'download_url' => route('api.medications.attachments.download', [
                            'client' => $administration->client_id,
                            'administration' => $administration->id,
                            'attachment' => $attachment->id,
                        ]),
                        'can_delete' => (bool) request()->user()
                            && request()->user()->canDo('medications.administer.correct'),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function getClientAlerts(Client $client, bool $canViewControlled): array
    {
        $query = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationDashboardAlert::query(),
            $client->site_id ? [(int) $client->site_id] : [],
            true,
        )
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->with(['medication' => fn ($medication) => $medication->withTrashed()]);

        if (! $canViewControlled) {
            $query->whereNotIn('alert_type', [
                'controlled_discrepancy',
                'controlled_overdue_check',
                'controlled_loss',
            ]);
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (MedicationDashboardAlert $alert) => [
                'id' => $alert->id,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'message' => $alert->message,
                'created_at' => $alert->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @param array<int, int> $allowedSiteIds */
    private function filterOverviewPayloadForCapabilities(array $payload, array $can, array $allowedSiteIds): array
    {
        if (! $can['view_controlled']) {
            foreach (['controlledCount', 'cdDue', 'activeDiscrepancies'] as $key) {
                $payload['stats'][$key] = 0;
            }
            $payload['actionCentre'] = array_values(array_filter(
                $payload['actionCentre'],
                fn (array $item) => ($item['category'] ?? null) !== 'controlled'
                    && ! ($item['is_controlled'] ?? false),
            ));
            $payload['medicationOptions'] = array_values(array_filter(
                $payload['medicationOptions'],
                fn (array $medication) => ! ($medication['controlled'] ?? false),
            ));
            $payload['activeAlertsList'] = collect($payload['activeAlertsList'])
                ->reject(fn (MedicationDashboardAlert $alert) => in_array($alert->alert_type, [
                    'controlled_discrepancy',
                    'controlled_overdue_check',
                    'controlled_loss',
                ], true) || (bool) $alert->medication?->controlled_drug)
                ->values();
            $payload['stats']['activeAlerts'] = $payload['activeAlertsList']->count();

            if ($can['manage_stock']) {
                $ordinaryStock = ClientMedicationStock::query()
                    ->whereHas('medication', fn ($query) => $query
                        ->active()
                        ->where('controlled_drug', false)
                        ->whereHas('client', fn ($client) => $client->whereIn('site_id', $allowedSiteIds)));
                $payload['stats']['lowStock'] = (clone $ordinaryStock)->lowStock()->count();
                $payload['stats']['expiringStock'] = (clone $ordinaryStock)->expiringSoon()->count();
                $payload['stats']['expiredStock'] = (clone $ordinaryStock)->expired()->count();
                $payload['stats']['stockAlerts'] = $payload['stats']['lowStock']
                    + $payload['stats']['expiringStock']
                    + $payload['stats']['expiredStock'];
            }
        }

        if (! $can['manage_stock']) {
            foreach (['stockAlerts', 'lowStock', 'expiringStock', 'expiredStock'] as $key) {
                $payload['stats'][$key] = 0;
            }
            $payload['actionCentre'] = array_values(array_filter(
                $payload['actionCentre'],
                fn (array $item) => ($item['category'] ?? null) !== 'stock',
            ));
        }

        if (! $can['manage_stock'] && ! $can['record_controlled']) {
            $payload['medicationOptions'] = [];
        }
        if (! $can['record'] && ! $can['record_controlled']) {
            $payload['witnesses'] = [];
        }

        return $payload;
    }

    private function getClientMedicationAttentionAlerts(Client $client, bool $includeControlled): array
    {
        return $client->medicationAlerts()
            ->enabled()
            ->unresolved()
            ->when(! $includeControlled, fn ($query) => $query->where(function ($types): void {
                $types->whereNull('type')->orWhere('type', 'not like', 'controlled%');
            }))
            ->latest()
            ->get()
            ->map(fn (ClientMedicationAlert $alert) => [
                'id' => $alert->id,
                'type' => $alert->type,
                'title' => $alert->title,
                'detail' => $alert->detail,
                'prompt_on_open' => (bool) $alert->prompt_on_open,
                'created_at' => $alert->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function getClientInrRecords(Client $client, bool $includeControlled): array
    {
        $query = $client->inrRecords();
        $this->governanceScope->scopeCanonicalClientMedicationRows(
            $query->getQuery(),
            $client->site_id ? [(int) $client->site_id] : [],
            false,
        );
        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query->getQuery());
        }

        return $query
            ->with('medication:id,name')
            ->latest('tested_on')
            ->limit(20)
            ->get()
            ->map(fn (ClientInrRecord $record) => [
                'id' => $record->id,
                'client_medication_id' => $record->client_medication_id,
                'medication_name' => $record->medication?->name,
                'inr_value' => $record->inr_value,
                'target_range_low' => $record->target_range_low,
                'target_range_high' => $record->target_range_high,
                'dose_mg' => $record->dose_mg,
                'tested_on' => $record->tested_on?->toDateString(),
                'next_test_date' => $record->next_test_date?->toDateString(),
                'disabled_at' => $record->disabled_at?->toIso8601String(),
                'notes' => $record->notes,
            ])
            ->values()
            ->all();
    }

    private function getRunningSyringeDrivers(Client $client, bool $includeControlled): array
    {
        return $client->syringeDrivers()
            ->running()
            ->with(['checks' => fn ($query) => $query->latest('checked_at')->limit(5)])
            ->latest('commenced_at')
            ->get()
            ->map(function (MedicationSyringeDriver $driver) use ($client, $includeControlled): ?array {
                $contents = $this->governanceScope->visibleSyringeDriverContents(
                    $client,
                    $driver->contents ?? [],
                    $includeControlled,
                );
                if ($contents === null) {
                    return null;
                }

                return [
                    'id' => $driver->id,
                    'status' => $driver->status,
                    'commenced_at' => $driver->commenced_at?->toIso8601String(),
                    'rate' => $driver->rate,
                    'rate_unit' => $driver->rate_unit,
                    'duration_hours' => $driver->duration_hours,
                    'contents' => $contents,
                    'site_of_insertion' => $driver->site_of_insertion,
                    'notes' => $driver->notes,
                    'checks' => $driver->checks
                        ->map(fn ($check) => [
                            'id' => $check->id,
                            'checked_at' => $check->checked_at?->toIso8601String(),
                            'infusion_running' => (bool) $check->infusion_running,
                            'site_condition' => $check->site_condition,
                            'volume_remaining' => $check->volume_remaining,
                            'notes' => $check->notes,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function getOpenControlledDiscrepancies(Client $client): array
    {
        return $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientControlledDrugDiscrepancy::query(),
            $client->site_id ? [(int) $client->site_id] : [],
            false,
        )
            ->where('client_id', $client->id)
            ->with('medication:id,name')
            ->whereIn('status', ['open', 'under_review'])
            ->latest('reported_at')
            ->limit(20)
            ->get()
            ->map(fn (ClientControlledDrugDiscrepancy $discrepancy) => [
                'id' => $discrepancy->id,
                'medication_name' => $discrepancy->medication?->name,
                'difference' => $discrepancy->difference,
                'reason' => $discrepancy->reason,
                'notes' => $discrepancy->notes,
                'immediate_action_taken' => $discrepancy->immediate_action_taken,
                'status' => $discrepancy->status,
                'reported_at' => $discrepancy->reported_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function serializeAdministration(
        ?ClientMedicationAdministration $administration,
        ?string $statusOverride = null,
        ?string $scheduledForOverride = null,
    ): array {
        $scheduledFor = $administration
            ? $this->administrationDateUtc($administration, 'scheduled_for')
            : null;
        $administeredAt = $administration
            ? $this->administrationDateUtc($administration, 'administered_at')
            : null;

        return [
            'id' => $administration?->id,
            'scheduled_for' => $scheduledForOverride ?? $scheduledFor?->toIso8601String(),
            'administered_at' => $administeredAt?->toIso8601String(),
            'status' => $statusOverride ?? $administration?->status,
            'administered_by' => $administration?->administeredBy?->name,
            'witnessed_by' => $administration?->witnessedBy?->name,
            'witnessed_at' => $administration?->witnessed_at?->toIso8601String(),
            'witness_method' => $administration?->witness_method,
            'notes' => $administration?->notes,
            'reason' => $administration?->reason,
            'reason_code' => $administration?->reason_code,
            'dose_given' => $administration?->dose_given,
            'outcome' => $administration?->outcome,
            'site' => $administration?->site,
            'blood_glucose_level' => $administration?->blood_glucose_level,
            'pulse_bpm' => $administration?->pulse_bpm,
            'blood_pressure_systolic' => $administration?->blood_pressure_systolic,
            'blood_pressure_diastolic' => $administration?->blood_pressure_diastolic,
            'created_at' => $administration?->created_at?->toIso8601String(),
            'is_correction' => (bool) $administration?->is_correction,
            'correction_reason' => $administration?->correction_reason,
            'correction_status' => $administration?->correction_status,
            'correction_rejection_reason' => $administration?->correction_rejection_reason,
            'attachments' => $administration
                ? $administration->attachments
                    ->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'file_name' => $attachment->file_name,
                        'mime_type' => $attachment->mime_type,
                        'file_size' => $attachment->file_size,
                        'formatted_size' => $attachment->formatted_size,
                        'description' => $attachment->description,
                        'uploaded_at' => $attachment->created_at?->toIso8601String(),
                        'uploaded_by' => $attachment->uploadedBy?->name,
                        'download_url' => route('api.medications.attachments.download', [
                            'client' => $administration->client_id,
                            'administration' => $administration->id,
                            'attachment' => $attachment->id,
                        ]),
                        'can_delete' => (bool) request()->user()
                            && request()->user()->canDo('medications.administer.correct'),
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    private function serializeSupportingAttachment($attachment, string $downloadRoute): array
    {
        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
            'formatted_size' => $attachment->formatted_size,
            'description' => $attachment->description,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'uploaded_by' => $attachment->uploadedBy?->name,
            'download_url' => route($downloadRoute, [
                'client' => $attachment->client_id,
                'attachment' => $attachment->id,
            ]),
            'can_delete' => (bool) request()->user()
                && request()->user()->canDo('medications.controlled.record'),
        ];
    }

    private function buildMedicationScanPayload(Client $client, ClientMedication $medication): array
    {
        $payload = $this->scanVerificationService->payload($client, $medication);
        $payload['svg_url'] = route('api.medications.scan_code.svg', [
            'client' => $client->id,
            'medication' => $medication->id,
        ]);

        return $payload;
    }

    private function verifyMedicationScanOrFail(
        Client $client,
        ClientMedication $medication,
        array $payload,
        string $errorKey = 'scan_code'
    ): array {
        if (! ($payload['scan_verified'] ?? false) || blank($payload['scan_code'] ?? null)) {
            throw ValidationException::withMessages([
                $errorKey => 'Verify the medication code before continuing.',
            ]);
        }

        $result = $this->scanVerificationService->verify(
            $client,
            $medication,
            (string) $payload['scan_code']
        );

        if (! $result['matched']) {
            throw ValidationException::withMessages([
                $errorKey => $result['message'],
            ]);
        }

        if (
            filled($payload['scan_match_source'] ?? null)
            && ($payload['scan_match_source'] ?? null) !== $result['match_source']
        ) {
            throw ValidationException::withMessages([
                $errorKey => 'The medication verification needs to be repeated.',
            ]);
        }

        return [
            'scan_source' => $payload['scan_source'] ?? 'manual',
            'scan_match_source' => $result['match_source'],
            'scan_match_label' => $result['match_label'],
            'scan_code_suffix' => substr(
                $this->scanVerificationService->normalize((string) $payload['scan_code']),
                -6
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stringifyStructuredItems(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->map(function ($item) {
                if (is_string($item)) {
                    return trim($item);
                }

                if (! is_array($item)) {
                    return null;
                }

                return trim((string) ($item['label']
                    ?? $item['task']
                    ?? $item['title']
                    ?? $item['description']
                    ?? $item['note']
                    ?? $item['name']
                    ?? ''));
            })
            ->filter(fn ($item) => filled($item))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string}>
     */
    private function parseHandoverText(?string $value): array
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $value) ?: [])
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '')
            ->map(fn ($line) => ['label' => $line])
            ->values()
            ->all();
    }

    private function assertControlledHandoverInputAuthority(Request $request, User $actor): void
    {
        $hasControlledEvidenceInput = collect(['cd_result', 'cd_witness_id', 'cd_witness_credential', 'cd_notes'])
            ->contains(fn (string $key): bool => $request->filled($key));
        if (! $hasControlledEvidenceInput && ! $this->hasMedicationDueInput($request)) {
            return;
        }

        abort_unless(
            $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                && $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
            404,
        );
    }

    private function hasMedicationDueInput(Request $request): bool
    {
        return $request->exists('medications_due') || $request->exists('medications_due_text');
    }

    private function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    private function assertMedicationCapability(Request $request, string $capability): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo($capability), 403);

        return $user;
    }

    /**
     * Canonical client ids inside the actor's allowed Site boundary. Adjacent
     * stock, audit, report, or client roles never imply application-wide Site
     * access; only the documented global Site permissions broaden this list.
     *
     * @return array<int, int>
     */
    private function medicationViewableClientIds(?User $user): ?array
    {
        abort_unless($user, 403);
        $siteIds = $this->governanceScope->readerSiteIds(
            $user,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
        );

        return Client::query()
            ->whereIn('site_id', $siteIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @return array<int, array<string, mixed>>
     */
    private function normaliseSyringeDriverContents(Client $client, array $contents, User $actor): array
    {
        return collect($contents)
            ->map(function (array $item) use ($client, $actor) {
                abort_unless(
                    isset($item['client_medication_id'])
                    && is_numeric($item['client_medication_id'])
                    && (int) $item['client_medication_id'] > 0,
                    404,
                );
                $medication = ClientMedication::query()
                    ->whereKey((int) $item['client_medication_id'])
                    ->where('client_id', $client->id)
                    ->whereNull('deleted_at')
                    ->whereNull('superseded_by')
                    ->lockForUpdate()
                    ->first();
                abort_unless($medication !== null, 404);
                abort_if(
                    $medication->controlled_drug
                        && (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                            || ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                    404,
                );

                return [
                    'client_medication_id' => $medication->id,
                    'name' => $medication->name,
                    'dose' => $item['dose'] ?? $medication->dosage,
                    'unit' => $item['unit'] ?? $medication->dose_unit,
                    'requires_witness' => (bool) $medication->requiresWitness(),
                ];
            })
            ->values()
            ->all();
    }

    private function syringeDriverPresenceEffectiveAtHint(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\InvalidArgumentException) {
            // The canonical validation error remains inside the locked Client
            // boundary; an invalid hint must not move validation ahead of 404.
            return null;
        }
    }

    private function assertRunningSyringeDriverMutationAuthority(
        Client $client,
        MedicationSyringeDriver $driver,
        User $actor,
    ): void {
        abort_unless(
            $driver->status === 'running' && $driver->completed_at === null,
            404,
        );

        $linkedMedicationIds = collect($driver->contents ?? [])
            ->map(function ($item): int {
                abort_unless(is_array($item), 404);
                abort_unless(
                    array_key_exists('client_medication_id', $item)
                    && is_numeric($item['client_medication_id'])
                    && (int) $item['client_medication_id'] > 0,
                    404,
                );

                return (int) $item['client_medication_id'];
            })
            ->unique()
            ->sort()
            ->values();
        abort_unless($linkedMedicationIds->isNotEmpty(), 404);

        $medications = ClientMedication::withTrashed()
            ->where('client_id', $client->id)
            ->whereIn('id', $linkedMedicationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'controlled_drug']);
        abort_unless($medications->count() === $linkedMedicationIds->count(), 404);

        if ($medications->contains(fn (ClientMedication $medication): bool => (bool) $medication->controlled_drug)) {
            abort_unless(
                $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                    && $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                404,
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function handoverBypassPermissions(): array
    {
        return MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS;
    }

    private function canonicalHandover(User $actor, mixed $handover): ShiftHandover
    {
        $handoverId = $handover instanceof ShiftHandover
            ? $handover->getKey()
            : $handover;

        return ShiftHandover::query()
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope(
                $query,
                $actor,
                $this->handoverBypassPermissions(),
            ))
            ->findOrFail($handoverId);
    }

    private function buildMedicationPayload(array $validated): array
    {
        $payload = [];

        if (array_key_exists('client_id', $validated)) {
            $payload['client_id'] = $validated['client_id'];
        }

        if (array_key_exists('medication_name', $validated)) {
            $payload['name'] = $validated['medication_name'];
        }

        if (array_key_exists('dose', $validated)) {
            $payload['dosage'] = trim((string) $validated['dose']);
            $payload['dose_amount'] = is_numeric($validated['dose']) ? (float) $validated['dose'] : null;
        }

        if (array_key_exists('dose_unit', $validated)) {
            $payload['dose_unit'] = $validated['dose_unit'];
        }

        if (array_key_exists('frequency', $validated)) {
            $payload['frequency'] = $validated['frequency'];
            $payload['dose_times'] = DoseSchedulingService::calculateDoseTimes((string) $validated['frequency']);
        }

        foreach (['route', 'form', 'instructions', 'indication', 'start_date', 'pharmac_therapeutic_group', 'pharmac_subgroup'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('is_prn', $validated)) {
            $payload['is_prn'] = (bool) $validated['is_prn'];
        }

        if (array_key_exists('prn_reason', $validated)) {
            $payload['prn_reason'] = $validated['prn_reason'];
        }

        if (array_key_exists('max_per_day', $validated) || array_key_exists('max_doses_per_day', $validated)) {
            $payload['max_per_day'] = $validated['max_per_day'] ?? $validated['max_doses_per_day'];
        }

        if (array_key_exists('min_hours_between_doses', $validated)) {
            $payload['min_hours_between_doses'] = $validated['min_hours_between_doses'];
        }

        if (array_key_exists('controlled_drug', $validated) || array_key_exists('is_controlled_drug', $validated)) {
            $payload['controlled_drug'] = (bool) ($validated['controlled_drug'] ?? $validated['is_controlled_drug']);
        }

        if (array_key_exists('high_risk', $validated) || array_key_exists('is_high_risk', $validated)) {
            $payload['high_risk'] = (bool) ($validated['high_risk'] ?? $validated['is_high_risk']);
        }

        if (array_key_exists('witness_required', $validated)) {
            $payload['witness_required'] = (bool) $validated['witness_required'];
        }

        if (array_key_exists('prescriber', $validated) || array_key_exists('prescriber_name', $validated)) {
            $payload['prescriber'] = $validated['prescriber'] ?? $validated['prescriber_name'];
        }

        if (array_key_exists('brand_name', $validated)) {
            $payload['brand_name'] = $validated['brand_name'];
        }

        return $payload;
    }

    private function assertActiveGovernanceMedication(ClientMedication $medication): void
    {
        abort_unless(
            $medication->isAdministrable(),
            404,
            'The requested medication record was not found.',
        );
    }

    // ─── Dashboard ─────────────────────────────────────────
    public function dashboard(Request $request, MedicationOverviewService $overview)
    {
        $scheduleDate = app(MarScheduleService::class)->dateFromInput($request->input('date'));

        $user = $request->user();
        $can = $this->buildMedicationPermissions($user);
        $allowedSiteIds = $this->governanceScope->readerSiteIds(
            $user,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
        );
        $payload = $this->filterOverviewPayloadForCapabilities(
            $overview->payload($scheduleDate, $user),
            $can,
            $allowedSiteIds,
        );

        return Inertia::render('emar/Index', array_merge(
            $payload,
            [
                'can' => $can,
                'canManageSettings' => $can['manage_settings'],
                'signedAs' => [
                    'name' => $user?->name,
                    'role_label' => $user && $user->role ? Str::headline($user->role) : null,
                ],
            ]
        ));
    }

    // ─── MAR Charts ────────────────────────────────────────
    public function mar(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $requestedClientId = $request->integer('client_id') ?: null;
        $allowedSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            requestedClientId: $requestedClientId,
        );
        $scheduleService = app(MarScheduleService::class);
        $scheduleDate = $scheduleService->dateFromInput($request->input('date'));
        $date = $scheduleDate->toDateString();
        [$dayStartUtc, $dayEndUtc] = $scheduleService->utcDayWindow($scheduleDate);
        $can = $this->buildMedicationPermissions($actor);
        $viewableClientIds = $this->medicationViewableClientIds($actor);
        $clients = Client::query()
            ->whereIn('id', $viewableClientIds)
            ->whereIn('site_id', $allowedSiteIds)
            ->withCount(['medications as active_medications_count' => fn ($q) => $q
                ->active()
                ->when(! $can['view_controlled'], fn ($query) => $query->where('controlled_drug', false))])
            ->having('active_medications_count', '>', 0)
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'nhi_number']);

        $selectedClient = null;
        $marData = [];
        $clientContext = null;
        $breakGlassAccess = ['active' => false, 'accesses' => []];
        $pendingCorrections = [];
        $alerts = [];
        $controlledDiscrepancies = [];
        // Shared meds-board payload (reused by /meds/today) so the MAR chart
        // records doses through the exact same RecordDoseWizard + pipeline.
        $boardSchedule = [];
        $boardPrn = [];
        $selectedClientInfo = null;
        $siteBrandColour = null;

        $marWith = [
            'site:id,name,brand_colour',
            'medications' => fn ($q) => $q
                ->active()
                ->when(! $can['view_controlled'], fn ($query) => $query->where('controlled_drug', false))
                ->orderBy('name'),
            'medications.stock',
            'medications.administrations' => function ($q) use ($allowedSiteIds, $dayStartUtc, $dayEndUtc): void {
                $administrationQuery = $q->getQuery()->effectiveClinicalEvidence();
                $this->governanceScope->scopeCanonicalClientMedicationRows(
                    $administrationQuery,
                    $allowedSiteIds,
                    false,
                )->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                    $query->whereBetween('scheduled_for', [$dayStartUtc, $dayEndUtc])
                        ->orWhereBetween('administered_at', [$dayStartUtc, $dayEndUtc]);
                });
            },
            'medications.administrations.attachments.uploadedBy:id,name',
        ];

        // Default the resident server-side so the MAR chart opens straight onto a
        // chart instead of a two-step picker. An explicit ?client_id (deep-link or
        // the hero EntityFilter) wins and enforces access (403 on denial); with no
        // client_id we fall back to the last chart this user viewed, else the first
        // resident they may view — never throwing for an auto-pick.
        if ($request->filled('client_id')) {
            $selectedClient = Client::with($marWith)
                ->whereIn('site_id', $allowedSiteIds)
                ->findOrFail($requestedClientId);
        } else {
            $defaultClientId = $this->defaultMarClientId($request, $clients);
            $selectedClient = $defaultClientId ? Client::with($marWith)->find($defaultClientId) : null;
        }

        if ($selectedClient) {
            // Remember the most-recently-opened chart so the next bare /emar/mar
            // visit reopens it (session-scoped, no schema needed).
            $request->session()->put('emar.mar.last_client_id', $selectedClient->id);

            $marData = $this->buildMarData($selectedClient, $scheduleDate, $can['view_controlled']);
            $clientContext = $this->buildClientMedicationContext($selectedClient);
            $breakGlassAccess = $this->getBreakGlassState($selectedClient);
            // Access-scope log: record a break-glass user opening this client's MAR.
            BreakGlassAccessEvent::recordFor($request->user(), $selectedClient, 'viewed_mar', null, 5);
            $pendingCorrections = $this->getPendingCorrections($selectedClient, $can['view_controlled']);
            $alerts = $this->getClientAlerts($selectedClient, $can['view_controlled']);
            $controlledDiscrepancies = $can['view_controlled']
                ? $this->getOpenControlledDiscrepancies($selectedClient)
                : [];

            $boardClientIds = [$selectedClient->id];
            $boardNow = Carbon::now($scheduleService->workerTimezone());
            $boardSchedule = $this->boardPayload->scheduleForDate(
                $boardClientIds,
                $scheduleDate,
                $boardNow,
                $this->boardPayload->slotIndex(
                    $this->boardPayload->administrationsForDay($boardClientIds, $scheduleDate, $can['view_controlled'])
                ),
                $can['view_controlled'],
            );
            $boardPrn = $this->boardPayload->prnMedications($boardClientIds, $boardNow, $can['view_controlled']);
            $selectedClientInfo = $this->boardPayload->clientsPayload($boardClientIds)[0] ?? null;
            $siteBrandColour = $selectedClient->site?->brand_colour;
        }

        return Inertia::render('emar/MarCharts', [
            'clients' => $clients,
            'selectedClient' => $selectedClient,
            'marData' => $marData,
            'date' => $date,
            'staff' => $this->governanceScope->staffPicker($allowedSiteIds),
            'allergies' => $selectedClient ? $selectedClient->medicationAllergies()
                ->whereNull('deleted_at')
                ->latest()
                ->get(['id', 'allergen', 'reaction', 'severity', 'notes', 'identified_date']) : [],
            'interactions' => $selectedClient ? $this->getActiveInteractions($selectedClient) : [],
            'clientContext' => $clientContext,
            'breakGlassAccess' => $breakGlassAccess,
            'pendingCorrections' => $pendingCorrections,
            'alerts' => $alerts,
            'controlledDiscrepancies' => $controlledDiscrepancies,
            'can' => $can,
            // Shared meds-board payload (mirrors /meds/today) powering the
            // time-grid + reused RecordDoseWizard / PrnWizard on the MAR chart.
            'schedule' => $boardSchedule,
            'prn_medications' => $boardPrn,
            'selected_client_info' => $selectedClientInfo,
            'site_brand_colour' => $siteBrandColour,
            'witnesses' => ($can['record'] || $can['record_controlled'])
                ? $this->governanceScope->controlledWitnessPicker($allowedSiteIds, $actor->id)
                : [],
            'not_given_reasons' => $this->boardPayload->notGivenReasons(),
            'board_user' => $this->boardPayload->boardUser($request->user()),
        ]);
    }

    /**
     * Resolve the resident to open by default when no ?client_id is supplied:
     * the last MAR chart this user viewed (if still selectable + viewable),
     * otherwise the first resident they may view. Null = no viewable residents.
     *
     * @param  Collection<int, Client>  $clients
     */
    private function defaultMarClientId(Request $request, $clients): ?int
    {
        if ($clients->isEmpty()) {
            return null;
        }

        $user = $request->user();
        $lastViewed = (int) ($request->session()->get('emar.mar.last_client_id') ?? 0);

        if ($lastViewed) {
            $prior = $clients->firstWhere('id', $lastViewed);
            if ($prior && Gate::forUser($user)->allows('viewMedications', $prior)) {
                return $lastViewed;
            }
        }

        foreach ($clients as $client) {
            if (Gate::forUser($user)->allows('viewMedications', $client)) {
                return $client->id;
            }
        }

        return null;
    }

    private function buildMarData(Client $client, Carbon $date, bool $includeControlled): array
    {
        $ruleService = app(MedicationRuleService::class);
        $scheduleService = app(MarScheduleService::class);
        $medications = $client->medications()
            ->active()
            ->when(! $includeControlled, fn ($query) => $query->where('controlled_drug', false))
            ->with([
                'stock',
                'administrations' => function ($q) use ($client, $date, $scheduleService) {
                    [$dayStartUtc, $dayEndUtc] = $scheduleService->utcDayWindow($date);

                    $administrationQuery = $q->getQuery()->effectiveClinicalEvidence();
                    $this->governanceScope->scopeCanonicalClientMedicationRows(
                        $administrationQuery,
                        $client->site_id ? [(int) $client->site_id] : [],
                        false,
                    )->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                        $query->whereBetween('scheduled_for', [$dayStartUtc, $dayEndUtc])
                            ->orWhereBetween('administered_at', [$dayStartUtc, $dayEndUtc]);
                    });
                },
                'administrations.administeredBy:id,name',
                'administrations.witnessedBy:id,name',
                'administrations.attachments.uploadedBy:id,name',
            ])->get();

        $scheduled = $medications->where('is_prn', false)->values();
        $prn = $medications->where('is_prn', true)->values();

        $scheduledPayload = $scheduled->map(function ($med) use ($client, $date, $ruleService, $scheduleService) {
            $adminRules = $ruleService->requirementsFor($med);
            $scheduledSlots = $scheduleService->scheduledTimesForDate($med, $date);
            $doseTimes = collect($scheduledSlots)
                ->map(fn (Carbon $slot) => $slot->format('H:i'))
                ->values()
                ->all();
            $matchedAdministrationIds = [];

            // Build administration slots: for each dose_time, find matching admin record
            $administrations = collect($scheduledSlots)->map(function (Carbon $scheduledAt) use ($med, &$matchedAdministrationIds, $scheduleService) {
                [$slotStartUtc, $slotEndUtc] = $scheduleService->utcSlotWindow($scheduledAt);
                // Find an administration record matching this time slot
                $admin = $med->administrations->first(function ($a) use ($slotStartUtc, $slotEndUtc) {
                    $scheduledFor = $this->administrationDateUtc($a, 'scheduled_for');

                    return $scheduledFor?->betweenIncluded($slotStartUtc, $slotEndUtc) === true;
                });

                if ($admin) {
                    $matchedAdministrationIds[] = $admin->id;

                    return $this->serializeAdministration($admin);
                }

                // No record yet: determine if pending or missed
                $now = now($scheduleService->workerTimezone());
                $status = $now->greaterThan($scheduledAt->copy()->addHour()) ? 'missed' : 'pending';

                return $this->serializeAdministration(null, $status, $scheduledAt->toIso8601String());
            })->values();

            // Also include any administration records that don't match a dose_time slot
            $unmatchedAdmins = $med->administrations->filter(function ($a) use ($matchedAdministrationIds) {
                return ! in_array($a->id, $matchedAdministrationIds, true);
            })->map(fn ($a) => $this->serializeAdministration($a));

            return [
                'id' => $med->id,
                'name' => $med->name,
                'dosage' => $med->formatted_dose,
                'frequency' => $med->frequency,
                'route' => $med->route,
                'form' => $med->form,
                'instructions' => $med->instructions,
                'controlled_drug' => $med->controlled_drug,
                'high_risk' => $med->high_risk,
                'witness_required' => $med->requiresWitness() || $adminRules['requires_countersign'],
                'approval_status' => $med->approval_status ?? 'verified',
                'is_administrable' => $med->isAdministrable(),
                'admin_rules' => $adminRules,
                'pharmac_therapeutic_group' => $med->pharmac_therapeutic_group,
                'pharmac_subgroup' => $med->pharmac_subgroup,
                'dose_times' => $doseTimes,
                'administrations' => $administrations->merge($unmatchedAdmins)->values(),
                'scan_verification' => $this->buildMedicationScanPayload($client, $med),
                'stock' => $med->stock ? [
                    'on_hand' => $med->stock->on_hand,
                    'unit' => $med->stock->unit,
                ] : null,
            ];
        })->values();

        $prnPayload = $prn->map(function ($med) use ($client, $ruleService) {
            $adminRules = $ruleService->requirementsFor($med);

            return [
                'id' => $med->id,
                'name' => $med->name,
                'dosage' => $med->formatted_dose,
                'indication' => $med->indication,
                'max_per_day' => $med->max_per_day,
                'prn_count_24h' => $med->prn_count_last24_hours,
                'prn_remaining' => $med->prn_remaining,
                'controlled_drug' => $med->controlled_drug,
                'high_risk' => $med->high_risk,
                'witness_required' => $med->requiresWitness() || $adminRules['requires_countersign'],
                'approval_status' => $med->approval_status ?? 'verified',
                'is_administrable' => $med->isAdministrable(),
                'admin_rules' => $adminRules,
                'pharmac_therapeutic_group' => $med->pharmac_therapeutic_group,
                'pharmac_subgroup' => $med->pharmac_subgroup,
                'administrations' => $med->administrations->map(fn ($a) => $this->serializeAdministration($a))->values(),
                'scan_verification' => $this->buildMedicationScanPayload($client, $med),
                'stock' => $med->stock ? [
                    'on_hand' => $med->stock->on_hand,
                    'unit' => $med->stock->unit,
                ] : null,
            ];
        })->values();

        $awaitingVerification = $client->medications()
            ->awaitingVerification()
            ->when(! $includeControlled, fn ($query) => $query->where('controlled_drug', false))
            ->with('stock')
            ->orderBy('name')
            ->get()
            ->map(function (ClientMedication $med) use ($client, $ruleService) {
                $adminRules = $ruleService->requirementsFor($med);

                return [
                    'id' => $med->id,
                    'name' => $med->name,
                    'dosage' => $med->formatted_dose,
                    'frequency' => $med->frequency,
                    'route' => $med->route,
                    'form' => $med->form,
                    'instructions' => $med->instructions,
                    'controlled_drug' => $med->controlled_drug,
                    'high_risk' => $med->high_risk,
                    'witness_required' => $med->requiresWitness() || $adminRules['requires_countersign'],
                    'approval_status' => $med->approval_status,
                    'rejection_reason' => $med->rejection_reason,
                    'is_administrable' => false,
                    'admin_rules' => $adminRules,
                    'pharmac_therapeutic_group' => $med->pharmac_therapeutic_group,
                    'pharmac_subgroup' => $med->pharmac_subgroup,
                    'scan_verification' => $this->buildMedicationScanPayload($client, $med),
                    'stock' => $med->stock ? [
                        'on_hand' => $med->stock->on_hand,
                        'unit' => $med->stock->unit,
                    ] : null,
                ];
            })
            ->values();

        $administrationStatuses = $scheduledPayload
            ->flatMap(fn ($medication) => collect($medication['administrations']))
            ->merge($prnPayload->flatMap(fn ($medication) => collect($medication['administrations'])));

        return [
            'scheduled' => $scheduledPayload,
            'prn' => $prnPayload,
            'awaiting_verification' => $awaitingVerification,
            'attention_alerts' => $this->getClientMedicationAttentionAlerts($client, $includeControlled),
            'inr_records' => $this->getClientInrRecords($client, $includeControlled),
            'syringe_drivers' => $this->getRunningSyringeDrivers($client, $includeControlled),
            'stats' => [
                'total_scheduled' => $scheduled->count(),
                'total_prn' => $prn->count(),
                'given' => $administrationStatuses->where('status', 'given')->count(),
                'refused' => $administrationStatuses->where('status', 'refused')->count(),
                'withheld' => $administrationStatuses->where('status', 'withheld')->count(),
                'missed' => $administrationStatuses->where('status', 'missed')->count(),
                'pending' => $administrationStatuses->where('status', 'pending')->count(),
            ],
            'settings' => [
                'suppress_med_admin_alerts' => (bool) $client->suppress_med_admin_alerts,
                'med_alerts_suppressed_reason' => $client->med_alerts_suppressed_reason,
                'chart_review_interval_months' => $client->chart_review_interval_months,
                'next_chart_review_date' => $client->next_chart_review_date?->toDateString(),
                'care_level' => $client->care_level,
            ],
        ];
    }

    private function administrationDateUtc(ClientMedicationAdministration $administration, string $column): ?\Illuminate\Support\Carbon
    {
        $raw = $administration->getRawOriginal($column);

        return $raw
            ? \Illuminate\Support\Carbon::parse((string) $raw, 'UTC')
            : null;
    }

    private function getActiveInteractions(Client $client): array
    {
        $medicationNames = $client->medications()
            ->active()
            ->pluck('name')
            ->map(fn ($name) => strtolower($name))
            ->toArray();

        if (count($medicationNames) < 2) {
            return [];
        }

        $interactions = MedicationInteraction::active()
            ->where(function ($query) use ($medicationNames) {
                foreach ($medicationNames as $name) {
                    $query->orWhere(function ($q) use ($name, $medicationNames) {
                        $q->where(function ($inner) use ($name) {
                            $inner->whereRaw('LOWER(medication_a) LIKE ?', ["%{$name}%"]);
                        })->where(function ($inner) use ($medicationNames, $name) {
                            foreach ($medicationNames as $otherName) {
                                if ($otherName !== $name) {
                                    $inner->orWhereRaw('LOWER(medication_b) LIKE ?', ["%{$otherName}%"]);
                                }
                            }
                        });
                    });
                }
            })
            ->get();

        return $interactions->map(fn ($i) => [
            'drug_a' => $i->medication_a,
            'drug_b' => $i->medication_b,
            'severity' => $i->severity,
            'description' => $i->description,
        ])->toArray();
    }

    // ─── PRN Records ───────────────────────────────────────
    public function prn(Request $request)
    {
        $user = $request->user();
        $canViewControlled = $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $viewableClientIds = $this->medicationViewableClientIds($user);
        $siteFilter = $request->integer('site_id') ?: null;
        $clientFilter = $request->integer('client_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $user,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            $siteFilter,
            $clientFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $viewableClientIds = Client::query()
            ->whereIn('id', $viewableClientIds)
            ->whereIn('site_id', $readerSiteIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $search = trim((string) $request->string('q')) ?: null;
        $scheduleService = app(MarScheduleService::class);
        $timezone = $scheduleService->workerTimezone();
        $now = Carbon::now($timezone);

        // The Site picker and explicit filter must use the same medication
        // visibility boundary as every PRN data query. Assigned-only workers
        // remain constrained to current HR Sites; explicitly unrestricted
        // medication viewers retain the existing all-Sites behaviour.
        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter !== null
            ? $sites->firstWhere('id', $siteFilter)
            : null;

        // Date anchor + lookback window for the register (mirrors the meds/today
        // hero day-stepper). The register ends on the selected day and looks back
        // `range` days; the page filters by tab/search/status client-side.
        $today = $now->copy()->startOfDay();
        $anchorYmd = $request->string('date')->toString();
        try {
            $anchor = $anchorYmd !== '' ? Carbon::parse($anchorYmd, $timezone)->startOfDay() : $today->copy();
        } catch (\Throwable) {
            $anchor = $today->copy();
        }
        $isToday = $anchor->isSameDay($today);
        $rangeDays = max(1, min(90, $request->integer('range') ?: 30));
        $windowStart = $anchor->copy()->subDays($rangeDays - 1)->startOfDay();
        $windowEnd = $anchor->copy()->endOfDay();

        // Eager loads shared by the register + History archive (both serialize
        // through serializePrnAdministration()).
        $prnWith = [
            'client:id,first_name,last_name,room_id',
            'client.room:id,name',
            'client.site:id,name',
            'medication:id,name,dosage,route,max_per_day,indication,controlled_drug,deleted_at',
            'administeredBy:id,name',
            'prnEffectiveness.reviewedByUser:id,name',
        ];

        // The register — recent PRN-given administrations within the window
        // (capped working view; the History tab is the paginated full archive).
        $administrations = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $readerSiteIds,
            false,
        )
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->when(! $canViewControlled, fn ($query) => $this->governanceScope->scopeWithoutControlledMedicationRows($query))
            ->when($viewableClientIds !== null, fn ($q) => $q->whereIn('client_id', $viewableClientIds))
            ->when($siteFilter, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteFilter)))
            ->when($clientFilter, fn ($q) => $q->where('client_id', $clientFilter))
            ->whereBetween('administered_at', [$windowStart->copy()->utc(), $windowEnd->copy()->utc()])
            ->with($prnWith)
            ->latest('administered_at')
            ->limit(200)
            ->get()
            ->map(fn (ClientMedicationAdministration $a) => $this->serializePrnAdministration($a, $timezone))
            ->all();

        // Pending effectiveness reviews — given PRN doses with no review yet
        // (PrnFollowUp shape for the reused PrnEffectDialog).
        $pendingReviews = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $readerSiteIds,
            false,
        )
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->when(! $canViewControlled, fn ($query) => $this->governanceScope->scopeWithoutControlledMedicationRows($query))
            ->when($viewableClientIds !== null, fn ($q) => $q->whereIn('client_id', $viewableClientIds))
            ->when($siteFilter, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteFilter)))
            ->when($clientFilter, fn ($q) => $q->where('client_id', $clientFilter))
            ->where('status', 'given')
            ->where('administered_at', '>=', $now->copy()->subHours(24)->utc())
            ->whereDoesntHave('prnEffectiveness')
            ->with(['client:id,first_name,last_name', 'medication:id,name,controlled_drug,deleted_at'])
            ->latest('administered_at')
            ->limit(50)
            ->get()
            ->map(function (ClientMedicationAdministration $a) use ($timezone) {
                $at = $a->getRawOriginal('administered_at') ? $this->boardPayload->rawUtcInstant($a, 'administered_at') : null;

                return [
                    'administration_id' => $a->id,
                    'client_id' => $a->client_id,
                    'medication_name' => $a->medication?->historicalDisplayName(),
                    'is_controlled' => (bool) ($a->medication?->controlled_drug ?? false),
                    'dose_given' => $a->dose_given,
                    'given_at' => $at?->toIso8601String(),
                    'given_time' => $at ? $at->copy()->timezone($timezone)->format('H:i') : null,
                    'check_at' => null,
                ];
            })->all();

        // All PRN clients for the active site drive the Client filter dropdown;
        // the data lists (near-limit meds, record wizard) honour the client filter.
        $siteClientIds = ClientMedication::active()->prn()
            ->when(! $canViewControlled, fn ($query) => $query->where('controlled_drug', false))
            ->when($viewableClientIds !== null, fn ($q) => $q->whereIn('client_id', $viewableClientIds))
            ->when($siteFilter, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteFilter)))
            ->distinct()->pluck('client_id')->all();
        $dataClientIds = $clientFilter
            ? array_values(array_intersect($siteClientIds, [$clientFilter]))
            : $siteClientIds;

        // BK3 — enrich near/over-limit PRN meds with today's per-dose timeline
        // (derived; no schema) and, for over-limit meds, any incident already
        // raised by MedicationIncidentIntegrationService (matched on its
        // deterministic title so the lookup is schema-robust).
        $boardPrn = $this->boardPayload->prnMedications($dataClientIds, $now, $canViewControlled);
        $riskMedIds = array_values(array_filter(array_map(
            fn ($m) => ($m['near_limit'] || $m['over_limit']) ? $m['id'] : null,
            $boardPrn,
        )));
        $dosesByMed = [];
        if (! empty($riskMedIds)) {
            $this->governanceScope->scopeCanonicalClientMedicationRows(
                ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
                $readerSiteIds,
                false,
            )
                ->whereIn('client_medication_id', $riskMedIds)
                ->where('status', 'given')
                ->where('administered_at', '>=', $now->copy()->subDay()->utc())
                ->with(['administeredBy:id,name', 'prnEffectiveness'])
                ->orderBy('administered_at')
                ->get()
                ->each(function (ClientMedicationAdministration $d) use (&$dosesByMed, $timezone) {
                    $at = $d->getRawOriginal('administered_at') ? $this->boardPayload->rawUtcInstant($d, 'administered_at') : null;
                    $dosesByMed[$d->client_medication_id][] = [
                        'id' => $d->id,
                        'time' => $at ? $at->copy()->timezone($timezone)->format('H:i') : null,
                        'date_label' => $at ? $at->copy()->timezone($timezone)->format('j M') : null,
                        'dose' => $d->dose_given,
                        'given_by' => $d->administeredBy?->name,
                        'effectiveness' => $d->prnEffectiveness?->effectiveness,
                        'effectiveness_label' => $d->prnEffectiveness?->effectiveness_label,
                    ];
                });
        }
        $boardPrn = array_map(function (array $m) use ($dosesByMed, $now) {
            $m['today_doses'] = $dosesByMed[$m['id']] ?? [];
            $m['over_limit_incident'] = null;
            if ($m['over_limit']) {
                $incident = ClientIncident::query()
                    ->where('client_id', $m['client_id'])
                    ->where('title', 'PRN limit exceeded: '.$m['name'])
                    ->where('created_at', '>=', $now->copy()->subDays(30))
                    ->latest('occurred_at')
                    ->first();
                if ($incident) {
                    $m['over_limit_incident'] = [
                        'id' => $incident->id,
                        'status' => $incident->status,
                        'occurred_label' => $incident->occurred_at?->format('j M'),
                        'url' => '/clients/'.$m['client_id'].'/incidents',
                    ];
                }
            }

            return $m;
        }, $boardPrn);

        // BK2 — History: the paginated, server-filtered full archive (the
        // register's 200-row cap is not enough here). Honours the hero
        // date/site/client/q plus its own chip filters; serialized through the
        // same helper as the register so the detail modal + row menu work.
        $historyEff = in_array($request->string('history_eff')->toString(), ['effective', 'partially_effective', 'not_effective', 'review_due'], true)
            ? $request->string('history_eff')->toString()
            : null;
        $history = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $readerSiteIds,
            false,
        )
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->when(! $canViewControlled, fn ($query) => $this->governanceScope->scopeWithoutControlledMedicationRows($query))
            ->when($viewableClientIds !== null, fn ($q) => $q->whereIn('client_id', $viewableClientIds))
            ->when($siteFilter, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteFilter)))
            ->when($clientFilter, fn ($q) => $q->where('client_id', $clientFilter))
            ->whereBetween('administered_at', [$windowStart->copy()->utc(), $windowEnd->copy()->utc()])
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('medication', fn ($m) => $m->where('name', 'like', "%{$search}%"))
                ->orWhereHas('client', fn ($c) => $c->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]))))
            ->when($request->integer('history_med') ?: null, fn ($q, $id) => $q->where('client_medication_id', $id))
            ->when($request->integer('history_given_by') ?: null, fn ($q, $id) => $q->where('administered_by', $id))
            ->when($request->boolean('history_cd'), fn ($q) => $q->whereHas('medication', fn ($m) => $m->where('controlled_drug', true)))
            ->when($request->boolean('history_esc'), fn ($q) => $q->whereHas('prnEffectiveness', fn ($e) => $e->where('escalation_needed', true)))
            ->when($historyEff === 'review_due', fn ($q) => $q->whereDoesntHave('prnEffectiveness'))
            ->when($historyEff && $historyEff !== 'review_due', fn ($q) => $q->whereHas('prnEffectiveness', fn ($e) => $e->where('effectiveness', $historyEff)))
            ->with($prnWith)
            ->latest('administered_at')
            ->paginate(25, ['*'], 'history_page')
            ->withQueryString();

        $giverIds = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $readerSiteIds,
            false,
        )
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->when(! $canViewControlled, fn ($query) => $this->governanceScope->scopeWithoutControlledMedicationRows($query))
            ->when($viewableClientIds !== null, fn ($q) => $q->whereIn('client_id', $viewableClientIds))
            ->when($siteFilter, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteFilter)))
            ->when($clientFilter, fn ($q) => $q->where('client_id', $clientFilter))
            ->whereBetween('administered_at', [$windowStart->copy()->utc(), $windowEnd->copy()->utc()])
            ->whereNotNull('administered_by')
            ->distinct()
            ->pluck('administered_by')
            ->all();

        return Inertia::render('emar/PrnRecords', [
            'administrations' => $administrations,
            'pending_reviews' => $pendingReviews,
            'prn_medications' => $boardPrn,
            'history' => [
                'data' => collect($history->items())->map(fn (ClientMedicationAdministration $a) => $this->serializePrnAdministration($a, $timezone))->all(),
                'meta' => [
                    'current_page' => $history->currentPage(),
                    'last_page' => $history->lastPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total(),
                    'from' => $history->firstItem(),
                    'to' => $history->lastItem(),
                ],
            ],
            'history_givers' => User::whereIn('id', $giverIds)->orderBy('name')->get(['id', 'name']),
            'history_active' => [
                'med' => $request->integer('history_med') ?: null,
                'eff' => $historyEff,
                'cd' => $request->boolean('history_cd'),
                'esc' => $request->boolean('history_esc'),
                'given_by' => $request->integer('history_given_by') ?: null,
            ],
            'clients' => $this->boardPayload->clientsPayload($siteClientIds),
            'witnesses' => $this->governanceScope->controlledWitnessPicker($accessibleSiteIds, $user->id),
            'board_user' => $this->boardPayload->boardUser($user),
            'can' => [
                'record' => $user->canDo('medications.administer.record'),
                'record_controlled' => $user->canDo('medications.controlled.record'),
            ],
            'date' => $anchor->toDateString(),
            'today' => $today->toDateString(),
            'is_today' => $isToday,
            'date_label' => $anchor->isoFormat('ddd D MMM'),
            'range' => $rangeDays,
            'client_id' => $clientFilter,
            'q' => $search,
            'sites' => $sites,
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
        ]);
    }

    /**
     * Flat row shape for a PRN administration, shared by the register, the
     * History archive, and the detail modal (PrnDetailDialog's contract).
     */
    private function serializePrnAdministration(ClientMedicationAdministration $a, string $timezone): array
    {
        $at = $a->getRawOriginal('administered_at') ? $this->boardPayload->rawUtcInstant($a, 'administered_at') : null;
        $eff = $a->prnEffectiveness;

        return [
            'id' => $a->id,
            'client_id' => $a->client_id,
            'client_name' => $a->client ? trim($a->client->first_name.' '.$a->client->last_name) : 'Unknown',
            'client_room' => $a->client?->room?->name,
            'client_site' => $a->client?->site?->name,
            'client_medication_id' => $a->client_medication_id,
            'medication_name' => $a->medication?->historicalDisplayName(),
            'route' => $a->medication?->route,
            'prescribed_dose' => $a->medication?->dosage,
            'controlled_drug' => (bool) ($a->medication?->controlled_drug ?? false),
            'dose_given' => $a->dose_given,
            'reason' => $a->reason,
            'indication' => $a->medication?->indication,
            'notes' => $a->notes,
            'status' => $a->status,
            'administered_at' => $at?->toIso8601String(),
            'given_time' => $at ? $at->copy()->timezone($timezone)->format('H:i') : null,
            'given_date' => $at ? $at->copy()->timezone($timezone)->format('j M') : null,
            'given_by' => $a->administeredBy?->name,
            'mar_url' => EmarUrl::mar($a->client_id),
            'baseline' => array_filter([
                'blood_glucose_level' => $a->blood_glucose_level,
                'pulse_bpm' => $a->pulse_bpm,
                'blood_pressure_systolic' => $a->blood_pressure_systolic,
                'blood_pressure_diastolic' => $a->blood_pressure_diastolic,
                'insulin_units_given' => $a->insulin_units_given,
            ], fn ($v) => $v !== null),
            'effectiveness' => $eff?->effectiveness,
            'effectiveness_label' => $eff?->effectiveness_label,
            'effectiveness_detail' => $eff ? [
                'effectiveness' => $eff->effectiveness,
                'label' => $eff->effectiveness_label,
                'review_minutes_after' => $eff->review_minutes_after,
                'observations' => $eff->observations,
                'escalation_needed' => (bool) $eff->escalation_needed,
                'escalation_action' => $eff->escalation_action,
                'reviewed_by' => $eff->reviewedByUser?->name,
                'reviewed_at' => $eff->reviewed_at?->toIso8601String(),
                'reviewed_label' => $eff->reviewed_at ? $eff->reviewed_at->copy()->timezone($timezone)->format('H:i · j M') : null,
            ] : null,
        ];
    }

    // ─── Controlled Drugs ──────────────────────────────────
    public function controlled(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteFilter = $request->integer('site_id') ?: null;
        $clientFilter = $request->integer('client_id') ?: null;
        $search = trim((string) $request->string('q')) ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            $siteFilter,
            $clientFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $byClient = fn ($q) => $q->where('client_id', $clientFilter);

        // Date anchor (mirrors the meds/today + PRN hero day-stepper). The register
        // (stock on-hand) and reconciliation always show today; movements — Recent
        // Entries, Destructions and the Audit trail — are scoped to the selected day.
        $timezone = app(MarScheduleService::class)->workerTimezone();
        $now = Carbon::now($timezone);
        $today = $now->copy()->startOfDay();
        $anchorYmd = $request->string('date')->toString();
        try {
            $anchor = $anchorYmd !== '' ? Carbon::parse($anchorYmd, $timezone)->startOfDay() : $today->copy();
        } catch (\Throwable) {
            $anchor = $today->copy();
        }
        $isToday = $anchor->isSameDay($today);
        $dayStart = $anchor->copy()->startOfDay()->utc();
        $dayEnd = $anchor->copy()->endOfDay()->utc();

        $controlledMedications = ClientMedication::query()
            ->active()
            ->controlled()
            ->whereHas('client', fn ($q) => $q->whereIn('site_id', $readerSiteIds))
            ->when($clientFilter, $byClient)
            ->with([
                'client:id,first_name,last_name',
                'stock',
            ])
            ->orderBy('name')
            ->get();

        // Last balance check per controlled drug — computed server-side over the
        // full register (NOT the day-scoped recentEntries) so Reconciliation and the
        // overdue-check alert stay current regardless of the selected day (BK-recon).
        // TODO(Gx): a scheduled job to *escalate* CDs with no balance check in N days
        // (a MedicationDashboardAlert via MedicationAlertService) does not exist yet —
        // the page derives the overdue count live from overdue_check below, which covers
        // the UI; only the background escalation remains. See docs/CONTROLLED_GAP_ANALYSIS.md.
        $lastChecks = ClientControlledDrugEntry::query()
            ->where('entry_type', 'balance_check')
            ->whereIn('client_medication_id', $controlledMedications->pluck('id')->all())
            ->selectRaw('client_medication_id, MAX(recorded_at) as last_at')
            ->groupBy('client_medication_id')
            ->pluck('last_at', 'client_medication_id');

        $recentEntries = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientControlledDrugEntry::query(),
            $readerSiteIds,
            false,
        )
            ->when($clientFilter, $byClient)
            ->whereBetween('recorded_at', [$dayStart, $dayEnd])
            ->with(['client:id,first_name,last_name', 'medication:id,name,controlled_drug,deleted_at', 'recordedBy:id,name', 'witnessedBy:id,name'])
            ->latest('recorded_at')
            ->limit(100)
            ->get();

        $discrepancies = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientControlledDrugDiscrepancy::query()->whereIn('status', ['open', 'under_review']),
            $readerSiteIds,
            false,
        )
            ->when($clientFilter, $byClient)
            ->with([
                'client:id,first_name,last_name',
                'medication:id,name',
                'reportedBy:id,name',
                'witnessedBy:id,name',
                'resolvedBy:id,name',
                'incident:id,title',
                'attachments.uploadedBy:id,name',
            ])
            ->latest()
            ->get();

        $destructions = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationDestruction::query()->controlled(),
            $readerSiteIds,
        )
            ->when($clientFilter, $byClient)
            ->whereBetween('destroyed_at', [$dayStart, $dayEnd])
            ->with(['client:id,first_name,last_name', 'destroyedByUser:id,name', 'witness1:id,name', 'witness2:id,name'])
            ->latest('destroyed_at')
            ->limit(50)
            ->get();

        $lossReports = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ControlledDrugLossReport::query(),
            $readerSiteIds,
        )
            ->when($clientFilter, $byClient)
            ->with([
                'client:id,first_name,last_name',
                'discoveredBy:id,name',
                'incident:id,title',
                'attachments.uploadedBy:id,name',
            ])
            ->latest()
            ->get();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $clients = $this->governanceScope->clientPicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/ControlledDrugs', [
            'can_record' => $request->user()?->canDo('medications.controlled.record') ?? false,
            'medications' => $controlledMedications->map(function (ClientMedication $m) use ($lastChecks, $now) {
                $lastCheckRaw = $lastChecks[$m->id] ?? null;
                $lastCheck = $lastCheckRaw ? Carbon::parse($lastCheckRaw) : null;
                $daysSince = $lastCheck ? (int) floor($lastCheck->copy()->diffInDays($now)) : null;

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'form' => $m->dose_unit ?? $m->route,
                    'strength' => $m->dosage,
                    'controlled_drug' => (bool) $m->controlled_drug,
                    'client_id' => $m->client_id,
                    'client_name' => $m->client ? trim($m->client->first_name.' '.$m->client->last_name) : 'Unknown',
                    // Always-current reconciliation state (decoupled from the day-scoped
                    // movements list) so the Reconciliation tab + overdue alert are correct
                    // whatever day is selected. Overdue = never checked or ≥ 7 days ago.
                    'last_balance_check_at' => $lastCheck?->toIso8601String(),
                    'days_since_check' => $daysSince,
                    'overdue_check' => $daysSince === null || $daysSince >= 7,
                    'stock' => $m->stock ? [
                        'on_hand' => $m->stock->on_hand,
                        'unit' => $m->stock->unit,
                        'last_counted_at' => $m->stock->last_counted_at instanceof \DateTimeInterface ? $m->stock->last_counted_at->toIso8601String() : null,
                        'expiry_date' => $m->stock->expiry_date instanceof \DateTimeInterface ? $m->stock->expiry_date->toDateString() : ($m->stock->expiry_date ?: null),
                        'batch_number' => $m->stock->batch_number,
                        'reorder_level' => $m->stock->reorder_level,
                    ] : null,
                    // CD schedule (2/3/4) — set when recording a movement; null until classified.
                    'schedule' => $m->cd_schedule,
                ];
            })->values(),
            'recentEntries' => $recentEntries->map(fn (ClientControlledDrugEntry $e) => [
                'id' => $e->id,
                'client_id' => $e->client_id,
                'client_name' => $e->client ? trim($e->client->first_name.' '.$e->client->last_name) : 'Unknown',
                'medication_name' => $e->medication?->historicalDisplayName(),
                'controlled_drug' => (bool) ($e->medication?->controlled_drug ?? true),
                'entry_type' => $e->entry_type,
                'quantity' => $e->quantity,
                'unit' => $e->unit,
                'on_hand_before' => $e->on_hand_before,
                'on_hand_after' => $e->on_hand_after,
                'batch_number' => $e->batch_number,
                'expiry_date' => $e->expiry_date instanceof \DateTimeInterface ? $e->expiry_date->toDateString() : ($e->expiry_date ?: null),
                'notes' => $e->notes,
                'recorded_at' => $e->recorded_at instanceof \DateTimeInterface ? $e->recorded_at->toIso8601String() : null,
                'recorded_by_name' => $e->recordedBy?->name,
                'witnessed_by_name' => $e->witnessedBy?->name,
            ])->values(),
            'discrepancies' => $discrepancies->map(fn (ClientControlledDrugDiscrepancy $discrepancy) => [
                'id' => $discrepancy->id,
                'client' => $discrepancy->client ? [
                    'id' => $discrepancy->client->id,
                    'first_name' => $discrepancy->client->first_name,
                    'last_name' => $discrepancy->client->last_name,
                ] : null,
                'medication' => $discrepancy->medication ? [
                    'id' => $discrepancy->medication->id,
                    'name' => $discrepancy->medication->name,
                ] : null,
                'difference' => $discrepancy->difference,
                'on_hand_before' => $discrepancy->on_hand_before,
                'on_hand_after' => $discrepancy->on_hand_after,
                'reason' => $discrepancy->reason,
                'notes' => $discrepancy->notes,
                'status' => $discrepancy->status,
                'reported_at' => $discrepancy->reported_at?->toIso8601String(),
                'reported_by_name' => $discrepancy->reportedBy?->name,
                'witnessed_by_name' => $discrepancy->witnessedBy?->name,
                'immediate_action_taken' => $discrepancy->immediate_action_taken,
                'resolved_at' => $discrepancy->resolved_at instanceof \DateTimeInterface ? $discrepancy->resolved_at->toIso8601String() : null,
                'resolved_by_name' => $discrepancy->resolvedBy?->name,
                'resolution_notes' => $discrepancy->resolution_notes,
                'incident_id' => $discrepancy->incident_id,
                'incident_title' => $discrepancy->incident?->title,
                'attachments' => $discrepancy->attachments
                    ->map(fn ($attachment) => $this->serializeSupportingAttachment(
                        $attachment,
                        'api.medications.supporting_attachments.download',
                    ))
                    ->values()
                    ->all(),
            ])->values(),
            'destructions' => $destructions->map(fn (MedicationDestruction $d) => [
                'id' => $d->id,
                'client_id' => $d->client_id,
                'client_name' => $d->client ? trim($d->client->first_name.' '.$d->client->last_name) : 'Unknown',
                'medication_name' => $d->medication_name,
                'quantity' => $d->quantity,
                'unit' => $d->unit,
                'reason' => $d->reason,
                'disposal_method' => $d->disposal_method,
                'destroyed_at' => $d->destroyed_at instanceof \DateTimeInterface ? $d->destroyed_at->toIso8601String() : null,
                'destroyed_by_name' => $d->destroyedByUser?->name,
                'witness_name' => $d->witness1?->name,
                'witness_2_name' => $d->witness2?->name,
                'authorised_by_name' => $d->authorised_by_name,
                'notes' => $d->notes,
            ])->values(),
            'lossReports' => $lossReports->map(fn (ControlledDrugLossReport $report) => [
                'id' => $report->id,
                'client' => $report->client ? [
                    'id' => $report->client->id,
                    'first_name' => $report->client->first_name,
                    'last_name' => $report->client->last_name,
                ] : null,
                'medication_name' => $report->medication_name,
                'quantity_lost' => $report->quantity_lost,
                'unit' => $report->unit,
                'circumstances' => $report->circumstances,
                'immediate_action_taken' => $report->immediate_action_taken,
                'accountable_officer_name' => $report->accountable_officer_name,
                'reported_to_police' => (bool) $report->reported_to_police,
                'police_reference' => $report->police_reference,
                'reported_to_pharmacy' => (bool) $report->reported_to_pharmacy,
                'pharmacy_name' => $report->pharmacy_name,
                'reported_to_regulator' => (bool) $report->reported_to_regulator,
                'regulator_name' => $report->regulator_name,
                'regulator_reference' => $report->regulator_reference,
                'regulator_notified_at' => $report->regulator_notified_at?->toIso8601String(),
                'discovered_at' => $report->discovered_at?->toIso8601String(),
                'discovered_by_name' => $report->discoveredBy?->name,
                'incident_id' => $report->incident_id,
                'incident_title' => $report->incident?->title,
                'investigation_status' => $report->investigation_status,
                'investigation_notes' => $report->investigation_notes,
                'resolution_outcome' => $report->resolution_outcome,
                'attachments' => $report->attachments
                    ->map(fn ($attachment) => $this->serializeSupportingAttachment(
                        $attachment,
                        'api.medications.supporting_attachments.download',
                    ))
                    ->values()
                    ->all(),
            ])->values(),
            'staff' => $this->governanceScope->controlledWitnessPicker($accessibleSiteIds, $actor->id),
            'clients' => $clients,
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
            'date' => $anchor->toDateString(),
            'today' => $today->toDateString(),
            'is_today' => $isToday,
            'date_label' => $anchor->isoFormat('ddd D MMM'),
            'client_id' => $clientFilter,
            'q' => $search,
            'current_user' => ['id' => $actor->id, 'name' => $actor->name],
            'can' => [
                'manage_evidence' => (bool) $request->user()
                    && $request->user()->canDo('medications.controlled.record'),
            ],
        ]);
    }

    // ─── Medications Database ──────────────────────────────
    /**
     * Lazy-loaded detail for a single register row (opened in the detail modal):
     * real stock-movement history (administrations + completed counts) and
     * per-client interaction detail (real MedicationInteraction records). Kept
     * off the whole-register payload to keep that lean; mirrors the on-demand
     * allergies fetch used by the Add-medication wizard. No invented tables.
     */
    public function medicationDetail(Request $request, ClientMedication $medication)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $medication = $this->governanceScope->readableMedication($actor, (int) $medication->id);
        $canViewControlled = $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        abort_if($medication->controlled_drug && ! $canViewControlled, 404);

        // ── Stock-movement history ──────────────────────────────────────────
        // Each administered dose is a real stock-out event; completed scheduled
        // counts are reconciliation events. Merged into one reverse-chron feed.
        $administrations = $medication->administrations()
            ->effectiveClinicalEvidence()
            ->with('administeredBy:id,name')
            ->where('client_id', $medication->client_id)
            ->whereNotNull('administered_at')
            ->latest('administered_at')
            ->limit(10)
            ->get()
            ->map(fn (ClientMedicationAdministration $a) => [
                'type' => 'administration',
                'ts' => $a->administered_at?->getTimestamp() ?? 0,
                'at' => $a->administered_at?->format('j M Y, g:ia'),
                'status' => $a->status,
                'label' => $a->dose_given ?: ucfirst((string) ($a->status ?? 'dose')),
                'by' => $a->administeredBy?->name,
                'note' => $a->reason ?: $a->notes,
            ]);

        $counts = collect();
        if (! $medication->controlled_drug
            || $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $counts = MedicationScheduledStockCount::query()
                ->where('client_medication_id', $medication->id)
                ->where('client_id', $medication->client_id)
                ->whereNotNull('completed_at')
                ->with('completedBy:id,name')
                ->latest('completed_at')
                ->limit(5)
                ->get()
                ->map(function (MedicationScheduledStockCount $c) {
                    $disc = $c->discrepancy;

                    return [
                        'type' => 'count',
                        'ts' => $c->completed_at?->getTimestamp() ?? 0,
                        'at' => $c->completed_at?->format('j M Y, g:ia'),
                        'status' => $disc ? 'discrepancy' : 'counted',
                        'label' => 'Counted '.$c->actual_quantity.($c->expected_quantity !== null ? ' (expected '.$c->expected_quantity.')' : ''),
                        'by' => $c->completedBy?->name,
                        'note' => $disc ? 'Discrepancy '.($disc > 0 ? '+' : '').$disc : ($c->notes ?: null),
                    ];
                });
        }

        $movements = $administrations->concat($counts)
            ->sortByDesc('ts')
            ->take(12)
            ->map(fn (array $m) => collect($m)->except('ts')->all())
            ->values()
            ->all();

        // ── Per-client interaction detail ───────────────────────────────────
        // Real MedicationInteraction records, intersected with the client's
        // other current medications so only relevant pairs are surfaced.
        $otherNames = ClientMedication::query()
            ->current()
            ->where('client_id', $medication->client_id)
            ->where('id', '!=', $medication->id)
            ->when(! $canViewControlled, fn ($query) => $query->where('controlled_drug', false))
            ->pluck('name')
            ->filter()
            ->values();

        $interactions = [];
        if ($otherNames->isNotEmpty()) {
            $thisLower = strtolower($medication->name);
            foreach (MedicationInteraction::findForMedication($medication->name) as $rec) {
                $other = str_contains(strtolower($rec->medication_a), $thisLower)
                    ? $rec->medication_b
                    : $rec->medication_a;
                $otherLower = strtolower($other);
                $match = $otherNames->first(fn ($n) => str_contains($otherLower, strtolower($n)) || str_contains(strtolower($n), $otherLower));
                if (! $match) {
                    continue;
                }
                $interactions[] = [
                    'other' => $match,
                    'severity' => $rec->severity,
                    'severity_label' => $rec->severity_info['label'] ?? ucfirst((string) $rec->severity),
                    'description' => $rec->description,
                    'clinical_effects' => $rec->clinical_effects,
                    'management' => $rec->management,
                ];
            }
        }

        return response()->json([
            'movements' => $movements,
            'interactions' => $interactions,
        ]);
    }

    public function medications(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $clientFilter = $request->integer('client_id') ?: null;
        $siteFilter = $request->integer('site_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $user,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            $siteFilter,
            $clientFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $canViewControlled = $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);

        // Flat register of current (non-superseded) medications — the redesigned
        // page filters by tab/search/client/sort entirely client-side, with live
        // facet counts, so the whole register is served at once.
        $meds = ClientMedication::query()
            ->current()
            ->whereHas('client', fn ($query) => $query->whereIn('site_id', $readerSiteIds))
            ->when(! $canViewControlled, fn ($query) => $query->where('controlled_drug', false))
            ->with([
                'client:id,first_name,last_name,site_id',
                'stock',
                'createdByUser:id,name',
                'verifiedByUser:id,name',
                'ceasedByUser:id,name',
            ])
            ->when($clientFilter, fn ($q, $id) => $q->where('client_id', $id))
            ->orderBy('name')
            ->get();

        // Map medication IDs that interact with another current med for the same client.
        $interactionMap = [];
        foreach ($meds->groupBy('client_id') as $clientMeds) {
            $names = $clientMeds->pluck('name')->map(fn ($n) => strtolower($n))->all();
            if (count($names) < 2) {
                continue;
            }

            $clientInteractions = MedicationInteraction::active()
                ->where(function ($query) use ($names) {
                    foreach ($names as $name) {
                        $query->orWhere(function ($q) use ($name, $names) {
                            $q->whereRaw('LOWER(medication_a) LIKE ?', ["%{$name}%"])
                                ->where(function ($inner) use ($names, $name) {
                                    foreach ($names as $other) {
                                        if ($other !== $name) {
                                            $inner->orWhereRaw('LOWER(medication_b) LIKE ?', ["%{$other}%"]);
                                        }
                                    }
                                });
                        });
                    }
                })
                ->get();

            foreach ($clientInteractions as $interaction) {
                foreach ($clientMeds as $med) {
                    $medLower = strtolower($med->name);
                    if (
                        str_contains(strtolower($interaction->medication_a), $medLower) ||
                        str_contains(strtolower($interaction->medication_b), $medLower)
                    ) {
                        $interactionMap[$med->id] = $interaction->severity;
                    }
                }
            }
        }

        $rows = $meds->map(function (ClientMedication $m) use ($interactionMap) {
            $start = $m->start_date instanceof \DateTimeInterface ? $m->start_date->format('Y-m-d') : ($m->start_date ?: null);

            return [
                'id' => $m->id,
                'client_id' => $m->client_id,
                'client_name' => $m->client ? trim($m->client->last_name.', '.$m->client->first_name) : 'Unknown',
                'name' => $m->name,
                'brand_name' => $m->brand_name,
                'dosage' => $m->dosage,
                'dose_unit' => $m->dose_unit,
                'frequency' => $m->frequency,
                'route' => $m->route,
                'form' => $m->form,
                'instructions' => $m->instructions,
                'indication' => $m->indication,
                'prescriber' => $m->prescriber,
                'is_prn' => (bool) $m->is_prn,
                'prn_reason' => $m->prn_reason,
                'max_per_day' => $m->max_per_day,
                'min_hours_between_doses' => $m->min_hours_between_doses,
                'controlled_drug' => (bool) $m->controlled_drug,
                'high_risk' => (bool) $m->high_risk,
                'witness_required' => (bool) $m->witness_required,
                'state' => $m->state,
                'approval_status' => $m->approval_status,
                'rejection_reason' => $m->rejection_reason,
                'pharmac_therapeutic_group' => $m->pharmac_therapeutic_group,
                'start_date' => $start,
                // Verification / lifecycle audit (all pre-existing columns — no migration).
                'created_by_name' => $m->createdByUser?->name,
                'created_at' => $m->created_at?->format('j M Y, g:ia'),
                'verified_by_name' => $m->verifiedByUser?->name,
                'verified_at' => $m->verified_at?->format('j M Y, g:ia'),
                'ceased_by_name' => $m->ceasedByUser?->name,
                'ceased_at' => $m->ceased_at?->format('j M Y'),
                'ceased_reason' => $m->ceased_reason,
                'review_date' => $m->review_date?->format('j M Y'),
                'stock' => $m->stock ? [
                    'on_hand' => $m->stock->on_hand,
                    'unit' => $m->stock->unit,
                    'low' => $m->stock->isLowStock(),
                ] : null,
                'interaction_severity' => $interactionMap[$m->id] ?? null,
            ];
        })->all();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/Medications', [
            'medications' => $rows,
            'clients' => $this->governanceScope->clientPicker($accessibleSiteIds),
            'staff' => $this->governanceScope->staffPicker($accessibleSiteIds),
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
            'witnesses' => $this->governanceScope->controlledWitnessPicker($accessibleSiteIds, $user->id),
            'can' => $this->buildMedicationPermissions($user),
        ]);
    }

    // ─── Stock Management ──────────────────────────────────
    public function stock(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $canViewControlled = $actor->canDo('medications.controlled.view');
        $siteFilter = $request->integer('site_id') ?: null;
        $clientFilter = $request->integer('client_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
            $siteFilter,
            $clientFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $byClient = fn ($q) => $q->where('client_id', $clientFilter);

        $stockModels = ClientMedicationStock::query()
            ->with(['medication' => fn ($q) => $q->with(['client:id,first_name,last_name,site_id,room_id', 'client.site:id,name', 'client.room:id,name'])])
            ->whereHas('medication', fn ($q) => $q->active()
                ->when(! $canViewControlled, fn ($medications) => $medications->where('controlled_drug', false))
                ->whereHas('client', fn ($c) => $c->whereIn('site_id', $readerSiteIds))
                ->when($clientFilter, $byClient))
            ->get();

        // Honest movement history per stock item — sourced from the audit log
        // (AuditableChanges on ClientMedicationStock), no dedicated movements
        // table. One grouped query; the detail modal shows the recent few.
        $movementsByStock = AuditLog::query()
            ->where('auditable_type', (new ClientMedicationStock)->getMorphClass())
            ->whereIn('auditable_id', $stockModels->pluck('id'))
            ->whereIn('action', ['clientmedicationstock.create', 'clientmedicationstock.update'])
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(400)
            ->get()
            ->groupBy('auditable_id');

        $stockItems = $stockModels->map(fn ($s) => [
            'id' => $s->id,
            'medication_id' => $s->client_medication_id,
            'medication_name' => $s->medication?->name,
            'medication_dose' => $s->medication?->dosage,
            'client_name' => trim(($s->medication?->client?->first_name ?? '').' '.($s->medication?->client?->last_name ?? '')),
            'client_id' => $s->medication?->client_id,
            'client_room' => $s->medication?->client?->room?->name,
            'mar_url' => $s->medication?->client_id ? EmarUrl::mar($s->medication->client_id) : null,
            'site_id' => $s->medication?->client?->site_id,
            'site_name' => $s->medication?->client?->site?->name,
            'on_hand' => $s->on_hand !== null
                ? MedicationStockQuantity::toFloat($s->on_hand)
                : null,
            'unit' => $s->unit,
            'reorder_level' => $s->reorder_level,
            'last_counted_at' => $s->last_counted_at?->toIso8601String(),
            'is_low' => $s->isLowStock(),
            'controlled' => (bool) $s->medication?->controlled_drug,
            'storage_condition' => $s->storage_condition ?? 'ambient',
            'requires_cold_chain' => $s->requiresColdChain(),
            'expiry_date' => $s->expiry_date?->toDateString(),
            'batch_number' => $s->batch_number,
            'supplier_name' => $s->supplier_name,
            'reorder_quantity' => $s->reorder_quantity,
            'is_expired' => $s->isExpired(),
            'is_expiring_soon' => $s->isExpiringSoon(30),
            'is_expiring_90' => $s->isExpiringSoon(90),
            'scan_verification' => $s->medication?->client
                ? $this->buildMedicationScanPayload($s->medication->client, $s->medication)
                : null,
            'movements' => ($movementsByStock->get($s->id) ?? collect())
                ->take(6)
                ->map(fn (AuditLog $log) => $this->formatStockMovement($log, $s->unit))
                ->values(),
        ])->values();

        $lowStockCount = $stockItems->where('is_low', true)->count();

        // Controlled-drug reconciliation: register balance (last witnessed
        // balance check) vs physical on-hand, plus any open discrepancy.
        $controlledMedIds = $canViewControlled
            ? $stockModels->filter(fn ($s) => $s->medication?->controlled_drug)->pluck('client_medication_id')->filter()->values()
            : collect();
        $lastChecks = ClientControlledDrugEntry::query()
            ->whereIn('client_medication_id', $controlledMedIds)
            ->where('entry_type', 'balance_check')
            ->with('witnessedBy:id,name')
            ->latest('recorded_at')
            ->get()
            ->groupBy('client_medication_id');
        $openDiscrepancies = ClientControlledDrugDiscrepancy::query()
            ->whereIn('client_medication_id', $controlledMedIds)
            ->whereIn('status', ['open', 'under_review'])
            ->get()
            ->groupBy('client_medication_id');

        $controlledRegister = $canViewControlled
            ? $stockModels
                ->filter(fn ($s) => $s->medication?->controlled_drug)
                ->map(function ($s) use ($lastChecks, $openDiscrepancies) {
                    $check = $lastChecks->get($s->client_medication_id)?->first();
                    $discrepancy = $openDiscrepancies->get($s->client_medication_id)?->first();
                    $registerBalance = $check?->on_hand_after ?? $s->on_hand;

                    return [
                        'id' => $s->id,
                        'medication_id' => $s->client_medication_id,
                        'medication_name' => $s->medication?->name,
                        'client_id' => $s->medication?->client_id,
                        'client_name' => trim(($s->medication?->client?->first_name ?? '').' '.($s->medication?->client?->last_name ?? '')),
                        'cd_class' => $s->medication?->controlled_drug_class,
                        'register_balance' => $registerBalance !== null
                            ? MedicationStockQuantity::toFloat($registerBalance)
                            : null,
                        'on_hand' => $s->on_hand !== null
                            ? MedicationStockQuantity::toFloat($s->on_hand)
                            : null,
                        'unit' => $s->unit,
                        'last_check_at' => $check?->recorded_at instanceof \DateTimeInterface ? $check->recorded_at->toIso8601String() : null,
                        'last_check_witness' => $check?->witnessedBy?->name,
                        'discrepancy' => $discrepancy ? (float) $discrepancy->difference : null,
                    ];
                })->values()
            : collect();

        // Flat pharmacy-order lifecycle — the page renders the 5-stage tracker.
        $pharmacyOrders = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationPharmacyOrder::query(),
            $readerSiteIds,
            false,
        )
            ->with(['client:id,first_name,last_name', 'medication:id,name,controlled_drug'])
            ->when(! $canViewControlled, fn ($orders) => $orders->whereHas(
                'medication',
                fn ($medications) => $medications->where('controlled_drug', false),
            ))
            ->when($clientFilter, $byClient)
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (MedicationPharmacyOrder $o) => [
                'id' => $o->id,
                'medication_id' => $o->client_medication_id,
                'client_name' => $o->client ? trim($o->client->first_name.' '.$o->client->last_name) : 'Unknown',
                'medication_name' => $o->medication?->name,
                'controlled' => (bool) $o->medication?->controlled_drug,
                'pharmacy_name' => $o->pharmacy_name,
                'order_type' => $o->order_type,
                'status' => $o->status,
                'quantity_ordered' => $o->quantity_ordered,
                'quantity_received' => $o->quantity_received,
                'ordered_at' => $o->created_at?->toIso8601String(),
                'submitted_at' => $o->submitted_at?->toIso8601String(),
                'confirmed_at' => $o->confirmed_at?->toIso8601String(),
                'dispensed_at' => $o->dispensed_at?->toIso8601String(),
                'delivered_at' => $o->delivered_at?->toIso8601String(),
                'batch_number' => $o->batch_number,
                'batch_expiry' => $o->batch_expiry instanceof \DateTimeInterface ? $o->batch_expiry->toDateString() : null,
            ])->values();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/StockManagement', [
            'can_record_controlled' => $request->user()?->canDo('medications.controlled.record') ?? false,
            'can_view_controlled' => $canViewControlled,
            'stockItems' => $stockItems,
            'lowStockCount' => $lowStockCount,
            'expiringCount' => $stockItems->where('is_expiring_soon', true)->count(),
            'expiredCount' => $stockItems->where('is_expired', true)->count(),
            'controlledRegister' => $controlledRegister,
            'pharmacyOrders' => $pharmacyOrders,
            'clients' => $this->governanceScope->clientPicker($accessibleSiteIds),
            'activeMedications' => ClientMedication::active()
                ->with('client:id,first_name,last_name')
                ->when(! $canViewControlled, fn ($medications) => $medications->where('controlled_drug', false))
                ->whereHas('client', fn ($q) => $q->whereIn('site_id', $readerSiteIds))
                ->when($clientFilter, $byClient)
                ->orderBy('name')
                ->get(['id', 'name', 'client_id', 'dosage', 'barcode', 'nzulm_code', 'controlled_drug'])
                ->map(fn (ClientMedication $medication) => [
                    'id' => $medication->id,
                    'name' => $medication->name,
                    'client_id' => $medication->client_id,
                    'controlled' => (bool) $medication->controlled_drug,
                    'client' => $medication->client ? [
                        'first_name' => $medication->client->first_name,
                        'last_name' => $medication->client->last_name,
                    ] : null,
                    'scan_verification' => $medication->client
                        ? $this->buildMedicationScanPayload($medication->client, $medication)
                        : null,
                ])
                ->values(),
            'witnesses' => $this->governanceScope->controlledWitnessPicker($accessibleSiteIds, $actor->id),
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
            'client_id' => $clientFilter,
        ]);
    }

    /**
     * Format one ClientMedicationStock audit-log entry into a movement row for
     * the stock detail modal. This is an honest change-ledger derivation from the
     * recorded before/after snapshot — no invented quantities or movement table.
     *
     * @return array<string, mixed>
     */
    private function formatStockMovement(AuditLog $log, ?string $unit): array
    {
        $meta = $log->meta ?? [];
        $after = $meta['after'] ?? [];
        $before = $meta['before'] ?? [];
        $fields = $meta['fields'] ?? array_keys($after);
        $isCreate = str_ends_with($log->action, '.create');

        $delta = null;
        if (array_key_exists('on_hand', $after) && is_numeric($after['on_hand'])) {
            $to = MedicationStockQuantity::normalize($after['on_hand']);
            $from = MedicationStockQuantity::normalize($isCreate ? 0 : ($before['on_hand'] ?? 0));
            $delta = MedicationStockQuantity::toFloat(MedicationStockQuantity::subtract($to, $from));
        }

        $notes = $after['notes'] ?? null;
        $reason = is_string($notes) ? preg_replace('/^Stock adjustment:\s*/', '', $notes) : null;

        if ($isCreate) {
            $type = 'created';
            $summary = 'Stock record created';
        } elseif (in_array('on_hand', $fields, true)) {
            // An on_hand change with a logged reason came through the adjust/count
            // path; one without a reason is a receipt increment.
            if (in_array('notes', $fields, true) && is_string($notes) && str_starts_with($notes, 'Stock adjustment')) {
                $isCount = $reason !== null && stripos($reason, 'count') !== false;
                $type = $isCount ? 'counted' : 'adjusted';
                $summary = $reason !== '' && $reason !== null ? $reason : ($isCount ? 'Stock counted' : 'Stock adjusted');
            } else {
                $type = ($delta ?? 0) >= 0 ? 'received' : 'removed';
                $summary = $type === 'received' ? 'Stock received' : 'Stock removed';
            }
        } else {
            $type = 'updated';
            $labelMap = [
                'reorder_level' => 'reorder level',
                'reorder_quantity' => 'reorder qty',
                'expiry_date' => 'expiry',
                'batch_number' => 'batch',
                'supplier_name' => 'supplier',
                'storage_condition' => 'storage',
            ];
            $labels = array_values(array_intersect_key($labelMap, array_flip($fields)));
            $summary = $labels ? 'Updated '.implode(', ', $labels) : 'Stock details updated';
        }

        return [
            'id' => $log->id,
            'at' => $log->created_at?->toIso8601String(),
            'actor' => $log->user?->name,
            'type' => $type,
            'summary' => $summary,
            'delta' => $delta,
            'unit' => $unit,
        ];
    }

    // ─── Prescriptions / Prescriber Orders ─────────────────
    public function prescriptions(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteFilter = $request->integer('site_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            $siteFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $canViewControlled = $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $canRecordControlled = $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY);
        $canManageOrders = $actor->canDo('medications.orders.manage');
        $canVerifyOrders = $actor->canDo('medications.orders.verify');
        $pageAt = now();
        $pageWorkerDate = $pageAt
            ->copy()
            ->timezone(config('app.worker_timezone', 'Pacific/Auckland'))
            ->toDateString();
        $clients = Client::query()
            ->whereIn('site_id', $accessibleSiteIds)
            ->with('site:id,name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'site_id']);
        $workScopedClientIds = $this->medicationScope->clientIdsWithCurrentAuthority(
            $actor,
            $clients->pluck('id')->map(fn ($clientId): int => (int) $clientId)->all(),
            $pageAt,
        );
        $workScopedClientIdSet = array_fill_keys($workScopedClientIds, true);
        $canCreateManualOrders = $canManageOrders
            && $workScopedClientIds !== [];
        $canClassifyManualOrders = $canCreateManualOrders
            && $canViewControlled
            && $canRecordControlled;

        $medications = ClientMedication::active()
            ->where('approval_status', 'verified')
            ->whereHas('client', fn ($q) => $q->whereIn('site_id', $accessibleSiteIds))
            ->when(! $canViewControlled, fn ($query) => $query->where('controlled_drug', false))
            ->orderBy('name')
            ->get(['id', 'name', 'client_id', 'controlled_drug'])
            ->map(function (ClientMedication $medication) use ($canManageOrders, $canViewControlled, $canRecordControlled, $workScopedClientIdSet): array {
                $canMutate = $canManageOrders && (
                    ! $medication->controlled_drug
                    || ($canViewControlled && $canRecordControlled)
                );
                $hasWorkScope = isset($workScopedClientIdSet[(int) $medication->client_id]);

                return [
                    'id' => $medication->id,
                    'name' => $medication->name,
                    'client_id' => $medication->client_id,
                    'controlled_drug' => (bool) $medication->controlled_drug,
                    'can_create_covert_authorisation' => $canMutate,
                    'can_link_prescriber_order' => $canMutate && $hasWorkScope,
                ];
            })
            ->values();

        // Flat order list — the redesigned page filters by tab/search/status
        // client-side with live facet counts.
        $orderQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationPrescriberOrder::query(),
            $readerSiteIds,
            true,
        );
        if (! $canViewControlled) {
            $orderQuery->visibleToOrdinaryReader();
        }
        $orderModels = $orderQuery
            ->with(['client:id,first_name,last_name,site_id,room_id', 'client.site:id,name', 'client.room:id,name', 'medication:id,name,controlled_drug', 'receivedByUser:id,name', 'countersignedByUser:id,name', 'dispensedByUser:id,name'])
            ->latest('order_date')
            ->get();
        $orders = $orderModels
            ->map(function (MedicationPrescriberOrder $o) use ($actor, $medications, $canManageOrders, $canVerifyOrders, $canViewControlled, $canRecordControlled, $workScopedClientIdSet, $pageAt, $pageWorkerDate) {
                $orderDate = $o->order_date instanceof \DateTimeInterface ? $o->order_date : null;
                $isExpired = $o->isExpired($pageAt);
                $isFutureEffectiveCease = $o->order_type === 'cease'
                    && $o->effective_date instanceof \DateTimeInterface
                    && $o->effective_date->format('Y-m-d') > $pageWorkerDate;
                $isOpenLifecycleState = $o->status === 'pending'
                    || ($o->status === 'confirmed'
                        && $o->order_type !== 'cease'
                        && $o->dispensed_at === null);
                $isOpenLifecycle = ! $isExpired && $isOpenLifecycleState;
                $hasWorkScope = isset($workScopedClientIdSet[(int) $o->client_id]);
                $controlledWriteAllowed = ! $o->requiresControlledView($o->medication)
                    || ($canViewControlled && $canRecordControlled);
                $canMutateOrder = ! $isExpired && $hasWorkScope && $controlledWriteAllowed;
                $isPending = $o->status === 'pending';
                $canLink = $isPending
                    && $o->client_medication_id === null
                    && $canManageOrders
                    && $canMutateOrder
                    && $medications->contains(function (array $candidate) use ($o): bool {
                        if ((int) $candidate['client_id'] !== (int) $o->client_id
                            || ! $candidate['can_link_prescriber_order']) {
                            return false;
                        }

                        return $o->controlled_drug_snapshot === null
                            || (bool) $candidate['controlled_drug'] === (bool) $o->controlled_drug_snapshot;
                    });

                return [
                    'id' => $o->id,
                    'client_id' => $o->client_id,
                    'client_name' => $o->client ? trim($o->client->first_name.' '.$o->client->last_name) : 'Unknown',
                    'client_room' => $o->client?->room?->name,
                    'client_site' => $o->client?->site?->name,
                    'client_medication_id' => $o->client_medication_id,
                    'order_type' => $o->order_type,
                    'status' => $isExpired && $isOpenLifecycleState ? 'expired' : $o->status,
                    'is_open_lifecycle' => $isOpenLifecycle,
                    'prescriber_name' => $o->prescriber_name,
                    'prescriber_registration' => $o->prescriber_registration,
                    'prescriber_type' => $o->prescriber_type,
                    'medication_name' => $o->medication_name,
                    'dose' => $o->dose,
                    'route' => $o->route,
                    'frequency' => $o->frequency,
                    'indication' => $o->indication,
                    'instructions' => $o->instructions,
                    'order_date' => $orderDate?->toDateString(),
                    'effective_date' => $o->effective_date instanceof \DateTimeInterface ? $o->effective_date->toDateString() : null,
                    'expiry_date' => $o->expiry_date instanceof \DateTimeInterface ? $o->expiry_date->toDateString() : null,
                    'expired' => $isExpired,
                    'requires_countersign' => (bool) $o->requires_countersign,
                    'countersigned_at' => $o->countersigned_at?->toIso8601String(),
                    'countersigned_by_name' => $o->countersignedByUser?->name,
                    'countersign_method' => $o->countersign_method,
                    'countersign_due_at' => (! $isExpired && $o->requires_countersign && ! $o->countersigned_at && $orderDate)
                        ? $orderDate->copy()->addDay()->toIso8601String()
                        : null,
                    'read_back_confirmed' => (bool) $o->read_back_confirmed,
                    'read_back_verified_at' => $o->read_back_verified_at?->toIso8601String(),
                    'read_back_verification_method' => $o->read_back_verification_method,
                    'received_by_name' => $o->receivedByUser?->name,
                    'dispensed_at' => $o->dispensed_at?->toIso8601String(),
                    'dispensed_by_name' => $o->dispensedByUser?->name,
                    'pharmacy_name' => $o->pharmacy_name,
                    'batch_number' => $o->batch_number,
                    'batch_expiry' => $o->batch_expiry instanceof \DateTimeInterface ? $o->batch_expiry->toDateString() : null,
                    'controlled_drug_snapshot' => $o->controlled_drug_snapshot,
                    'can_confirm' => $isPending
                        && ! $isFutureEffectiveCease
                        && ! $o->requires_countersign
                        && ! in_array($o->order_type, ['verbal', 'telephone'], true)
                        && $o->received_by !== null
                        && (int) $o->received_by !== (int) $actor->id
                        && ($o->order_type !== 'cease' || $o->client_medication_id !== null)
                        && ($o->order_type !== 'cease' || $canManageOrders)
                        && $canVerifyOrders
                        && $canMutateOrder,
                    'can_countersign' => $isPending
                        && ! $isFutureEffectiveCease
                        && $o->requires_countersign
                        && in_array($o->order_type, ['verbal', 'telephone', 'cease'], true)
                        && ($o->order_type !== 'cease' || $o->client_medication_id !== null)
                        && $o->countersigned_at === null
                        && $o->hasVerifiedReadBack()
                        && $o->received_by !== null
                        && (int) $o->received_by !== (int) $actor->id
                        && (int) $o->read_back_witnessed_by !== (int) $actor->id
                        && $canManageOrders
                        && $canVerifyOrders
                        && $canMutateOrder,
                    'can_dispense' => $o->status === 'confirmed'
                        && $o->order_type !== 'cease'
                        && $o->dispensed_at === null
                        && $canManageOrders
                        && $canMutateOrder,
                    'can_link' => $canLink,
                    'can_cancel' => $isPending && $canManageOrders && $canMutateOrder,
                ];
            })->all();

        $covertQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationCovertAuthorisation::query(),
            $readerSiteIds,
            false,
        );
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($covertQuery);
        }
        $covert = $covertQuery
            ->active()
            ->with([
                'client:id,first_name,last_name',
                'medication' => fn ($medication) => $medication->withTrashed()->select(['id', 'name', 'controlled_drug']),
                'recordedByUser:id,name',
            ])
            ->get()
            ->map(function (MedicationCovertAuthorisation $c) use ($canManageOrders, $canViewControlled, $canRecordControlled) {
                $review = $c->review_date instanceof \DateTimeInterface ? $c->review_date : null;
                $controlledWriteAllowed = ! $c->medication?->controlled_drug
                    || ($canViewControlled && $canRecordControlled);

                return [
                    'id' => $c->id,
                    'client_id' => $c->client_id,
                    'client_medication_id' => $c->client_medication_id,
                    'client_name' => $c->client ? trim($c->client->first_name.' '.$c->client->last_name) : 'Unknown',
                    'medication_name' => $c->medication?->name,
                    'authorised_by_name' => $c->authorised_by_name,
                    'authorised_by_registration' => $c->authorised_by_registration,
                    'clinical_justification' => $c->clinical_justification,
                    'legal_basis' => $c->legal_basis,
                    'administration_method' => $c->administration_method,
                    'pharmacist_advice' => $c->pharmacist_advice,
                    'authorised_date' => $c->authorised_date instanceof \DateTimeInterface ? $c->authorised_date->toDateString() : null,
                    'review_date' => $review?->toDateString(),
                    'recorded_by_name' => $c->recordedByUser?->name,
                    'review_overdue' => $review ? $review->isPast() : false,
                    'can_revoke' => $canManageOrders && $controlledWriteAllowed,
                ];
            })->all();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/Prescriptions', [
            'orders' => $orders,
            'covert' => $covert,
            'clients' => $clients
                ->map(fn (Client $c) => [
                    'id' => $c->id,
                    'first_name' => $c->first_name,
                    'last_name' => $c->last_name,
                    'site_id' => (int) $c->site_id,
                    'site_name' => $c->site?->name,
                    'can_create_prescriber_order' => $canCreateManualOrders
                        && isset($workScopedClientIdSet[(int) $c->id]),
                ])->values(),
            'staff' => $this->governanceScope->prescriptionWitnessStaffPicker($accessibleSiteIds),
            'medications' => $medications,
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
            'can' => [
                'manage_orders' => $canManageOrders,
                'verify_orders' => $canVerifyOrders,
            ],
            'can_create_manual_order' => $canCreateManualOrders,
            'can_create_covert' => $medications->contains('can_create_covert_authorisation', true),
            'can_classify_manual_orders' => $canClassifyManualOrders,
            'current_user_id' => (int) $actor->id,
        ]);
    }

    // ─── Competency Assessments ────────────────────────────
    public function competency(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteFilter = $request->integer('site_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            $siteFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;

        // Flat, client-side-filterable register (drops pagination). Staff are not
        // site-scoped, so the page facets by role/status/search; brand colour is
        // still resolved from ?site_id for themed deep-links (§3b parity).
        $models = MedicationCompetencyAssessment::query()
            ->with(['user:id,name,email,role', 'assessor:id,name'])
            ->whereHas('user.hrEmployeeProfile', fn ($profile) => $profile->where(function ($site) use ($readerSiteIds) {
                $site->whereIn('primary_site_id', $readerSiteIds);
                foreach ($readerSiteIds as $siteId) {
                    $site->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            }))
            ->latest('assessment_date')
            ->limit(300)
            ->get();

        $assessments = $models->map(fn (MedicationCompetencyAssessment $a) => $this->serializeCompetency($a))->values();

        // Latest assessment per staff member drives the headline KPIs.
        $latestByUser = $models->groupBy('user_id')->map(fn ($g) => $g->first());
        $inDate = $latestByUser->filter(fn (MedicationCompetencyAssessment $a) => $a->isPassed())->count();
        $cdWitnesses = $latestByUser->filter(fn (MedicationCompetencyAssessment $a) => $a->isPassed() && $a->can_witness_controlled)->count();
        $expiring = $latestByUser->filter(fn (MedicationCompetencyAssessment $a) => $a->isPassed()
            && $a->expiry_date?->isFuture()
            && $a->expiry_date->lte(today()->addDays(30)))->count();
        $expired = $latestByUser->filter(fn (MedicationCompetencyAssessment $a) => $a->isExpired())->count();

        $staffWithoutAssessment = User::query()
            ->whereHas('hrEmployeeProfile', fn ($profile) => $profile->where(function ($site) use ($readerSiteIds) {
                $site->whereIn('primary_site_id', $readerSiteIds);
                foreach ($readerSiteIds as $siteId) {
                    $site->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            }))
            ->whereDoesntHave('medicationCompetencyAssessments', fn ($q) => $q->active())
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role])
            ->values();

        $totalStaff = $latestByUser->keys()->merge($staffWithoutAssessment->pluck('id'))->unique()->count();
        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/Competency', [
            'assessments' => $assessments,
            'staffWithoutAssessment' => $staffWithoutAssessment,
            'staff' => $this->governanceScope->staffPicker($accessibleSiteIds),
            'kpis' => [
                'total_staff' => $totalStaff,
                'in_date' => $inDate,
                'in_date_pct' => $totalStaff > 0 ? (int) round($inDate / $totalStaff * 100) : 0,
                'expiring' => $expiring,
                'expired' => $expired,
                'unassessed' => $staffWithoutAssessment->count(),
                'cd_witnesses' => $cdWitnesses,
            ],
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
        ]);
    }

    private function serializeCompetency(MedicationCompetencyAssessment $a): array
    {
        $areas = ['medication_knowledge', 'five_rights', 'safety_checks', 'documentation', 'controlled_drugs', 'prn_assessment', 'insulin_competent', 'inhaler_competent', 'topical_competent', 'covert_admin_knowledge', 'error_reporting', 'allergy_awareness'];

        return array_merge(
            collect($areas)->mapWithKeys(fn ($k) => [$k => (bool) $a->{$k}])->all(),
            [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'user_name' => $a->user?->name ?? 'Unknown',
                'user_role' => $a->user?->role,
                'assessor_name' => $a->assessor?->name,
                'assessment_type' => $a->assessment_type,
                'status' => $a->status,
                'assessment_date' => $a->assessment_date?->toDateString(),
                'expiry_date' => $a->expiry_date?->toDateString(),
                'not_seen_areas' => $a->not_seen_areas ?? [],
                'observed_rounds' => $a->observed_rounds ?? [],
                'restricted' => (bool) $a->restricted,
                'restriction_notes' => $a->restriction_notes,
                'total_score' => $a->total_score,
                'pass_threshold' => $a->pass_threshold,
                'strengths' => $a->strengths,
                'areas_for_improvement' => $a->areas_for_improvement,
                'action_plan' => $a->action_plan,
                'assessor_comments' => $a->assessor_comments,
                'assessor_declared_at' => $a->assessor_declared_at?->toDateString(),
                'staff_acknowledged_at' => $a->staff_acknowledged_at?->toDateString(),
                'can_administer_unsupervised' => (bool) $a->can_administer_unsupervised,
                'can_witness_controlled' => (bool) $a->can_witness_controlled,
                'is_expired' => $a->isExpired(),
                'is_passed' => $a->isPassed(),
            ],
        );
    }

    // ─── Medication Reviews ────────────────────────────────
    public function reviews(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteFilter = $request->integer('site_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            $siteFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;

        // Flat, client-side-filterable feed — the redesigned page facets by tab,
        // search, site and reviewer with live counts.
        $models = MedicationReview::query()
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name', 'reviewer:id,name', 'requestedBy:id,name'])
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $readerSiteIds))
            ->latest('scheduled_date')
            ->limit(250)
            ->get();

        $today = now()->startOfDay();
        $reviews = $models->map(fn (MedicationReview $r) => $this->serializeReview($r))->values();

        // Deprescribing pipeline (G1/G2): every non-"Continue" recommendation on a
        // completed review becomes a tracked card keyed by review + action index.
        $deprescribing = $models
            ->where('status', 'completed')
            ->flatMap(function (MedicationReview $r) {
                return collect($r->actions ?? [])
                    ->map(fn ($a, $i) => is_array($a) ? array_merge($a, ['__i' => $i]) : null)
                    ->filter(fn ($a) => $a && ($a['action'] ?? 'Continue') !== 'Continue')
                    ->map(fn ($a) => [
                        'review_id' => $r->id,
                        'index' => $a['__i'],
                        'drug' => $a['drug'] ?? '—',
                        'action' => $a['action'] ?? 'Monitor',
                        'rationale' => $a['rationale'] ?? null,
                        'gp_status' => $a['gp_status'] ?? 'pending',
                        'stage' => $a['stage'] ?? 'gp',
                        'client_name' => $r->client ? trim($r->client->first_name.' '.$r->client->last_name) : 'Unknown',
                        'reviewer_name' => $r->reviewer?->name ?? $r->reviewer_name,
                    ]);
            })
            ->values();

        // GP acceptance rate across decided recommendations (KPI, gap G8).
        $decided = $deprescribing->whereIn('gp_status', ['accepted', 'declined']);
        $gpAcceptance = $decided->count() > 0 ? (int) round($decided->where('gp_status', 'accepted')->count() / $decided->count() * 100) : null;

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/Reviews', [
            'reviews' => $reviews,
            'deprescribing' => $deprescribing,
            'kpis' => [
                'overdue' => $models->filter(fn (MedicationReview $r) => $r->status === 'scheduled' && $r->scheduled_date && $r->scheduled_date->lt($today))->count(),
                'due_30' => $models->filter(fn (MedicationReview $r) => $r->status === 'scheduled' && $r->scheduled_date && $r->scheduled_date->gte($today) && $r->scheduled_date->lte($today->copy()->addDays(30)))->count(),
                'completed_quarter' => $models->filter(fn (MedicationReview $r) => $r->status === 'completed' && $r->completed_date && $r->completed_date->gte(now()->firstOfQuarter()))->count(),
                'gp_acceptance' => $gpAcceptance,
                'in_monitoring' => $deprescribing->where('stage', 'monitor')->count(),
                'awaiting_gp' => $deprescribing->where('stage', 'gp')->count(),
            ],
            'clients' => $this->governanceScope->clientPicker($accessibleSiteIds),
            'staff' => $this->governanceScope->staffPicker($accessibleSiteIds),
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
        ]);
    }

    private function serializeReview(MedicationReview $r): array
    {
        return [
            'id' => $r->id,
            'client_id' => $r->client_id,
            'client_name' => $r->client ? trim($r->client->first_name.' '.$r->client->last_name) : 'Unknown',
            'site_id' => $r->client?->site_id,
            'site_name' => $r->client?->site?->name,
            'review_type' => $r->review_type,
            'status' => $r->status,
            'scheduled_date' => $r->scheduled_date?->toDateString(),
            'completed_date' => $r->completed_date?->toDateString(),
            'reviewer_name' => $r->reviewer?->name ?? $r->reviewer_name,
            'reviewer_role' => $r->reviewer_role,
            'reviewer_user_id' => $r->reviewer_user_id,
            'trigger_reason' => $r->trigger_reason,
            'medications_reviewed' => $r->medications_reviewed ?? [],
            'actions' => $r->actions ?? [],
            'clinical_summary' => $r->clinical_summary,
            'recommendations' => $r->recommendations,
            'drug_burden_index' => $r->drug_burden_index,
            'falls_last_quarter' => $r->falls_last_quarter,
            'whanau_involved' => (bool) $r->whanau_involved,
            'whanau_notes' => $r->whanau_notes,
            'next_review_date' => $r->next_review_date?->toDateString(),
            'is_overdue' => $r->status === 'scheduled' && $r->scheduled_date && $r->scheduled_date->isPast(),
        ];
    }

    // ─── Medication Rounds ─────────────────────────────────
    public function rounds(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $date = $request->input('date', today()->toDateString());
        $siteFilter = $request->integer('site_id') ?: null;
        $canReadRounds = (bool) $user?->canDo('medications.view');
        $canRecordRounds = (bool) $user?->canDo('medications.administer.record');
        $includeControlledRounds = $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        abort_unless($canReadRounds || $canRecordRounds, 403);
        $accessibleSiteIds = $this->siteAccess()->accessibleSiteIds(
            $user,
            ['clinical.accessAllSites', 'sites.viewAll'],
        );
        $canReadApplicationWideTemplates = $canReadRounds && (
            $user->canDo('clinical.accessAllSites')
            || $user->canDo('sites.viewAll')
        );

        if ($siteFilter !== null && ! in_array($siteFilter, $accessibleSiteIds, true)) {
            abort(404);
        }
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $roundScopedClientIds = null;
        if (! $canReadRounds) {
            $roundScopedClientIds = $this->medicationScope->clientIdsWithCurrentAuthority(
                $user,
                Client::query()
                    ->whereIn('site_id', $readerSiteIds)
                    ->pluck('id')
                    ->map(fn ($clientId): int => (int) $clientId)
                    ->all(),
                now(),
            );
        }

        $svc = app(GuidedRoundService::class);
        $residents = [];

        // The board/timeline derive counts from the round's stored counters, but
        // the Chart matrix and the per-round audit timeline need the live, ordered
        // doses ("cells"). cells() reuses the one GuidedRoundService pipeline, so
        // there's no second schedule/administration code path here.
        $rounds = MedicationRound::query()
            ->forDate($date)
            ->whereIn('site_id', $accessibleSiteIds)
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->when(! $canReadRounds, fn ($q) => $q->where(function ($owned) use ($user) {
                $owned->where('assigned_to', $user->id)
                    ->orWhere(function ($legacy) use ($user) {
                        $legacy->whereNull('assigned_to')
                            ->where('started_by', $user->id);
                    });
            }))
            ->with(['assignedTo:id,name', 'startedBy:id,name', 'completedBy:id,name', 'template:id,name', 'site:id,name'])
            ->orderBy('scheduled_time')
            ->get()
            ->map(function (MedicationRound $r) use ($svc, &$residents, $includeControlledRounds, $roundScopedClientIds) {
                $cells = $svc->cells($r, $includeControlledRounds, $roundScopedClientIds);
                $cellStatuses = collect($cells)->pluck('status');
                foreach ($cells as $cell) {
                    if ($cell['resident_id'] && ! isset($residents[$cell['resident_id']])) {
                        $residents[$cell['resident_id']] = [
                            'id' => $cell['resident_id'],
                            'name' => $cell['resident_name'],
                            'site_id' => $cell['site_id'],
                            'site_name' => $cell['site_name'],
                        ];
                    }
                }

                return [
                    'id' => $r->id,
                    'name' => $r->name,
                    'scheduled_time' => substr((string) $r->scheduled_time, 0, 5),
                    'window_minutes' => (int) ($r->window_minutes ?? 60),
                    'status' => $r->status,
                    'round_date' => $r->round_date?->toDateString(),
                    'site_id' => $r->site_id,
                    'site_name' => $r->site?->name,
                    'template_name' => $r->template?->name,
                    'total_medications' => count($cells),
                    'given' => $cellStatuses->filter(fn ($status) => $status === 'given')->count(),
                    'refused' => $cellStatuses->filter(fn ($status) => $status === 'refused')->count(),
                    'withheld' => $cellStatuses->filter(fn ($status) => $status === 'withheld')->count(),
                    'missed' => $cellStatuses->filter(fn ($status) => $status === 'missed')->count(),
                    'assignee' => $r->assignedTo?->name,
                    'assigned_to' => $r->assigned_to,
                    'created_at' => $r->created_at?->toIso8601String(),
                    'started_at' => $r->started_at?->toIso8601String(),
                    'started_by' => $r->startedBy?->name,
                    'completed_at' => $r->completed_at?->toIso8601String(),
                    'completed_by' => $r->completedBy?->name,
                    'can_complete' => $svc->canCompleteCanonicalRound($r),
                    'cells' => $cells,
                ];
            })
            ->all();

        usort($residents, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
        $residents = array_values($residents);
        $governedTemplateStaff = $this->governanceScope
            ->staffPicker($accessibleSiteIds)
            ->keyBy(fn (array $staff): int => (int) $staff['id']);

        $templates = MedicationRoundTemplate::query()
            ->when(! $canReadRounds, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($canReadRounds, fn ($q) => $q->where(function ($templates) use ($readerSiteIds, $canReadApplicationWideTemplates) {
                $templates
                    ->whereRaw('1 = 0')
                    ->orWhere(function ($siteBound) use ($readerSiteIds) {
                        $siteBound
                            ->whereIn('medication_round_templates.site_id', $readerSiteIds)
                            ->where(function ($context) {
                                $context
                                    ->whereNull('medication_round_templates.service_context_id')
                                    ->orWhereHas('serviceContext', function ($serviceContext) {
                                        $serviceContext
                                            ->where('service_contexts.is_active', true)
                                            ->where(function ($contextSite) {
                                                $contextSite
                                                    ->whereNull('service_contexts.site_id')
                                                    ->orWhereColumn(
                                                        'service_contexts.site_id',
                                                        'medication_round_templates.site_id',
                                                    );
                                            });
                                    });
                            });
                    })
                    ->orWhere(function ($contextBound) use ($readerSiteIds) {
                        $contextBound
                            ->whereNull('medication_round_templates.site_id')
                            ->whereNotNull('medication_round_templates.service_context_id')
                            ->whereHas('serviceContext', function ($serviceContext) use ($readerSiteIds) {
                                $serviceContext
                                    ->where('service_contexts.is_active', true)
                                    ->whereIn('service_contexts.site_id', $readerSiteIds);
                            });
                    });

                if ($canReadApplicationWideTemplates) {
                    $templates->orWhere(function ($applicationWide) {
                        $applicationWide
                            ->whereNull('medication_round_templates.site_id')
                            ->where(function ($context) {
                                $context
                                    ->whereNull('medication_round_templates.service_context_id')
                                    ->orWhereHas('serviceContext', function ($serviceContext) {
                                        $serviceContext
                                            ->where('service_contexts.is_active', true)
                                            ->whereNull('service_contexts.site_id');
                                    });
                            });
                    });
                }
            }))
            ->with(['retiredBy:id,name', 'site:id,name'])
            ->orderBy('scheduled_time')
            ->get()
            ->map(function (MedicationRoundTemplate $t) use ($governedTemplateStaff): array {
                $defaultStaff = $governedTemplateStaff->get((int) $t->default_assigned_to);

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'scheduled_time' => substr((string) $t->scheduled_time, 0, 5),
                    'window_minutes' => (int) ($t->window_minutes ?? 60),
                    'days_of_week' => $t->days_of_week ?? [],
                    'active' => (bool) $t->active,
                    'retired_at' => $t->retired_at?->toIso8601String(),
                    'retired_by' => $t->retiredBy?->name,
                    'site_id' => $t->site_id,
                    'site_name' => $t->site?->name,
                    'service_context_id' => $t->service_context_id,
                    'default_assigned_to' => $defaultStaff === null ? null : (int) $defaultStaff['id'],
                    'default_staff' => $defaultStaff['name'] ?? null,
                ];
            })
            ->all();

        $lastGenerated = MedicationRound::whereNotNull('round_template_id')
            ->whereIn('site_id', $accessibleSiteIds)
            ->latest('created_at')
            ->value('created_at');

        // Guided-round modal payload (round + ordered doses + progress) when the
        // page is opened with ?guided={id}. GET is deliberately read-only:
        // pending rounds are started through the exact, scope-checked POST route.
        $guidedRound = null;
        if ($request->filled('guided')) {
            $round = MedicationRound::with(['template:id,name', 'assignedTo:id,name', 'startedBy:id,name', 'completedBy:id,name'])
                ->whereKey($request->integer('guided'))
                ->whereIn('site_id', $accessibleSiteIds)
                ->when(! $canReadRounds, fn ($q) => $q->where(function ($owned) use ($user) {
                    $owned->where('assigned_to', $user->id)
                        ->orWhere(function ($legacy) use ($user) {
                            $legacy->whereNull('assigned_to')
                                ->where('started_by', $user->id);
                        });
                }))
                ->first();

            abort_unless($round, 404);

            $buildGuidedRound = function (MedicationRound $round, bool $canRecord, bool $canStart) use ($svc, $includeControlledRounds, $roundScopedClientIds): array {
                $items = $svc->items($round, $includeControlledRounds, $roundScopedClientIds);

                return [
                    'can_record' => $canRecord,
                    'can_start' => $canStart,
                    'can_complete' => $svc->canCompleteCanonicalRound($round),
                    'round' => [
                        'id' => $round->id,
                        'name' => $round->name,
                        'status' => $round->status,
                        'scheduled_time' => substr((string) $round->scheduled_time, 0, 5),
                        'window_minutes' => (int) ($round->window_minutes ?? 60),
                        'round_date' => $round->round_date?->toDateString(),
                        'template_name' => $round->template?->name,
                        'assignee' => $round->assignedTo?->name,
                        'created_at' => $round->created_at?->toIso8601String(),
                        'started_at' => $round->started_at?->toIso8601String(),
                        'started_by' => $round->startedBy?->name,
                        'completed_at' => $round->completed_at?->toIso8601String(),
                        'completed_by' => $round->completedBy?->name,
                    ],
                    'items' => $items,
                    'progress' => $svc->summarise($items),
                ];
            };

            if ($round->status === 'completed') {
                $guidedRound = $buildGuidedRound($round, false, false);
            } else {
                abort_unless($canRecordRounds, 404);
                $guidedRound = $this->medicationScope->forRound(
                    $user,
                    $round,
                    now(),
                    fn (MedicationScopeDecision $scope) => $buildGuidedRound(
                        $scope->round,
                        $scope->round->status === 'in_progress',
                        in_array($scope->round->status, ['pending', 'partial'], true),
                    ),
                    ['pending', 'partial', 'in_progress'],
                );
            }
        }

        // Activity feed — administrations recorded against this day's rounds.
        $roundIds = array_column($rounds, 'id');
        $activityQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $accessibleSiteIds,
            false,
        );
        if (! $includeControlledRounds) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($activityQuery);
        }
        if ($roundScopedClientIds !== null) {
            $activityQuery->whereIn('client_medication_administrations.client_id', $roundScopedClientIds);
        }
        $activity = empty($roundIds) ? [] : $activityQuery
            ->whereIn('medication_round_id', $roundIds)
            ->with([
                'medication:id,name,deleted_at',
                'administeredBy:id,name',
                'witnessedBy:id,name',
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'round:id,name',
            ])
            ->latest('administered_at')
            ->limit(150)
            ->get()
            ->map(fn (ClientMedicationAdministration $a) => [
                'id' => $a->id,
                'status' => $a->status,
                'medication_id' => $a->client_medication_id,
                'medication_name' => $a->medication?->historicalDisplayName(),
                'dose' => $a->dose_given,
                'resident_id' => $a->client_id,
                'resident_name' => $a->client ? trim(($a->client->first_name ?? '').' '.($a->client->last_name ?? '')) : null,
                'site_id' => $a->client?->site_id,
                'site_name' => $a->client?->site?->name,
                'round_id' => $a->medication_round_id,
                'round_name' => $a->round?->name,
                'staff' => $a->administeredBy?->name,
                'witnessed_by' => $a->witnessedBy?->name,
                'blood_glucose_level' => $a->blood_glucose_level !== null ? (float) $a->blood_glucose_level : null,
                'pulse_bpm' => $a->pulse_bpm,
                'reason' => $a->reason,
                'reason_code' => $a->reason_code,
                'scheduled_for' => $a->scheduled_for?->toIso8601String(),
                'administered_at' => $a->administered_at?->toIso8601String(),
                'time' => $a->administered_at?->format('H:i'),
            ])
            ->all();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $canManageRounds = (bool) $user?->canDo('medications.orders.manage');
        $staff = $canManageRounds
            ? $governedTemplateStaff->values()
            : collect();

        return Inertia::render('emar/Rounds', [
            'rounds' => $rounds,
            'templates' => $templates,
            'staff' => $staff,
            'date' => $date,
            'now_label' => now()->setTimezone(config('app.worker_timezone', config('app.timezone')))->format('g:i a'),
            'lastGenerated' => $lastGenerated?->toIso8601String(),
            'guidedRound' => $guidedRound,
            'activity' => $activity,
            'residents' => $residents,
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'site_brand_colour' => $siteFilter
                ? $sites->firstWhere('id', $siteFilter)?->brand_colour
                : null,
            'witnesses' => $canRecordRounds
                ? $this->governanceScope->controlledWitnessPicker($accessibleSiteIds, $user->id)
                : [],
            'not_given_reasons' => $this->boardPayload->notGivenReasons(),
            'board_user' => $this->boardPayload->boardUser($user),
            'can_manage' => $canManageRounds,
            'can_export' => (bool) ($user?->canDo('medications.reports.export') || $user?->canDo('reports.viewAny')),
        ]);
    }

    // ─── Self-Administration Assessments ───────────────────
    public function selfAdmin(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteFilter = $request->integer('site_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            $siteFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $canViewControlled = $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);

        $models = MedicationSelfAdminAssessment::query()
            ->with(['client:id,first_name,last_name,nhi_number,site_id', 'client.site:id,name', 'assessor:id,name', 'agreementSigner:id,name'])
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $readerSiteIds))
            ->latest('assessment_date')
            ->limit(300)
            ->get();

        // A reassessment supersedes the prior record — the live register shows
        // only the current assessment per client (records not superseded by another).
        $supersededIds = $models->pluck('supersedes_id')->filter()->unique()->all();
        $live = $models->reject(fn (MedicationSelfAdminAssessment $a) => in_array($a->id, $supersededIds, true));

        // The self-managing clients' active medications power the per-med scope tab.
        $medsByClient = ClientMedication::query()
            ->active()
            ->whereIn('client_id', $live->pluck('client_id')->unique()->filter()->all())
            ->when(! $canViewControlled, fn ($query) => $query->where('controlled_drug', false))
            ->orderBy('name')
            ->get(['id', 'name', 'client_id', 'dosage', 'controlled_drug'])
            ->groupBy('client_id');

        $assessments = $live->map(fn (MedicationSelfAdminAssessment $a) => $this->serializeSelfAdmin($a, $medsByClient))->values();

        $today = today();
        $activity = $models->flatMap(function (MedicationSelfAdminAssessment $a) {
            $client = $a->client ? trim($a->client->first_name.' '.$a->client->last_name) : 'a client';
            $events = [[
                'actor' => $a->assessor?->name ?? 'System',
                'text' => $a->supersedes_id ? 'reassessed' : 'assessed',
                'subject' => $client,
                'at' => $a->created_at?->toIso8601String(),
                'icon' => 'clipboard',
            ]];
            if ($a->agreement_signed_at) {
                $events[] = ['actor' => $a->agreementSigner?->name ?? 'Staff', 'text' => 'signed the self-administration agreement for', 'subject' => $client, 'at' => $a->agreement_signed_at->toIso8601String(), 'icon' => 'file'];
            }

            return $events;
        })->sortByDesc('at')->take(40)->values();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/SelfAdmin', [
            'assessments' => $assessments,
            'activity' => $activity,
            'kpis' => [
                'self_managing' => $live->whereIn('outcome', ['independent', 'prompted'])->count(),
                'supervised' => $live->where('outcome', 'supervised')->count(),
                'administered' => $live->where('outcome', 'administered')->count(),
                'due_now' => $live->filter(fn ($a) => $a->reassessment_date && $a->reassessment_date->lte($today))->count(),
                'independent' => $live->where('outcome', 'independent')->count(),
                'independent_pct' => $live->count() > 0 ? (int) round($live->where('outcome', 'independent')->count() / $live->count() * 100) : 0,
                'unsigned' => $live->whereIn('outcome', ['independent', 'prompted'])->whereNull('agreement_signed_at')->count(),
                'total' => $live->count(),
            ],
            'clients' => $this->governanceScope->clientPicker($accessibleSiteIds),
            'staff' => $this->governanceScope->staffPicker($accessibleSiteIds),
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
        ]);
    }

    private function serializeSelfAdmin(MedicationSelfAdminAssessment $a, $medsByClient): array
    {
        $medications = collect($medsByClient->get($a->client_id) ?? [])->keyBy(
            fn (ClientMedication $medication) => (int) $medication->id,
        );
        $canonicalScope = collect($a->med_scope ?? [])
            ->filter(fn ($item) => is_array($item) && is_numeric($item['med_id'] ?? null))
            ->map(function (array $item) use ($medications): ?array {
                $medication = $medications->get((int) $item['med_id']);
                if ($medication === null) {
                    return null;
                }

                return [
                    'med_id' => (int) $medication->id,
                    'med_name' => (string) $medication->name,
                    'scope' => is_string($item['scope'] ?? null) ? $item['scope'] : null,
                ];
            })
            ->filter()
            ->unique('med_id')
            ->values();
        $scope = $canonicalScope->keyBy('med_id');

        return [
            'id' => $a->id,
            'client_id' => $a->client_id,
            'client_name' => $a->client ? trim($a->client->first_name.' '.$a->client->last_name) : 'Unknown',
            'nhi' => $a->client?->nhi_number,
            'site_id' => $a->client?->site_id,
            'site_name' => $a->client?->site?->name,
            'status' => $a->status,
            'outcome' => $a->outcome,
            'outcome_label' => $a->outcome_label,
            'wishes_to_self_administer' => (bool) $a->wishes_to_self_administer,
            'people_involved' => $a->people_involved ?? [],
            'cognitive_capacity' => $a->cognitive_capacity,
            'physical_dexterity' => $a->physical_dexterity,
            'vision_ability' => $a->vision_ability,
            'swallowing_ability' => $a->swallowing_ability,
            'understanding_score' => $a->understanding_score,
            'total_score' => $a->total_score,
            'can_identify_medications' => (bool) $a->can_identify_medications,
            'can_read_labels' => (bool) $a->can_read_labels,
            'can_open_packaging' => (bool) $a->can_open_packaging,
            'can_manage_timing' => (bool) $a->can_manage_timing,
            'can_store_safely' => (bool) $a->can_store_safely,
            'willing_to_self_admin' => (bool) $a->willing_to_self_admin,
            'risk_factors' => $a->risk_factors,
            'support_needed' => $a->support_needed,
            'support_adjustments' => $a->support_adjustments ?? [],
            'safe_storage_notes' => $a->safe_storage_notes,
            'storage_location' => $a->storage_location,
            'assessor_notes' => $a->assessor_notes,
            'assessor_name' => $a->assessor?->name,
            'assessment_date' => $a->assessment_date?->toDateString(),
            'reassessment_date' => $a->reassessment_date?->toDateString(),
            'reassessment_interval_months' => $a->reassessment_interval_months,
            'reassessment_trigger' => $a->reassessment_trigger,
            'reassessment_due' => $a->isReassessmentDue(),
            'med_scope' => $canonicalScope->all(),
            'ordering_responsibility' => $a->ordering_responsibility,
            'agreement_responsibilities' => $a->agreement_responsibilities,
            'agreement_signed_at' => $a->agreement_signed_at?->toIso8601String(),
            'agreement_signed_by_name' => $a->agreementSigner?->name,
            'client_medications' => $a->outcome !== 'administered'
                ? $medications->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'dosage' => $m->dosage,
                    'controlled' => (bool) $m->controlled_drug,
                    'scope' => $scope->get($m->id)['scope'] ?? null,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $submittedScope
     * @return array<int, array{med_id: int, med_name: string, scope: string}>
     */
    private function canonicalSelfAdminMedicationScope(
        Client $client,
        MedicationSelfAdminAssessment $assessment,
        User $actor,
        array $submittedScope,
    ): array {
        $medications = ClientMedication::query()
            ->active()
            ->where('client_id', $client->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'client_id', 'name', 'controlled_drug'])
            ->keyBy(fn (ClientMedication $medication) => (int) $medication->id);

        $submitted = collect($submittedScope);
        $submittedIds = $submitted
            ->map(fn (array $item) => (int) $item['med_id'])
            ->values();
        if ($submittedIds->unique()->count() !== $submittedIds->count()) {
            throw ValidationException::withMessages([
                'med_scope' => 'Each medication may appear only once.',
            ]);
        }

        $canGovernControlled = $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
            && $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY);
        $canonical = $submitted->map(function (array $item) use ($medications, $canGovernControlled): array {
            $medication = $medications->get((int) $item['med_id']);
            abort_unless($medication !== null, 404);
            abort_if($medication->controlled_drug && ! $canGovernControlled, 404);

            return [
                'med_id' => (int) $medication->id,
                'med_name' => (string) $medication->name,
                'scope' => (string) $item['scope'],
            ];
        });

        return $canonical->sortBy('med_id')->values()->all();
    }

    private function assertSelfAdminMutationAuthority(
        Client $client,
        MedicationSelfAdminAssessment $assessment,
        User $actor,
    ): void {
        $scope = collect($assessment->med_scope ?? []);
        if ($scope->isEmpty()) {
            return;
        }

        $ids = $scope->map(function ($item): int {
            abort_unless(
                is_array($item)
                && is_numeric($item['med_id'] ?? null)
                && (int) $item['med_id'] > 0
                && is_string($item['scope'] ?? null)
                && in_array($item['scope'], ['self_managed', 'prompted', 'staff_given'], true),
                404,
            );

            return (int) $item['med_id'];
        });
        abort_unless($ids->unique()->count() === $ids->count(), 404);

        $medications = ClientMedication::withTrashed()
            ->where('client_id', $client->id)
            ->whereIn('id', $ids->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'controlled_drug']);
        abort_unless($medications->count() === $ids->count(), 404);

        if ($medications->contains(fn (ClientMedication $medication): bool => (bool) $medication->controlled_drug)) {
            abort_unless(
                $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                && $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                404,
            );
        }
    }

    // ─── Destruction Records ───────────────────────────────
    public function destructions(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteFilter = $request->integer('site_id') ?: null;
        $clientFilter = $request->integer('client_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            $siteFilter,
            $clientFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;

        // Flat, client-side-filterable disposal register. Voided records remain
        // in the list (struck through) — the register is immutable (MoD Regs 1977).
        $destructions = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationDestruction::query(),
            $readerSiteIds,
        )
            ->when($clientFilter, fn ($query) => $query->where('client_id', $clientFilter))
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'destroyedByUser:id,name',
                'witness1:id,name',
                'witness2:id,name',
                'voidedByUser:id,name',
            ])
            ->latest('destroyed_at')
            ->limit(300)
            ->get();

        // Every active medication is destroyable — same CdMedication shape the
        // shared RecordDestructionDialog consumes on the Controlled Drugs page.
        $medications = ClientMedication::query()
            ->active()
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $readerSiteIds))
            ->when($clientFilter, fn ($query) => $query->where('client_id', $clientFilter))
            ->with(['client:id,first_name,last_name', 'stock'])
            ->orderBy('name')
            ->get();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/Destructions', [
            'can_record' => $request->user()?->canDo('medications.controlled.record') ?? false,
            'destructions' => $destructions->map(fn (MedicationDestruction $d) => [
                'id' => $d->id,
                'client_id' => $d->client_id,
                'client_name' => $d->client ? trim($d->client->first_name.' '.$d->client->last_name) : 'Unknown',
                'site_id' => $d->client?->site_id,
                'site_name' => $d->client?->site?->name,
                'medication_name' => $d->medication_name,
                'form' => $d->form,
                'strength' => $d->strength,
                'quantity' => $d->quantity,
                'unit' => $d->unit,
                'batch_number' => $d->batch_number,
                'expiry_date' => $d->expiry_date instanceof \DateTimeInterface ? $d->expiry_date->toDateString() : null,
                'reason' => $d->reason,
                'reason_label' => $d->reason_label,
                'disposal_method' => $d->disposal_method,
                'disposal_method_label' => $d->disposal_method_label,
                'is_controlled_drug' => (bool) $d->is_controlled_drug,
                'controlled_drug_class' => $d->controlled_drug_class,
                'authorised_by_name' => $d->authorised_by_name,
                'destroyed_at' => $d->destroyed_at instanceof \DateTimeInterface ? $d->destroyed_at->toIso8601String() : null,
                'destroyed_by_name' => $d->destroyedByUser?->name,
                'witness_1_name' => $d->witness1?->name,
                'witness_2_name' => $d->witness2?->name,
                'notes' => $d->notes,
                'voided_at' => $d->voided_at instanceof \DateTimeInterface ? $d->voided_at->toIso8601String() : null,
                'void_reason' => $d->void_reason,
                'voided_by_name' => $d->voidedByUser?->name,
                'is_voided' => $d->voided_at !== null,
                'void_stock_semantics' => MedicationDestruction::VOID_STOCK_SEMANTICS,
                'requires_governed_stock_reconciliation' => $d->voided_at !== null && (bool) $d->is_controlled_drug,
                'mar_url' => $d->client_id ? EmarUrl::mar($d->client_id) : null,
            ])->values(),
            'medications' => $medications->map(fn (ClientMedication $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'controlled_drug' => (bool) $m->controlled_drug,
                'client_id' => $m->client_id,
                'client_name' => $m->client ? trim($m->client->first_name.' '.$m->client->last_name) : 'Unknown',
                'stock' => $m->stock ? [
                    'on_hand' => $m->stock->on_hand,
                    'unit' => $m->stock->unit,
                ] : null,
            ])->values(),
            'staff' => $this->governanceScope->controlledWitnessPicker($accessibleSiteIds, $actor->id),
            'clients' => $this->governanceScope->clientPicker($accessibleSiteIds),
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
        ]);
    }

    // ─── Handovers ─────────────────────────────────────────
    public function handovers(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        // The eMAR Handovers page is the medication-focused lens on the shared
        // ShiftHandover workflow. It reuses the Operations HandoverPresenter so
        // the payload matches the shared Handover/Catalogue contract consumed by
        // the reused cards/rail/detail/wizard components — no second shape.
        $presenter = app(HandoverPresenter::class);
        $siteFilter = $request->integer('site_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $auth,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            $siteFilter,
        );
        if ($siteFilter) {
            $this->siteAccess()->assertCanAccessSiteId(
                $auth,
                $siteFilter,
                $this->handoverBypassPermissions(),
            );
        }

        // Week (Mon–Sun) is the unit of navigation. Compute the window in the
        // worker timezone, then query the UTC-stored columns with UTC bounds.
        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $weekStart = $request->filled('week')
            ? Carbon::parse((string) $request->input('week'), $tz)->startOfWeek(Carbon::MONDAY)
            : Carbon::now($tz)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $startUtc = $weekStart->copy()->utc();
        $endUtc = $weekEnd->copy()->utc();

        $canViewAny = $this->handoverService->canViewAny($auth);

        $handovers = ShiftHandover::query()
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            ->with($presenter->mapEagerLoads())
            ->when($siteFilter, fn ($query) => $this->siteAccess()->applyHandoverSiteScopeForSiteIds($query, [$siteFilter]))
            ->where(function ($dateScope) use ($startUtc, $endUtc) {
                $dateScope
                    ->whereHas('outgoingShift', fn ($s) => $s->whereNotNull('starts_at')->whereBetween('starts_at', [$startUtc, $endUtc]))
                    ->orWhere(fn ($noShift) => $noShift
                        ->whereDoesntHave('outgoingShift', fn ($s) => $s->whereNotNull('starts_at'))
                        ->whereBetween('created_at', [$startUtc, $endUtc]));
            })
            ->when(! $canViewAny, function ($query) use ($auth) {
                $query->where(function ($nested) use ($auth) {
                    $nested->where('outgoing_staff_id', $auth->id)
                        ->orWhere(fn ($q) => $q->whereNull('incoming_shift_id')->where('incoming_staff_id', $auth->id))
                        ->orWhereHas('outgoingShift', fn ($s) => $s->where('user_id', $auth->id))
                        ->orWhereHas('incomingShift', fn ($s) => $s->where('user_id', $auth->id));
                });
            })
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->map(fn (ShiftHandover $handover) => $presenter->mapHandover(
                $handover,
                $auth,
                $auth->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
            ))
            ->values();

        // Enrich the catalogue clients with their active medication orders so the
        // wizard's "Medications due" step is MAR-bound (not free-hand) in eMAR.
        $catalogue = $presenter->catalogue($auth);
        $allowedCatalogueClientIds = Client::query()
            ->whereIn('site_id', $accessibleSiteIds)
            ->whereIn('id', collect($catalogue['clients'])->pluck('id'))
            ->pluck('id');
        $catalogue['clients'] = collect($catalogue['clients'])
            ->whereIn('id', $allowedCatalogueClientIds)
            ->values();
        $medsByClient = ClientMedication::query()
            ->active()
            ->whereIn('client_id', collect($catalogue['clients'])->pluck('id'))
            ->when(
                ! $auth->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                fn ($query) => $query->where('controlled_drug', false),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'client_id'])
            ->groupBy('client_id');
        $catalogue['clients'] = collect($catalogue['clients'])->map(function ($client) use ($medsByClient) {
            $client['medications'] = ($medsByClient[$client['id']] ?? collect())
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])
                ->values()
                ->all();

            return $client;
        })->values()->all();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/Handovers', [
            'handovers' => $handovers,
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'catalogue' => $catalogue,
            'can' => [
                'create' => $auth->canDo('handovers.create') || $auth->canDo('shifts.update') || $auth->canDo('shifts.manageAny'),
                'manage' => (bool) $auth->canDo('shifts.manageAny'),
            ],
            'currentUser' => ['id' => $auth->id, 'name' => $auth->name],
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // CRUD / Workflow Methods
    // ═════════════════════════════════════════════════════════

    // ─── Prescriber Orders CRUD ─────────────────────────────

    public function storePrescription(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.orders.manage'), 403);

        return $this->medicationScope->forClient(
            $user,
            (int) $request->input('client_id'),
            now(),
            function (MedicationScopeDecision $scope) use ($request, $user) {
                $target = $request->validate([
                    'client_id' => 'required|integer',
                    'client_medication_id' => 'nullable|integer|min:1',
                ]);
                abort_unless((int) $target['client_id'] === (int) $scope->client->id, 404);
                $medication = null;
                if (isset($target['client_medication_id'])) {
                    $medication = ClientMedication::query()
                        ->whereKey($target['client_medication_id'])
                        ->where('client_id', $scope->client->id)
                        ->where('state', 'active')
                        ->where('active', true)
                        ->where('approval_status', 'verified')
                        ->whereNull('deleted_at')
                        ->whereNull('superseded_by')
                        ->lockForUpdate()
                        ->first();
                    abort_unless($medication, 404, 'The requested medication action is not available.');
                    $this->assertControlledMedicationOrderWriteAuthority(
                        $user,
                        (bool) $medication->controlled_drug,
                    );
                }

                $validated = $request->validate([
                    'client_id' => 'required|integer',
                    'client_medication_id' => 'nullable|integer|min:1',
                    'controlled_drug_snapshot' => [
                        'nullable',
                        'boolean',
                        Rule::requiredIf(fn () => blank($request->input('client_medication_id'))),
                    ],
                    'order_type' => 'required|in:new,change,cease,verbal,telephone',
                    'prescriber_name' => 'required|string|max:255',
                    'prescriber_registration' => 'nullable|string|max:255',
                    'prescriber_type' => 'nullable|string|max:255',
                    'medication_name' => 'required|string|max:255',
                    'dose' => 'required|string|max:255',
                    'route' => 'required|string|max:255',
                    'frequency' => 'required|string|max:255',
                    'instructions' => 'nullable|string',
                    'indication' => 'nullable|string',
                    'clinical_notes' => 'nullable|string',
                    'order_date' => 'required|date',
                    'effective_date' => 'nullable|date|after_or_equal:order_date',
                    'expiry_date' => [
                        'nullable',
                        'date',
                        $request->filled('effective_date')
                            ? 'after_or_equal:effective_date'
                            : 'after_or_equal:order_date',
                    ],
                    'read_back_confirmed' => 'nullable|boolean',
                    'read_back_witnessed_by' => 'nullable|integer|min:1',
                    'read_back_witness_credential' => 'nullable|string|max:255',
                ]);
                if ($medication !== null && $validated['order_type'] === 'cease') {
                    $validated['medication_name'] = $medication->name;
                }

                $controlledSnapshot = $medication !== null
                    ? (bool) $medication->controlled_drug
                    : (bool) $validated['controlled_drug_snapshot'];
                abort_if(
                    $controlledSnapshot
                        && (! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                            || ! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                    404,
                    'The requested medication action is not available.',
                );
                $readBackWitness = null;
                $readBackWitnessedAt = null;
                $readBackVerificationMethod = null;
                if (in_array($validated['order_type'], ['verbal', 'telephone'], true)) {
                    if (! ($validated['read_back_confirmed'] ?? false)) {
                        throw ValidationException::withMessages([
                            'read_back_confirmed' => 'Confirm the read-back before saving a verbal or telephone order.',
                        ]);
                    }
                    if (empty($validated['read_back_witnessed_by'])) {
                        throw ValidationException::withMessages([
                            'read_back_witnessed_by' => 'Choose the current Site staff member who witnessed the read-back.',
                        ]);
                    }
                    $readBackWitness = $this->lockCurrentMedicationOrderStaff(
                        $user,
                        $scope->client,
                        (int) $validated['read_back_witnessed_by'],
                        'read_back_witnessed_by',
                        true,
                    );
                    if (blank($validated['read_back_witness_credential'] ?? null)) {
                        throw ValidationException::withMessages([
                            'read_back_witness_credential' => 'The read-back witness must enter their password or PIN.',
                        ]);
                    }
                    $rateLimitKey = $this->prescriptionReadBackRateLimitKey(
                        $user,
                        $readBackWitness,
                        $scope->client,
                    );
                    if (RateLimiter::tooManyAttempts(
                        $rateLimitKey,
                        self::PRESCRIPTION_READ_BACK_ATTEMPT_LIMIT,
                    )) {
                        $this->rejectPrescriptionReadBackCredential(
                            $user,
                            $readBackWitness,
                            $scope->client,
                            'throttled',
                            RateLimiter::attempts($rateLimitKey),
                        );
                    }
                    if (! Hash::check((string) $validated['read_back_witness_credential'], (string) $readBackWitness->password)) {
                        RateLimiter::hit($rateLimitKey, self::PRESCRIPTION_READ_BACK_DECAY_SECONDS);
                        $this->rejectPrescriptionReadBackCredential(
                            $user,
                            $readBackWitness,
                            $scope->client,
                            'mismatch',
                            RateLimiter::attempts($rateLimitKey),
                        );
                    }
                    RateLimiter::clear($rateLimitKey);
                    $readBackWitnessedAt = now();
                    $readBackVerificationMethod = MedicationPrescriberOrder::READ_BACK_VERIFICATION_METHOD_PASSWORD;
                } else {
                    unset(
                        $validated['read_back_confirmed'],
                        $validated['read_back_witnessed_by'],
                    );
                }
                unset($validated['read_back_witness_credential']);

                $payload = $validated;
                $payload['client_id'] = $scope->client->id;
                $payload['controlled_drug_snapshot'] = $controlledSnapshot;
                $payload['received_by'] = $user->id;
                $payload['requires_countersign'] = in_array($payload['order_type'], ['verbal', 'telephone']);
                $payload['status'] = 'pending';
                $payload['read_back_verified_at'] = $readBackWitnessedAt;
                $payload['read_back_verification_method'] = $readBackVerificationMethod;

                // Blank values are converted to null; omit this NOT NULL field
                // so the schema's canonical default applies.
                if (blank($payload['prescriber_type'] ?? null)) {
                    unset($payload['prescriber_type']);
                }

                $order = MedicationPrescriberOrder::create($payload);
                AuditLogger::logOrFail('medications.prescriber_order.created', $order, [
                    'actor_id' => (int) $user->id,
                    'client_id' => (int) $scope->client->id,
                    'client_medication_id' => $medication?->id,
                    'order_id' => (int) $order->id,
                    'status_before' => null,
                    'status_after' => 'pending',
                    'read_back_witnessed_by' => $readBackWitness?->id,
                    'read_back_witness_method' => $readBackVerificationMethod,
                    'read_back_witnessed_at' => $readBackWitnessedAt?->toIso8601String(),
                ]);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'created_prescriber_order',
                    'Order '.$order->id,
                );

                return redirect()->back();
            },
        );
    }

    /**
     * A "cease" prescriber order referencing an active medication must actually
     * stop the medication appearing on rounds/MAR — recording the order without
     * discontinuing the ClientMedication left it administrable.
     */
    private function applyCeaseOrder(
        MedicationPrescriberOrder $order,
        User $performer,
        ?ClientMedication $medication = null,
        ?Carbon $ceasedAt = null,
    ): void {
        if ($order->order_type !== 'cease' || ! $order->client_medication_id) {
            return;
        }

        $medication ??= ClientMedication::query()
            ->whereKey($order->client_medication_id)
            ->where('client_id', $order->client_id)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
        abort_unless($medication, 404, 'The requested medication action is not available.');

        $this->medicationOrderLifecycle->discontinue(
            $performer,
            $medication,
            mb_substr(
                'Prescriber cease order — '.$order->prescriber_name
                    .(filled($order->clinical_notes) ? ': '.$order->clinical_notes : ''),
                0,
                255,
            ),
            (int) $order->client_id,
            $ceasedAt ?? now(),
            requestKey: 'prescriber-order:'.$order->id,
        );
    }

    private function assertControlledPrescriptionWriteAuthority(
        MedicationScopeDecision $scope,
        User $actor,
    ): void {
        abort_if(
            $scope->prescription->requiresControlledView($scope->medication)
                && (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                    || ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
            404,
            'The requested medication action is not available.',
        );
    }

    private function assertControlledMedicationOrderWriteAuthority(User $actor, bool $controlled): void
    {
        abort_if(
            $controlled
                && (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                    || ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
            404,
            'The requested medication action is not available.',
        );
    }

    private function assertActiveVerifiedPrescriptionMedication(ClientMedication $medication): void
    {
        abort_unless(
            $medication->state === 'active'
            && (bool) $medication->active
            && $medication->approval_status === 'verified'
            && $medication->superseded_by === null
            && $medication->deleted_at === null,
            404,
            'The requested medication action is not available.',
        );
    }

    /**
     * A verified verbal/telephone read-back attests the clinical content and
     * medication identity that the witness heard. Until a separately governed
     * re-witness flow exists, this update route may only accept byte-equivalent
     * repeats of those fields.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function prescriptionUpdatePayloadAfterReadBackFreeze(
        MedicationPrescriberOrder $order,
        array $validated,
    ): array {
        if (! $order->requires_countersign
            || ! in_array($order->order_type, ['verbal', 'telephone'], true)
            || ! $order->hasVerifiedReadBack()) {
            return $validated;
        }

        $errors = [];
        foreach ([
            'instructions' => 'The witnessed read-back instructions cannot be changed. Record a new order with a new read-back instead.',
            'clinical_notes' => 'The witnessed read-back clinical notes cannot be changed. Record a new order with a new read-back instead.',
        ] as $field => $message) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            if ($validated[$field] !== $order->getAttribute($field)) {
                $errors[$field] = $message;
            } else {
                unset($validated[$field]);
            }
        }

        if (array_key_exists('client_medication_id', $validated)) {
            $submittedMedicationId = $validated['client_medication_id'] === null
                ? null
                : (int) $validated['client_medication_id'];
            $attestedMedicationId = $order->client_medication_id === null
                ? null
                : (int) $order->client_medication_id;
            if ($submittedMedicationId !== $attestedMedicationId) {
                $errors['client_medication_id'] = 'The medication identity and classification witnessed during read-back cannot be linked, unlinked, or changed.';
            } else {
                unset($validated['client_medication_id']);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    public function updatePrescription(Request $request, MedicationPrescriberOrder $order)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.orders.manage'), 403);

        return $this->medicationScope->forPrescription(
            $user,
            $order,
            now(),
            function (MedicationScopeDecision $scope) use ($request, $user) {
                $this->assertControlledPrescriptionWriteAuthority($scope, $user);
                $this->assertPrescriptionNotExpired($scope->prescription, 'update');
                $validated = $request->validate([
                    'status' => 'prohibited',
                    'client_medication_id' => 'nullable|integer',
                    'pharmacy_notes' => 'prohibited',
                    'pharmacy_name' => 'prohibited',
                    'batch_number' => 'prohibited',
                    'batch_expiry' => 'prohibited',
                    'dispensed_by' => 'prohibited',
                    'dispensed_at' => 'prohibited',
                    'clinical_notes' => 'nullable|string',
                    'instructions' => 'nullable|string',
                ]);
                if ($scope->prescription->status !== 'pending') {
                    throw ValidationException::withMessages([
                        'update' => 'Only a pending prescriber order can be edited or linked.',
                    ]);
                }
                $payload = $this->prescriptionUpdatePayloadAfterReadBackFreeze(
                    $scope->prescription,
                    $validated,
                );
                $linkedMedication = null;
                if (array_key_exists('client_medication_id', $payload)) {
                    if ($scope->prescription->client_medication_id !== null) {
                        if ($payload['client_medication_id'] === null
                            || (int) $payload['client_medication_id'] !== (int) $scope->prescription->client_medication_id) {
                            throw ValidationException::withMessages([
                                'client_medication_id' => 'A linked prescriber order cannot be unlinked or attached to a different medication.',
                            ]);
                        }
                        unset($payload['client_medication_id']);
                    } elseif ($payload['client_medication_id'] !== null) {
                        $linkedMedication = ClientMedication::query()
                            ->whereKey($payload['client_medication_id'])
                            ->where('client_id', $scope->client->id)
                            ->where('state', 'active')
                            ->where('active', true)
                            ->where('approval_status', 'verified')
                            ->whereNull('deleted_at')
                            ->whereNull('superseded_by')
                            ->lockForUpdate()
                            ->first();
                        abort_unless($linkedMedication, 404, 'The requested medication action is not available.');
                        abort_if(
                            $linkedMedication->controlled_drug
                                && (! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                                    || ! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                            404,
                            'The requested medication action is not available.',
                        );

                        $linkedSnapshot = (bool) $linkedMedication->controlled_drug;
                        if ($scope->prescription->controlled_drug_snapshot !== null
                            && $scope->prescription->controlled_drug_snapshot !== $linkedSnapshot) {
                            throw ValidationException::withMessages([
                                'client_medication_id' => 'The charted medication classification does not match this order.',
                            ]);
                        }
                        $payload['client_medication_id'] = $linkedMedication->id;
                        $payload['controlled_drug_snapshot'] = $linkedSnapshot;
                        $payload['medication_name'] = $linkedMedication->name;
                    } else {
                        unset($payload['client_medication_id']);
                    }
                }

                if ($payload === []) {
                    return redirect()->back();
                }

                $scope->prescription->update($payload);
                AuditLogger::logOrFail(
                    $linkedMedication
                        ? 'medications.prescriber_order.linked'
                        : 'medications.prescriber_order.updated',
                    $scope->prescription,
                    [
                        'actor_id' => (int) $user->id,
                        'client_id' => (int) $scope->client->id,
                        'client_medication_id' => $scope->prescription->client_medication_id,
                        'order_id' => (int) $scope->prescription->id,
                        'status_before' => 'pending',
                        'status_after' => 'pending',
                    ],
                );
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    $linkedMedication ? 'linked_prescriber_order' : 'updated_prescriber_order',
                    'Order '.$scope->prescription->id,
                );

                return redirect()->back();
            },
        );
    }

    public function confirmPrescription(Request $request, MedicationPrescriberOrder $order)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.orders.verify'), 403);

        return $this->medicationScope->forPrescription(
            $user,
            $order,
            now(),
            function (MedicationScopeDecision $scope) use ($user) {
                $this->assertControlledPrescriptionWriteAuthority($scope, $user);
                $this->assertPrescriptionChronology($scope->prescription, 'confirm');
                $this->assertPrescriptionNotExpired($scope->prescription, 'confirm');
                if ($scope->prescription->status !== 'pending'
                    || $scope->prescription->requires_countersign
                    || in_array($scope->prescription->order_type, ['verbal', 'telephone'], true)
                    || $scope->prescription->received_by === null
                    || ($scope->prescription->order_type === 'cease' && $scope->medication === null)) {
                    throw ValidationException::withMessages([
                        'confirm' => 'This prescriber order cannot be confirmed through this transition.',
                    ]);
                }
                if ((int) $scope->prescription->received_by === (int) $user->id) {
                    throw ValidationException::withMessages([
                        'confirm' => 'A prescriber order must be confirmed by a different authorised worker from the recorder.',
                    ]);
                }
                if ($scope->prescription->order_type === 'cease') {
                    abort_unless($user->canDo('medications.orders.manage'), 403);
                }
                if ($scope->medication !== null) {
                    $this->assertActiveVerifiedPrescriptionMedication($scope->medication);
                }
                $this->assertCeaseOrderEffective($scope->prescription, 'confirm');

                $confirmedAt = now();
                $scope->prescription->update(['status' => 'confirmed']);
                $this->applyCeaseOrder($scope->prescription, $user, $scope->medication, $confirmedAt);
                AuditLogger::logOrFail('medications.prescriber_order.confirmed', $scope->prescription, [
                    'actor_id' => (int) $user->id,
                    'client_id' => (int) $scope->client->id,
                    'client_medication_id' => $scope->prescription->client_medication_id,
                    'order_id' => (int) $scope->prescription->id,
                    'status_before' => 'pending',
                    'status_after' => 'confirmed',
                    'confirmed_at' => $confirmedAt->toIso8601String(),
                ]);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'confirmed_prescriber_order',
                    'Order '.$scope->prescription->id,
                );

                return redirect()->back();
            },
        );
    }

    public function countersignPrescription(Request $request, MedicationPrescriberOrder $order)
    {
        $user = $request->user();
        abort_unless(
            $user
            && $user->canDo('medications.orders.manage')
            && $user->canDo('medications.orders.verify'),
            403,
        );

        return $this->medicationScope->forPrescription(
            $user,
            $order,
            now(),
            function (MedicationScopeDecision $scope) use ($request, $user) {
                $this->assertControlledPrescriptionWriteAuthority($scope, $user);
                $this->assertPrescriptionChronology($scope->prescription, 'countersign');
                $this->assertPrescriptionNotExpired($scope->prescription, 'countersign');
                $validated = $request->validate([
                    'countersign_method' => [
                        'required',
                        'string',
                        Rule::in(['in_person', 'electronic']),
                    ],
                    'prescriber_declaration' => 'accepted',
                ]);
                if ($scope->prescription->status !== 'pending'
                    || ! $scope->prescription->requires_countersign
                    || ! in_array($scope->prescription->order_type, ['verbal', 'telephone', 'cease'], true)
                    || ! $scope->prescription->hasVerifiedReadBack()
                    || $scope->prescription->received_by === null
                    || ($scope->prescription->order_type === 'cease' && $scope->medication === null)
                    || $scope->prescription->countersigned_at !== null) {
                    throw ValidationException::withMessages([
                        'countersign' => 'This prescriber order cannot be countersigned through this transition.',
                    ]);
                }
                if ((int) $scope->prescription->received_by === (int) $user->id
                    || (int) $scope->prescription->read_back_witnessed_by === (int) $user->id) {
                    throw ValidationException::withMessages([
                        'countersign' => 'Choose an independent countersigner who did not record or witness the read-back.',
                    ]);
                }
                if ($scope->medication !== null) {
                    $this->assertActiveVerifiedPrescriptionMedication($scope->medication);
                }
                $this->assertCeaseOrderEffective($scope->prescription, 'countersign');

                $countersignedAt = now();
                $scope->prescription->update([
                    'countersigned_at' => $countersignedAt,
                    'countersigned_by' => $user->id,
                    'countersign_method' => $validated['countersign_method'],
                    'status' => 'confirmed',
                ]);
                $this->applyCeaseOrder($scope->prescription, $user, $scope->medication, $countersignedAt);
                AuditLogger::logOrFail('medications.prescriber_order.countersigned', $scope->prescription, [
                    'actor_id' => (int) $user->id,
                    'client_id' => (int) $scope->client->id,
                    'client_medication_id' => $scope->prescription->client_medication_id,
                    'order_id' => (int) $scope->prescription->id,
                    'status_before' => 'pending',
                    'status_after' => 'confirmed',
                    'countersign_method' => $validated['countersign_method'],
                    'countersigned_at' => $countersignedAt->toIso8601String(),
                ]);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'countersigned_prescriber_order',
                    'Order '.$scope->prescription->id,
                );

                return redirect()->back();
            },
        );
    }

    public function dispensePrescription(Request $request, MedicationPrescriberOrder $order)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.orders.manage'), 403);

        return $this->medicationScope->forPrescription(
            $user,
            $order,
            now(),
            function (MedicationScopeDecision $scope) use ($request, $user) {
                $this->assertControlledPrescriptionWriteAuthority($scope, $user);
                $this->assertPrescriptionChronology($scope->prescription, 'dispense');
                $this->assertPrescriptionNotExpired($scope->prescription, 'dispense');
                $validated = $request->validate([
                    'status' => 'prohibited',
                    'dispensed_by' => 'prohibited',
                    'dispensed_at' => 'required|date',
                    'pharmacy_name' => 'required|string|max:255',
                    'batch_number' => 'nullable|string|max:255',
                    'batch_expiry' => 'nullable|date',
                    'pharmacy_notes' => 'nullable|string|max:2000',
                ]);
                if ($scope->prescription->status !== 'confirmed'
                    || $scope->prescription->order_type === 'cease'
                    || $scope->prescription->dispensed_at !== null) {
                    throw ValidationException::withMessages([
                        'dispense' => 'Only an undispensed confirmed medication order can be dispensed.',
                    ]);
                }
                if ($scope->medication !== null) {
                    $this->assertActiveVerifiedPrescriptionMedication($scope->medication);
                }
                $workerTimezone = config('app.worker_timezone', 'Pacific/Auckland');
                $dispensedDate = Carbon::parse($validated['dispensed_at'], $workerTimezone)->toDateString();
                $orderDate = $scope->prescription->order_date?->toDateString();
                if ($orderDate === null || $dispensedDate < $orderDate) {
                    throw ValidationException::withMessages([
                        'dispensed_at' => 'The dispensing date cannot be before the order date.',
                    ]);
                }
                if ($dispensedDate > now($workerTimezone)->toDateString()) {
                    throw ValidationException::withMessages([
                        'dispensed_at' => 'The dispensing date cannot be in the future.',
                    ]);
                }

                $scope->prescription->update([
                    'status' => 'dispensed',
                    'dispensed_by' => $user->id,
                    'dispensed_at' => Carbon::parse($dispensedDate, $workerTimezone)->startOfDay(),
                    'pharmacy_name' => $validated['pharmacy_name'],
                    'batch_number' => $validated['batch_number'] ?? null,
                    'batch_expiry' => $validated['batch_expiry'] ?? null,
                    'pharmacy_notes' => $validated['pharmacy_notes'] ?? null,
                ]);
                AuditLogger::logOrFail('medications.prescriber_order.dispensed', $scope->prescription, [
                    'actor_id' => (int) $user->id,
                    'client_id' => (int) $scope->client->id,
                    'client_medication_id' => $scope->prescription->client_medication_id,
                    'order_id' => (int) $scope->prescription->id,
                    'status_before' => 'confirmed',
                    'status_after' => 'dispensed',
                    'dispensed_at' => $scope->prescription->dispensed_at?->toIso8601String(),
                    'pharmacy_name' => $scope->prescription->pharmacy_name,
                    'batch_number' => $scope->prescription->batch_number,
                    'batch_expiry' => $scope->prescription->batch_expiry?->toDateString(),
                ]);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'dispensed_prescriber_order',
                    'Order '.$scope->prescription->id,
                );

                return redirect()->back();
            },
        );
    }

    public function cancelPrescription(Request $request, MedicationPrescriberOrder $order)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.orders.manage'), 403);

        return $this->medicationScope->forPrescription(
            $user,
            $order,
            now(),
            function (MedicationScopeDecision $scope) use ($request, $user) {
                $this->assertControlledPrescriptionWriteAuthority($scope, $user);
                $this->assertPrescriptionNotExpired($scope->prescription, 'cancel');
                $validated = $request->validate([
                    'reason' => 'required|string|max:500',
                ]);
                if ($scope->prescription->status !== 'pending') {
                    throw ValidationException::withMessages([
                        'cancel' => 'Only a pending prescriber order can be cancelled.',
                    ]);
                }

                $scope->prescription->update(['status' => 'cancelled']);
                AuditLogger::logOrFail('medications.prescriber_order.cancelled', $scope->prescription, [
                    'actor_id' => (int) $user->id,
                    'client_id' => (int) $scope->client->id,
                    'client_medication_id' => $scope->prescription->client_medication_id,
                    'order_id' => (int) $scope->prescription->id,
                    'status_before' => 'pending',
                    'status_after' => 'cancelled',
                    'reason' => trim($validated['reason']),
                ]);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'cancelled_prescriber_order',
                    'Order '.$scope->prescription->id,
                );

                return redirect()->back();
            },
        );
    }

    // ─── Covert Authorisations CRUD ─────────────────────────

    public function storeCovert(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forMedication(
            $actor,
            (int) $request->input('client_medication_id'),
            'medications.orders.manage',
            function (Client $client, ClientMedication $medication) use ($request, $actor) {
                $this->assertActiveVerifiedPrescriptionMedication($medication);
                abort_if(
                    $medication->controlled_drug
                        && (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                            || ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                    404,
                );
                $validated = $request->validate([
                    'client_id' => 'required|integer|min:1',
                    'client_medication_id' => 'required|integer|min:1',
                    'authorised_by_name' => 'required|string|max:255',
                    'authorised_by_registration' => 'nullable|string|max:255',
                    'clinical_justification' => 'required|string',
                    'legal_basis' => 'nullable|string',
                    'administration_method' => 'nullable|string|max:255',
                    'pharmacist_advice' => 'nullable|string',
                    'authorised_date' => 'required|date',
                    'review_date' => 'required|date|after:authorised_date',
                ]);
                abort_unless(
                    (int) $validated['client_id'] === (int) $client->id
                        && (int) $validated['client_medication_id'] === (int) $medication->id,
                    404,
                );
                $actionDate = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
                if (Carbon::parse($validated['authorised_date'])->toDateString() > $actionDate) {
                    throw ValidationException::withMessages([
                        'authorised_date' => 'The authorisation date cannot be in the future.',
                    ]);
                }
                if (Carbon::parse($validated['review_date'])->toDateString() < $actionDate) {
                    throw ValidationException::withMessages([
                        'review_date' => 'The review date must not already have lapsed.',
                    ]);
                }
                $activeAuthorisations = MedicationCovertAuthorisation::query()
                    ->where('client_id', $client->id)
                    ->where('client_medication_id', $medication->id)
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($activeAuthorisations->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'clinical_justification' => 'Revoke the current covert authorisation before recording another one.',
                    ]);
                }

                $authorisation = MedicationCovertAuthorisation::create(array_merge($validated, [
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'status' => 'active',
                    'recorded_by' => $actor->id,
                ]));
                AuditLogger::logOrFail('medications.covert_authorisation.created', $authorisation, [
                    'actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id,
                    'client_medication_id' => (int) $medication->id,
                    'authorisation_id' => (int) $authorisation->id,
                    'status_before' => null,
                    'status_after' => 'active',
                    'authorised_date' => $authorisation->authorised_date?->toDateString(),
                    'review_date' => $authorisation->review_date?->toDateString(),
                ]);

                return redirect()->back();
            },
            (int) $request->input('client_id'),
        );
    }

    public function revokeCovert(Request $request, MedicationCovertAuthorisation $authorisation)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forCovertAuthorisation(
            $actor,
            $authorisation,
            function (Client $client, ClientMedication $medication, MedicationCovertAuthorisation $lockedAuthorisation) use ($actor, $request) {
                abort_if(
                    $medication->controlled_drug
                        && (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                            || ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                    404,
                );
                if ($lockedAuthorisation->status !== 'active') {
                    throw ValidationException::withMessages([
                        'authorisation' => 'Only an active covert authorisation can be revoked.',
                    ]);
                }
                $validated = $request->validate([
                    'reason' => 'required|string|max:500',
                ]);
                $lockedAuthorisation->update(['status' => 'revoked']);
                AuditLogger::logOrFail('medications.covert_authorisation.revoked', $lockedAuthorisation, [
                    'actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id,
                    'client_medication_id' => (int) $medication->id,
                    'authorisation_id' => (int) $lockedAuthorisation->id,
                    'status_before' => 'active',
                    'status_after' => 'revoked',
                    'revoked_at' => now()->toIso8601String(),
                    'reason' => trim($validated['reason']),
                ]);

                return redirect()->back();
            },
        );
    }

    private function resolveMedicationReviewReviewer(
        Client $client,
        ?int $reviewerId,
        Collection $lockedUsers,
    ): ?User {
        if ($reviewerId === null) {
            return null;
        }

        abort_unless($reviewerId > 0 && (int) $client->site_id > 0, 404);
        $reviewer = $lockedUsers->get($reviewerId);
        $profile = $reviewer?->hrEmployeeProfile;
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        abort_unless(
            $reviewer instanceof User
                && ! $reviewer->hasRole('client', 'next_of_kin')
                && $profile instanceof HrEmployeeProfile
                && $profile->is_active
                && ($profile->start_date === null || $profile->start_date->toDateString() <= $today)
                && ($profile->end_date === null || $profile->end_date->toDateString() >= $today)
                && collect([
                    $profile->primary_site_id,
                    ...($profile->secondary_site_ids ?? []),
                ])->contains(fn (mixed $siteId): bool => (int) $siteId === (int) $client->site_id),
            404,
        );

        return $reviewer;
    }

    private function assertMedicationReviewIsActive(MedicationReview $review): void
    {
        if (! in_array($review->status, ['scheduled', 'overdue', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'review' => 'Completed or cancelled medication reviews are read-only.',
            ]);
        }
    }

    private function assertMedicationReviewCanAdvanceActions(MedicationReview $review): void
    {
        if ($review->status !== 'completed') {
            throw ValidationException::withMessages([
                'review' => 'Only a completed medication review can advance recommendation actions.',
            ]);
        }
    }

    // ─── Reviews CRUD ───────────────────────────────────────

    public function storeReview(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $clientId = (int) $request->validate([
            'client_id' => 'required|integer|min:1',
        ])['client_id'];
        $reviewerId = $this->positiveMedicationReviewInputId($request, 'reviewer_user_id');

        return $this->governanceScope->forClient(
            $actor,
            $clientId,
            'medications.orders.manage',
            function (Client $client, User $lockedActor, Collection $lockedUsers) use ($request) {
                $validated = $request->validate([
                    'client_id' => 'required|integer|min:1',
                    'review_type' => 'required|string|max:255',
                    'scheduled_date' => 'required|date',
                    'reviewer_name' => 'nullable|string|max:255',
                    'reviewer_role' => 'nullable|string|max:255',
                    'reviewer_user_id' => ['nullable', 'integer', 'min:1'],
                    'trigger_reason' => 'nullable|string',
                ]);
                abort_unless((int) $validated['client_id'] === (int) $client->id, 404);
                if (array_key_exists('reviewer_user_id', $validated)) {
                    $reviewer = $this->resolveMedicationReviewReviewer(
                        $client,
                        $validated['reviewer_user_id'] !== null
                            ? (int) $validated['reviewer_user_id']
                            : null,
                        $lockedUsers,
                    );
                    $validated['reviewer_user_id'] = $reviewer?->id;
                }

                MedicationReview::create(array_merge($validated, [
                    'client_id' => $client->id,
                    'status' => 'scheduled',
                    'requested_by' => $lockedActor->id,
                ]));

                return redirect()->back();
            },
            authorizationUserIds: array_filter([$reviewerId]),
        );
    }

    public function updateReview(Request $request, MedicationReview $review)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $reviewerId = $this->positiveMedicationReviewInputId($request, 'reviewer_user_id');

        return $this->governanceScope->forReview($actor, $review, function (
            Client $client,
            MedicationReview $lockedReview,
            User $lockedActor,
            Collection $lockedUsers,
        ) use ($request) {
            $validated = $request->validate([
                'review_type' => 'nullable|string|max:255',
                'scheduled_date' => 'nullable|date',
                'reviewer_name' => 'nullable|string|max:255',
                'reviewer_role' => 'nullable|string|max:255',
                'reviewer_user_id' => ['nullable', 'integer', 'min:1'],
                'trigger_reason' => 'nullable|string',
            ]);
            $this->assertMedicationReviewIsActive($lockedReview);
            if (array_key_exists('reviewer_user_id', $validated)) {
                $reviewer = $this->resolveMedicationReviewReviewer(
                    $client,
                    $validated['reviewer_user_id'] !== null
                        ? (int) $validated['reviewer_user_id']
                        : null,
                    $lockedUsers,
                );
                $validated['reviewer_user_id'] = $reviewer?->id;
            }
            $lockedReview->update($validated);

            return redirect()->back();
        }, authorizationUserIds: array_filter([$reviewerId]));
    }

    private function positiveMedicationReviewInputId(Request $request, string $key): ?int
    {
        $value = filter_var($request->input($key), FILTER_VALIDATE_INT);

        return $value !== false && $value > 0 ? $value : null;
    }

    public function completeReview(Request $request, MedicationReview $review)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forReview($actor, $review, function (Client $client, MedicationReview $lockedReview) use ($request) {
            $this->assertMedicationReviewIsActive($lockedReview);
            $validated = $request->validate([
                'clinical_summary' => 'required|string',
                'medications_reviewed' => 'nullable|array',
                'drug_burden_index' => 'nullable|numeric|min:0|max:99.99',
                'falls_last_quarter' => 'nullable|integer|min:0',
                'recommendations' => 'nullable|string',
                'actions' => 'nullable|array',
                'whanau_involved' => 'nullable|boolean',
                'whanau_notes' => 'nullable|string',
                'next_review_date' => 'nullable|date',
            ]);
            $validated['status'] = 'completed';
            $validated['completed_date'] = today();
            $validated['next_review_date'] = $validated['next_review_date']
                ?? today()->addMonthsNoOverflow((int) ($client->chart_review_interval_months ?: 3))->toDateString();

            $lockedReview->update($validated);
            $client->forceFill([
                'next_chart_review_date' => $validated['next_review_date'],
            ])->save();

            return redirect()->back();
        });
    }

    public function destroyReview(Request $request, MedicationReview $review)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forReview($actor, $review, function (Client $client, MedicationReview $lockedReview) use ($actor, $request) {
            $this->assertMedicationReviewIsActive($lockedReview);
            $validated = $request->validate([
                'reason' => ['required', 'string', 'min:10', 'max:500'],
            ]);
            $reason = trim($validated['reason']);
            $statusBefore = (string) $lockedReview->status;
            $lockedReview->update(['status' => 'cancelled']);
            AuditLogger::logOrFail('medications.review.cancelled', $lockedReview, [
                'actor_id' => (int) $actor->id,
                'client_id' => (int) $client->id,
                'review_id' => (int) $lockedReview->id,
                'status_before' => $statusBefore,
                'status_after' => 'cancelled',
                'reason' => $reason,
            ]);

            return redirect()->back();
        });
    }

    /**
     * Advance a single deprescribing recommendation through its lifecycle
     * (gp → implemented → monitor → done). Accepting a recommendation as it
     * leaves "Awaiting GP" records the GP decision (gap G2).
     */
    public function advanceReviewAction(Request $request, MedicationReview $review)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forReview($actor, $review, function (Client $client, MedicationReview $lockedReview) use ($request) {
            $this->assertMedicationReviewCanAdvanceActions($lockedReview);
            $validated = $request->validate([
                'index' => 'required|integer|min:0',
            ]);
            $actions = $lockedReview->actions ?? [];
            $index = $validated['index'];

            abort_unless(isset($actions[$index]) && is_array($actions[$index]), 404, 'Recommendation not found.');

            $flow = ['gp' => 'implemented', 'implemented' => 'monitor', 'monitor' => 'done'];
            $current = $actions[$index]['stage'] ?? 'gp';
            $next = $flow[$current] ?? null;

            if (! $next) {
                return redirect()->back();
            }

            $actions[$index]['stage'] = $next;
            if ($current === 'gp') {
                $actions[$index]['gp_status'] = 'accepted';
            }

            $lockedReview->update(['actions' => $actions]);

            return redirect()->back();
        });
    }

    // ─── 1CHART Attention / INR / Syringe Driver Workflows ─────

    public function storeAttentionAlert(Request $request, Client $client)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClient($actor, (int) $client->id, 'medications.orders.manage', function (Client $lockedClient) use ($request, $actor) {
            $validated = $request->validate([
                'type' => ['required', 'string', Rule::in(['paper_prescription', 'chart_warning', 'warfarin'])],
                'title' => ['required', 'string', 'max:255'],
                'detail' => ['nullable', 'string', 'max:2000'],
                'prompt_on_open' => ['nullable', 'boolean'],
                'enabled' => ['nullable', 'boolean'],
            ]);

            $lockedClient->medicationAlerts()->create([
                ...$validated,
                'prompt_on_open' => (bool) ($validated['prompt_on_open'] ?? false),
                'enabled' => (bool) ($validated['enabled'] ?? true),
                'created_by' => $actor->id,
            ]);
            app(MedicationAlertService::class)->generateClientAlerts($lockedClient->fresh());

            return redirect()->back()->with('success', 'Medication chart alert added.');
        });
    }

    public function updateAttentionAlert(Request $request, ClientMedicationAlert $alert)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClientRecord($actor, $alert, 'medications.orders.manage', function (Client $client, ClientMedicationAlert $lockedAlert) use ($request) {
            $validated = $request->validate([
                'type' => ['nullable', 'string', Rule::in(['paper_prescription', 'chart_warning', 'warfarin'])],
                'title' => ['nullable', 'string', 'max:255'],
                'detail' => ['nullable', 'string', 'max:2000'],
                'prompt_on_open' => ['nullable', 'boolean'],
                'enabled' => ['nullable', 'boolean'],
            ]);
            $lockedAlert->update($validated);
            app(MedicationAlertService::class)->generateClientAlerts($client->fresh());

            return redirect()->back()->with('success', 'Medication chart alert updated.');
        });
    }

    public function resolveAttentionAlert(Request $request, ClientMedicationAlert $alert)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClientRecord($actor, $alert, 'medications.orders.manage', function (Client $client, ClientMedicationAlert $lockedAlert) use ($actor) {
            $lockedAlert->resolve($actor->id);

            return redirect()->back()->with('success', 'Medication chart alert resolved.');
        });
    }

    public function toggleMedicationAlertSuppression(Request $request, Client $client)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClient($actor, (int) $client->id, 'medications.orders.manage', function (Client $lockedClient) use ($request, $actor) {
            $validated = $request->validate([
                'suppress_med_admin_alerts' => ['required', 'boolean'],
                'reason' => ['nullable', 'string', 'max:500'],
                // Structured lawful basis for suppressing a safety alert — free text
                // alone left auditors unable to verify the decision was grounded
                // (capacity / MDT / clinical judgement / client preference).
                'basis' => ['nullable', 'string', Rule::in(['capacity_assessment', 'mdt_decision', 'clinical_judgement', 'client_preference'])],
            ]);

            if ($validated['suppress_med_admin_alerts'] && blank($validated['reason'] ?? null)) {
                throw ValidationException::withMessages([
                    'reason' => 'Enter why medication administration alerts are being suppressed.',
                ]);
            }

            if ($validated['suppress_med_admin_alerts'] && blank($validated['basis'] ?? null)) {
                throw ValidationException::withMessages([
                    'basis' => 'Select the basis for suppressing these alerts (capacity assessment, MDT decision, clinical judgement, or client preference).',
                ]);
            }

            $basisLabel = match ($validated['basis'] ?? null) {
                'capacity_assessment' => 'Capacity assessment',
                'mdt_decision' => 'MDT decision',
                'clinical_judgement' => 'Clinical judgement',
                'client_preference' => 'Client preference',
                default => null,
            };

            $lockedClient->forceFill([
                'suppress_med_admin_alerts' => (bool) $validated['suppress_med_admin_alerts'],
                'med_alerts_suppressed_reason' => $validated['suppress_med_admin_alerts']
                    ? trim(($basisLabel ? "[{$basisLabel}] " : '').$validated['reason'])
                    : null,
                'med_alerts_suppressed_by' => $validated['suppress_med_admin_alerts'] ? $actor->id : null,
                'med_alerts_suppressed_at' => $validated['suppress_med_admin_alerts'] ? now() : null,
            ])->save();

            app(MedicationAlertService::class)->generateClientAlerts($lockedClient->fresh());

            return redirect()->back()->with('success', 'Medication alert settings updated.');
        });
    }

    public function updateMedicationSettings(Request $request, Client $client)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClient($actor, (int) $client->id, 'medications.orders.manage', function (Client $lockedClient) use ($request) {
            $validated = $request->validate([
                'care_level' => ['nullable', 'string', 'max:60'],
                'chart_review_interval_months' => ['nullable', 'integer', 'min:1', 'max:12'],
                'next_chart_review_date' => ['nullable', 'date'],
            ]);

            $lockedClient->forceFill([
                'care_level' => $validated['care_level'] ?? null,
                'chart_review_interval_months' => $validated['chart_review_interval_months'] ?? $lockedClient->chart_review_interval_months ?? 3,
                'next_chart_review_date' => $validated['next_chart_review_date'] ?? null,
            ])->save();
            app(MedicationAlertService::class)->generateClientAlerts($lockedClient->fresh());

            return redirect()->back()->with('success', 'Medication chart settings updated.');
        });
    }

    /**
     * Retired endpoint. INR now lives on the MAR clinical rail (Record-INR
     * modal + disable-not-delete history, served in the page payload), so this
     * standalone JSON endpoint has no UI consumer. Redirect any direct hit to
     * the resident's MAR chart rather than dumping raw JSON / 404ing.
     */
    public function inrHistory(Request $request, Client $client)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            requestedClientId: (int) $client->id,
        );

        return redirect()->route('emar.mar', ['client_id' => $client->id]);
    }

    public function storeInr(Request $request, Client $client)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClient($actor, (int) $client->id, 'medications.orders.manage', function (Client $lockedClient) use ($request, $actor) {
            $record = function (?ClientMedication $medication = null) use ($request, $actor, $lockedClient) {
                $validated = $request->validate([
                    'client_medication_id' => ['nullable', 'integer'],
                    'inr_value' => ['required', 'numeric', 'min:0.5', 'max:20'],
                    'target_range_low' => ['nullable', 'numeric', 'min:0.5', 'max:20'],
                    'target_range_high' => ['nullable', 'numeric', 'min:0.5', 'max:20', 'gte:target_range_low'],
                    'dose_mg' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
                    'tested_on' => ['required', 'date'],
                    'next_test_date' => ['nullable', 'date', 'after_or_equal:tested_on'],
                    'notes' => ['nullable', 'string', 'max:2000'],
                ]);
                if ($medication !== null) {
                    abort_unless((int) $validated['client_medication_id'] === (int) $medication->id, 404);
                    $validated['client_medication_id'] = $medication->id;
                }

                $lockedClient->inrRecords()->create([
                    ...$validated,
                    'recorded_by' => $actor->id,
                ]);
                app(MedicationAlertService::class)->generateClientAlerts($lockedClient->fresh());

                return redirect()->back()->with('success', 'INR result recorded.');
            };

            $medicationId = (int) $request->input('client_medication_id');
            if ($medicationId <= 0) {
                return $record();
            }

            return $this->governanceScope->forMedication(
                $actor,
                $medicationId,
                'medications.orders.manage',
                function (Client $canonicalClient, ClientMedication $medication) use ($actor, $lockedClient, $record) {
                    abort_unless((int) $canonicalClient->id === (int) $lockedClient->id, 404);
                    abort_if(
                        $medication->controlled_drug
                            && ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                        404,
                    );

                    return $record($medication);
                },
                $lockedClient->id,
            );
        });
    }

    public function disableInr(Request $request, ClientInrRecord $inr)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClientRecord($actor, $inr, 'medications.orders.manage', function (Client $client, ClientInrRecord $lockedInr) use ($actor) {
            if ($lockedInr->client_medication_id !== null) {
                $medication = ClientMedication::withTrashed()
                    ->whereKey($lockedInr->client_medication_id)
                    ->where('client_id', $client->id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($medication !== null, 404);
                abort_if(
                    $medication->controlled_drug
                        && ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                    404,
                );
            }
            if (! $lockedInr->disabled_at) {
                $lockedInr->disable($actor->id);
            }
            app(MedicationAlertService::class)->generateClientAlerts($client->fresh());

            return redirect()->back()->with('success', 'INR result disabled.');
        });
    }

    public function storeSyringeDriver(Request $request, Client $client)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        $submittedWitnessId = $request->input('witnessed_by');
        $authorizationUserIds = is_numeric($submittedWitnessId) && (int) $submittedWitnessId > 0
            ? [(int) $submittedWitnessId]
            : [];
        $presenceEffectiveAt = $this->syringeDriverPresenceEffectiveAtHint(
            $request->input('commenced_at'),
        );

        return $this->governanceScope->forClient($actor, (int) $client->id, 'medications.orders.manage', function (
            Client $lockedClient,
            User $lockedActor,
            Collection $lockedUsers,
        ) use ($request, $presenceEffectiveAt) {
            $validated = $request->validate([
                'site_id' => ['nullable', 'integer', 'exists:sites,id'],
                'commenced_at' => ['required', 'date'],
                'rate' => ['nullable', 'string', 'max:80'],
                'rate_unit' => ['nullable', 'string', 'max:40'],
                'duration_hours' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
                'contents' => ['required', 'array', 'min:1'],
                'contents.*.client_medication_id' => ['required', 'integer', 'min:1'],
                'contents.*.name' => ['nullable', 'string', 'max:255'],
                'contents.*.dose' => ['nullable', 'string', 'max:80'],
                'contents.*.unit' => ['nullable', 'string', 'max:40'],
                'contents.*.requires_witness' => ['nullable', 'boolean'],
                'site_of_insertion' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'witnessed_by' => ['nullable', 'integer', 'min:1'],
                'witness_credential' => ['nullable', 'string'],
            ]);

            abort_if(isset($validated['site_id']) && (int) $validated['site_id'] !== (int) $lockedClient->site_id, 404);
            $commencedAt = $presenceEffectiveAt?->copy()
                ?? Carbon::parse($validated['commenced_at']);
            if ($commencedAt->gt(now()->addMinute())) {
                throw ValidationException::withMessages([
                    'commenced_at' => 'The syringe driver commencement time cannot be in the future.',
                ]);
            }
            $contents = $this->normaliseSyringeDriverContents($lockedClient, $validated['contents'], $lockedActor);
            $requiresWitness = collect($contents)->contains(fn ($item) => (bool) ($item['requires_witness'] ?? false));
            $witness = $requiresWitness
                ? $this->governanceScope->confirmedControlledWitness(
                    $lockedActor,
                    $lockedClient,
                    (int) ($validated['witnessed_by'] ?? 0),
                    $validated['witness_credential'] ?? null,
                    recorderId: (int) $lockedActor->id,
                    lockedUsers: $lockedUsers,
                    effectiveAt: $commencedAt,
                )
                : null;

            $driver = $lockedClient->syringeDrivers()->create([
                'site_id' => $lockedClient->site_id,
                'status' => 'running',
                'commenced_at' => $commencedAt,
                'commenced_by' => $lockedActor->id,
                'witnessed_by' => $witness?->id,
                'witnessed_at' => $witness ? now() : null,
                'witness_method' => $witness ? 'password' : null,
                'rate' => $validated['rate'] ?? null,
                'rate_unit' => $validated['rate_unit'] ?? null,
                'duration_hours' => $validated['duration_hours'] ?? null,
                'contents' => $contents,
                'site_of_insertion' => $validated['site_of_insertion'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            return redirect()->back()->with('success', "Syringe driver {$driver->id} commenced.");
        },
            authorizationUserIds: $authorizationUserIds,
            authorizationEffectiveAt: $presenceEffectiveAt,
            lockPresence: true,
        );
    }

    public function addSyringeDriverCheck(Request $request, MedicationSyringeDriver $driver)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClientRecord($actor, $driver, 'medications.orders.manage', function (Client $client, MedicationSyringeDriver $lockedDriver) use ($request, $actor) {
            abort_if($lockedDriver->site_id !== null && (int) $lockedDriver->site_id !== (int) $client->site_id, 404);
            $this->assertRunningSyringeDriverMutationAuthority($client, $lockedDriver, $actor);
            $validated = $request->validate([
                'checked_at' => ['nullable', 'date'],
                'infusion_running' => ['required', 'boolean'],
                'site_condition' => ['nullable', 'string', 'max:255'],
                'volume_remaining' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            $checkedAt = isset($validated['checked_at'])
                ? Carbon::parse($validated['checked_at'])
                : now();
            if ($checkedAt->lt($lockedDriver->commenced_at) || $checkedAt->gt(now()->addMinute())) {
                throw ValidationException::withMessages([
                    'checked_at' => 'The check time must be between commencement and the current time.',
                ]);
            }

            $lockedDriver->checks()->create([
                ...$validated,
                'checked_at' => $checkedAt,
                'checked_by' => $actor->id,
            ]);

            return redirect()->back()->with('success', 'Syringe driver check recorded.');
        });
    }

    public function completeSyringeDriver(Request $request, MedicationSyringeDriver $driver)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClientRecord($actor, $driver, 'medications.orders.manage', function (Client $client, MedicationSyringeDriver $lockedDriver) use ($request, $actor) {
            abort_if($lockedDriver->site_id !== null && (int) $lockedDriver->site_id !== (int) $client->site_id, 404);
            $this->assertRunningSyringeDriverMutationAuthority($client, $lockedDriver, $actor);
            $validated = $request->validate([
                'status' => ['nullable', 'string', Rule::in(['completed', 'stopped'])],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            // A driver must have at least one recorded running check before it can
            // be closed out — otherwise "completed" is indistinguishable from
            // "abandoned without monitoring" in the audit trail.
            $latestCheck = $lockedDriver->checks()
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($latestCheck === null) {
                throw ValidationException::withMessages([
                    'status' => 'Record at least one syringe-driver check before completing — a driver with no checks cannot be signed off as monitored.',
                ]);
            }

            $completedAt = now();
            if ($completedAt->lt($lockedDriver->commenced_at) || $completedAt->lt($latestCheck->checked_at)) {
                throw ValidationException::withMessages([
                    'status' => 'The syringe driver cannot be completed before its commencement or latest check.',
                ]);
            }

            $lockedDriver->forceFill([
                'status' => $validated['status'] ?? 'completed',
                'completed_at' => $completedAt,
                'completed_by' => $actor->id,
                'notes' => trim($lockedDriver->notes."\n".($validated['notes'] ?? '')) ?: $lockedDriver->notes,
            ])->save();

            return redirect()->back()->with('success', 'Syringe driver completed.');
        });
    }

    // ─── Competency CRUD ────────────────────────────────────

    /** @return array{0: User, 1: User, 2: array<int, int>} */
    private function lockCurrentCompetencyMutationUsers(User $actor, int $subjectUserId): array
    {
        $locks = app(PeopleMutationLockService::class)->lock([
            (int) $actor->id,
            $subjectUserId,
        ]);
        /** @var User|null $lockedActor */
        $lockedActor = $locks['users']->get((int) $actor->id);
        /** @var User|null $subject */
        $subject = $locks['users']->get($subjectUserId);
        abort_unless(
            $lockedActor instanceof User
                && $subject instanceof User
                && $lockedActor->approved_at !== null
                && $subject->approved_at !== null
                && $lockedActor->canDo('medications.orders.manage'),
            403,
        );

        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        foreach ([$lockedActor, $subject] as $staffUser) {
            $profile = $staffUser->hrEmployeeProfile;
            abort_unless(
                $profile instanceof HrEmployeeProfile
                    && $profile->is_active
                    && ($profile->start_date === null || $profile->start_date->toDateString() <= $today)
                    && ($profile->end_date === null || $profile->end_date->toDateString() >= $today),
                404,
            );
        }
        $subjectProfile = $subject->hrEmployeeProfile;

        $assignedSiteIds = collect([
            $subjectProfile->primary_site_id,
            ...($subjectProfile->secondary_site_ids ?? []),
        ])->map(fn (mixed $siteId): int => (int) $siteId)
            ->filter(fn (int $siteId): bool => $siteId > 0)
            ->unique();
        $accessibleSiteIds = $assignedSiteIds
            ->intersect($this->governanceScope->mutationSiteIds(
                $lockedActor,
                'medications.orders.manage',
            ))
            ->sort()
            ->values();
        $lockedSiteIds = Site::query()
            ->whereIn('id', $accessibleSiteIds->all())
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->all();
        abort_unless($lockedSiteIds !== [], 404);

        return [$lockedActor, $subject, $lockedSiteIds];
    }

    public function storeCompetency(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteIds = $this->governanceScope->mutationSiteIds($actor, 'medications.orders.manage');
        $submittedUserId = $request->integer('user_id');
        $this->assertCurrentStaffWithinSites($submittedUserId, $siteIds);

        $validated = $request->validate([
            'user_id' => 'required|integer|min:1',
            'assessment_type' => 'required|string|max:255',
            'assessment_date' => 'required|date',
            'expiry_date' => 'nullable|date|after_or_equal:assessment_date',
            'medication_knowledge' => 'required|boolean',
            'five_rights' => 'required|boolean',
            'safety_checks' => 'required|boolean',
            'documentation' => 'required|boolean',
            'controlled_drugs' => 'required|boolean',
            'prn_assessment' => 'required|boolean',
            'insulin_competent' => 'required|boolean',
            'inhaler_competent' => 'required|boolean',
            'topical_competent' => 'required|boolean',
            'covert_admin_knowledge' => 'required|boolean',
            'error_reporting' => 'required|boolean',
            'allergy_awareness' => 'required|boolean',
            'strengths' => 'nullable|string',
            'areas_for_improvement' => 'nullable|string',
            'action_plan' => 'nullable|string',
            'assessor_comments' => 'nullable|string',
            'observed_rounds' => 'nullable|array',
            'not_seen_areas' => 'nullable|array',
            'not_seen_areas.*' => 'string',
            'restricted' => 'nullable|boolean',
            'restriction_notes' => 'nullable|string',
            'can_administer_unsupervised' => 'nullable|boolean',
            'can_witness_controlled' => 'nullable|boolean',
            'assessor_declared' => 'nullable|boolean',
            'staff_acknowledged' => 'nullable|boolean',
        ]);

        $booleanFields = [
            'medication_knowledge', 'five_rights', 'safety_checks', 'documentation',
            'controlled_drugs', 'prn_assessment', 'insulin_competent', 'inhaler_competent',
            'topical_competent', 'covert_admin_knowledge', 'error_reporting', 'allergy_awareness',
        ];

        $totalScore = collect($booleanFields)->filter(fn ($f) => ! empty($validated[$f]))->count();

        $validated['total_score'] = $totalScore;
        $validated['pass_threshold'] = 10;
        $validated['status'] = $totalScore >= 10 ? 'passed' : 'failed';
        $validated['restricted'] = (bool) ($validated['restricted'] ?? false);
        $validated['assessor_id'] = $actor->id;
        // The assessor may record only their own declaration here. The staff
        // acknowledgement is a separate authenticated action by the subject.
        $validated['assessor_declared_at'] = ! empty($validated['assessor_declared']) ? now() : null;
        $validated['staff_acknowledged_at'] = null;
        unset($validated['assessor_declared'], $validated['staff_acknowledged']);
        $validated['expiry_date'] = $validated['expiry_date']
            ?? Carbon::parse($validated['assessment_date'])->addYear()->toDateString();
        $validated['can_administer_unsupervised'] = (bool) ($validated['can_administer_unsupervised'] ?? false);
        $validated['can_witness_controlled'] = (bool) ($validated['can_witness_controlled'] ?? false);

        DB::transaction(function () use ($actor, $validated): void {
            // Medication administration locks the same staff row before its
            // final competency decision. Serializing assessment creation here
            // closes the no-record/first-assessment race.
            [$lockedActor] = $this->lockCurrentCompetencyMutationUsers(
                $actor,
                (int) $validated['user_id'],
            );
            if ($validated['status'] === 'passed' && (int) $validated['user_id'] === (int) $lockedActor->id) {
                throw ValidationException::withMessages([
                    'user_id' => 'A passed medication competency must be assessed by a different staff member.',
                ]);
            }

            $validated['assessor_id'] = $lockedActor->id;
            MedicationCompetencyAssessment::create($validated);
        });

        return redirect()->back();
    }

    public function updateCompetency(Request $request, MedicationCompetencyAssessment $assessment)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteIds = $this->governanceScope->mutationSiteIds($actor, 'medications.orders.manage');
        $snapshot = MedicationCompetencyAssessment::query()
            ->whereKey($assessment->id)
            ->first(['id', 'user_id']);
        abort_unless($snapshot !== null, 404);
        $this->assertCurrentStaffWithinSites((int) $snapshot->user_id, $siteIds);
        $proposedUserId = $request->exists('user_id')
            ? $request->integer('user_id')
            : (int) $snapshot->user_id;
        $this->assertCurrentStaffWithinSites($proposedUserId, $siteIds);
        abort_unless($proposedUserId === (int) $snapshot->user_id, 404);

        $validated = $request->validate([
            'user_id' => 'nullable|integer|min:1',
            'assessment_type' => 'nullable|string|max:255',
            'assessment_date' => 'nullable|date',
            'medication_knowledge' => 'nullable|boolean',
            'five_rights' => 'nullable|boolean',
            'safety_checks' => 'nullable|boolean',
            'documentation' => 'nullable|boolean',
            'controlled_drugs' => 'nullable|boolean',
            'prn_assessment' => 'nullable|boolean',
            'insulin_competent' => 'nullable|boolean',
            'inhaler_competent' => 'nullable|boolean',
            'topical_competent' => 'nullable|boolean',
            'covert_admin_knowledge' => 'nullable|boolean',
            'error_reporting' => 'nullable|boolean',
            'allergy_awareness' => 'nullable|boolean',
            'strengths' => 'nullable|string',
            'areas_for_improvement' => 'nullable|string',
            'action_plan' => 'nullable|string',
            'assessor_comments' => 'nullable|string',
            'observed_rounds' => 'nullable|array',
            'not_seen_areas' => 'nullable|array',
            'not_seen_areas.*' => 'string',
            'restricted' => 'nullable|boolean',
            'restriction_notes' => 'nullable|string',
            'expiry_date' => 'nullable|date|after_or_equal:assessment_date',
            'can_administer_unsupervised' => 'nullable|boolean',
            'can_witness_controlled' => 'nullable|boolean',
            'assessor_declared' => 'nullable|boolean',
            'staff_acknowledged' => 'nullable|boolean',
        ]);

        $assessorDeclared = (bool) ($validated['assessor_declared'] ?? false);
        unset($validated['assessor_declared'], $validated['staff_acknowledged']);
        unset($validated['user_id']);

        $booleanFields = [
            'medication_knowledge', 'five_rights', 'safety_checks', 'documentation',
            'controlled_drugs', 'prn_assessment', 'insulin_competent', 'inhaler_competent',
            'topical_competent', 'covert_admin_knowledge', 'error_reporting', 'allergy_awareness',
        ];

        DB::transaction(function () use ($actor, $assessment, $validated, $booleanFields, $snapshot, $assessorDeclared): void {
            [$lockedActor] = $this->lockCurrentCompetencyMutationUsers(
                $actor,
                (int) $snapshot->user_id,
            );

            $locked = MedicationCompetencyAssessment::query()
                ->whereKey($assessment->id)
                ->where('user_id', $snapshot->user_id)
                ->lockForUpdate()
                ->first();
            abort_unless($locked !== null, 404);
            abort_unless((int) $locked->user_id !== (int) $lockedActor->id, 404);

            if ($assessorDeclared && $locked->assessor_declared_at === null) {
                abort_unless((int) $locked->assessor_id === (int) $lockedActor->id, 404);
                $validated['assessor_declared_at'] = now();
            }

            if (collect($booleanFields)->contains(fn ($field) => array_key_exists($field, $validated))) {
                $merged = array_merge($locked->only($booleanFields), $validated);
                $totalScore = collect($booleanFields)->filter(fn ($field) => ! empty($merged[$field]))->count();
                $validated['total_score'] = $totalScore;
                $validated['pass_threshold'] = 10;
                $validated['status'] = $totalScore >= 10 ? 'passed' : 'failed';
            }

            if (! array_key_exists('expiry_date', $validated) && ! empty($validated['assessment_date'])) {
                $validated['expiry_date'] = Carbon::parse($validated['assessment_date'])->addYear()->toDateString();
            }

            $locked->update($validated);
        });

        return redirect()->back();
    }

    public function destroyCompetency(Request $request, MedicationCompetencyAssessment $assessment)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteIds = $this->governanceScope->mutationSiteIds($actor, 'medications.orders.manage');
        $snapshot = MedicationCompetencyAssessment::query()
            ->whereKey($assessment->id)
            ->first(['id', 'user_id']);
        abort_unless($snapshot !== null, 404);
        $this->assertCurrentStaffWithinSites((int) $snapshot->user_id, $siteIds);

        DB::transaction(function () use ($actor, $assessment, $snapshot): void {
            [$lockedActor] = $this->lockCurrentCompetencyMutationUsers(
                $actor,
                (int) $snapshot->user_id,
            );

            $locked = MedicationCompetencyAssessment::query()
                ->whereKey($assessment->id)
                ->where('user_id', $snapshot->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $locked->user_id !== (int) $lockedActor->id, 404);
            if ($locked->status !== 'expired') {
                $locked->forceFill(['status' => 'expired'])->save();
            }
        });

        return redirect()->back();
    }

    public function acknowledgeCompetency(Request $request, MedicationCompetencyAssessment $assessment)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return DB::transaction(function () use ($actor, $assessment) {
            $lockedUser = User::query()
                ->whereKey($actor->id)
                ->whereNotNull('approved_at')
                ->lockForUpdate()
                ->first();
            abort_unless($lockedUser !== null, 404);

            $currentProfile = HrEmployeeProfile::query()
                ->where('user_id', $actor->id)
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNull('start_date')->orWhereDate('start_date', '<=', today());
                })
                ->where(function ($query): void {
                    $query->whereNull('end_date')->orWhereDate('end_date', '>=', today());
                })
                ->lockForUpdate()
                ->first();
            abort_unless($currentProfile !== null, 404);

            $locked = MedicationCompetencyAssessment::query()
                ->whereKey($assessment->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $locked !== null
                && $locked->status === 'passed'
                && $locked->assessor_declared_at !== null
                && (int) $locked->assessor_id !== (int) $actor->id,
                404,
            );

            if ($locked->staff_acknowledged_at === null) {
                $locked->forceFill(['staff_acknowledged_at' => now()])->save();
            }

            return redirect()->back();
        });
    }

    // ─── Rounds CRUD / Workflow ─────────────────────────────

    public function storeRoundTemplate(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'scheduled_time' => 'required|date_format:H:i',
            'window_minutes' => 'required|integer|min:5|max:120',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:1|max:7',
            'site_id' => 'required|integer|min:1',
            'service_context_id' => 'nullable|integer|min:1',
            'default_assigned_to' => 'nullable|integer|min:1',
        ]);
        $assigneeId = isset($validated['default_assigned_to'])
            ? (int) $validated['default_assigned_to']
            : null;
        $serviceContextId = isset($validated['service_context_id'])
            ? (int) $validated['service_context_id']
            : null;

        return $this->governanceScope->forNewRoundTemplate(
            $actor,
            (int) $validated['site_id'],
            $serviceContextId,
            $assigneeId,
            function (int $canonicalSiteId, User $lockedActor, Collection $lockedUsers) use ($validated, $assigneeId) {
                if ($assigneeId !== null) {
                    /** @var User|null $assignee */
                    $assignee = $lockedUsers->get($assigneeId);
                    $profile = $assignee?->hrEmployeeProfile;
                    abort_unless(
                        $profile instanceof HrEmployeeProfile
                            && collect([
                                $profile->primary_site_id,
                                ...($profile->secondary_site_ids ?? []),
                            ])->contains(fn (mixed $siteId): bool => (int) $siteId === $canonicalSiteId),
                        404,
                    );
                }

                $validated['active'] = true;
                $validated['site_id'] = $canonicalSiteId;
                MedicationRoundTemplate::query()->create($validated);

                return redirect()->back();
            },
        );
    }

    public function updateRoundTemplate(Request $request, MedicationRoundTemplate $template)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'scheduled_time' => 'nullable|date_format:H:i',
            'window_minutes' => 'nullable|integer|min:5|max:120',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:1|max:7',
            'site_id' => 'sometimes|nullable|integer|min:1',
            'service_context_id' => 'sometimes|nullable|integer|min:1',
            'active' => 'sometimes|boolean',
            'default_assigned_to' => 'sometimes|nullable|integer|min:1',
        ]);
        $requestedAssigneeId = array_key_exists('default_assigned_to', $validated)
            && $validated['default_assigned_to'] !== null
            ? (int) $validated['default_assigned_to']
            : null;
        $requestedSiteId = array_key_exists('site_id', $validated) && $validated['site_id'] !== null
            ? (int) $validated['site_id']
            : null;
        $requestedContextId = array_key_exists('service_context_id', $validated)
            && $validated['service_context_id'] !== null
            ? (int) $validated['service_context_id']
            : null;

        return $this->governanceScope->forRoundTemplate($actor, $template, function (
            MedicationRoundTemplate $lockedTemplate,
            ?int $currentSiteId,
            User $lockedActor,
            Collection $lockedUsers,
            Collection $lockedContexts,
            Collection $lockedSites,
        ) use ($validated) {
            if ($lockedTemplate->isRetired()) {
                throw ValidationException::withMessages([
                    'template' => 'Retired round templates are retained as historical evidence and cannot be changed.',
                ]);
            }

            $proposedSiteId = array_key_exists('site_id', $validated)
                ? ($validated['site_id'] !== null ? (int) $validated['site_id'] : null)
                : $currentSiteId;
            $proposedContextId = array_key_exists('service_context_id', $validated)
                ? ($validated['service_context_id'] !== null ? (int) $validated['service_context_id'] : null)
                : ($lockedTemplate->service_context_id ? (int) $lockedTemplate->service_context_id : null);
            $resultingActive = array_key_exists('active', $validated)
                ? (bool) $validated['active']
                : (bool) $lockedTemplate->active;
            if ($resultingActive && $proposedSiteId === null) {
                throw ValidationException::withMessages([
                    'site_id' => 'Choose a Site before activating this round template.',
                ]);
            }
            $canonicalSiteId = $proposedSiteId !== null || $proposedContextId !== null
                ? $this->governanceScope->lockedRoundTemplateSiteId(
                    $lockedActor,
                    $proposedSiteId,
                    $proposedContextId,
                    $lockedContexts,
                    $lockedSites,
                )
                : null;
            if ($resultingActive) {
                abort_unless($canonicalSiteId !== null, 404);
            }
            $defaultAssigneeId = array_key_exists('default_assigned_to', $validated)
                ? ($validated['default_assigned_to'] !== null ? (int) $validated['default_assigned_to'] : null)
                : ($lockedTemplate->default_assigned_to ? (int) $lockedTemplate->default_assigned_to : null);
            if ($defaultAssigneeId !== null) {
                /** @var User|null $assignee */
                $assignee = $lockedUsers->get($defaultAssigneeId);
                $profile = $assignee?->hrEmployeeProfile;
                $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
                abort_unless(
                    $profile instanceof HrEmployeeProfile
                        && $profile->is_active
                        && ($profile->start_date === null || $profile->start_date->toDateString() <= $today)
                        && ($profile->end_date === null || $profile->end_date->toDateString() >= $today)
                        && $canonicalSiteId !== null
                        && collect([
                            $profile->primary_site_id,
                            ...($profile->secondary_site_ids ?? []),
                        ])->contains(fn (mixed $siteId): bool => (int) $siteId === $canonicalSiteId),
                    404,
                );
            }
            if (array_key_exists('site_id', $validated) || $resultingActive) {
                $validated['site_id'] = $canonicalSiteId;
            }
            $lockedTemplate->update($validated);

            return redirect()->back();
        },
            authorizationUserIds: array_filter([$requestedAssigneeId]),
            additionalSiteIds: array_filter([$requestedSiteId]),
            additionalServiceContextIds: array_filter([$requestedContextId]),
        );
    }

    public function retireRoundTemplate(Request $request, MedicationRoundTemplate $template)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forRoundTemplate($actor, $template, function (
            MedicationRoundTemplate $lockedTemplate,
            ?int $siteId,
            User $lockedActor,
        ) {
            $statusBefore = $lockedTemplate->active ? 'active' : 'inactive';
            if ($lockedTemplate->retireGoverned((int) $lockedActor->id)) {
                AuditLogger::logOrFail('medications.round_template.retired', $lockedTemplate, [
                    'actor_id' => (int) $lockedActor->id,
                    'site_id' => $lockedTemplate->site_id !== null ? (int) $lockedTemplate->site_id : null,
                    'round_template_id' => (int) $lockedTemplate->id,
                    'status_before' => $statusBefore,
                    'status_after' => 'retired',
                    'retired_at' => $lockedTemplate->retired_at?->toIso8601String(),
                ]);
            }

            return redirect()->back()->with('success', 'Round template retired. Existing rounds were retained.');
        });
    }

    public function generateRounds(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $allowedSiteIds = $this->governanceScope->mutationSiteIds($actor, 'medications.orders.manage');
        $validated = $request->validate([
            'date' => 'required|date',
            'generate_all' => 'nullable|boolean',
        ]);

        $date = Carbon::parse($validated['date']);
        $generateAll = (bool) ($validated['generate_all'] ?? false);
        $templateIds = MedicationRoundTemplate::query()
            ->active()
            ->whereIn('site_id', $allowedSiteIds)
            ->orderBy('id')
            ->pluck('id');
        $results = $templateIds->map(fn ($templateId): array => $this->roundGeneration->generate(
            (int) $templateId,
            $date,
            $generateAll,
            $allowedSiteIds,
            $actor,
        ));
        $summary = [
            'created' => $results->where('status', MedicationRoundGenerationService::STATUS_CREATED)->count(),
            'already_exists' => $results->where('status', MedicationRoundGenerationService::STATUS_ALREADY_EXISTS)->count(),
            'skipped' => $results->where('status', MedicationRoundGenerationService::STATUS_SKIPPED)->count(),
            'skipped_by_reason' => $results
                ->where('status', MedicationRoundGenerationService::STATUS_SKIPPED)
                ->countBy('reason')
                ->all(),
        ];

        return redirect()->back()->with('round_generation', $summary);
    }

    public function startRound(Request $request, MedicationRound $round)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.orders.manage'), 403);

        return $this->medicationScope->forRound(
            $actor,
            $round,
            now(),
            function (MedicationScopeDecision $scope) {
                if ($scope->round->status === 'pending') {
                    $scope->round->update([
                        'status' => 'in_progress',
                        'started_by' => $scope->performer->id,
                        'started_at' => now(),
                    ]);
                } elseif ($scope->round->status === 'partial') {
                    $scope->round->update(['status' => 'in_progress']);
                }

                return redirect()->back();
            },
            ['pending', 'partial', 'in_progress'],
            requireAssignment: false,
            requireWorkScope: false,
        );
    }

    public function completeRound(Request $request, MedicationRound $round)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.orders.manage'), 403);

        return $this->medicationScope->forRound(
            $actor,
            $round,
            now(),
            function (MedicationScopeDecision $scope) {
                if ($scope->round->status === 'in_progress') {
                    if (! app(GuidedRoundService::class)->canCompleteCanonicalRoundUnderLock($scope->round)) {
                        throw ValidationException::withMessages([
                            'round' => 'This round cannot be completed yet.',
                        ]);
                    }

                    $scope->round->update([
                        'status' => 'completed',
                        'completed_by' => $scope->performer->id,
                        'completed_at' => now(),
                    ]);
                    $scope->round->updateCounts();
                }

                return redirect()->back();
            },
            ['in_progress', 'completed'],
            requireAssignment: false,
            requireWorkScope: false,
            lockCanonicalMembership: true,
        );
    }

    public function assignRound(Request $request, MedicationRound $round)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.orders.manage'), 403);
        $validated = $request->validate([
            'assigned_to' => 'required|integer|min:1',
        ]);
        $assigneeId = (int) $validated['assigned_to'];

        return $this->medicationScope->forRound(
            $actor,
            $round,
            now(),
            function (MedicationScopeDecision $scope, Collection $lockedUsers) use ($assigneeId) {
                /** @var User|null $assignee */
                $assignee = $lockedUsers->get($assigneeId);
                $profile = $assignee?->hrEmployeeProfile;
                abort_unless(
                    $profile instanceof HrEmployeeProfile
                        && collect([
                            $profile->primary_site_id,
                            ...($profile->secondary_site_ids ?? []),
                        ])->contains(fn (mixed $siteId): bool => (int) $siteId === $scope->siteId),
                    404,
                );
                $scope->round->update(['assigned_to' => $assigneeId]);

                return redirect()->back();
            },
            allowedStatuses: ['pending', 'partial', 'in_progress'],
            requireAssignment: false,
            requireWorkScope: false,
            authorizationUserIds: [$assigneeId],
        );
    }

    // ─── Self-Admin CRUD ────────────────────────────────────

    public function storeSelfAdmin(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClient($actor, (int) $request->input('client_id'), 'medications.orders.manage', function (Client $client) use ($request, $actor) {
            $validated = $request->validate([
                'client_id' => 'required|integer|min:1',
                'wishes_to_self_administer' => 'nullable|boolean',
                'people_involved' => 'nullable|array',
                'people_involved.*' => 'string',
                'cognitive_capacity' => 'required|integer|min:1|max:5',
                'physical_dexterity' => 'required|integer|min:1|max:5',
                'vision_ability' => 'required|integer|min:1|max:5',
                'swallowing_ability' => 'required|integer|min:1|max:5',
                'understanding_score' => 'required|integer|min:1|max:5',
                'can_identify_medications' => 'required|boolean',
                'can_read_labels' => 'required|boolean',
                'can_open_packaging' => 'required|boolean',
                'can_manage_timing' => 'required|boolean',
                'can_store_safely' => 'required|boolean',
                'willing_to_self_admin' => 'required|boolean',
                'risk_factors' => 'nullable|string',
                'support_needed' => 'nullable|string',
                'support_adjustments' => 'nullable|array',
                'support_adjustments.*' => 'string',
                'safe_storage_notes' => 'nullable|string',
                'storage_location' => 'nullable|string|max:64',
                'assessor_notes' => 'nullable|string',
                'reassessment_date' => 'nullable|date',
                'reassessment_interval_months' => 'nullable|integer|min:1|max:24',
                'reassessment_trigger' => 'nullable|string',
                'supersedes_id' => 'nullable|integer|min:1',
            ]);
            abort_unless((int) $validated['client_id'] === (int) $client->id, 404);
            $assessmentGraph = MedicationSelfAdminAssessment::query()
                ->where('client_id', $client->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'supersedes_id']);
            $supersededIds = $assessmentGraph->pluck('supersedes_id')->filter()->map(fn ($id) => (int) $id);
            $liveLeaves = $assessmentGraph
                ->reject(fn (MedicationSelfAdminAssessment $candidate): bool => $supersededIds->contains((int) $candidate->id))
                ->values();
            $submittedSupersedesId = isset($validated['supersedes_id'])
                ? (int) $validated['supersedes_id']
                : null;
            if ($assessmentGraph->isEmpty()) {
                if ($submittedSupersedesId !== null) {
                    throw ValidationException::withMessages([
                        'supersedes_id' => 'The selected prior assessment is not the current assessment for this client.',
                    ]);
                }
            } else {
                abort_if($liveLeaves->count() !== 1, 409);
                if ($submittedSupersedesId !== (int) $liveLeaves->sole()->id) {
                    throw ValidationException::withMessages([
                        'supersedes_id' => 'A reassessment must supersede the current assessment for this client.',
                    ]);
                }
                $validated['supersedes_id'] = $submittedSupersedesId;
            }

            $totalScore = $validated['cognitive_capacity']
                + $validated['physical_dexterity']
                + $validated['vision_ability']
                + $validated['swallowing_ability']
                + $validated['understanding_score'];

            $validated['wishes_to_self_administer'] = (bool) ($validated['wishes_to_self_administer'] ?? true);
            $validated['outcome'] = MedicationSelfAdminAssessment::computeOutcome(
                $validated['wishes_to_self_administer'],
                (bool) $validated['willing_to_self_admin'],
                $totalScore,
            );

            // Derive the reassessment date from the cadence when one wasn't given.
            if (empty($validated['reassessment_date']) && ! empty($validated['reassessment_interval_months'])) {
                $validated['reassessment_date'] = today()->addMonthsNoOverflow((int) $validated['reassessment_interval_months'])->toDateString();
            }

            $validated['client_id'] = $client->id;
            $validated['assessed_by'] = $actor->id;
            $validated['assessment_date'] = today();
            $validated['status'] = 'completed';

            MedicationSelfAdminAssessment::create($validated);

            return redirect()->back();
        });
    }

    public function updateSelfAdmin(Request $request, MedicationSelfAdminAssessment $assessment)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClientRecord($actor, $assessment, 'medications.orders.manage', function (Client $client, MedicationSelfAdminAssessment $lockedAssessment) use ($request, $actor) {
            $this->assertSelfAdminMutationAuthority($client, $lockedAssessment, $actor);
            $validated = $request->validate([
                'wishes_to_self_administer' => 'nullable|boolean',
                'people_involved' => 'nullable|array',
                'people_involved.*' => 'string',
                'cognitive_capacity' => 'nullable|integer|min:1|max:5',
                'physical_dexterity' => 'nullable|integer|min:1|max:5',
                'vision_ability' => 'nullable|integer|min:1|max:5',
                'swallowing_ability' => 'nullable|integer|min:1|max:5',
                'understanding_score' => 'nullable|integer|min:1|max:5',
                'can_identify_medications' => 'nullable|boolean',
                'can_read_labels' => 'nullable|boolean',
                'can_open_packaging' => 'nullable|boolean',
                'can_manage_timing' => 'nullable|boolean',
                'can_store_safely' => 'nullable|boolean',
                'willing_to_self_admin' => 'nullable|boolean',
                'risk_factors' => 'nullable|string',
                'support_needed' => 'nullable|string',
                'support_adjustments' => 'nullable|array',
                'support_adjustments.*' => 'string',
                'safe_storage_notes' => 'nullable|string',
                'storage_location' => 'nullable|string|max:64',
                'assessor_notes' => 'nullable|string',
                'reassessment_date' => 'nullable|date',
                'reassessment_interval_months' => 'nullable|integer|min:1|max:24',
                'reassessment_trigger' => 'nullable|string',
                'med_scope' => 'nullable|array',
                'med_scope.*.med_id' => 'required|integer|min:1',
                'med_scope.*.med_name' => 'nullable|string|max:255',
                'med_scope.*.scope' => ['required', 'string', Rule::in(['self_managed', 'prompted', 'staff_given'])],
                'ordering_responsibility' => 'nullable|string|max:64',
                'agreement_responsibilities' => 'nullable|string',
                'sign_agreement' => 'nullable|boolean',
            ]);

            $signAgreement = (bool) ($validated['sign_agreement'] ?? false);
            unset($validated['sign_agreement']);

            if (array_key_exists('med_scope', $validated)) {
                $validated['med_scope'] = $this->canonicalSelfAdminMedicationScope(
                    $client,
                    $lockedAssessment,
                    $actor,
                    $validated['med_scope'] ?? [],
                );
            }

            // Recompute the consent-first category whenever a score or consent flag
            // changes — never trust a client-supplied outcome (gap: stale category).
            $scoreKeys = ['cognitive_capacity', 'physical_dexterity', 'vision_ability', 'swallowing_ability', 'understanding_score'];
            $consentKeys = ['wishes_to_self_administer', 'willing_to_self_admin'];
            if (collect([...$scoreKeys, ...$consentKeys])->contains(fn ($k) => array_key_exists($k, $validated))) {
                $merged = array_merge($lockedAssessment->only([...$scoreKeys, ...$consentKeys]), $validated);
                $total = collect($scoreKeys)->sum(fn ($k) => (int) ($merged[$k] ?? 0));
                $validated['outcome'] = MedicationSelfAdminAssessment::computeOutcome(
                    (bool) ($merged['wishes_to_self_administer'] ?? true),
                    (bool) ($merged['willing_to_self_admin'] ?? false),
                    $total,
                );
            }

            if ($signAgreement) {
                $validated['agreement_signed_at'] = now();
                $validated['agreement_signed_by'] = $actor->id;
            }

            $lockedAssessment->update($validated);

            return redirect()->back();
        });
    }

    public function destroySelfAdmin(Request $request, MedicationSelfAdminAssessment $assessment)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forClientRecord($actor, $assessment, 'medications.orders.manage', function (Client $client, MedicationSelfAdminAssessment $lockedAssessment) use ($actor) {
            $this->assertSelfAdminMutationAuthority($client, $lockedAssessment, $actor);
            // Soft delete — self-administration assessments are clinical records and
            // are retained (reassessments supersede rather than overwrite).
            $lockedAssessment->delete();

            return redirect()->back();
        });
    }

    // ─── Destructions CRUD ──────────────────────────────────

    public function storeDestruction(Request $request)
    {
        $this->assertMedicationCapability($request, 'medications.controlled.record');

        // Only validate the aggregate identity before canonical Client/medication
        // resolution. Target-sensitive clinical fields are validated after the
        // locked scope has concealed missing, foreign, and controlled records.
        $identity = $request->validate([
            'client_id' => 'required|integer|min:1',
            'client_medication_id' => 'required|integer|min:1',
            ...$this->medicationOfflineSubmissionRules($request),
        ]);
        $rules = [
            'client_id' => 'required|integer|min:1',
            'client_medication_id' => 'required|integer|min:1',
            'site_id' => 'nullable|integer|min:1',
            'medication_name' => 'required|string|max:255',
            'form' => 'nullable|string|max:255',
            'strength' => 'nullable|string|max:255',
            'quantity' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE],
            'unit' => 'required|string|max:50',
            'batch_number' => 'nullable|string|max:255',
            'expiry_date' => 'nullable|date',
            'reason' => 'required|string|max:255',
            'disposal_method' => 'required|string|max:255',
            'is_controlled_drug' => 'nullable|boolean',
            'controlled_drug_class' => 'nullable|string|max:50',
            'witness_1_id' => 'required|integer|min:1',
            'witness_1_credential' => 'nullable|string|max:255',
            'witness_2_id' => 'nullable|integer|min:1',
            'witness_2_credential' => 'nullable|string|max:255',
            'authorised_by_name' => 'nullable|string|max:255',
            'authorised_by_registration' => 'nullable|string|max:255',
            'denaturing_confirmed' => 'nullable|boolean',
            'notes' => 'nullable|string',
            ...$this->medicationOfflineSubmissionRules($request),
        ];

        $actor = $request->user();
        abort_unless($actor, 403);
        $witnessEffectiveAt = ($identity['queued_offline'] ?? false)
            ? Carbon::parse((string) $identity['captured_offline_at'])
            : now();

        $record = function (
            Client $client,
            ClientMedication $medication,
            User $lockedActor,
            Collection $lockedWitnessUsers,
        ) use ($request, $rules, $actor, $witnessEffectiveAt) {
            $actor = $lockedActor;
            if ((bool) $medication->controlled_drug) {
                abort_unless(
                    $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                        && $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                    404,
                );
            }

            if ($request->filled('site_id')) {
                $submittedSiteId = filter_var($request->input('site_id'), FILTER_VALIDATE_INT);
                abort_unless(
                    $submittedSiteId !== false
                    && $submittedSiteId > 0
                    && $submittedSiteId === (int) $client->site_id,
                    404,
                );
            }

            $validated = $request->validate($rules);

            $payload = $validated;
            $payload['quantity'] = MedicationStockQuantity::normalizeMovement($payload['quantity']);
            $payload['client_id'] = $client->id;
            $payload['site_id'] = $client->site_id;
            $payload['client_medication_id'] = $medication->id;
            $payload['medication_name'] = $medication->name;
            $payload['is_controlled_drug'] = (bool) $medication->controlled_drug;
            $payload['form'] = filled($medication->form) ? trim((string) $medication->form) : null;
            $payload['strength'] = filled($medication->dosage) ? trim((string) $medication->dosage) : null;
            // No authoritative drug-class field exists on ClientMedication.
            // Retain the destruction column for historical rows, but never let
            // a submitted descriptor manufacture clinical register evidence.
            $payload['controlled_drug_class'] = null;

            if (! empty($payload['is_controlled_drug'])) {
                abort_unless(
                    $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                        && $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                    404,
                );
                $request->validate([
                    'witness_2_id' => 'required|integer|min:1',
                    'authorised_by_name' => 'required|string|max:255',
                    'denaturing_confirmed' => 'accepted',
                ]);
            }

            // Witness integrity: the destroyer cannot witness their own
            // destruction, and the two witnesses must be distinct people.
            if ((int) $payload['witness_1_id'] === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'witness_1_id' => 'Witness must be a different person from the person destroying the medication.',
                ]);
            }
            if (! empty($payload['witness_2_id'])) {
                if ((int) $payload['witness_2_id'] === (int) $actor->id) {
                    throw ValidationException::withMessages([
                        'witness_2_id' => 'The second witness must be a different person from the person destroying the medication.',
                    ]);
                }
                if ((int) $payload['witness_2_id'] === (int) $payload['witness_1_id']) {
                    throw ValidationException::withMessages([
                        'witness_2_id' => 'The second witness must be a different person from the first witness.',
                    ]);
                }
            }

            $idempotencyScope = 'emar-destruction';
            $requestFingerprint = hash('sha256', json_encode([
                'actor_id' => (int) $actor->id,
                'client_id' => (int) $client->id,
                'client_medication_id' => $medication->id,
                'site_id' => (int) $client->site_id,
                'medication_name' => $medication->name,
                'form' => filled($payload['form'] ?? null) ? trim((string) $payload['form']) : null,
                'strength' => filled($payload['strength'] ?? null) ? trim((string) $payload['strength']) : null,
                'quantity' => $payload['quantity'],
                'unit' => trim((string) $payload['unit']),
                'batch_number' => filled($payload['batch_number'] ?? null) ? trim((string) $payload['batch_number']) : null,
                'expiry_date' => $payload['expiry_date'] ?? null,
                'reason' => trim((string) $payload['reason']),
                'disposal_method' => trim((string) $payload['disposal_method']),
                'is_controlled_drug' => (bool) ($payload['is_controlled_drug'] ?? false),
                'controlled_drug_class' => filled($payload['controlled_drug_class'] ?? null) ? trim((string) $payload['controlled_drug_class']) : null,
                'witness_1_id' => (int) $payload['witness_1_id'],
                'witness_2_id' => ! empty($payload['witness_2_id']) ? (int) $payload['witness_2_id'] : null,
                'authorised_by_name' => filled($payload['authorised_by_name'] ?? null) ? trim((string) $payload['authorised_by_name']) : null,
                'authorised_by_registration' => filled($payload['authorised_by_registration'] ?? null) ? trim((string) $payload['authorised_by_registration']) : null,
                'denaturing_confirmed' => (bool) ($payload['denaturing_confirmed'] ?? false),
                'notes' => filled($payload['notes'] ?? null) ? trim((string) $payload['notes']) : null,
                'captured_offline_at' => $payload['captured_offline_at'] ?? null,
                'origin_device_id' => $payload['origin_device_id'] ?? null,
                'queued_offline' => (bool) ($payload['queued_offline'] ?? false),
            ], JSON_THROW_ON_ERROR));
            $this->governanceScope->lockCurrentStaffProfilesAtSite(
                $lockedWitnessUsers,
                array_filter([
                    (int) $payload['witness_1_id'],
                    ! empty($payload['witness_2_id']) ? (int) $payload['witness_2_id'] : null,
                ]),
                (int) $client->site_id,
            );

            $witness1 = $lockedWitnessUsers->get((int) $payload['witness_1_id']);
            $witness2 = ! empty($payload['witness_2_id'])
                ? $lockedWitnessUsers->get((int) $payload['witness_2_id'])
                : null;
            abort_unless($witness1 instanceof User && ($witness2 === null || $witness2 instanceof User), 404);
            if (! empty($payload['is_controlled_drug'])) {
                $witness1 = $this->governanceScope->confirmedControlledWitness(
                    $actor,
                    $client,
                    (int) $payload['witness_1_id'],
                    $payload['witness_1_credential'] ?? null,
                    'witness_1_id',
                    'witness_1_credential',
                    (int) $actor->id,
                    $lockedWitnessUsers,
                    effectiveAt: $witnessEffectiveAt,
                );
                $witness2 = $this->governanceScope->confirmedControlledWitness(
                    $actor,
                    $client,
                    (int) $payload['witness_2_id'],
                    $payload['witness_2_credential'] ?? null,
                    'witness_2_id',
                    'witness_2_credential',
                    (int) $actor->id,
                    $lockedWitnessUsers,
                    effectiveAt: $witnessEffectiveAt,
                );
            }

            // Durable replays still require both witnesses to remain current,
            // present, qualified, authorised, and credential-confirmed for the
            // canonical Client before an earlier success can be returned.
            $replayConflict = 'This destruction request identifier was already used for different destruction details.';
            try {
                $stored = $this->governanceScope->idempotencyResult(
                    $idempotencyScope,
                    $payload,
                    $requestFingerprint,
                    $replayConflict,
                    durable: true,
                );
            } catch (ValidationException $exception) {
                if (! $request->expectsJson()) {
                    throw $exception;
                }

                return response()->json(
                    $this->buildMedicationConflictPayload($validated, $replayConflict),
                    409,
                );
            }

            if ($stored) {
                if ($request->expectsJson()) {
                    return response()->json($this->withMedicationSync(
                        ['success' => true, ...$stored],
                        $validated,
                        'duplicate',
                        true,
                        'This destruction was already recorded.',
                    ));
                }

                return redirect()->back()->with('success', 'Destruction was already recorded.');
            }

            $payload['destroyed_by'] = $actor->id;
            $payload['destroyed_at'] = now();
            unset(
                $payload['witness_1_credential'],
                $payload['witness_2_credential'],
                $payload['denaturing_confirmed'],
                $payload['client_request_uuid'],
                $payload['captured_offline_at'],
                $payload['origin_device_id'],
                $payload['queued_offline'],
            );

            $stock = ClientMedicationStock::query()
                ->where('client_medication_id', $medication->id)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                throw ValidationException::withMessages([
                    'quantity' => 'No stock position exists for this medication. Reconcile the stock count before recording a destruction.',
                ]);
            }

            $before = MedicationStockQuantity::normalize($stock->on_hand ?? 0);
            $qty = $payload['quantity'];

            if (MedicationStockQuantity::greaterThan($qty, $before)) {
                throw ValidationException::withMessages([
                    'quantity' => 'Only '.MedicationStockQuantity::display($before)." {$stock->unit} on hand — you cannot destroy ".MedicationStockQuantity::display($qty).'. Reconcile the stock count first.',
                ]);
            }

            $after = MedicationStockQuantity::subtract($before, $qty);
            $payload['unit'] = $stock->unit;

            $destruction = MedicationDestruction::create($payload);
            $registerEntry = null;

            $stock->on_hand = $after;
            $stock->last_counted_at = now();
            $stock->save();

            if (! empty($payload['is_controlled_drug'])) {
                $registerEntry = ClientControlledDrugEntry::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'service_context_id' => $client->service_context_id,
                    'entry_type' => 'disposal',
                    'quantity' => $payload['quantity'],
                    'unit' => $stock->unit,
                    'batch_number' => $payload['batch_number'] ?? null,
                    'on_hand_before' => $before,
                    'on_hand_after' => $after,
                    'reason' => 'Destruction — '.$payload['reason'],
                    'notes' => $payload['disposal_method'].(empty($payload['notes']) ? '' : "\n".$payload['notes']),
                    'recorded_at' => now(),
                    'recorded_by' => $actor->id,
                    'witnessed_by' => $payload['witness_1_id'],
                ]);
            }

            AuditLogger::logOrFail('medications.destruction.record', $destruction, array_filter([
                'actor_id' => $actor->id,
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'stock_id' => $stock->id,
                'controlled_drug_entry_id' => $registerEntry?->id,
                'witness_1_id' => $witness1?->id,
                'witness_2_id' => $witness2?->id,
                'witness_method' => $witness1
                    ? (! empty($payload['is_controlled_drug']) ? 'password' : 'site_staff_record')
                    : null,
                'witnessed_at' => $witness1 ? now()->toIso8601String() : null,
                'on_hand_before' => $before,
                'on_hand_after' => $after,
                'client_request_uuid' => $validated['client_request_uuid'] ?? null,
                'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                'origin_device_id' => $validated['origin_device_id'] ?? null,
                'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
            ], fn ($value) => $value !== null));

            $syncPayload = $this->withMedicationSync([
                'success' => true,
                'destruction_id' => (int) $destruction->id,
                'controlled_drug_entry_id' => $registerEntry?->id,
                'on_hand_after' => $after,
            ], $validated, $this->medicationProcessedStatus($validated));
            $syncPayload = $this->governanceScope->rememberIdempotencyResult(
                $idempotencyScope,
                $validated,
                $syncPayload,
                $requestFingerprint,
                $replayConflict,
                durable: true,
            );

            if ($request->expectsJson()) {
                return response()->json($syncPayload);
            }

            return redirect()->back();
        };

        return $this->governanceScope->forMedication(
            $actor,
            (int) $identity['client_medication_id'],
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            $record,
            (int) $identity['client_id'],
            authorizationUserIds: array_filter([
                is_numeric($request->input('witness_1_id')) ? (int) $request->input('witness_1_id') : null,
                is_numeric($request->input('witness_2_id')) ? (int) $request->input('witness_2_id') : null,
            ]),
            authorizationEffectiveAt: $witnessEffectiveAt,
        );
    }

    // ─── Handovers CRUD ─────────────────────────────────────

    public function storeHandover(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $identity = $request->validate([
            'shift_id' => ['required', 'integer', 'min:1'],
        ]);
        $shift = $this->handoverService->writableOutgoingShift($auth, (int) $identity['shift_id']);
        $this->assertControlledHandoverInputAuthority($request, $auth);

        $validated = $request->validate([
            'shift_id' => ['required', 'integer', 'min:1'],
            'incoming_shift_id' => ['nullable', 'integer', 'min:1'],
            'incoming_staff_id' => ['nullable', 'integer', 'min:1'],
            'handover_notes' => ['required', 'string', 'max:5000'],
            'client_mood' => ['nullable', 'string', 'max:255'],
            'medications_due_text' => ['nullable', 'string'],
            'follow_up_items_text' => ['nullable', 'string'],
            'incidents_to_note_text' => ['nullable', 'string'],
            'tasks_pending_text' => ['nullable', 'string'],
            'cd_result' => ['nullable', 'in:verified,discrepancy'],
            'cd_witness_id' => ['nullable', 'integer', 'min:1'],
            'cd_witness_credential' => ['nullable', 'string', 'max:255'],
            'cd_notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'integer', 'min:1'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $result = $this->handoverService->save($shift, $auth, [
            ...($request->exists('incoming_shift_id') ? [
                'incoming_shift_id' => $validated['incoming_shift_id'] ?? null,
            ] : []),
            ...($request->exists('incoming_staff_id') ? [
                'incoming_staff_id' => $validated['incoming_staff_id'] ?? null,
            ] : []),
            'handover_notes' => $validated['handover_notes'],
            'client_mood' => $validated['client_mood'] ?? null,
            ...($this->hasMedicationDueInput($request) ? [
                'medications_due' => $this->parseHandoverText($validated['medications_due_text'] ?? null),
            ] : []),
            'follow_up_items' => $this->parseHandoverText($validated['follow_up_items_text'] ?? null),
            'incidents_to_note' => $this->parseHandoverText($validated['incidents_to_note_text'] ?? null),
            'tasks_pending' => $this->parseHandoverText($validated['tasks_pending_text'] ?? null),
            'cd_verification_input' => [
                'result' => $validated['cd_result'] ?? null,
                'witness_id' => $validated['cd_witness_id'] ?? null,
                'witness_credential' => $validated['cd_witness_credential'] ?? null,
                'notes' => $validated['cd_notes'] ?? null,
            ],
            'expected_version' => $validated['version'] ?? null,
            'submit' => (bool) ($validated['submit'] ?? true),
        ]);

        return redirect()->back()->with(
            'success',
            $result['action'] === 'draft_saved' ? 'Medication handover draft saved.' : 'Medication handover submitted.',
        );
    }

    public function submitHandover(Request $request, mixed $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
        $handover = $this->canonicalHandover($auth, $handover);
        $this->siteAccess()->assertCanAccessHandover(
            $auth,
            $handover,
            $this->handoverBypassPermissions(),
            'You are not authorized to access handovers for this site.',
        );
        abort_unless($this->handoverService->canSubmit($handover, $auth), 403);

        $this->handoverService->submit($handover, $auth);

        return redirect()->back()->with('success', 'Medication handover submitted.');
    }

    public function acknowledgeHandover(Request $request, mixed $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
        $handover = $this->canonicalHandover($auth, $handover);
        $this->siteAccess()->assertCanAccessHandover(
            $auth,
            $handover,
            $this->handoverBypassPermissions(),
            'You are not authorized to access handovers for this site.',
        );
        abort_unless($this->handoverService->canAcknowledge($handover, $auth), 403);

        $this->handoverService->acknowledge($handover, $auth);

        return redirect()->back()->with('success', 'Medication handover acknowledged.');
    }

    /**
     * Live "Medications this shift" snapshot for the handover wizard / detail
     * dialog — meds due/given/missed/refused in the outgoing shift's window, PRN
     * given + effectiveness reviews outstanding, MAR omissions and stock/CD
     * alerts. Computed on demand (one shift at a time) so the heavy MAR build is
     * never fanned across the handover index. eMAR-only (the medication lens).
     */
    public function shiftMedicationSnapshot(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $validated = $request->validate([
            'shift_id' => ['required', 'integer'],
        ]);
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $auth,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
        );

        // Full client (not a column subset) — EnhancedMarService::build() reads
        // many client columns/relations downstream.
        $shift = Shift::query()
            ->with('client')
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $accessibleSiteIds))
            ->findOrFail($validated['shift_id']);

        $this->siteAccess()->assertCanAccessShift(
            $auth,
            $shift,
            $this->handoverBypassPermissions(),
            'You are not authorized to view medications for this site.',
        );

        return response()->json([
            'snapshot' => app(ShiftMedicationSnapshotService::class)->forShift(
                $shift,
                $auth->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
            ),
        ]);
    }

    /**
     * Take the presence edit-lock on a handover when the wizard opens it for
     * editing. Returns held_by (the other worker) when someone else holds a still
     * active lock, so the UI can warn + disable rather than clobber their edit.
     */
    public function lockHandover(Request $request, mixed $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
        $handover = $this->canonicalHandover($auth, $handover);
        $this->siteAccess()->assertCanAccessHandover(
            $auth,
            $handover,
            $this->handoverBypassPermissions(),
            'You are not authorized to access handovers for this site.',
        );

        $heldBy = $this->handoverService->acquireEditLock($handover, $auth);

        return response()->json(['locked' => $heldBy === null, 'held_by' => $heldBy]);
    }

    /** Release the presence edit-lock when the wizard closes. */
    public function unlockHandover(Request $request, mixed $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
        $handover = $this->canonicalHandover($auth, $handover);
        $this->siteAccess()->assertCanAccessHandover(
            $auth,
            $handover,
            $this->handoverBypassPermissions(),
            'You are not authorized to access handovers for this site.',
        );

        $this->handoverService->releaseEditLock($handover, $auth);

        return response()->json(['released' => true]);
    }

    // ─── Pharmacy Orders + Stock CRUD ───────────────────────

    public function storePharmacyOrder(Request $request)
    {
        $this->assertMedicationCapability($request, 'medications.stock.update');

        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forMedication(
            $actor,
            $request->integer('client_medication_id'),
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
            function (Client $client, ClientMedication $medication) use ($request, $actor) {
                abort_if(
                    $medication->controlled_drug
                        && (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                            || ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                    404,
                );
                $validated = $request->validate([
                    'client_id' => 'required|integer|min:1',
                    'client_medication_id' => 'required|integer|min:1',
                    'pharmacy_name' => 'required|string|max:255',
                    'pharmacy_phone' => 'nullable|string|max:255',
                    'pharmacy_email' => 'nullable|string|email|max:255',
                    'quantity_ordered' => 'required|integer|min:1',
                    'order_notes' => 'nullable|string',
                    'order_type' => 'nullable|string|max:255',
                    'batch_number' => 'nullable|string|max:255',
                    'batch_expiry' => 'nullable|date',
                    'expiry_date' => 'nullable|date',
                ]);
                $this->assertActiveGovernanceMedication($medication);
                $payload = $validated;
                if (! isset($payload['batch_expiry']) && ! empty($payload['expiry_date'])) {
                    $payload['batch_expiry'] = $payload['expiry_date'];
                }

                unset($payload['expiry_date']);
                $payload['client_id'] = $client->id;
                $payload['client_medication_id'] = $medication->id;
                $payload['status'] = 'draft';
                $payload['ordered_by'] = $actor->id;

                MedicationPharmacyOrder::create($payload);

                return redirect()->back();
            },
            $request->integer('client_id'),
        );
    }

    public function updatePharmacyOrder(Request $request, MedicationPharmacyOrder $order)
    {
        $this->assertMedicationCapability($request, 'medications.stock.update');

        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forPharmacyOrder(
            $actor,
            $order,
            function (Client $client, ClientMedication $medication, MedicationPharmacyOrder $lockedOrder) use ($request, $actor) {
                abort_if(
                    $medication->controlled_drug
                        && (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                            || ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                    404,
                );
                $validated = $request->validate([
                    'order_notes' => 'nullable|string',
                    'pharmacy_name' => 'nullable|string|max:255',
                    'pharmacy_phone' => 'nullable|string|max:255',
                    'pharmacy_email' => 'nullable|string|email|max:255',
                    'quantity_ordered' => 'nullable|integer|min:1',
                    'delivery_notes' => 'nullable|string',
                    'batch_number' => 'nullable|string|max:255',
                    'batch_expiry' => 'nullable|date',
                    'expiry_date' => 'nullable|date',
                ]);
                $payload = $validated;
                if (! isset($payload['batch_expiry']) && ! empty($payload['expiry_date'])) {
                    $payload['batch_expiry'] = $payload['expiry_date'];
                }

                unset($payload['expiry_date']);
                $lockedOrder->update($payload);

                return redirect()->back();
            },
        );
    }

    public function advancePharmacyOrder(Request $request, MedicationPharmacyOrder $order)
    {
        $this->assertMedicationCapability($request, 'medications.stock.update');

        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forPharmacyOrder(
            $actor,
            $order,
            function (Client $client, ClientMedication $medication, MedicationPharmacyOrder $lockedOrder) use ($request, $actor) {
                abort_if(
                    $medication->controlled_drug
                        && (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                            || ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                    404,
                );
                $validated = $request->validate([
                    'expected_status' => 'nullable|string|in:draft,submitted,confirmed,dispensed',
                    'batch_number' => 'nullable|string|max:255',
                    'batch_expiry' => 'nullable|date',
                    'quantity_received' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
                    'delivery_notes' => 'nullable|string',
                    ...$this->medicationOfflineSubmissionRules($request),
                ]);
                $expectedStatus = (string) ($validated['expected_status'] ?? $lockedOrder->status);
                $scope = 'emar-pharmacy-order-advance';
                $requestFingerprint = hash('sha256', json_encode([
                    'actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id,
                    'client_medication_id' => (int) $medication->id,
                    'pharmacy_order_id' => (int) $lockedOrder->id,
                    'expected_status' => $expectedStatus,
                    'batch_number' => $validated['batch_number'] ?? null,
                    'batch_expiry' => $validated['batch_expiry'] ?? null,
                    'quantity_received' => isset($validated['quantity_received'])
                        ? MedicationStockQuantity::normalize($validated['quantity_received'])
                        : null,
                    'delivery_notes' => $validated['delivery_notes'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ], JSON_THROW_ON_ERROR));
                if ($this->medicationSyncRequested($validated)) {
                    try {
                        $stored = $this->governanceScope->idempotencyResult(
                            $scope,
                            $validated,
                            $requestFingerprint,
                            'This pharmacy-order transition request was already used for a different actor, order, payload, or provenance.',
                            durable: true,
                        );
                    } catch (ValidationException) {
                        return response()->json(
                            $this->buildMedicationConflictPayload(
                                $validated,
                                'This pharmacy-order transition request was already used for a different actor, order, payload, or provenance.',
                            ),
                            409,
                        );
                    }

                    if ($stored) {
                        return response()->json($this->withMedicationSync(
                            $stored,
                            $validated,
                            'duplicate',
                            true,
                            'This medication request was already processed.',
                        ));
                    }
                }
                if (! hash_equals((string) $lockedOrder->status, $expectedStatus)) {
                    if ($this->medicationSyncRequested($validated)) {
                        return response()->json(
                            $this->buildMedicationConflictPayload(
                                $validated,
                                'The pharmacy order changed before this transition could be applied. Refresh and try again.',
                            ),
                            409,
                        );
                    }

                    throw ValidationException::withMessages([
                        'status' => 'The pharmacy order changed before this transition could be applied. Refresh and try again.',
                    ]);
                }
                $transitions = [
                    'draft' => 'submitted',
                    'submitted' => 'confirmed',
                    'confirmed' => 'dispensed',
                    'dispensed' => 'delivered',
                ];

                $nextStatus = $transitions[$lockedOrder->status] ?? null;
                if (! $nextStatus) {
                    return redirect()->back()->withErrors(['status' => 'Order cannot be advanced from its current status.']);
                }

                $updateData = ['status' => $nextStatus];
                switch ($nextStatus) {
                    case 'submitted':
                        $updateData['submitted_at'] = now();
                        break;
                    case 'confirmed':
                        $updateData['confirmed_at'] = now();
                        break;
                    case 'dispensed':
                        $updateData['dispensed_at'] = now();
                        $updateData['batch_number'] = $validated['batch_number'] ?? null;
                        $updateData['batch_expiry'] = $validated['batch_expiry'] ?? null;
                        break;
                    case 'delivered':
                        if ((bool) $medication->controlled_drug) {
                            throw ValidationException::withMessages([
                                'client_medication_id' => 'Controlled drug deliveries must be recorded through the controlled-drug register with a second witness.',
                            ]);
                        }
                        $updateData['delivered_at'] = now();
                        $updateData['received_by'] = $actor->id;
                        $quantityReceived = $validated['quantity_received'] ?? null;
                        $updateData['quantity_received'] = MedicationStockQuantity::normalize(
                            $quantityReceived ?? $lockedOrder->quantity_ordered,
                        );
                        $updateData['delivery_notes'] = $validated['delivery_notes'] ?? null;
                        break;
                }

                $lockedOrder->update($updateData);

                if ($nextStatus === 'delivered') {
                    $quantityReceived = $updateData['quantity_received'] ?? 0;
                    $stock = ClientMedicationStock::query()
                        ->where('client_medication_id', $medication->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $stock) {
                        $stock = ClientMedicationStock::create([
                            'client_medication_id' => $medication->id,
                            'on_hand' => 0,
                            'unit' => 'units',
                        ]);
                    }

                    $onHandBefore = MedicationStockQuantity::normalize($stock->on_hand ?? 0);

                    if ($quantityReceived > 0) {
                        $stock->on_hand = $this->addMedicationStockBalanceOrFail(
                            $stock->on_hand ?? 0,
                            $quantityReceived,
                            'quantity_received',
                        );
                    }

                    $stock->fill([
                        'batch_number' => $lockedOrder->batch_number,
                        'expiry_date' => $lockedOrder->batch_expiry,
                        'supplier_name' => $lockedOrder->pharmacy_name,
                        'last_counted_at' => now(),
                    ])->save();

                    AuditLogger::logOrFail('medications.stock.pharmacy_delivery', $stock, [
                        'actor_id' => $actor->id,
                        'client_id' => $client->id,
                        'client_medication_id' => $medication->id,
                        'pharmacy_order_id' => $lockedOrder->id,
                        'quantity_received' => $quantityReceived,
                        'on_hand_before' => $onHandBefore,
                        'on_hand_after' => MedicationStockQuantity::normalize($stock->on_hand),
                        'client_request_uuid' => $validated['client_request_uuid'] ?? null,
                        'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                        'origin_device_id' => $validated['origin_device_id'] ?? null,
                        'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                    ]);
                }

                AuditLogger::logOrFail('medications.stock.pharmacy_order.advance', $lockedOrder, [
                    'actor_id' => $actor->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'pharmacy_order_id' => $lockedOrder->id,
                    'status_before' => $expectedStatus,
                    'status_after' => $nextStatus,
                    'client_request_uuid' => $validated['client_request_uuid'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ]);

                $payload = [
                    'success' => true,
                    'pharmacy_order' => [
                        'id' => $lockedOrder->id,
                        'status' => $nextStatus,
                    ],
                ];
                if ($this->medicationSyncRequested($validated)) {
                    return response()->json(
                        $this->governanceScope->rememberIdempotencyResult(
                            $scope,
                            $validated,
                            $this->withMedicationSync(
                                $payload,
                                $validated,
                                $this->medicationProcessedStatus($validated),
                            ),
                            $requestFingerprint,
                            'This pharmacy-order transition request was already used for a different actor, order, payload, or provenance.',
                            durable: true,
                        ),
                    );
                }

                return redirect()->back();
            },
        );
    }

    public function receiveControlledPharmacyOrder(Request $request, MedicationPharmacyOrder $order)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forControlledPharmacyOrder(
            $actor,
            $order,
            function (
                Client $client,
                ClientMedication $medication,
                MedicationPharmacyOrder $lockedOrder,
                User $lockedActor,
                Collection $lockedUsers,
            ) use ($request, $actor) {
                $validated = $request->validate([
                    'client_medication_id' => 'required|integer|min:1',
                    'quantity_received' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE],
                    'on_hand_before' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
                    'on_hand_after' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
                    'witnessed_by' => 'required|integer|min:1',
                    'witness_credential' => 'nullable|string|max:255',
                    'delivery_notes' => 'nullable|string|max:2000',
                    ...$this->medicationOnlineOnlySubmissionRules(),
                ]);
                $this->assertActiveGovernanceMedication($medication);
                abort_unless(
                    (bool) $medication->controlled_drug
                    && (int) $validated['client_medication_id'] === (int) $medication->id,
                    404,
                    'The requested medication record was not found.',
                );

                $scope = 'emar-controlled-pharmacy-delivery';
                $quantityReceived = MedicationStockQuantity::normalizeMovement($validated['quantity_received']);
                $onHandBefore = MedicationStockQuantity::normalize($validated['on_hand_before']);
                $onHandAfter = MedicationStockQuantity::normalize($validated['on_hand_after']);
                $requestFingerprint = hash('sha256', json_encode([
                    'actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id,
                    'client_medication_id' => (int) $medication->id,
                    'pharmacy_order_id' => (int) $lockedOrder->id,
                    'quantity_received' => $quantityReceived,
                    'on_hand_before' => $onHandBefore,
                    'on_hand_after' => $onHandAfter,
                    'witnessed_by' => (int) $validated['witnessed_by'],
                    'delivery_notes' => $validated['delivery_notes'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ], JSON_THROW_ON_ERROR));

                // An exact replay is still a controlled-drug action: recheck
                // the current in-person witness authority and credential before
                // returning the durable result.
                $witness = $this->governanceScope->confirmedControlledWitness(
                    $lockedActor,
                    $client,
                    (int) $validated['witnessed_by'],
                    $validated['witness_credential'] ?? null,
                    recorderId: (int) $lockedActor->id,
                    lockedUsers: $lockedUsers,
                );

                if ($stored = $this->governanceScope->idempotencyResult(
                    $scope,
                    $validated,
                    $requestFingerprint,
                    'This controlled delivery request identifier was already used for a different actor, order, target, payload, or provenance.',
                    durable: true,
                )) {
                    return redirect()->back()->with('success', 'Controlled drug delivery already recorded.');
                }

                if ($lockedOrder->status !== 'dispensed') {
                    throw ValidationException::withMessages([
                        'status' => 'Only a dispensed controlled drug order can be received.',
                    ]);
                }

                $stock = ClientMedicationStock::query()
                    ->where('client_medication_id', $medication->id)
                    ->lockForUpdate()
                    ->first();
                if (! $stock) {
                    throw ValidationException::withMessages([
                        'on_hand_before' => 'No controlled drug stock position exists. Initialize it through the controlled-drug register before receiving this order.',
                    ]);
                }

                $authoritativeBefore = MedicationStockQuantity::normalize($stock->on_hand ?? 0);
                if (! MedicationStockQuantity::equals($authoritativeBefore, $onHandBefore)) {
                    throw ValidationException::withMessages([
                        'on_hand_before' => 'The controlled drug balance changed. Review the current balance before receiving this order.',
                    ]);
                }

                $expectedAfter = $this->addMedicationStockBalanceOrFail(
                    $authoritativeBefore,
                    $quantityReceived,
                    'quantity_received',
                );
                if (! MedicationStockQuantity::equals($expectedAfter, $onHandAfter)) {
                    throw ValidationException::withMessages([
                        'on_hand_after' => 'The delivery balance does not reconcile with the locked stock position and quantity received.',
                    ]);
                }

                $entry = ClientControlledDrugEntry::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'pharmacy_order_id' => $lockedOrder->id,
                    'client_request_uuid' => $validated['client_request_uuid'],
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                    'service_context_id' => $client->service_context_id,
                    'entry_type' => 'receipt',
                    'quantity' => $quantityReceived,
                    'unit' => $stock->unit,
                    'batch_number' => $lockedOrder->batch_number,
                    'expiry_date' => $lockedOrder->batch_expiry,
                    'on_hand_before' => $authoritativeBefore,
                    'on_hand_after' => $expectedAfter,
                    'reason' => 'Pharmacy order delivery',
                    'notes' => $validated['delivery_notes'] ?? null,
                    'recorded_at' => now(),
                    'recorded_by' => $actor->id,
                    'witnessed_by' => $witness->id,
                ]);

                $stock->forceFill([
                    'on_hand' => $expectedAfter,
                    'batch_number' => $lockedOrder->batch_number,
                    'expiry_date' => $lockedOrder->batch_expiry,
                    'supplier_name' => $lockedOrder->pharmacy_name,
                    'last_counted_at' => now(),
                ])->save();

                $lockedOrder->forceFill([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'received_by' => $actor->id,
                    'quantity_received' => $quantityReceived,
                    'delivery_notes' => $validated['delivery_notes'] ?? null,
                ])->save();

                AuditLogger::logOrFail('medications.controlled.pharmacy_delivery.receive', $entry, [
                    'actor_id' => $actor->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'pharmacy_order_id' => $lockedOrder->id,
                    'stock_id' => $stock->id,
                    'quantity_received' => $quantityReceived,
                    'on_hand_before' => $authoritativeBefore,
                    'on_hand_after' => $expectedAfter,
                    'witnessed_by' => $witness->id,
                    'witness_method' => 'password',
                ]);

                $this->governanceScope->rememberIdempotencyResult(
                    $scope,
                    $validated,
                    [
                        'success' => true,
                        'pharmacy_order_id' => $lockedOrder->id,
                        'controlled_drug_entry_id' => $entry->id,
                        'on_hand_after' => $expectedAfter,
                    ],
                    $requestFingerprint,
                    'This controlled delivery request identifier was already used for a different actor, order, target, payload, or provenance.',
                    durable: true,
                );

                return redirect()->back()->with('success', 'Controlled drug delivery recorded.');
            },
            authorizationUserIds: [
                is_numeric($request->input('witnessed_by')) ? (int) $request->input('witnessed_by') : 0,
            ],
        );
    }

    public function receiveStock(Request $request)
    {
        $this->assertMedicationCapability($request, 'medications.stock.update');

        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forMedication(
            $actor,
            (int) $request->input('client_medication_id'),
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
            function (Client $client, ClientMedication $medication) use ($request, $actor) {
                $this->assertActiveGovernanceMedication($medication);
                if ((bool) $medication->controlled_drug) {
                    abort_unless(
                        $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                        404,
                    );
                    throw ValidationException::withMessages([
                        'client_medication_id' => 'Controlled drug receipts must be recorded through the controlled-drug register with a second witness.',
                    ]);
                }
                $validated = $request->validate([
                    'client_medication_id' => 'required|integer|min:1',
                    // on_hand is decimal (half/quarter tablets exist) — don't reject
                    // fractional receipts with an integer rule.
                    'quantity' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.25', 'max:100000'],
                    'notes' => 'nullable|string|max:2000',
                    'batch_number' => 'nullable|string|max:100',
                    'expiry_date' => 'nullable|date',
                    'scan_code' => 'nullable|string|max:255',
                    'scan_source' => 'nullable|string|in:manual,scanner',
                    'scan_verified' => 'nullable|boolean',
                    'scan_match_source' => 'nullable|string|max:50',
                    ...$this->medicationOfflineSubmissionRules($request),
                ]);
                $validated['quantity'] = MedicationStockQuantity::normalize($validated['quantity']);
                $scope = 'emar-stock-receive';
                $requestFingerprint = hash('sha256', json_encode([
                    'actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id,
                    'client_medication_id' => (int) $medication->id,
                    'quantity' => $validated['quantity'],
                    'notes' => $validated['notes'] ?? null,
                    'batch_number' => $validated['batch_number'] ?? null,
                    'expiry_date' => $validated['expiry_date'] ?? null,
                    'scan_code' => $validated['scan_code'] ?? null,
                    'scan_source' => $validated['scan_source'] ?? null,
                    'scan_verified' => (bool) ($validated['scan_verified'] ?? false),
                    'scan_match_source' => $validated['scan_match_source'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ], JSON_THROW_ON_ERROR));

                if ($this->medicationSyncRequested($validated)) {
                    try {
                        $stored = $this->governanceScope->idempotencyResult(
                            $scope,
                            $validated,
                            $requestFingerprint,
                            durable: true,
                        );
                    } catch (ValidationException) {
                        return response()->json(
                            $this->buildMedicationConflictPayload(
                                $validated,
                                'This stock-receipt request was already used for a different target or payload. Please submit it again with a new request identifier.',
                            ),
                            409,
                        );
                    }

                    if ($stored) {
                        return response()->json($this->withMedicationSync(
                            $stored,
                            $validated,
                            'duplicate',
                            true,
                            'This medication request was already processed.',
                        ));
                    }
                }

                $medication->loadMissing(['client:id,first_name,last_name', 'stock']);
                $scanAudit = $this->verifyMedicationScanOrFail($client, $medication, $validated);

                $stock = ClientMedicationStock::query()
                    ->where('client_medication_id', $medication->id)
                    ->lockForUpdate()
                    ->first();
                if (! $stock) {
                    $stock = ClientMedicationStock::create([
                        'client_medication_id' => $medication->id,
                        'on_hand' => 0,
                        'unit' => 'units',
                    ]);
                }

                $stock->on_hand = $this->addMedicationStockBalanceOrFail(
                    $stock->on_hand ?? 0,
                    $validated['quantity'],
                    'quantity',
                );
                $stock->update([
                    'last_counted_at' => now(),
                    'notes' => $validated['notes'] ?? $stock->notes,
                    'batch_number' => $validated['batch_number'] ?? $stock->batch_number,
                    'expiry_date' => $validated['expiry_date'] ?? $stock->expiry_date,
                ]);

                AuditLogger::logOrFail('medications.stock.receive', $stock, array_filter([
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'quantity_received' => $validated['quantity'],
                    'scan_source' => $scanAudit['scan_source'] ?? null,
                    'scan_match_source' => $scanAudit['scan_match_source'] ?? null,
                    'scan_match_label' => $scanAudit['scan_match_label'] ?? null,
                    'entered_code_suffix' => $scanAudit['scan_code_suffix'] ?? null,
                    'batch_number' => $validated['batch_number'] ?? null,
                    'expiry_date' => $validated['expiry_date'] ?? null,
                    'client_request_uuid' => $validated['client_request_uuid'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ], fn ($value) => $value !== null && $value !== ''));

                $payload = [
                    'success' => true,
                    'stock' => [
                        'id' => $stock->id,
                        'client_medication_id' => $medication->id,
                        'on_hand' => $stock->on_hand,
                        'unit' => $stock->unit,
                        'batch_number' => $stock->batch_number,
                        'expiry_date' => $stock->expiry_date?->toDateString(),
                    ],
                ];

                if ($this->medicationSyncRequested($validated)) {
                    return response()->json(
                        $this->governanceScope->rememberIdempotencyResult(
                            $scope,
                            $validated,
                            $this->withMedicationSync(
                                $payload,
                                $validated,
                                $this->medicationProcessedStatus($validated),
                            ),
                            $requestFingerprint,
                            durable: true,
                        ),
                    );
                }

                return redirect()->back()->with('success', 'Stock received successfully.');
            },
        );
    }

    public function updateStockItem(Request $request, ClientMedicationStock $stock)
    {
        $this->assertMedicationCapability($request, 'medications.stock.update');

        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forStock(
            $actor,
            $stock,
            function (Client $client, ClientMedication $medication, ClientMedicationStock $lockedStock) use ($request, $actor) {
                $this->assertActiveGovernanceMedication($medication);
                if ((bool) $medication->controlled_drug) {
                    abort_unless(
                        $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                        404,
                    );
                }
                $validated = $request->validate([
                    'reorder_level' => 'nullable|integer|min:0',
                    'reorder_quantity' => 'nullable|integer|min:1',
                    'expiry_date' => 'nullable|date',
                    'batch_number' => 'nullable|string|max:100',
                    'supplier_name' => 'nullable|string|max:255',
                    'storage_condition' => 'nullable|string|in:ambient,fridge,controlled_room',
                ]);
                $lockedStock->update($validated);

                AuditLogger::logOrFail('medications.stock.metadata.update', $lockedStock, [
                    'actor_id' => $actor->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'changed_fields' => array_keys($validated),
                ]);

                return redirect()->back();
            },
        );
    }

    public function adjustStock(Request $request)
    {
        $this->assertMedicationCapability($request, 'medications.stock.update');

        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forMedication(
            $actor,
            (int) $request->input('client_medication_id'),
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
            function (Client $client, ClientMedication $medication) use ($request, $actor) {
                $this->assertActiveGovernanceMedication($medication);
                if ((bool) $medication->controlled_drug) {
                    abort_unless(
                        $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                        404,
                    );
                    throw ValidationException::withMessages([
                        'client_medication_id' => 'Controlled drug stock counts must be recorded through the controlled-drug balance check with a second witness.',
                    ]);
                }
                $validated = $request->validate([
                    'client_medication_id' => 'required|integer|min:1',
                    'new_quantity' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', 'max:1000000'],
                    'reason' => 'required|string|max:500',
                    ...$this->medicationOfflineSubmissionRules($request),
                ]);
                $validated['new_quantity'] = MedicationStockQuantity::normalize($validated['new_quantity']);
                $scope = 'emar-stock-adjust';
                $requestFingerprint = hash('sha256', json_encode([
                    'actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id,
                    'client_medication_id' => (int) $medication->id,
                    'new_quantity' => $validated['new_quantity'],
                    'reason' => $validated['reason'],
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ], JSON_THROW_ON_ERROR));
                if ($this->medicationSyncRequested($validated)) {
                    try {
                        $stored = $this->governanceScope->idempotencyResult(
                            $scope,
                            $validated,
                            $requestFingerprint,
                            'This stock-adjustment request was already used for a different actor, target, payload, or provenance.',
                            durable: true,
                        );
                    } catch (ValidationException) {
                        return response()->json(
                            $this->buildMedicationConflictPayload(
                                $validated,
                                'This stock-adjustment request was already used for a different actor, target, payload, or provenance.',
                            ),
                            409,
                        );
                    }

                    if ($stored) {
                        return response()->json($this->withMedicationSync(
                            $stored,
                            $validated,
                            'duplicate',
                            true,
                            'This medication request was already processed.',
                        ));
                    }
                }
                $stock = ClientMedicationStock::query()
                    ->where('client_medication_id', $medication->id)
                    ->lockForUpdate()
                    ->first();
                if (! $stock) {
                    $stock = ClientMedicationStock::create([
                        'client_medication_id' => $medication->id,
                        'on_hand' => 0,
                        'unit' => 'units',
                    ]);
                }
                $onHandBefore = MedicationStockQuantity::normalize($stock->on_hand ?? 0);
                $stock->update([
                    'on_hand' => $validated['new_quantity'],
                    'last_counted_at' => now(),
                    'notes' => 'Stock adjustment: '.$validated['reason'],
                ]);

                AuditLogger::logOrFail('medications.stock.adjust', $stock, [
                    'actor_id' => $actor->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'reason' => $validated['reason'],
                    'on_hand_before' => $onHandBefore,
                    'on_hand_after' => $validated['new_quantity'],
                    'client_request_uuid' => $validated['client_request_uuid'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ]);

                $payload = [
                    'success' => true,
                    'stock' => [
                        'id' => $stock->id,
                        'client_medication_id' => $medication->id,
                        'on_hand' => $stock->on_hand,
                        'unit' => $stock->unit,
                    ],
                ];
                if ($this->medicationSyncRequested($validated)) {
                    return response()->json(
                        $this->governanceScope->rememberIdempotencyResult(
                            $scope,
                            $validated,
                            $this->withMedicationSync(
                                $payload,
                                $validated,
                                $this->medicationProcessedStatus($validated),
                            ),
                            $requestFingerprint,
                            'This stock-adjustment request was already used for a different actor, target, payload, or provenance.',
                            durable: true,
                        ),
                    );
                }

                return redirect()->back();
            },
        );
    }

    // ─── PRN Effectiveness CRUD ─────────────────────────────

    public function storePrnEffectiveness(Request $request)
    {
        $this->assertMedicationCapability($request, 'medications.administer.record');

        $user = $request->user();
        abort_unless($user, 403);
        $administration = ClientMedicationAdministration::query()
            ->whereKey($request->integer('client_medication_administration_id'))
            ->firstOrFail();

        return $this->medicationScope->forPrnEffectiveness(
            $user,
            $administration,
            now(),
            function (MedicationScopeDecision $scope) use ($request) {
                abort_if(
                    $scope->medication->controlled_drug
                        && ! $scope->performer->canDo('medications.controlled.record'),
                    404,
                );
                $validated = $request->validate([
                    'client_medication_administration_id' => 'required|integer',
                    'effectiveness' => 'required|in:effective,partially_effective,not_effective',
                    'review_minutes_after' => 'nullable|integer|min:0',
                    'observations' => 'nullable|string',
                    'escalation_needed' => 'nullable|boolean',
                    'escalation_action' => 'nullable|string',
                ]);
                MedicationPrnEffectiveness::updateOrCreate(
                    ['client_medication_administration_id' => $scope->administration->id],
                    [
                        ...$validated,
                        'client_id' => $scope->client->id,
                        'client_medication_id' => $scope->medication->id,
                        'reviewed_by' => $scope->performer->id,
                        'reviewed_at' => now(),
                    ],
                );
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'recorded_prn_effectiveness',
                    'Administration '.$scope->administration->id,
                );

                return redirect()->back();
            },
        );
    }

    // ─── Medications CRUD ─────────────────────────────────

    public function storeMedication(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        return $this->medicationScope->forClient(
            $user,
            $request->integer('client_id'),
            now(),
            function (MedicationScopeDecision $scope) use ($request, $user) {
                $validated = $request->validate([
                    'client_id' => 'required|integer',
                    'medication_name' => 'required|string|max:255',
                    'brand_name' => 'nullable|string|max:255',
                    'dose' => 'required|string|max:100',
                    'dose_unit' => 'nullable|string|max:50',
                    'frequency' => 'required|string|max:100',
                    'route' => 'nullable|string|max:50',
                    'form' => 'nullable|string|max:50',
                    'instructions' => 'nullable|string|max:2000',
                    'indication' => 'nullable|string|max:500',
                    'is_prn' => 'nullable|boolean',
                    'prn_reason' => 'nullable|string|max:500',
                    'max_per_day' => 'nullable|integer|min:1',
                    'max_doses_per_day' => 'nullable|integer|min:1',
                    'min_hours_between_doses' => 'nullable|numeric|min:0',
                    'controlled_drug' => 'nullable|boolean',
                    'is_controlled_drug' => 'nullable|boolean',
                    'high_risk' => 'nullable|boolean',
                    'is_high_risk' => 'nullable|boolean',
                    'witness_required' => 'nullable|boolean',
                    'start_date' => 'nullable|date',
                    'prescriber' => 'nullable|string|max:255',
                    'prescriber_name' => 'nullable|string|max:255',
                    'pharmac_therapeutic_group' => 'nullable|string|max:255',
                    'pharmac_subgroup' => 'nullable|string|max:255',
                ]);
                $payload = $this->buildMedicationPayload($validated);
                $this->assertControlledMedicationOrderWriteAuthority(
                    $user,
                    (bool) ($payload['controlled_drug'] ?? false),
                );
                $payload['client_id'] = $scope->client->id;

                $medication = ClientMedication::create(array_merge(
                    $payload,
                    [
                        'created_by' => $user->id,
                        'start_date' => $validated['start_date'] ?? now()->toDateString(),
                        'state' => 'active',
                        'active' => true,
                        'approval_status' => 'pending_verification',
                        'verified_by' => null,
                        'verified_at' => null,
                    ],
                ));
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'created_medication_order',
                    'Medication '.$medication->id,
                );

                return redirect()->back();
            },
        );
    }

    public function updateMedication(Request $request, ClientMedication $medication)
    {
        $user = $request->user();
        abort_unless($user, 403);

        return $this->medicationScope->forMedication(
            $user,
            $medication,
            now(),
            function (MedicationScopeDecision $scope) use ($request, $user) {
                $this->assertControlledMedicationOrderWriteAuthority(
                    $user,
                    (bool) $scope->medication->controlled_drug,
                );
                $validated = $request->validate([
                    'client_id' => 'nullable|integer',
                    'medication_name' => 'sometimes|string|max:255',
                    'brand_name' => 'nullable|string|max:255',
                    'dose' => 'sometimes|string|max:100',
                    'dose_unit' => 'nullable|string|max:50',
                    'frequency' => 'sometimes|string|max:100',
                    'route' => 'nullable|string|max:50',
                    'form' => 'nullable|string|max:50',
                    'instructions' => 'nullable|string|max:2000',
                    'indication' => 'nullable|string|max:500',
                    'is_prn' => 'nullable|boolean',
                    'prn_reason' => 'nullable|string|max:500',
                    'max_per_day' => 'nullable|integer|min:1',
                    'max_doses_per_day' => 'nullable|integer|min:1',
                    'min_hours_between_doses' => 'nullable|numeric|min:0',
                    'controlled_drug' => 'nullable|boolean',
                    'is_controlled_drug' => 'nullable|boolean',
                    'high_risk' => 'nullable|boolean',
                    'is_high_risk' => 'nullable|boolean',
                    'witness_required' => 'nullable|boolean',
                    'start_date' => 'nullable|date',
                    'prescriber' => 'nullable|string|max:255',
                    'prescriber_name' => 'nullable|string|max:255',
                    'pharmac_therapeutic_group' => 'nullable|string|max:255',
                    'pharmac_subgroup' => 'nullable|string|max:255',
                ]);
                if (array_key_exists('client_id', $validated)
                    && (int) $validated['client_id'] !== (int) $scope->client->id) {
                    throw ValidationException::withMessages([
                        'client_id' => 'The requested medication action is not available.',
                    ]);
                }

                $payload = $this->buildMedicationPayload($validated);
                unset($payload['client_id']);
                if (array_key_exists('controlled_drug', $payload)) {
                    $this->assertControlledMedicationOrderWriteAuthority(
                        $user,
                        (bool) $payload['controlled_drug'],
                    );
                    if ((bool) $payload['controlled_drug'] !== (bool) $scope->medication->controlled_drug) {
                        throw ValidationException::withMessages([
                            'controlled_drug' => 'Controlled-drug classification cannot be changed on an existing medication order.',
                        ]);
                    }
                }
                $payload['approval_status'] = 'pending_verification';
                $payload['verified_by'] = null;
                $payload['verified_at'] = null;
                $payload['rejection_reason'] = null;

                $scope->medication->update($payload);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'updated_medication_order',
                    'Medication '.$scope->medication->id,
                );

                return redirect()->back();
            },
        );
    }

    public function verifyMedication(Request $request, ClientMedication $medication)
    {
        $verifier = $request->user();
        abort_unless($this->canVerifyMedicationOrders($verifier), 403);

        return $this->medicationScope->forMedication(
            $verifier,
            $medication,
            now(),
            function (MedicationScopeDecision $scope) use ($request, $verifier) {
                $this->assertControlledMedicationOrderWriteAuthority(
                    $verifier,
                    (bool) $scope->medication->controlled_drug,
                );
                if ($scope->medication->approval_status === 'verified') {
                    return redirect()->back()->with('success', 'Medication order was already verified.');
                }

                if ($scope->medication->approval_status !== 'pending_verification') {
                    throw ValidationException::withMessages([
                        'approval_status' => 'Only a medication order awaiting verification can be verified.',
                    ]);
                }

                if ($scope->medication->state !== 'active' || ! (bool) $scope->medication->active) {
                    throw ValidationException::withMessages([
                        'medication' => 'Only an active medication order can be verified.',
                    ]);
                }

                $validated = $request->validate([
                    'waiver_reason' => [
                        'nullable',
                        'string',
                        'max:1000',
                        'required_with:waiver_approved_by,waiver_approver_credential',
                    ],
                    'waiver_approved_by' => [
                        'nullable',
                        'integer',
                        'required_with:waiver_reason,waiver_approver_credential',
                    ],
                    'waiver_approver_credential' => [
                        'nullable',
                        'string',
                        'max:255',
                        'required_with:waiver_reason,waiver_approved_by',
                    ],
                    'scan_code' => ['nullable', 'string', 'max:255'],
                    'scan_source' => ['nullable', 'string', 'in:manual,scanner'],
                    'scan_verified' => ['nullable', 'boolean'],
                    'scan_match_source' => ['nullable', 'string', 'max:50'],
                ]);

                $scanEvidenceSubmitted = collect([
                    'scan_code',
                    'scan_source',
                    'scan_verified',
                    'scan_match_source',
                ])->contains(fn (string $key): bool => $request->exists($key));
                $scanAudit = $scanEvidenceSubmitted
                    ? $this->verifyMedicationScanOrFail(
                        $scope->client,
                        $scope->medication,
                        $validated,
                    )
                    : null;

                $requiresIndependentVerifier = $scope->medication->requiresIndependentVerification();
                $creatorSeparationUnproved = $scope->medication->created_by === null
                    || (int) $scope->medication->created_by === (int) $verifier->id;
                $waiverApprover = null;
                $waiverEvidenceSubmitted = collect([
                    'waiver_reason',
                    'waiver_approved_by',
                    'waiver_approver_credential',
                ])->contains(fn (string $key): bool => filled($validated[$key] ?? null));

                if ($requiresIndependentVerifier && $creatorSeparationUnproved) {
                    $waiverApprover = $this->resolveMedicationVerificationWaiverApprover(
                        $scope,
                        $verifier,
                        $validated,
                    );
                } elseif ($waiverEvidenceSubmitted) {
                    throw ValidationException::withMessages([
                        'waiver_reason' => 'An emergency waiver is only available when a high-risk order creator must verify their own order.',
                    ]);
                }

                $orderEvidenceHash = $scope->medication->verificationEvidenceHash();
                $orderVersion = (int) ($scope->medication->version ?? 1);

                $scope->medication->forceFill([
                    'approval_status' => 'verified',
                    'verified_by' => $verifier->id,
                    'verified_at' => now(),
                    'rejection_reason' => null,
                ])->save();

                $auditMeta = [
                    'site_id' => $scope->siteId,
                    'creator_user_id' => $scope->medication->created_by !== null
                        ? (int) $scope->medication->created_by
                        : null,
                    'verifier_user_id' => (int) $verifier->id,
                    'independent_verifier_required' => $requiresIndependentVerifier,
                    'verification_mode' => $waiverApprover !== null
                        ? 'emergency_waiver'
                        : ($requiresIndependentVerifier ? 'independent_verifier' : 'standard'),
                    'approval_status_from' => 'pending_verification',
                    'approval_status_to' => 'verified',
                    'order_version' => $orderVersion,
                    'order_evidence_sha256' => $orderEvidenceHash,
                    'scan_verification_used' => $scanAudit !== null,
                ];
                if ($waiverApprover !== null) {
                    $auditMeta['waiver_reason'] = trim((string) $validated['waiver_reason']);
                    $auditMeta['waiver_approved_by_user_id'] = (int) $waiverApprover->id;
                }
                if ($scanAudit !== null) {
                    $auditMeta['scan_source'] = $scanAudit['scan_source'];
                    $auditMeta['scan_match_source'] = $scanAudit['scan_match_source'];
                    $auditMeta['scan_match_label'] = $scanAudit['scan_match_label'];
                    $auditMeta['entered_code_suffix'] = $scanAudit['scan_code_suffix'];
                }

                AuditLogger::logOrFail('medications.order.verified', $scope->medication, $auditMeta);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'verified_medication_order',
                    'Medication '.$scope->medication->id,
                );

                return redirect()->back()->with('success', 'Medication order verified.');
            },
            allowCeased: true,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveMedicationVerificationWaiverApprover(
        MedicationScopeDecision $scope,
        User $verifier,
        array $validated,
    ): User {
        if (blank($validated['waiver_reason'] ?? null)
            || empty($validated['waiver_approved_by'])
            || blank($validated['waiver_approver_credential'] ?? null)) {
            throw ValidationException::withMessages([
                'verified_by' => 'A different authorized verifier is required for high-risk medication orders.',
            ]);
        }

        $approver = User::query()
            ->whereKey((int) $validated['waiver_approved_by'])
            ->whereNotNull('approved_at')
            ->first();
        $approverIsEligible = $approver !== null
            && (int) $approver->id !== (int) $verifier->id
            && $this->canVerifyMedicationOrders($approver)
            && in_array(
                $scope->siteId,
                $this->siteAccess()->accessibleSiteIds($approver, ['clinical.accessAllSites', 'sites.viewAll']),
                true,
            )
            && Hash::check((string) $validated['waiver_approver_credential'], (string) $approver->password);

        if (! $approverIsEligible) {
            throw ValidationException::withMessages([
                'waiver_approver_credential' => 'The emergency waiver approval could not be verified.',
            ]);
        }

        return $approver;
    }

    public function rejectMedication(Request $request, ClientMedication $medication)
    {
        $reviewer = $request->user();
        abort_unless($this->canVerifyMedicationOrders($reviewer), 403);

        return $this->medicationScope->forMedication(
            $reviewer,
            $medication,
            now(),
            function (MedicationScopeDecision $scope) use ($request, $reviewer) {
                $this->assertControlledMedicationOrderWriteAuthority(
                    $reviewer,
                    (bool) $scope->medication->controlled_drug,
                );
                if ($scope->medication->approval_status === 'rejected') {
                    return redirect()->back()->with('success', 'Medication order was already rejected.');
                }

                if ($scope->medication->approval_status !== 'pending_verification') {
                    throw ValidationException::withMessages([
                        'approval_status' => 'Only a medication order awaiting verification can be rejected.',
                    ]);
                }

                if ($scope->medication->state !== 'active' || ! (bool) $scope->medication->active) {
                    throw ValidationException::withMessages([
                        'medication' => 'Only an active medication order can be rejected.',
                    ]);
                }

                $validated = $request->validate([
                    'rejection_reason' => ['required', 'string', 'max:1000'],
                ]);
                $reason = trim($validated['rejection_reason']);
                $orderEvidenceHash = $scope->medication->verificationEvidenceHash();
                $orderVersion = (int) ($scope->medication->version ?? 1);

                $scope->medication->forceFill([
                    'approval_status' => 'rejected',
                    'verified_by' => null,
                    'verified_at' => null,
                    'rejection_reason' => $reason,
                ])->save();

                AuditLogger::logOrFail('medications.order.rejected', $scope->medication, [
                    'site_id' => $scope->siteId,
                    'creator_user_id' => $scope->medication->created_by !== null
                        ? (int) $scope->medication->created_by
                        : null,
                    'reviewer_user_id' => (int) $reviewer->id,
                    'approval_status_from' => 'pending_verification',
                    'approval_status_to' => 'rejected',
                    'order_version' => $orderVersion,
                    'order_evidence_sha256' => $orderEvidenceHash,
                    'rejection_reason_sha256' => hash('sha256', $reason),
                ]);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'rejected_medication_order',
                    'Medication '.$scope->medication->id,
                );

                return redirect()->back()->with('success', 'Medication order rejected.');
            },
        );
    }

    public function discontinueMedication(Request $request, ClientMedication $medication)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $this->medicationOrderLifecycle->discontinue(
            $user,
            $medication,
            $request->input('reason'),
            requestKey: $request->input('request_key'),
        );

        return redirect()->back()->with('success', 'Medication discontinued successfully.');
    }

    // ─── Controlled Drug Entry CRUD ──────────────────────

    public function storeCDEntry(Request $request)
    {
        $this->assertMedicationCapability($request, 'medications.controlled.record');

        $validated = $request->validate([
            'client_medication_id' => 'required|integer|min:1',
            'client_id' => 'nullable|integer|min:1',
            'medication_name' => 'nullable|string|max:255',
            'entry_type' => 'required|in:receipt,administration,disposal,transfer_in,transfer_out,adjustment',
            'quantity' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE],
            'unit' => 'nullable|string|max:50',
            'on_hand_before' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
            'on_hand_after' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
            'initialize_stock' => 'nullable|boolean',
            'witnessed_by' => 'required|integer|min:1',
            'witness_credential' => 'nullable|string|max:255',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'cd_schedule' => 'nullable|integer|in:2,3,4',
            'notes' => 'nullable|string|max:2000',
            ...$this->medicationOfflineSubmissionRules($request),
        ]);

        $actor = $request->user();
        abort_unless($actor, 403);
        $expectsJson = $request->expectsJson();
        $witnessEffectiveAt = ($validated['queued_offline'] ?? false)
            ? Carbon::parse((string) $validated['captured_offline_at'])
            : now();

        return $this->governanceScope->forMedication(
            $actor,
            (int) $validated['client_medication_id'],
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            function (
                Client $client,
                ClientMedication $medication,
                User $lockedActor,
                Collection $lockedUsers,
            ) use ($validated, $actor, $expectsJson, $witnessEffectiveAt) {
                $this->assertActiveGovernanceMedication($medication);
                abort_unless((bool) $medication->controlled_drug, 404, 'The requested medication record was not found.');
                abort_unless(
                    ! isset($validated['medication_name'])
                    || hash_equals((string) $medication->name, $validated['medication_name']),
                    404,
                    'The requested medication record was not found.',
                );

                $onHandBefore = MedicationStockQuantity::normalize($validated['on_hand_before']);
                $onHandAfter = MedicationStockQuantity::normalize($validated['on_hand_after']);
                $quantity = MedicationStockQuantity::normalizeMovement($validated['quantity']);
                $scope = 'emar-controlled-entry';
                $requestFingerprint = hash('sha256', json_encode([
                    'actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id,
                    'client_medication_id' => (int) $medication->id,
                    'entry_type' => $validated['entry_type'],
                    'quantity' => $quantity,
                    'unit' => $validated['unit'] ?? null,
                    'on_hand_before' => $onHandBefore,
                    'on_hand_after' => $onHandAfter,
                    'initialize_stock' => (bool) ($validated['initialize_stock'] ?? false),
                    'witnessed_by' => (int) $validated['witnessed_by'],
                    'batch_number' => $validated['batch_number'] ?? null,
                    'expiry_date' => $validated['expiry_date'] ?? null,
                    'cd_schedule' => isset($validated['cd_schedule']) ? (int) $validated['cd_schedule'] : null,
                    'notes' => $validated['notes'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ], JSON_THROW_ON_ERROR));
                $witness = $this->governanceScope->confirmedControlledWitness(
                    $lockedActor,
                    $client,
                    (int) $validated['witnessed_by'],
                    $validated['witness_credential'] ?? null,
                    recorderId: (int) $lockedActor->id,
                    lockedUsers: $lockedUsers,
                    effectiveAt: $witnessEffectiveAt,
                );
                if ($this->medicationSyncRequested($validated)) {
                    try {
                        $stored = $this->governanceScope->idempotencyResult(
                            $scope,
                            $validated,
                            $requestFingerprint,
                            durable: true,
                        );
                    } catch (ValidationException $exception) {
                        if (! $expectsJson) {
                            throw $exception;
                        }

                        return response()->json(
                            $this->buildMedicationConflictPayload(
                                $validated,
                                'This controlled-drug entry request was already used for a different target or payload. Please submit it again with a new request identifier.',
                            ),
                            409,
                        );
                    }

                    if ($stored) {
                        if (! $expectsJson) {
                            return redirect()->back()->with('success', 'Controlled drug entry was already recorded.');
                        }

                        return response()->json($this->withMedicationSync(
                            $stored,
                            $validated,
                            'duplicate',
                            true,
                            'This medication request was already processed.',
                        ));
                    }
                }

                $stock = ClientMedicationStock::query()
                    ->where('client_medication_id', $medication->id)
                    ->lockForUpdate()
                    ->first();
                $initializing = (bool) ($validated['initialize_stock'] ?? false);
                $entryType = $validated['entry_type'];

                if ($stock && $initializing) {
                    throw ValidationException::withMessages([
                        'initialize_stock' => 'Controlled drug stock has already been initialized for this medication.',
                    ]);
                }

                if ($stock && ! MedicationStockQuantity::equals($stock->on_hand ?? 0, $onHandBefore)) {
                    if ($this->medicationSyncRequested($validated) && $expectsJson) {
                        return response()->json(
                            $this->buildMedicationConflictPayload(
                                $validated,
                                'Controlled drug stock changed before this entry could be applied. Please review the current balance before recording it again.',
                            ),
                            409,
                        );
                    }

                    throw ValidationException::withMessages([
                        'on_hand_before' => 'The controlled drug balance changed. Review the current balance before recording this entry.',
                    ]);
                }

                $unit = $stock?->unit ?? ($validated['unit'] ?? null);
                if (! $stock) {
                    $validInitialization = $initializing
                        && $entryType === 'receipt'
                        && MedicationStockQuantity::equals($onHandBefore, 0)
                        && MedicationStockQuantity::equals($onHandAfter, $quantity)
                        && filled($unit);

                    if (! $validInitialization) {
                        throw ValidationException::withMessages([
                            'initialize_stock' => 'Initialize controlled drug stock with an explicit receipt from a zero balance and a unit.',
                        ]);
                    }
                }

                $expectedAfter = match ($entryType) {
                    'receipt', 'transfer_in' => $this->addMedicationStockBalanceOrFail(
                        $onHandBefore,
                        $quantity,
                        'quantity',
                    ),
                    'administration', 'disposal', 'transfer_out' => MedicationStockQuantity::subtract($onHandBefore, $quantity),
                    'adjustment' => null,
                };

                if ($expectedAfter !== null && ! MedicationStockQuantity::equals($expectedAfter, $onHandAfter)) {
                    throw ValidationException::withMessages([
                        'on_hand_after' => 'Balance does not reconcile: '
                            .MedicationStockQuantity::display($onHandBefore)
                            .' and a movement of '.MedicationStockQuantity::display($quantity)
                            .' should leave '.MedicationStockQuantity::display($expectedAfter)
                            .', not '.MedicationStockQuantity::display($onHandAfter).'.',
                    ]);
                }

                if ($entryType === 'adjustment' && ! MedicationStockQuantity::equals(
                    MedicationStockQuantity::absoluteDifference($onHandAfter, $onHandBefore),
                    $quantity,
                )) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Adjustment quantity must equal the absolute change between the before and after balances.',
                    ]);
                }

                if (! $stock) {
                    $stock = ClientMedicationStock::create([
                        'client_medication_id' => $medication->id,
                        'on_hand' => $onHandBefore,
                        'unit' => $unit,
                    ]);
                }

                $entry = ClientControlledDrugEntry::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'service_context_id' => $client->service_context_id,
                    'entry_type' => $entryType,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'batch_number' => $validated['batch_number'] ?? null,
                    'expiry_date' => $validated['expiry_date'] ?? null,
                    'on_hand_before' => $onHandBefore,
                    'on_hand_after' => $onHandAfter,
                    'reason' => ucwords(str_replace('_', ' ', $entryType)),
                    'recorded_by' => $actor->id,
                    'witnessed_by' => $validated['witnessed_by'],
                    'notes' => $validated['notes'] ?? null,
                    'recorded_at' => now(),
                ]);

                $stock->update([
                    'on_hand' => $onHandAfter,
                    'unit' => $unit,
                    'batch_number' => $validated['batch_number'] ?? $stock->batch_number,
                    'expiry_date' => $validated['expiry_date'] ?? $stock->expiry_date,
                    'last_counted_at' => now(),
                ]);

                if (! empty($validated['cd_schedule'])) {
                    $medication->forceFill(['cd_schedule' => (int) $validated['cd_schedule']])->save();
                }

                AuditLogger::logOrFail('medications.controlled.entry.record', $entry, [
                    'actor_id' => $actor->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'client_request_uuid' => $validated['client_request_uuid'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                    'stock_id' => $stock->id,
                    'entry_type' => $entryType,
                    'witnessed_by' => $witness->id,
                    'witness_method' => 'password',
                    'witnessed_at' => now()->toIso8601String(),
                    'on_hand_before' => $onHandBefore,
                    'on_hand_after' => $onHandAfter,
                ]);

                $refreshedStock = ClientMedicationStock::query()
                    ->where('client_medication_id', $medication->id)
                    ->first();
                $payload = [
                    'success' => true,
                    'entry' => [
                        'id' => $entry->id,
                        'client_medication_id' => $entry->client_medication_id,
                        'entry_type' => $entry->entry_type,
                        'quantity' => $entry->quantity,
                        'unit' => $entry->unit,
                        'recorded_at' => $entry->recorded_at?->toIso8601String(),
                        'on_hand_after' => $entry->on_hand_after,
                    ],
                    'stock' => $refreshedStock ? [
                        'client_medication_id' => $medication->id,
                        'on_hand' => $refreshedStock->on_hand,
                        'unit' => $refreshedStock->unit,
                    ] : null,
                ];

                if ($this->medicationSyncRequested($validated)) {
                    $syncPayload = $this->withMedicationSync(
                        $payload,
                        $validated,
                        $this->medicationProcessedStatus($validated),
                    );

                    $payload = $this->governanceScope->rememberIdempotencyResult(
                        $scope,
                        $validated,
                        $syncPayload,
                        $requestFingerprint,
                        durable: true,
                    );
                }

                if ($expectsJson) {
                    return response()->json($payload);
                }

                return redirect()->back()->with('success', 'Controlled drug entry recorded.');
            },
            isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            authorizationUserIds: [(int) $validated['witnessed_by']],
            authorizationEffectiveAt: $witnessEffectiveAt,
        );
    }

    public function storeBalanceCheck(Request $request)
    {
        $this->assertMedicationCapability($request, 'medications.controlled.record');

        $validated = $request->validate([
            'client_medication_id' => 'required|integer|min:1',
            'client_id' => 'nullable|integer|min:1',
            'medication_name' => 'nullable|string|max:255',
            'expected_balance' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
            // actual_balance also persists to client_controlled_drug_entries.quantity,
            // so that DECIMAL(10,2) register sink is the governing limit.
            'actual_balance' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE],
            'witnessed_by' => 'required|integer|min:1',
            'witness_credential' => 'nullable|string|max:255',
            'discrepancy_notes' => 'nullable|string|max:2000',
            'immediate_action_taken' => [
                Rule::requiredIf(function () use ($request): bool {
                    $expectedBalance = $request->input('expected_balance');
                    $actualBalance = $request->input('actual_balance');
                    if (! is_numeric($expectedBalance) || ! is_numeric($actualBalance)) {
                        return false;
                    }

                    try {
                        return ! MedicationStockQuantity::equals(
                            $expectedBalance,
                            $actualBalance,
                        );
                    } catch (\InvalidArgumentException) {
                        return false;
                    }
                }),
                'nullable',
                'string',
                'max:5000',
            ],
            ...$this->medicationOfflineSubmissionRules($request),
        ]);

        $actor = $request->user();
        abort_unless($actor, 403);
        $expectsJson = $request->expectsJson();
        $witnessEffectiveAt = ($validated['queued_offline'] ?? false)
            ? Carbon::parse((string) $validated['captured_offline_at'])
            : now();

        return $this->governanceScope->forMedication(
            $actor,
            (int) $validated['client_medication_id'],
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            function (
                Client $client,
                ClientMedication $medication,
                User $lockedActor,
                Collection $lockedUsers,
            ) use ($validated, $actor, $expectsJson, $witnessEffectiveAt) {
                $this->assertActiveGovernanceMedication($medication);
                abort_unless((bool) $medication->controlled_drug, 404, 'The requested medication record was not found.');
                abort_unless(
                    ! isset($validated['medication_name'])
                    || hash_equals((string) $medication->name, $validated['medication_name']),
                    404,
                    'The requested medication record was not found.',
                );

                $expectedBalance = MedicationStockQuantity::normalize($validated['expected_balance']);
                $actualBalance = MedicationStockQuantity::normalizeMovement($validated['actual_balance']);
                $scope = 'emar-controlled-balance-check';
                $requestFingerprint = hash('sha256', json_encode([
                    'actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id,
                    'client_medication_id' => (int) $medication->id,
                    'expected_balance' => $expectedBalance,
                    'actual_balance' => $actualBalance,
                    'witnessed_by' => (int) $validated['witnessed_by'],
                    'discrepancy_notes' => $validated['discrepancy_notes'] ?? null,
                    'immediate_action_taken' => $validated['immediate_action_taken'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ], JSON_THROW_ON_ERROR));
                $witness = $this->governanceScope->confirmedControlledWitness(
                    $lockedActor,
                    $client,
                    (int) $validated['witnessed_by'],
                    $validated['witness_credential'] ?? null,
                    recorderId: (int) $lockedActor->id,
                    lockedUsers: $lockedUsers,
                    effectiveAt: $witnessEffectiveAt,
                );
                if ($this->medicationSyncRequested($validated)) {
                    try {
                        $stored = $this->governanceScope->idempotencyResult(
                            $scope,
                            $validated,
                            $requestFingerprint,
                            durable: true,
                        );
                    } catch (ValidationException $exception) {
                        if (! $expectsJson) {
                            throw $exception;
                        }

                        return response()->json(
                            $this->buildMedicationConflictPayload(
                                $validated,
                                'This controlled-drug balance-check request was already used for a different target or payload. Please submit it again with a new request identifier.',
                            ),
                            409,
                        );
                    }

                    if ($stored) {
                        if (! $expectsJson) {
                            return redirect()->back()->with('success', 'Controlled drug balance check was already recorded.');
                        }

                        return response()->json($this->withMedicationSync(
                            $stored,
                            $validated,
                            'duplicate',
                            true,
                            'This medication request was already processed.',
                        ));
                    }
                }

                $stock = ClientMedicationStock::query()
                    ->where('client_medication_id', $medication->id)
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    throw ValidationException::withMessages([
                        'expected_balance' => 'No controlled drug stock position exists. Record a receipt to initialize stock before completing a balance check.',
                    ]);
                }

                if (! MedicationStockQuantity::equals($stock->on_hand ?? 0, $expectedBalance)) {
                    if ($this->medicationSyncRequested($validated) && $expectsJson) {
                        return response()->json(
                            $this->buildMedicationConflictPayload(
                                $validated,
                                'Controlled drug stock changed before this balance check could be applied. Please review the current balance before recording it again.',
                            ),
                            409,
                        );
                    }

                    throw ValidationException::withMessages([
                        'expected_balance' => 'The controlled drug balance changed. Review the current balance before recording this check.',
                    ]);
                }

                $entry = ClientControlledDrugEntry::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'service_context_id' => $client->service_context_id,
                    'entry_type' => 'balance_check',
                    'quantity' => $actualBalance,
                    'unit' => $stock->unit,
                    'on_hand_before' => $expectedBalance,
                    'on_hand_after' => $actualBalance,
                    'reason' => 'Balance check',
                    'recorded_by' => $actor->id,
                    'witnessed_by' => $validated['witnessed_by'],
                    'notes' => $validated['discrepancy_notes'] ?? null,
                    'recorded_at' => now(),
                ]);

                $stock->update([
                    'on_hand' => $actualBalance,
                    'last_counted_at' => now(),
                ]);

                $discrepancy = null;
                if (! MedicationStockQuantity::equals($expectedBalance, $actualBalance)) {
                    $discrepancy = ClientControlledDrugDiscrepancy::create([
                        'client_id' => $client->id,
                        'client_medication_id' => $medication->id,
                        'service_context_id' => $client->service_context_id,
                        'on_hand_before' => $expectedBalance,
                        'on_hand_after' => $actualBalance,
                        'difference' => MedicationStockQuantity::subtract($actualBalance, $expectedBalance),
                        'reason' => 'Balance check discrepancy',
                        'reported_by' => $actor->id,
                        'witnessed_by' => $validated['witnessed_by'],
                        'notes' => $validated['discrepancy_notes'] ?? null,
                        'immediate_action_taken' => trim((string) $validated['immediate_action_taken']),
                        'status' => 'open',
                        'reported_at' => now(),
                    ]);
                }

                AuditLogger::logOrFail('medications.controlled.balance_check.record', $entry, [
                    'actor_id' => $actor->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'client_request_uuid' => $validated['client_request_uuid'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                    'stock_id' => $stock->id,
                    'discrepancy_id' => $discrepancy?->id,
                    'witnessed_by' => $witness->id,
                    'witness_method' => 'password',
                    'witnessed_at' => now()->toIso8601String(),
                    'on_hand_before' => $expectedBalance,
                    'on_hand_after' => $actualBalance,
                ]);

                if ($discrepancy) {
                    app(MedicationIncidentIntegrationService::class)
                        ->handleControlledDiscrepancy($discrepancy, $actor->id);
                }

                MedicationDashboardAlert::query()
                    ->where('client_id', $client->id)
                    ->where('client_medication_id', $medication->id)
                    ->where('alert_type', 'controlled_overdue_check')
                    ->where('status', 'active')
                    ->get()
                    ->each(fn ($alert) => $alert->resolve('Balance check recorded.'));

                $stock->refresh();
                $payload = [
                    'success' => true,
                    'entry' => [
                        'id' => $entry->id,
                        'entry_type' => $entry->entry_type,
                        'quantity' => $entry->quantity,
                        'recorded_at' => $entry->recorded_at?->toIso8601String(),
                    ],
                    'discrepancy' => $discrepancy ? [
                        'id' => $discrepancy->id,
                        'status' => $discrepancy->status,
                        'difference' => $discrepancy->difference,
                        'reported_at' => $discrepancy->reported_at?->toIso8601String(),
                    ] : null,
                    'stock' => [
                        'client_medication_id' => $medication->id,
                        'on_hand' => $stock->on_hand,
                        'unit' => $stock->unit,
                    ],
                ];

                if ($this->medicationSyncRequested($validated)) {
                    $syncPayload = $this->withMedicationSync(
                        $payload,
                        $validated,
                        $this->medicationProcessedStatus($validated),
                    );

                    $payload = $this->governanceScope->rememberIdempotencyResult(
                        $scope,
                        $validated,
                        $syncPayload,
                        $requestFingerprint,
                        durable: true,
                    );
                }

                if ($expectsJson) {
                    return response()->json($payload);
                }

                return redirect()->back()->with('success', 'Controlled drug balance check recorded.');
            },
            isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            authorizationUserIds: [(int) $validated['witnessed_by']],
            authorizationEffectiveAt: $witnessEffectiveAt,
        );
    }

    public function resolveDiscrepancy(Request $request, ClientControlledDrugDiscrepancy $discrepancy)
    {
        $this->assertMedicationCapability($request, 'medications.controlled.record');

        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:2000',
            'resolution_action' => 'nullable|string|max:255',
        ]);

        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forDiscrepancy(
            $actor,
            $discrepancy,
            function (Client $client, ?ClientMedication $medication, ClientControlledDrugDiscrepancy $lockedDiscrepancy) use ($validated, $actor) {
                if (! in_array($lockedDiscrepancy->status, ['open', 'under_review'], true)) {
                    return redirect()->back()->withErrors(['resolution_notes' => 'This discrepancy has already been resolved.']);
                }

                $lockedDiscrepancy->update([
                    'status' => 'closed',
                    'resolution_notes' => trim(
                        ($validated['resolution_action'] ? 'Action: '.$validated['resolution_action']."\n\n" : '')
                        .$validated['resolution_notes']
                    ),
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                ]);

                AuditLogger::logOrFail('medications.controlled.discrepancy.resolve', $lockedDiscrepancy, array_filter([
                    'actor_id' => $actor->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication?->id,
                    'resolution_action' => $validated['resolution_action'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''));

                app(MedicationIncidentIntegrationService::class)->resolveControlledDiscrepancy(
                    $lockedDiscrepancy,
                    'Controlled drug discrepancy resolved.',
                    $actor->id,
                );

                return redirect()->back();
            },
        );
    }

    public function dismissAlert(Request $request, int $alert)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteIds = $this->governanceScope->readerSiteIds(
            $actor,
            'medications.administer.correct',
        );
        $alertQuery = $this->governanceScope
            ->scopeCanonicalClientMedicationRows(
                MedicationDashboardAlert::query()->whereKey($alert),
                $siteIds,
            );
        if (! $actor->canDo('medications.controlled.view')) {
            $alertQuery->whereNotIn('alert_type', [
                'controlled_discrepancy',
                'controlled_overdue_check',
                'controlled_loss',
            ]);
            $this->governanceScope->scopeWithoutControlledMedicationRows($alertQuery);
        }

        $canonicalAlert = $alertQuery
            ->with('client:id,site_id')
            ->firstOrFail();

        app(MedicationAlertService::class)->acknowledgeAlert(
            $canonicalAlert,
            $actor,
        );

        return redirect()->back();
    }

    // ─── Handover Update/Delete ──────────────────────────

    public function updateHandover(Request $request, mixed $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
        $handover = $this->canonicalHandover($auth, $handover);
        $this->siteAccess()->assertCanAccessHandover(
            $auth,
            $handover,
            $this->handoverBypassPermissions(),
            'You are not authorized to access handovers for this site.',
        );
        abort_unless($handover->status === ShiftHandoverService::STATUS_DRAFT, 422, 'Only draft handovers can be edited.');
        abort_unless($this->handoverService->canSubmit($handover, $auth), 403);
        $this->assertControlledHandoverInputAuthority($request, $auth);

        $validated = $request->validate([
            'incoming_shift_id' => ['nullable', 'integer', 'min:1'],
            'incoming_staff_id' => ['nullable', 'integer', 'min:1'],
            'handover_notes' => ['required', 'string', 'max:5000'],
            'client_mood' => ['nullable', 'string', 'max:255'],
            'medications_due_text' => ['nullable', 'string'],
            'follow_up_items_text' => ['nullable', 'string'],
            'incidents_to_note_text' => ['nullable', 'string'],
            'tasks_pending_text' => ['nullable', 'string'],
            'cd_result' => ['nullable', 'in:verified,discrepancy'],
            'cd_witness_id' => ['nullable', 'integer', 'min:1'],
            'cd_witness_credential' => ['nullable', 'string', 'max:255'],
            'cd_notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $result = $this->handoverService->save($handover->outgoingShift, $auth, [
            ...($request->exists('incoming_shift_id') ? [
                'incoming_shift_id' => $validated['incoming_shift_id'] ?? null,
            ] : []),
            ...($request->exists('incoming_staff_id') ? [
                'incoming_staff_id' => $validated['incoming_staff_id'] ?? null,
            ] : []),
            'handover_notes' => $validated['handover_notes'],
            'client_mood' => $validated['client_mood'] ?? null,
            ...($this->hasMedicationDueInput($request) ? [
                'medications_due' => $this->parseHandoverText($validated['medications_due_text'] ?? null),
            ] : []),
            'follow_up_items' => $this->parseHandoverText($validated['follow_up_items_text'] ?? null),
            'incidents_to_note' => $this->parseHandoverText($validated['incidents_to_note_text'] ?? null),
            'tasks_pending' => $this->parseHandoverText($validated['tasks_pending_text'] ?? null),
            'cd_verification_input' => [
                'result' => $validated['cd_result'] ?? null,
                'witness_id' => $validated['cd_witness_id'] ?? null,
                'witness_credential' => $validated['cd_witness_credential'] ?? null,
                'notes' => $validated['cd_notes'] ?? null,
            ],
            'expected_version' => $validated['version'] ?? null,
            'submit' => (bool) ($validated['submit'] ?? false),
        ]);

        return redirect()->back()->with(
            'success',
            $result['action'] === 'draft_saved' ? 'Medication handover draft updated.' : 'Medication handover submitted.',
        );
    }

    public function destroyHandover(Request $request, mixed $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
        $handover = $this->canonicalHandover($auth, $handover);
        $this->handoverService->destroyDraft($handover, $auth);

        return redirect()->back()->with('success', 'Medication handover draft deleted.');
    }

    // ─── Destruction Void ────────────────────────────────

    /**
     * The destruction register is immutable and retained (MoD Regs 1977). A
     * record is never hard-deleted; an erroneous entry is *voided* — it stays
     * visible (struck through, with the reason). Voiding is administrative only:
     * it does not reverse stock/register effects. Any correction uses the
     * separately witnessed governed reconciliation flow.
     */
    public function voidDestruction(Request $request, MedicationDestruction $destruction)
    {
        $this->assertMedicationCapability($request, 'medications.controlled.record');

        $actor = $request->user();
        abort_unless($actor, 403);

        return $this->governanceScope->forDestruction(
            $actor,
            $destruction,
            function (Client $client, ?ClientMedication $medication, MedicationDestruction $lockedDestruction) use ($request, $actor) {
                $validated = $request->validate([
                    'void_reason' => 'required|string|max:1000',
                ]);
                if ($lockedDestruction->voided_at !== null) {
                    return redirect()->back()->withErrors(['void_reason' => 'This destruction record has already been voided.']);
                }

                $lockedDestruction->update([
                    'voided_at' => now(),
                    'void_reason' => $validated['void_reason'],
                    'voided_by' => $actor->id,
                ]);

                AuditLogger::logOrFail('medications.destruction.void', $lockedDestruction, [
                    'actor_id' => $actor->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication?->id,
                    'void_reason' => $validated['void_reason'],
                    'void_stock_semantics' => MedicationDestruction::VOID_STOCK_SEMANTICS,
                    'stock_effect_reversed' => false,
                    'requires_governed_stock_reconciliation' => (bool) $lockedDestruction->is_controlled_drug,
                ]);

                return redirect()->back()->with(
                    'success',
                    'Destruction record voided. Stock and register balances were not changed; record any correction through witnessed reconciliation.',
                );
            },
        );
    }

    // ─── Medications CSV Import ──────────────────────────

    public function importMedications(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return redirect()->back()->withErrors(['csv_file' => 'Unable to read the CSV file.']);
        }

        $rows = [];
        $rowNumber = 0;

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if (empty(array_filter($row))) {
                    continue;
                }

                if ($rowNumber === 1 && stripos($row[0] ?? '', 'client') !== false) {
                    continue;
                }

                if (count($row) < 4) {
                    continue;
                }

                $clientName = trim($row[0] ?? '');
                $medicationName = trim($row[1] ?? '');
                $dose = trim($row[2] ?? '');
                $frequency = trim($row[3] ?? '');
                $route = trim($row[4] ?? 'oral');

                if (! $clientName || ! $medicationName || ! $dose || ! $frequency) {
                    continue;
                }

                if (str_contains($clientName, ',')) {
                    [$lastName, $firstName] = array_map('trim', explode(',', $clientName, 2));
                } else {
                    $nameParts = explode(' ', $clientName, 2);
                    if (count($nameParts) !== 2) {
                        continue;
                    }

                    [$firstName, $lastName] = array_map('trim', $nameParts);
                }

                if ($firstName === '' || $lastName === '') {
                    continue;
                }

                $rows[] = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'medication_name' => $medicationName,
                    'dose' => $dose,
                    'frequency' => $frequency,
                    'route' => $route,
                ];
            }
        } finally {
            fclose($handle);
        }

        $accessibleSiteIds = $this->siteAccess()->accessibleSiteIds(
            $user,
            ['clinical.accessAllSites', 'sites.viewAll'],
        );
        if ($rows === [] || $accessibleSiteIds === []) {
            return redirect()->back();
        }

        $resolvedRows = [];
        foreach ($rows as $row) {
            $matchingClientIds = Client::query()
                ->whereIn('site_id', $accessibleSiteIds)
                ->where('status', 'active')
                ->where('first_name', $row['first_name'])
                ->where('last_name', $row['last_name'])
                ->orderBy('id')
                ->limit(2)
                ->pluck('id');

            if ($matchingClientIds->count() !== 1) {
                continue;
            }

            $row['client_id'] = (int) $matchingClientIds->first();
            $resolvedRows[] = $row;
        }

        if ($resolvedRows === []) {
            return redirect()->back();
        }

        DB::transaction(function () use ($resolvedRows, $accessibleSiteIds, $user): void {
            $clientIds = collect($resolvedRows)
                ->pluck('client_id')
                ->map(fn ($clientId): int => (int) $clientId)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $lockedClients = Client::query()
                ->whereIn('id', $clientIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Client $client): int => (int) $client->id);

            foreach ($resolvedRows as $row) {
                /** @var Client|null $client */
                $client = $lockedClients->get($row['client_id']);
                if (! $client
                    || ! in_array((int) $client->site_id, $accessibleSiteIds, true)
                    || $client->status !== 'active'
                    || $client->first_name !== $row['first_name']
                    || $client->last_name !== $row['last_name']) {
                    throw ValidationException::withMessages([
                        'csv_file' => 'A matched client changed while the import was being processed. Please try again.',
                    ]);
                }

                ClientMedication::query()->create([
                    'client_id' => $client->id,
                    'created_by' => $user->id,
                    'name' => $row['medication_name'],
                    'dosage' => $row['dose'],
                    'frequency' => $row['frequency'],
                    'dose_times' => DoseSchedulingService::calculateDoseTimes($row['frequency']),
                    'route' => $row['route'],
                    'state' => 'active',
                    'active' => true,
                    'start_date' => now()->toDateString(),
                    'approval_status' => 'pending_verification',
                    'verified_by' => null,
                    'verified_at' => null,
                ]);
            }
        }, 3);

        return redirect()->back();
    }
}
