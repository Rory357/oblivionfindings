<?php

namespace App\Http\Controllers\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Controller;
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
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationDestruction;
use App\Models\MedicationInteraction;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\MedicationScheduledStockCount;
use App\Models\MedicationSyringeDriver;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\Emar\ShiftMedicationSnapshotService;
use App\Services\GuidedRoundService;
use App\Services\MarScheduleService;
use App\Services\Medication\CompetencyPolicySettings;
use App\Services\Medication\Controlled\ControlledRegisterService;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use App\Services\Medication\Followups\LegacyEffectFollowupAdapter;
use App\Services\Medication\Followups\MedicationFollowupProjection;
use App\Services\Medication\MarLinkService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationLegacyOrderBridge;
use App\Services\Medication\MedicationOrderLifecycleService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\MedicationRoundGenerationService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\PharmacyConnect\PharmacyDispatchGuard;
use App\Services\Medication\RoundTemplateCatalogue;
use App\Services\Medication\SecondPersonConfirmationPayload;
use App\Services\Medication\Settings\MedicationSettingsStore;
use App\Services\Medication\Stock\MedicationStockService;
use App\Services\Medication\Stock\StockManagementReadPayload;
use App\Services\Medication\WitnessPinService;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MedicationOverviewService;
use App\Services\MedicationRuleService;
use App\Services\MedicationScanVerificationService;
use App\Services\Operations\HandoverPresenter;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use App\Support\Medication\MedicationStockQuantity;
use App\Support\WorkerClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmarController extends Controller
{
    use HandlesMedicationSync;

    public function __construct(
        protected ShiftHandoverService $handoverService,
        protected MedicationScanVerificationService $scanVerificationService,
        protected MedsBoardPayloadService $boardPayload,
        protected MedicationScopeDecisionService $medicationScope,
        protected MedicationOrderLifecycleService $medicationOrderLifecycle,
        protected MedicationGovernanceScopeService $governanceScope,
        protected MedicationRoundGenerationService $roundGeneration,
    ) {}

    private ?MarLinkService $marLinks = null;

    // ─── Helpers ──────────────────────────────────────────

    /**
     * Site-scoped list rows link to a resident's MAR only when the viewer may
     * open it (ClientPolicy::viewMedications), never to a chart that 404s.
     */
    private function marUrlFor(mixed $clientId): ?string
    {
        return $this->marLinks()->urlFor(request()->user(), $clientId);
    }

    private function marLinks(): MarLinkService
    {
        return $this->marLinks ??= app(MarLinkService::class);
    }

    /** The one per-person medication-record gate (P02). */
    private function recordAccess(): MedicationRecordAccess
    {
        return app(MedicationRecordAccess::class);
    }

    /**
     * Site scope is not person scope: narrow a Site-wide list's clients to the
     * people whose chart this user may open (ClientPolicy::viewMedications), so
     * an ordinary support worker never sees another resident's rows while leads
     * keep the whole Site. Same rule as the MAR picker.
     *
     * @param  iterable<int, mixed>  $clientIds
     * @return array<int, int>
     */
    private function personScopedClientIds(User $actor, iterable $clientIds): array
    {
        return $this->marLinks()->openableClientIds($actor, $clientIds);
    }

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
            // EA-196: ending access needs breakglass (own) or breakglass.end, not audit.view.
            'revoke_break_glass' => (bool) $user && ($user->canDo('medications.breakglass') || $user->canDo('medications.breakglass.end')),
            'view_reports' => (bool) $user && $user->canDo('medications.reports.view'),
            'view_audit' => (bool) $user && $user->canDo('medications.reports.view') && $user->canDo('medications.audit.view'),
            'export_reports' => (bool) $user && (
                $user->canDo('medications.reports.view')
                && $user->canDo('medications.reports.export')
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
            ->whereNull('source_referral_evidence_id')
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
        // A reading with no medicine linked is never hidden (NF-23); the MAR
        // rail labels it. A linked reading still needs a same-Client order.
        $this->governanceScope->scopeCanonicalClientMedicationRows(
            $query->getQuery(),
            $client->site_id ? [(int) $client->site_id] : [],
            true,
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
            ...app(SecondPersonConfirmationPayload::class)->forAdministration($administration),
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
            return $this->parseSyringeDriverCommencedAt($value);
        } catch (\InvalidArgumentException) {
            // The canonical validation error remains inside the locked Client
            // boundary; an invalid hint must not move validation ahead of 404.
            return null;
        }
    }

    /**
     * The MAR dialog submits a zone-less datetime-local value (worker wall
     * clock). Read it in the worker timezone — an explicit offset still wins —
     * and normalise to UTC for storage, so "now" in NZ is neither refused as a
     * future time nor recorded 12–13 hours off.
     */
    private function parseSyringeDriverCommencedAt(string $value): Carbon
    {
        return Carbon::parse($value, config('app.worker_timezone', 'Pacific/Auckland'))->utc();
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
    private function assertActiveGovernanceMedication(ClientMedication $medication): void
    {
        abort_unless(
            $medication->isAdministrable(),
            404,
            'The requested medication record was not found.',
        );
    }

    /**
     * Interim single-row stock identity until P06's per-lot rows (EM-10). A
     * delivery never blanks the recorded batch or expiry, and the row keeps
     * the earliest expiry among stock actually on hand (with that lot's
     * batch). When nothing was on hand, the delivery's values, where given,
     * replace the old ones.
     */
    private function mergeDeliveredBatch(
        ClientMedicationStock $stock,
        ?string $deliveredBatch,
        mixed $deliveredExpiry,
        string $onHandBefore,
    ): void {
        $batch = filled($deliveredBatch) ? trim((string) $deliveredBatch) : null;
        $expiry = filled($deliveredExpiry) ? Carbon::parse($deliveredExpiry)->toDateString() : null;
        $currentExpiry = $stock->expiry_date?->toDateString();

        if (MedicationStockQuantity::lessThanOrEqual($onHandBefore, 0)) {
            $stock->batch_number = $batch ?? $stock->batch_number;
            $stock->expiry_date = $expiry ?? $currentExpiry;

            return;
        }

        if ($expiry !== null && ($currentExpiry === null || $expiry < $currentExpiry)) {
            $stock->expiry_date = $expiry;
            $stock->batch_number = $batch ?? $stock->batch_number;

            return;
        }

        if (blank($stock->batch_number) && $batch !== null) {
            $stock->batch_number = $batch;
        }
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
                    'competency_notice' => $user ? $this->boardPayload->competencyNoticeFor($user) : null,
                ],
            ]
        ));
    }

    // ─── MAR Charts ────────────────────────────────────────
    public function mar(Request $request)
    {
        // The completed record serves a named person; the default bare route
        // lists permitted charts. An explicit legacy override keeps the earlier page.
        if (config('medications.person_record') === 'p02' && $request->filled('client_id')) {
            return app(PersonMedicationRecordController::class)->show($request, $request->integer('client_id'));
        }
        if (config('medications.person_record') === 'p02') {
            return app(MedicationRecordHubController::class)->show($request, 'charts');
        }

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
        // Site scope is not person scope: the picker lists only residents whose
        // medication record this user may open (ClientPolicy::viewMedications —
        // assignment for ordinary support workers), so it never names a person
        // the explicit ?client_id path below refuses. site_id is selected
        // because the policy's Site check reads it.
        $clients = Client::query()
            ->whereIn('id', $viewableClientIds)
            ->whereIn('site_id', $allowedSiteIds)
            ->withCount(['medications as active_medications_count' => fn ($q) => $q
                ->active()
                ->when(! $can['view_controlled'], fn ($query) => $query->where('controlled_drug', false))])
            ->having('active_medications_count', '>', 0)
            ->orderBy('last_name')
            ->get(['id', 'site_id', 'first_name', 'last_name', 'date_of_birth', 'nhi_number'])
            ->filter(fn (Client $client) => Gate::forUser($actor)->allows('viewMedications', $client))
            ->values();

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
            'medications.administrations.'.SecondPersonConfirmationPayload::RELATION,
        ];

        // Default the resident server-side so the MAR chart opens straight onto a
        // chart instead of a two-step picker. An explicit ?client_id (deep-link or
        // the hero EntityFilter) wins; it must pass both the Site boundary and the
        // per-person ClientPolicy::viewMedications check, and a denial is a 404
        // indistinguishable from a missing or out-of-Site record. With no
        // client_id we fall back to the last chart this user viewed, else the
        // first resident they may view — never throwing for an auto-pick.
        if ($request->filled('client_id')) {
            $selectedClient = Client::query()
                ->whereIn('site_id', $allowedSiteIds)
                ->find($requestedClientId);
            $this->recordAccess()->assertReadable($actor, $selectedClient);
            $selectedClient->load($marWith);
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
            'interactions' => $selectedClient ? $this->getActiveInteractions($selectedClient, $can['view_controlled']) : [],
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
        // The orders the MAR's schedule rows list (C6k): verified, or with a
        // change waiting for the order check — whose doses show as waiting.
        $medications = $client->medications()
            ->where(fn ($orders) => $orders->active()->orWhere(fn ($waiting) => $waiting->awaitingVerification()))
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
                'administrations.'.SecondPersonConfirmationPayload::RELATION,
            ])->get();

        $scheduled = $medications->where('is_prn', false)->values();
        // PRN: only orders that can be given.
        $prn = $medications->where('is_prn', true)->filter(fn (ClientMedication $med): bool => $med->isAdministrable())->values();
        // Each order's doses that day, from the dose-slot projection (C6k):
        // the slot's due time is the grid's column (the spring-forward 02:30
        // dose at 03:00), as the schedule rows carry it.
        $doses = app(ScheduledDoseStates::class)->dosesOn($scheduled, $date, now());

        $scheduledPayload = $scheduled->map(function ($med) use ($client, $ruleService, $scheduleService, $doses) {
            $adminRules = $ruleService->requirementsFor($med);
            $medDoses = collect($doses[(int) $med->id] ?? []);
            $doseTimes = $medDoses
                ->map(fn (array $dose) => $dose['due_at']->copy()->timezone($scheduleService->workerTimezone())->format('H:i'))
                ->values()
                ->all();
            $matchedAdministrationIds = [];

            // Build administration slots: for each dose, find matching admin record
            $administrations = $medDoses->map(function (array $dose) use ($med, &$matchedAdministrationIds, $scheduleService) {
                $scheduledAt = $dose['due_at'];
                [$slotStartUtc, $slotEndUtc] = $scheduleService->utcSlotWindow($scheduledAt);
                // The slot's current record: the latest one matching this time
                // slot (a re-offer after a refusal is a second record; P01).
                // Earlier records still show below as the slot's history.
                $admin = $med->administrations->sortByDesc('id')->first(function ($a) use ($slotStartUtc, $slotEndUtc) {
                    $scheduledFor = $this->administrationDateUtc($a, 'scheduled_for');

                    return $scheduledFor?->betweenIncluded($slotStartUtc, $slotEndUtc) === true;
                });

                if ($admin) {
                    $matchedAdministrationIds[] = $admin->id;

                    return $this->serializeAdministration($admin);
                }

                // No record yet: the dose's state as Meds today shows it
                // (overdue once its window has ended — not "missed" an hour
                // after its time; C6k).
                return $this->serializeAdministration(null, ScheduledDoseStates::listStatus($dose), $scheduledAt->toIso8601String());
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
                    'on_hand' => $med->stock->availableQuantity(),
                    'pack_workflow_url' => $med->stock->pack_workflow_url,
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
                    'on_hand' => $med->stock->availableQuantity(),
                    'pack_workflow_url' => $med->stock->pack_workflow_url,
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
                        'on_hand' => $med->stock->availableQuantity(),
                        'pack_workflow_url' => $med->stock->pack_workflow_url,
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

    /**
     * Interaction pairs among the client's active medicines. Without controlled
     * view, controlled medicines are excluded from the candidate set (as on the
     * register and medication detail) so no pair can name one.
     */
    private function getActiveInteractions(Client $client, bool $includeControlled): array
    {
        $medicationNames = $client->medications()
            ->active()
            ->when(! $includeControlled, fn ($query) => $query->where('controlled_drug', false))
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
        if (config('medications.person_record') === 'p02') {
            return app(MedicationRecordHubController::class)->show($request, 'asneeded');
        }
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
        $viewableClientIds = $this->personScopedClientIds($user, Client::query()
            ->whereIn('id', $viewableClientIds)
            ->whereIn('site_id', $readerSiteIds)
            ->pluck('id'));
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
            'client:id,first_name,last_name,room_id,site_id',
            'client.room:id,name',
            'client.site:id,name',
            'medication:id,name,dosage,route,max_per_day,indication,controlled_drug,deleted_at',
            'administeredBy:id,name',
            'prnEffectiveness.reviewedByUser:id,name',
            'witnessedBy:id,name',
            SecondPersonConfirmationPayload::RELATION,
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
            ...app(SecondPersonConfirmationPayload::class)->forAdministration($a),
            'mar_url' => $this->marUrlFor($a->client_id),
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
        $medication = $this->recordAccess()->medication($actor, (int) $medication->id);
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
                'at' => $a->administered_at ? \App\Support\WorkerClock::display($a->administered_at, 'j M Y, g:ia') : null,
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
                    // decimal:2 cast: "0.00" is a truthy string, so compare
                    // the quantity numerically.
                    $disc = $c->discrepancy;
                    $hasDiscrepancy = $disc !== null && ! MedicationStockQuantity::equals($disc, 0);

                    return [
                        'type' => 'count',
                        'ts' => $c->completed_at?->getTimestamp() ?? 0,
                        'at' => $c->completed_at ? \App\Support\WorkerClock::display($c->completed_at, 'j M Y, g:ia') : null,
                        'status' => $hasDiscrepancy ? 'discrepancy' : 'counted',
                        'label' => 'Counted '.$c->actual_quantity.($c->expected_quantity !== null ? ' (expected '.$c->expected_quantity.')' : ''),
                        'by' => $c->completedBy?->name,
                        'note' => $hasDiscrepancy
                            ? 'Discrepancy '.(MedicationStockQuantity::greaterThan($disc, 0) ? '+' : '').$disc
                            : ($c->notes ?: null),
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
        if (config('medications.person_record') === 'p02') {
            return app(MedicationRecordHubController::class)->show($request, 'medicines');
        }
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
        // People, not a whole house: the register lists the medicines of the
        // people whose record this user may open; a named person must pass
        // the same per-person gate (404 otherwise).
        if ($clientFilter !== null) {
            $this->recordAccess()->client($user, $clientFilter);
        }
        $readableClientIds = $this->recordAccess()->readableClientIds(
            $user,
            Client::query()->whereIn('site_id', $readerSiteIds)->pluck('id'),
        );

        // Flat register of current (non-superseded) medications — the redesigned
        // page filters by tab/search/client/sort entirely client-side, with live
        // facet counts, so the whole register is served at once.
        $meds = ClientMedication::query()
            ->current()
            ->whereHas('client', fn ($query) => $query->whereIn('site_id', $readerSiteIds))
            ->whereIn('client_id', $readableClientIds)
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
                'requires_witness' => $m->requiresWitness(),
                'state' => $m->state,
                'approval_status' => $m->approval_status,
                'rejection_reason' => $m->rejection_reason,
                'pharmac_therapeutic_group' => $m->pharmac_therapeutic_group,
                'start_date' => $start,
                // Verification / lifecycle audit (all pre-existing columns — no migration).
                'created_by_name' => $m->createdByUser?->name,
                // EA-205 (Lane C7): stored UTC instants shown in NZ time.
                'created_at' => $m->created_at ? \App\Support\WorkerClock::display($m->created_at, 'j M Y, g:ia') : null,
                'verified_by_name' => $m->verifiedByUser?->name,
                'verified_at' => $m->verified_at ? \App\Support\WorkerClock::display($m->verified_at, 'j M Y, g:ia') : null,
                'ceased_by_name' => $m->ceasedByUser?->name,
                'ceased_at' => $m->ceased_at ? \App\Support\WorkerClock::display($m->ceased_at, 'j M Y') : null,
                'ceased_reason' => $m->ceased_reason,
                'review_date' => $m->review_date?->format('j M Y'),
                'stock' => $m->stock ? [
                    'on_hand' => $m->stock->availableQuantity(),
                    'pack_workflow_url' => $m->stock->pack_workflow_url,
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
            'clients' => $this->governanceScope->clientPicker($accessibleSiteIds)
                ->whereIn('id', $readableClientIds)
                ->values(),
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
        return Inertia::render('emar/StockManagement', app(StockManagementReadPayload::class)->page($request));
    }

    public function stockMedicationContext(Request $request, ClientMedication $medication): JsonResponse
    {
        return response()->json(app(StockManagementReadPayload::class)->medicationContext($request, (int) $medication->id))
            ->header('Cache-Control', 'no-store');
    }

    // ─── Prescriptions / Prescriber Orders ─────────────────
    public function prescriptions(Request $request)
    {
        $request->validate(['site_id' => ['nullable', 'integer', 'min:1'], 'client_id' => ['nullable', 'integer', 'min:1'], 'prescriber_order_id' => ['nullable', 'integer', 'min:1']]);
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
        $readableIds = $this->personScopedClientIds($actor, $clients->pluck('id'));
        $clients = $clients->whereIn('id', $readableIds)->values();
        $clientFilter = $request->integer('client_id') ?: null;
        abort_if($clientFilter !== null && ! in_array($clientFilter, $readableIds, true), 404);
        $scopedIds = $clientFilter !== null ? [$clientFilter] : $readableIds;
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

        $medications = ClientMedication::active()->whereIn('client_id', $scopedIds)
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
        $orderQuery->whereIn('client_id', $scopedIds);
        if ($request->integer('prescriber_order_id')) {
            abort_unless((clone $orderQuery)->when(! $canViewControlled, fn ($q) => $q->visibleToOrdinaryReader())->whereKey($request->integer('prescriber_order_id'))->exists(), 404);
        }
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
        $covertQuery->whereIn('client_id', $scopedIds);
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

        // EA-053 (Lane C3): these are old prescriber-order records, read only.
        // New orders, changes and stops are entered and checked in Orders (P04).
        $orders = array_map(fn (array $order): array => [...$order, 'can_confirm' => false, 'can_countersign' => false,
            'can_dispense' => false, 'can_link' => false, 'can_cancel' => false], $orders);
        $canCreateManualOrders = false;

        return Inertia::render('emar/Prescriptions', [
            'orders' => $orders,
            'read_only' => true,
            'selected_prescriber_order_id' => $request->integer('prescriber_order_id') ?: null,
            'selected_prescriber_order' => collect($orders)->firstWhere('id', $request->integer('prescriber_order_id')),
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
    // ─── Medication Rounds ─────────────────────────────────
    public function rounds(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $date = app(MarScheduleService::class)->dateFromInput($request->input('date'))->toDateString();
        $siteFilter = $request->integer('site_id') ?: null;
        $canReadRounds = (bool) $user?->canDo('medications.view');
        $canRecordRounds = (bool) $user?->canDo('medications.administer.record');
        $includeControlledRounds = $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        abort_unless($canReadRounds || $canRecordRounds, 403);
        $accessibleSiteIds = $this->siteAccess()->accessibleSiteIds(
            $user,
            ['clinical.accessAllSites', 'sites.viewAll'],
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
        // Round templates live in Medication › Settings › Rounds & timing (P11).
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
                SecondPersonConfirmationPayload::RELATION,
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
                ...app(SecondPersonConfirmationPayload::class)->forAdministration($a),
                'blood_glucose_level' => $a->blood_glucose_level !== null ? (float) $a->blood_glucose_level : null,
                'pulse_bpm' => $a->pulse_bpm,
                'reason' => $a->reason,
                'reason_code' => $a->reason_code,
                'scheduled_for' => $a->scheduled_for?->toIso8601String(),
                'administered_at' => $a->administered_at?->toIso8601String(),
                // EA-115 (Lane C7): the dose time in NZ, as the detail shows it.
                'time' => $a->administered_at ? \App\Support\WorkerClock::display($a->administered_at, 'H:i') : null,
            ])
            ->all();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $canManageRounds = (bool) $user?->canDo('medications.orders.manage');

        return Inertia::render('emar/Rounds', [
            'rounds' => $rounds,
            'date' => $date,
            'now_label' => now()->setTimezone(config('app.worker_timezone', config('app.timezone')))->format('g:i a'),
            'selected_site_id' => $siteFilter,
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
            // Round templates are read and changed in Settings › Rounds & timing (P11).
            'can_read_templates' => $canReadRounds,
            'can_export' => (bool) ($user?->canDo('medications.reports.view') && $user?->canDo('medications.reports.export')),
        ]);
    }
    // ─── Destruction Records ───────────────────────────────
    public function destructions(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('medications.view'), 403);
        $filters = $request->validate([
            'site_id' => ['nullable', 'integer', 'min:1'], 'client_id' => ['nullable', 'integer', 'min:1'],
            'client_medication_id' => ['nullable', 'integer', 'min:1'], 'date' => ['nullable', 'date_format:Y-m-d'], 'destruction_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $siteFilter = isset($filters['site_id']) ? (int) $filters['site_id'] : null;
        $clientFilter = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $medicineFilter = isset($filters['client_medication_id']) ? (int) $filters['client_medication_id'] : null;
        $controlledRead = $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        abort_unless($controlledRead || $actor->canDo(MedicationGovernanceScopeService::STOCK_CAPABILITY), 403);
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            $controlledRead ? MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY : MedicationGovernanceScopeService::STOCK_CAPABILITY,
            $siteFilter,
            $clientFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $openableClientIds = $this->personScopedClientIds(
            $actor,
            Client::query()->whereIn('site_id', $readerSiteIds)->pluck('id'),
        );
        abort_if($clientFilter !== null && ! in_array($clientFilter, $openableClientIds, true), 404);
        if ($medicineFilter !== null) {
            $medicine = ClientMedication::withTrashed()->whereKey($medicineFilter)
                ->whereIn('client_id', $openableClientIds)
                ->when($clientFilter !== null, fn ($q) => $q->where('client_id', $clientFilter))->firstOrFail();
            abort_if(! $controlledRead && $medicine->controlled_drug, 404);
        }
        $dayStart = isset($filters['date']) ? CarbonImmutable::parse($filters['date'], 'Pacific/Auckland')->startOfDay() : null;

        // Flat, client-side-filterable disposal register. Voided records remain
        // in the list (struck through) — the register is immutable (MoD Regs 1977).
        $destructionQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationDestruction::query(),
            $readerSiteIds,
        )
            ->whereIn('client_id', $openableClientIds)
            ->when($clientFilter, fn ($query) => $query->where('client_id', $clientFilter))
            ->when($medicineFilter !== null, fn ($query) => $query->where('client_medication_id', $medicineFilter))
            ->when($dayStart !== null, fn ($query) => $query->where('destroyed_at', '>=', $dayStart->utc())->where('destroyed_at', '<', $dayStart->addDay()->utc()))
            ->when(! $controlledRead, fn ($query) => $query->where('is_controlled_drug', false)
                ->whereDoesntHave('medication', fn ($med) => $med->withTrashed()->where('controlled_drug', true)))
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'destroyedByUser:id,name',
                'witness1:id,name',
                'witness2:id,name',
                'voidedByUser:id,name',
            ])
            ->where(fn ($q) => $q->whereNull('medication_destructions.site_id')->orWhereHas('client', fn ($c) => $c->whereColumn('clients.site_id', 'medication_destructions.site_id')));
        $selectedDestruction = $request->integer('destruction_id') ? (clone $destructionQuery)->findOrFail($request->integer('destruction_id')) : null;
        $destructions = (clone $destructionQuery)->latest('destroyed_at')->limit(300)->get();
        if ($selectedDestruction !== null) {
            $destructions = $destructions->push($selectedDestruction)->unique('id')->sortByDesc('destroyed_at')->values();
        }

        // Every active medication is destroyable — same CdMedication shape the
        // shared RecordDestructionDialog consumes on the Controlled Drugs page.
        $medications = ClientMedication::query()
            ->active()
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $readerSiteIds))
            ->whereIn('client_id', $openableClientIds)
            ->when($clientFilter, fn ($query) => $query->where('client_id', $clientFilter))
            ->when($medicineFilter !== null, fn ($query) => $query->whereKey($medicineFilter))
            ->when(! $controlledRead, fn ($query) => $query->where('controlled_drug', false))
            ->with(['client:id,first_name,last_name', 'stock'])
            ->orderBy('name')
            ->get();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter ? $sites->firstWhere('id', $siteFilter) : null;

        return Inertia::render('emar/Destructions', [
            'filters' => ['site_id' => $siteFilter, 'client_id' => $clientFilter, 'client_medication_id' => $medicineFilter, 'date' => $filters['date'] ?? null],
            'selected_destruction_id' => $selectedDestruction?->id,
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
                'mar_url' => $this->marUrlFor($d->client_id),
            ])->values(),
            'medications' => $medications->map(fn (ClientMedication $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'controlled_drug' => (bool) $m->controlled_drug,
                'client_id' => $m->client_id,
                'client_name' => $m->client ? trim($m->client->first_name.' '.$m->client->last_name) : 'Unknown',
                'stock' => $m->stock ? [
                    'on_hand' => $m->stock->availableQuantity(),
                    'pack_workflow_url' => $m->stock->pack_workflow_url,
                    'unit' => $m->stock->unit,
                ] : null,
            ])->values(),
            'staff' => $actor->canDo('medications.controlled.record') ? $this->governanceScope->controlledWitnessPicker($accessibleSiteIds, $actor->id) : [],
            'clients' => Client::query()->whereIn('id', $openableClientIds)->get(['id', 'first_name', 'last_name', 'site_id'])
                ->map(fn ($client) => ['id' => $client->id, 'name' => $client->full_name, 'site_id' => $client->site_id])->values(),
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
    // ─── Covert Authorisations CRUD ─────────────────────────

    public function storeCovert(Request $request)
    {
        return app(MedicationLegacyOrderBridge::class)->covert($request);
    }

    public function revokeCovert(Request $request, MedicationCovertAuthorisation $authorisation)
    {
        return app(MedicationLegacyOrderBridge::class)->revokeCovert($request, $authorisation);
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

        return $this->governanceScope->forClient($actor, (int) $client->id, 'medications.orders.manage', function (Client $lockedClient, User $lockedActor) use ($request) {
            $this->recordAccess()->assertReadable($lockedActor, $lockedClient);
            $reviewMessage = 'Use Medication reviews to change the interval, or book or move a review.';
            $validated = $request->validate([
                'care_level' => ['sometimes', 'nullable', 'string', 'max:60'],
                // Cadence decisions and booked dates belong to the Reviews workflow.
                'chart_review_interval_months' => ['missing'],
                'medication_review_interval_months' => ['missing'],
                'next_chart_review_date' => ['missing'],
            ], [
                'chart_review_interval_months.missing' => $reviewMessage,
                'medication_review_interval_months.missing' => $reviewMessage,
                'next_chart_review_date.missing' => $reviewMessage,
            ]);

            if (array_key_exists('care_level', $validated)) {
                $lockedClient->forceFill(['care_level' => $validated['care_level']])->save();
                app(MedicationAlertService::class)->generateClientAlerts($lockedClient->fresh());
            }

            return redirect()->back()->with('success', 'Care level settings updated.');
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
        // Same per-person gate as the MAR chart it redirects to, so the redirect
        // is never an existence oracle for a resident this user may not open.
        abort_unless(
            Gate::forUser($actor)->allows('viewMedications', $client),
            404,
            'The requested medication record was not found.',
        );

        return redirect()->route('emar.mar', ['client_id' => $client->id]);
    }

    public function storeInr(Request $request, Client $client)
    {
        $response = app(PersonMedicationClinicalController::class)->store($request, (int) $client->id, 'inr');

        return $request->expectsJson() || $request->header('X-Inertia')
            ? $response
            : redirect()->back()->with('success', 'INR result recorded.');
    }

    public function disableInr(Request $request, ClientInrRecord $inr)
    {
        $request->merge(['record_id' => (int) $inr->id]);
        $response = app(PersonMedicationClinicalController::class)->store($request, (int) $inr->client_id, 'disable-inr');

        return $request->expectsJson() || $request->header('X-Inertia')
            ? $response
            : redirect()->back()->with('success', 'INR result marked as entered in error.');
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
                ?? $this->parseSyringeDriverCommencedAt((string) $validated['commenced_at']);
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
                'witness_method' => $witness ? WitnessPinService::METHOD : null,
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

        return $this->governanceScope->forClientRecord($actor, $driver, 'medications.administer.record', function (Client $client, MedicationSyringeDriver $lockedDriver) use ($request, $actor) {
            app(MedicationRecordAccess::class)->assertReadable($actor, $client);
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

    /**
     * The end date an assessment is saved with: the one asked for, which must
     * be no later than the validity setting allows, or that latest date.
     */
    private function competencyExpiry(CompetencyPolicySettings $policy, string $assessmentDate, ?string $requested): string
    {
        $latest = $policy->latestExpiry($assessmentDate);
        if ($requested === null || $requested === '') {
            return $latest->toDateString();
        }
        if (Carbon::parse($requested)->startOfDay()->gt($latest)) {
            throw ValidationException::withMessages([
                'expiry_date' => sprintf(
                    'An assessment stays current for at most %d %s — choose %s or earlier.',
                    $policy->validityMonths(),
                    $policy->validityMonths() === 1 ? 'month' : 'months',
                    $latest->format('j M Y'),
                ),
            ]);
        }

        return Carbon::parse($requested)->toDateString();
    }

    /**
     * P11 Q-C: when the organisation asks for a minimum of observed
     * administrations, an assessment logging fewer isn't saved.
     *
     * @param  array<int, mixed>|null  $observedRounds
     */
    private function assertObservedMinimum(CompetencyPolicySettings $policy, ?array $observedRounds): void
    {
        $minimum = $policy->observedMinimum();
        if ($minimum === null) {
            return;
        }
        $logged = collect($observedRounds ?? [])
            ->filter(fn (mixed $round): bool => is_array($round) && trim((string) ($round['resident'] ?? '')) !== '')
            ->count();
        if ($logged < $minimum) {
            throw ValidationException::withMessages([
                'observed_rounds' => sprintf(
                    'Log at least %d observed %s — your organisation asks for %d (Settings › Staff & PINs). %d logged.',
                    $minimum,
                    $minimum === 1 ? 'administration' : 'administrations',
                    $minimum,
                    $logged,
                ),
            ]);
        }
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
        // P11 Staff & PINs: the pass mark and how long an assessment stays
        // current are organisation settings.
        $policy = app(CompetencyPolicySettings::class);

        $this->assertObservedMinimum($policy, $validated['observed_rounds'] ?? null);

        $validated['total_score'] = $totalScore;
        $validated['pass_threshold'] = $policy->passMark();
        $validated['status'] = $policy->passes(Arr::only($validated, $booleanFields)) ? 'passed' : 'failed';
        $validated['restricted'] = (bool) ($validated['restricted'] ?? false);
        $validated['assessor_id'] = $actor->id;
        // The assessor may record only their own declaration here. The staff
        // acknowledgement is a separate authenticated action by the subject.
        $validated['assessor_declared_at'] = ! empty($validated['assessor_declared']) ? now() : null;
        $validated['staff_acknowledged_at'] = null;
        unset($validated['assessor_declared'], $validated['staff_acknowledged']);
        $validated['expiry_date'] = $this->competencyExpiry(
            $policy,
            (string) $validated['assessment_date'],
            $validated['expiry_date'] ?? null,
        );
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

            $policy = app(CompetencyPolicySettings::class);
            if (array_key_exists('observed_rounds', $validated)) {
                $this->assertObservedMinimum($policy, $validated['observed_rounds']);
            }
            if (collect($booleanFields)->contains(fn ($field) => array_key_exists($field, $validated))) {
                $merged = array_merge($locked->only($booleanFields), $validated);
                $totalScore = collect($booleanFields)->filter(fn ($field) => ! empty($merged[$field]))->count();
                $validated['total_score'] = $totalScore;
                $validated['pass_threshold'] = $policy->passMark();
                $validated['status'] = $policy->passes(Arr::only($merged, $booleanFields)) ? 'passed' : 'failed';
            }

            // A changed date or end date stays within how long an assessment
            // stays current; an untouched one keeps the end date it was given.
            if (! empty($validated['assessment_date']) || array_key_exists('expiry_date', $validated)) {
                $validated['expiry_date'] = $this->competencyExpiry(
                    $policy,
                    (string) ($validated['assessment_date'] ?? $locked->assessment_date?->toDateString()),
                    array_key_exists('expiry_date', $validated)
                        ? $validated['expiry_date']
                        : (empty($validated['assessment_date']) ? $locked->expiry_date?->toDateString() : null),
                );
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
                AuditLogger::logOrFail('medications.competency.acknowledged', $locked, [
                    'actor_id' => (int) $actor->id,
                    'assessment_id' => (int) $locked->id,
                    'assessor_id' => (int) $locked->assessor_id,
                ]);
            }

            return redirect()->back()->with('success', 'Assessment acknowledged. You can record doses as given until '
                .($locked->expiry_date?->format('j M Y') ?? 'its end date').'.');
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
            // P11: a template can be saved as paused.
            'active' => 'sometimes|boolean',
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

                $validated['active'] = $validated['active'] ?? true;
                $validated['site_id'] = $canonicalSiteId;
                $template = MedicationRoundTemplate::query()->create($validated);
                $this->recordRoundTemplateChange($lockedActor, $template, 'created', 'Round template added', '—', [
                    'status_after' => $template->active ? 'active' : 'paused',
                ]);

                return redirect()->back()->with('medication_settings_saved', 'Template added: '.$template->name.'. '.(
                    $template->active
                        ? 'Rounds are created from it from tomorrow (12:05 am).'
                        : 'Saved as paused — no rounds are created until someone turns it on.'
                ));
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
            $catalogue = app(RoundTemplateCatalogue::class);
            $lockedTemplate->loadMissing('defaultAssignedTo:id,name');
            $before = $catalogue->describe($lockedTemplate);
            $wasActive = (bool) $lockedTemplate->active;
            $lockedTemplate->fill($validated);
            // "08:00" and the stored "08:00:00" are the same time.
            $changed = array_values(array_filter(
                array_diff(array_keys($lockedTemplate->getDirty()), ['updated_at']),
                fn (string $field): bool => $field !== 'scheduled_time'
                    || substr((string) $lockedTemplate->getRawOriginal('scheduled_time'), 0, 5) !== substr((string) $lockedTemplate->scheduled_time, 0, 5),
            ));
            $lockedTemplate->save();
            if ($changed === []) {
                return redirect()->back()->with('medication_settings_saved', 'Nothing was saved — the template already had those values.');
            }
            $lockedTemplate->load('defaultAssignedTo:id,name');

            // Pausing or turning a template back on is its own event; any other
            // edit is "changed". Pausing stops rounds being created: a loosening.
            if ($changed === ['active']) {
                $paused = ! $lockedTemplate->active;
                $this->recordRoundTemplateChange(
                    $lockedActor,
                    $lockedTemplate,
                    $paused ? 'paused' : 'resumed',
                    $paused ? 'Round template paused' : 'Round template turned back on',
                    $wasActive ? 'Active' : 'Paused',
                    ['status_before' => $wasActive ? 'active' : 'paused', 'status_after' => $paused ? 'paused' : 'active'],
                    afterText: $paused ? 'Paused' : 'Active',
                    loosens: $paused,
                );

                return redirect()->back()->with('medication_settings_saved', $paused
                    ? 'Template paused. No rounds from tomorrow.'
                    : 'Template turned back on. Rounds are created from tomorrow.');
            }
            $this->recordRoundTemplateChange(
                $lockedActor,
                $lockedTemplate,
                'updated',
                'Round template changed',
                $before,
                ['changed' => $changed, 'status_before' => $wasActive ? 'active' : 'paused', 'status_after' => $lockedTemplate->active ? 'active' : 'paused'],
                loosens: $wasActive && ! $lockedTemplate->active,
            );

            return redirect()->back()->with('medication_settings_saved', 'Template updated: '.$lockedTemplate->name.'. Rounds created from tomorrow use the new details; today’s rounds keep theirs.');
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
                app(MedicationSettingsStore::class)->recordTemplateChange(
                    $lockedActor,
                    $lockedTemplate,
                    'Round template retired — '.$lockedTemplate->name,
                    $statusBefore === 'active' ? 'Active' : 'Paused',
                    'Retired',
                    true,
                    'medications.round_template.retired',
                );
                AuditLogger::logOrFail('medications.round_template.retired', $lockedTemplate, [
                    'actor_id' => (int) $lockedActor->id,
                    'site_id' => $lockedTemplate->site_id !== null ? (int) $lockedTemplate->site_id : null,
                    'round_template_id' => (int) $lockedTemplate->id,
                    'status_before' => $statusBefore,
                    'status_after' => 'retired',
                    'retired_at' => $lockedTemplate->retired_at?->toIso8601String(),
                ]);
            }

            return redirect()->back()->with('medication_settings_saved', 'Template retired. No new rounds are created from it; past rounds keep it on their record.');
        });
    }

    /**
     * eMAR P11: every round-template change is in Settings › Change history
     * (at the template's house) and in the audit log.
     *
     * @param  array<string, mixed>  $meta
     */
    private function recordRoundTemplateChange(
        User $actor,
        MedicationRoundTemplate $template,
        string $event,
        string $what,
        string $before,
        array $meta = [],
        ?string $afterText = null,
        bool $loosens = false,
    ): void {
        $template->loadMissing('defaultAssignedTo:id,name');
        $auditEvent = 'medications.round_template.'.$event;
        app(MedicationSettingsStore::class)->recordTemplateChange(
            $actor,
            $template,
            $what.' — '.$template->name,
            $before,
            $afterText ?? app(RoundTemplateCatalogue::class)->describe($template),
            $loosens,
            $auditEvent,
        );
        AuditLogger::logOrFail($auditEvent, $template, [
            'actor_id' => (int) $actor->id,
            'site_id' => $template->site_id !== null ? (int) $template->site_id : null,
            'round_template_id' => (int) $template->id,
            ...$meta,
        ]);
    }

    /**
     * "Create rounds for a day" preview (eMAR P11): which rounds the same
     * generation would create and which already exist. Writes nothing.
     */
    public function previewRounds(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $allowedSiteIds = $this->governanceScope->mutationSiteIds($actor, 'medications.orders.manage');
        $validated = $request->validate([
            'date' => 'required|date',
            'generate_all' => 'nullable|boolean',
            'site_id' => 'nullable|integer|min:1',
        ]);
        $siteIds = $this->roundGenerationSiteIds($validated, $allowedSiteIds);
        $date = Carbon::parse($validated['date']);
        $generateAll = (bool) ($validated['generate_all'] ?? false);

        $templates = MedicationRoundTemplate::query()
            ->active()
            ->whereIn('site_id', $siteIds)
            ->orderBy('scheduled_time')
            ->orderBy('id')
            ->get(['id', 'name', 'scheduled_time']);
        $rows = $templates->map(function (MedicationRoundTemplate $template) use ($date, $generateAll, $allowedSiteIds, $actor): array {
            $result = $this->roundGeneration->generate((int) $template->id, $date, $generateAll, $allowedSiteIds, $actor, dryRun: true);

            return [
                'template_id' => (int) $template->id,
                'name' => (string) $template->name,
                'scheduled_time' => substr((string) $template->scheduled_time, 0, 5),
                'status' => match ($result['status']) {
                    MedicationRoundGenerationService::STATUS_CREATED => 'new',
                    MedicationRoundGenerationService::STATUS_ALREADY_EXISTS => 'exists',
                    default => 'skipped',
                },
                'reason' => $result['reason'],
            ];
        })->values();

        return response()->json([
            'rounds' => $rows->where('status', '!=', 'skipped')->values()->all(),
            'create' => $rows->where('status', 'new')->count(),
            'exists' => $rows->where('status', 'exists')->count(),
        ]);
    }

    /**
     * The houses a round generation covers: one house, when asked for and
     * allowed, otherwise every house the person may manage.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<int>  $allowedSiteIds
     * @return list<int>
     */
    private function roundGenerationSiteIds(array $validated, array $allowedSiteIds): array
    {
        if (! isset($validated['site_id'])) {
            return $allowedSiteIds;
        }
        $siteId = (int) $validated['site_id'];
        abort_unless(in_array($siteId, $allowedSiteIds, true), 404);

        return [$siteId];
    }

    public function generateRounds(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $allowedSiteIds = $this->governanceScope->mutationSiteIds($actor, 'medications.orders.manage');
        $validated = $request->validate([
            'date' => 'required|date',
            'generate_all' => 'nullable|boolean',
            'site_id' => 'nullable|integer|min:1',
        ]);

        $date = Carbon::parse($validated['date']);
        $generateAll = (bool) ($validated['generate_all'] ?? false);
        $templateIds = MedicationRoundTemplate::query()
            ->active()
            ->whereIn('site_id', $this->roundGenerationSiteIds($validated, $allowedSiteIds))
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

        $message = sprintf(
            '%d %s created (%d already existed and %d skipped).',
            $summary['created'],
            $summary['created'] === 1 ? 'round' : 'rounds',
            $summary['already_exists'],
            $summary['skipped'],
        );

        return redirect()->back()
            ->with('round_generation', $summary)
            ->with('medication_settings_saved', $message)
            ->with('success', $message);
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
    // ─── Destructions CRUD ──────────────────────────────────

    public function storeDestruction(Request $request, bool $ordinaryOnly = false)
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
            'pack_lines' => ['nullable', 'array', 'max:100'],
            'pack_lines.*.lot_id' => ['required', 'integer', 'min:1'],
            'pack_lines.*.revision' => ['required', 'integer', 'min:0'],
            'pack_lines.*.quantity' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE],
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
        ) use ($request, $rules, $actor, $witnessEffectiveAt, $ordinaryOnly) {
            $actor = $lockedActor;
            // The compatibility dispatch cannot admit an order whose classification changed under lock.
            abort_if($ordinaryOnly && $medication->controlled_drug, 404);
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
                $payload['pack_lines'],
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

            $packTracked = $stock->lots_started_at !== null;
            $before = $packTracked ? app(MedicationStockService::class)->physicalQuantity($stock) : MedicationStockQuantity::normalize($stock->on_hand ?? 0);
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

            if ($packTracked) {
                abort_if($medication->controlled_drug, 422, 'Use the canonical witnessed controlled pack destruction.');
                app(MedicationStockService::class)->removeForDestruction($stock, $actor, $destruction,
                    $validated['pack_lines'] ?? [], $validated['client_request_uuid'] ?? (string) Str::uuid());
                $after = $stock->availableQuantity();
            } else {
                $stock->on_hand = $after;
                $stock->last_counted_at = now();
                $stock->save();
            }

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
                    ? (! empty($payload['is_controlled_drug']) ? WitnessPinService::METHOD : 'site_staff_record')
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
            'snapshot' => [
                ...app(ShiftMedicationSnapshotService::class)->forShift(
                    $shift,
                    $auth->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                ),
                ...app(MedicationFollowupProjection::class)->forClient($auth, (int) $shift->client_id),
            ],
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
                app(PharmacyDispatchGuard::class)->assertContentEditable($lockedOrder, $payload);
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
                    // A delivery records what actually arrived — never a silent
                    // copy of the ordered amount.
                    'quantity_received' => [
                        Rule::requiredIf($lockedOrder->status === 'dispensed'),
                        'nullable',
                        'numeric',
                        MedicationStockQuantity::VALIDATION_RULE,
                        'min:0',
                        MedicationStockQuantity::DECIMAL_12_2_MAX_RULE,
                    ],
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

                // New pack orders require the explicit communication/dispense/receipt workflow.
                // Replay above still returns evidence from a previously completed legacy request.
                app(PharmacyDispatchGuard::class)->assertManualAdvance($lockedOrder);
                $packStock = ClientMedicationStock::where('client_medication_id', $medication->id)->lockForUpdate()->first();
                $packStock?->rejectScalarWrite('status');
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
                        // Record a batch/expiry when given; never blank one the
                        // order already carries.
                        if (filled($validated['batch_number'] ?? null)) {
                            $updateData['batch_number'] = $validated['batch_number'];
                        }
                        if (filled($validated['batch_expiry'] ?? null)) {
                            $updateData['batch_expiry'] = $validated['batch_expiry'];
                        }
                        break;
                    case 'delivered':
                        if ((bool) $medication->controlled_drug) {
                            throw ValidationException::withMessages([
                                'client_medication_id' => 'Controlled drug deliveries must be recorded through the controlled-drug register with a second witness.',
                            ]);
                        }
                        $updateData['delivered_at'] = now();
                        $updateData['received_by'] = $actor->id;
                        $updateData['quantity_received'] = MedicationStockQuantity::normalize(
                            $validated['quantity_received'],
                        );
                        $updateData['delivery_notes'] = $validated['delivery_notes'] ?? null;
                        // The receiver may record the batch/expiry on the label.
                        if (filled($validated['batch_number'] ?? null)) {
                            $updateData['batch_number'] = $validated['batch_number'];
                        }
                        if (filled($validated['batch_expiry'] ?? null)) {
                            $updateData['batch_expiry'] = $validated['batch_expiry'];
                        }
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

                    $this->mergeDeliveredBatch(
                        $stock,
                        $lockedOrder->batch_number,
                        $lockedOrder->batch_expiry,
                        $onHandBefore,
                    );
                    $stock->fill([
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
                    'packs' => ['nullable', 'array', 'min:1', 'max:25'],
                    'packs.*.quantity' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE],
                    'packs.*.batch_number' => ['nullable', 'string', 'max:100'],
                    'packs.*.batch_not_printed' => ['required', 'boolean'],
                    'packs.*.expiry_month' => ['nullable', 'string', 'max:7'],
                    'packs.*.expiry_not_printed' => ['required', 'boolean'],
                    'packs.*.short_expiry_reason' => ['nullable', 'string', 'max:2000'],
                    'delivery_outcome' => ['nullable', 'in:still_to_come,closed_short'],
                    'closure_reason' => ['nullable', 'string', 'max:2000'],
                    'quantity_received' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE],
                    'on_hand_before' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
                    'on_hand_after' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
                    'witnessed_by' => 'required|integer|min:1',
                    'witness_credential' => 'nullable|string|max:255',
                    'delivery_notes' => 'nullable|string|max:2000',
                    ...$this->medicationOnlineOnlySubmissionRules(),
                ]);
                // EA-073: a held medicine's delivery is still received into the register.
                abort_unless(MedicationStockService::receivable($medication), 404, 'The requested medication record was not found.');
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
                    'packs' => $validated['packs'] ?? null,
                    'delivery_outcome' => $validated['delivery_outcome'] ?? null,
                    'closure_reason' => $validated['closure_reason'] ?? null,
                    'on_hand_before' => $onHandBefore,
                    'on_hand_after' => $onHandAfter,
                    'witnessed_by' => (int) $validated['witnessed_by'],
                    'delivery_notes' => $validated['delivery_notes'] ?? null,
                    'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                    'origin_device_id' => $validated['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
                ], JSON_THROW_ON_ERROR));

                // An exact replay is still a controlled-drug action: recheck
                // the recorder's presence and the current in-person witness
                // authority and credential before returning the durable result.
                app(ControlledRegisterService::class)->assertPresent($lockedActor, (int) $client->site_id, true);
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

                // EA-092 (Lane C2): one receipt writer. The canonical P07 register
                // receipt writes the entry and balance (with its own audit), and
                // this delivery's packs or batch attach inside the same lock.
                $entry = app(ControlledRegisterService::class)->recordReceipt(
                    $lockedActor, $client, $medication, $stock,
                    [
                        'witnessed_by' => (int) $validated['witnessed_by'], 'witness_credential' => $validated['witness_credential'] ?? null,
                        'quantity' => $quantityReceived, 'pharmacy_order_id' => $lockedOrder->id,
                        'batch_number' => $lockedOrder->batch_number, 'expiry_date' => $lockedOrder->batch_expiry,
                        'delivery_notes' => $validated['delivery_notes'] ?? null,
                    ],
                    $lockedUsers,
                    function (ClientControlledDrugEntry $entry) use ($stock, $lockedActor, $validated, $lockedOrder, $authoritativeBefore, $quantityReceived, $actor): void {
                        if ($stock->lots_started_at !== null) {
                            $stock->forceFill(['supplier_name' => $lockedOrder->pharmacy_name])->saveFromPackLedger();
                            app(MedicationStockService::class)->controlledDeliveryAfterRegister($stock, $lockedActor,
                                [...$validated, 'request_uuid' => $validated['client_request_uuid'], 'packs' => $validated['packs'] ?? [],
                                    'source' => 'pharmacy', 'source_reference' => 'Pharmacy order '.$lockedOrder->id], $entry, $lockedOrder);
                        } else {
                            $this->mergeDeliveredBatch($stock, $lockedOrder->batch_number, $lockedOrder->batch_expiry, $authoritativeBefore);
                            $stock->forceFill(['supplier_name' => $lockedOrder->pharmacy_name, 'last_counted_at' => now()])->save();
                            $lockedOrder->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'received_by' => $actor->id,
                                'quantity_received' => $quantityReceived, 'delivery_notes' => $validated['delivery_notes'] ?? null])->save();
                        }
                    },
                );
                app(\App\Services\Medication\Audit\MedicationEventRecorder::class)->append(new \App\Services\Medication\Audit\MedicationEventData(
                    siteId: (int) $client->site_id, kind: 'stock.receive', subjectType: 'stock', subjectId: (string) $medication->id,
                    actorId: (int) $lockedActor->id, occurredAt: CarbonImmutable::now('UTC'), summary: 'Stock received',
                    facts: ['request_uuid' => $validated['client_request_uuid'], 'client_medication_id' => $medication->id,
                        'entry_id' => $entry->id, 'pharmacy_order_id' => $lockedOrder->id, 'quantity_received' => $quantityReceived],
                    clientId: (int) $client->id, controlled: true,
                ));
                AuditLogger::logOrFail('medications.controlled.pharmacy_delivery.receive', $entry, [
                    'actor_id' => $actor->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'pharmacy_order_id' => $lockedOrder->id,
                    'stock_id' => $stock->id,
                    'quantity_received' => $quantityReceived,
                    'packs' => $validated['packs'] ?? null,
                    'delivery_outcome' => $validated['delivery_outcome'] ?? null,
                    'closure_reason' => $validated['closure_reason'] ?? null,
                    'on_hand_before' => $authoritativeBefore,
                    'on_hand_after' => $expectedAfter,
                    'witnessed_by' => $witness->id,
                    'witness_method' => WitnessPinService::METHOD,
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

                $stock->rejectScalarWrite('quantity');
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
                // A controlled drug's batch and expiry are register facts,
                // recorded with a witness through the controlled-drug
                // register — not edited here. Unchanged values (the edit
                // dialog resends them) pass.
                if ((bool) $medication->controlled_drug) {
                    $currentExpiry = $lockedStock->expiry_date?->toDateString();
                    $submittedExpiry = filled($validated['expiry_date'] ?? null)
                        ? Carbon::parse($validated['expiry_date'])->toDateString()
                        : null;
                    $currentBatch = filled($lockedStock->batch_number) ? trim((string) $lockedStock->batch_number) : null;
                    $submittedBatch = filled($validated['batch_number'] ?? null) ? trim((string) $validated['batch_number']) : null;
                    $message = 'A controlled drug\'s batch and expiry are recorded through the controlled-drug register, with a witness.';
                    $errors = array_filter([
                        'batch_number' => array_key_exists('batch_number', $validated) && $submittedBatch !== $currentBatch ? $message : null,
                        'expiry_date' => array_key_exists('expiry_date', $validated) && $submittedExpiry !== $currentExpiry ? $message : null,
                    ]);
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                }
                if ($lockedStock->lots_started_at !== null && ! $medication->controlled_drug) {
                    $submitted = array_intersect_key($validated, array_flip(['batch_number', 'expiry_date']));
                    $changed = (array_key_exists('batch_number', $submitted) && ($submitted['batch_number'] ?: null) !== $lockedStock->batch_number)
                        || (array_key_exists('expiry_date', $submitted) && ($submitted['expiry_date'] ?: null) !== $lockedStock->expiry_date?->toDateString());
                    if ($changed) {
                        $lockedStock->rejectScalarWrite('batch_number');
                    }
                    // Aggregate batch metadata is historic evidence; pack labels are recorded at receipt.
                    $validated = array_diff_key($validated, array_flip(['batch_number', 'expiry_date']));
                }
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
                $stock->rejectScalarWrite('new_quantity');
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
        return app(LegacyEffectFollowupAdapter::class)->save($request, $this->medicationScope);
    }
    // ─── Medications CRUD ─────────────────────────────────

    public function storeMedication(Request $request)
    {
        return app(MedicationLegacyOrderBridge::class)->enter($request);
    }

    public function updateMedication(Request $request, ClientMedication $medication)
    {
        return app(MedicationLegacyOrderBridge::class)->enter($request, $medication);
    }

    public function verifyMedication(Request $request, ClientMedication $medication)
    {
        return app(MedicationLegacyOrderBridge::class)->check($request, $medication);
    }
    public function rejectMedication(Request $request, ClientMedication $medication)
    {
        return app(MedicationLegacyOrderBridge::class)->check($request, $medication, sendBack: true);
    }

    public function discontinueMedication(Request $request, ClientMedication $medication)
    {
        return app(MedicationOrdersController::class)->stop($request, $medication->id);
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
                $this->recordAccess()->assertReadable($actor, $client);
                abort_if(($lockedDestruction->is_controlled_drug || $medication?->controlled_drug)
                    && ! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY), 404);
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
}
