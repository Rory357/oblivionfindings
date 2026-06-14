<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientDocument;
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
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSyringeDriver;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DoseSchedulingService;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\MarScheduleService;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MedicationOverviewService;
use App\Services\MedicationRuleService;
use App\Services\MedicationScanVerificationService;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
    ) {}

    // ─── Helpers ──────────────────────────────────────────

    private function getStaffList()
    {
        return User::staff()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->filter(fn (User $user) => $user->canDo('medications.controlled.witness'))
            ->values()
            ->map(fn (User $user) => $user->only(['id', 'name']));
    }

    private function getClientsList()
    {
        return Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);
    }

    private function buildMedicationPermissions(?User $user): array
    {
        return [
            'record' => (bool) $user && ($user->canDo('medications.administer.record') || $user->canDo('clients.update')),
            'correct' => (bool) $user && ($user->canDo('medications.administer.correct') || $user->canDo('clients.update')),
            'verify_orders' => $this->canVerifyMedicationOrders($user),
            'manage_settings' => (bool) $user && ($user->canDo('medications.settings.manage') || $user->canDo('clients.update')),
            'manage_inr' => (bool) $user && ($user->canDo('medications.orders.manage') || $user->canDo('clients.update')),
            'manage_syringe_drivers' => (bool) $user && ($user->canDo('medications.orders.manage') || $user->canDo('medications.administer.record') || $user->canDo('clients.update')),
            'manage_allergies' => (bool) $user && $user->canDo('clients.update'),
            'manage_interactions' => (bool) $user && ($user->canDo('medications.administer.correct') || $user->canDo('clients.update')),
            'manage_stock' => (bool) $user && ($user->canDo('medications.stock.update') || $user->canDo('clients.update')),
            'view_controlled' => (bool) $user && ($user->canDo('medications.controlled.view') || $user->canDo('clients.update')),
            'revoke_break_glass' => (bool) $user && ($user->canDo('medications.breakglass') || $user->canDo('medications.audit.view')),
            'export_reports' => (bool) $user && ($user->canDo('medications.reports.export') || $user->canDo('reports.viewAny')),
        ];
    }

    private function canVerifyMedicationOrders(?User $user): bool
    {
        return (bool) $user && (
            $user->canDo('medications.orders.verify')
            || $user->canDo('medications.orders.manage')
            || $user->canDo('clients.update')
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

    private function getPendingCorrections(Client $client): array
    {
        return ClientMedicationAdministration::query()
            ->with([
                'medication:id,name,dosage',
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
                'medication_name' => $administration->medication?->name ?? 'Unknown',
                'status' => $administration->status,
                'dose_given' => $administration->dose_given,
                'reason' => $administration->reason,
                'notes' => $administration->notes,
                'correction_reason' => $administration->correction_reason,
                'submitted_by' => $administration->administeredBy?->name,
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
                        'can_delete' => (bool) request()->user() && (
                            request()->user()->canDo('medications.administer.correct')
                            || request()->user()->canDo('clients.update')
                            || (int) $attachment->uploaded_by === (int) request()->user()->id
                        ),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function getClientAlerts(Client $client): array
    {
        return MedicationDashboardAlert::query()
            ->where('client_id', $client->id)
            ->where('status', 'active')
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

    private function getClientMedicationAttentionAlerts(Client $client): array
    {
        return $client->medicationAlerts()
            ->enabled()
            ->unresolved()
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

    private function getClientInrRecords(Client $client): array
    {
        return $client->inrRecords()
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

    private function getRunningSyringeDrivers(Client $client): array
    {
        return $client->syringeDrivers()
            ->running()
            ->with(['checks' => fn ($query) => $query->latest('checked_at')->limit(5)])
            ->latest('commenced_at')
            ->get()
            ->map(fn (MedicationSyringeDriver $driver) => [
                'id' => $driver->id,
                'status' => $driver->status,
                'commenced_at' => $driver->commenced_at?->toIso8601String(),
                'rate' => $driver->rate,
                'rate_unit' => $driver->rate_unit,
                'duration_hours' => $driver->duration_hours,
                'contents' => $driver->contents ?? [],
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
            ])
            ->values()
            ->all();
    }

    private function getOpenControlledDiscrepancies(Client $client): array
    {
        return $client->controlledDrugDiscrepancies()
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
                        'can_delete' => (bool) request()->user() && (
                            request()->user()->canDo('medications.administer.correct')
                            || request()->user()->canDo('clients.update')
                            || (int) $attachment->uploaded_by === (int) request()->user()->id
                        ),
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
            'can_delete' => (bool) request()->user() && (
                request()->user()->canDo('medications.administer.correct')
                || request()->user()->canDo('medications.controlled.record')
                || request()->user()->canDo('clients.update')
                || (int) $attachment->uploaded_by === (int) request()->user()->id
            ),
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

    private function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @return array<int, array<string, mixed>>
     */
    private function normaliseSyringeDriverContents(Client $client, array $contents): array
    {
        return collect($contents)
            ->map(function (array $item) use ($client) {
                $medication = null;
                if (! empty($item['client_medication_id'])) {
                    $medication = ClientMedication::query()
                        ->whereKey($item['client_medication_id'])
                        ->where('client_id', $client->id)
                        ->firstOrFail();
                }

                return [
                    'client_medication_id' => $medication?->id ?? $item['client_medication_id'] ?? null,
                    'name' => $medication?->name ?? $item['name'] ?? 'Medication',
                    'dose' => $item['dose'] ?? $medication?->dosage,
                    'unit' => $item['unit'] ?? $medication?->dose_unit,
                    'requires_witness' => (bool) ($item['requires_witness'] ?? $medication?->requiresWitness() ?? false),
                ];
            })
            ->values()
            ->all();
    }

    private function validateWorkflowWitness(array $data, int $currentUserId): User
    {
        if (empty($data['witnessed_by'])) {
            throw ValidationException::withMessages([
                'witnessed_by' => 'Select a second checker.',
            ]);
        }

        if ((int) $data['witnessed_by'] === $currentUserId) {
            throw ValidationException::withMessages([
                'witnessed_by' => 'The second checker must be a different staff member.',
            ]);
        }

        if (blank($data['witness_credential'] ?? null)) {
            throw ValidationException::withMessages([
                'witness_credential' => 'The second checker must enter their password or PIN.',
            ]);
        }

        $witness = User::query()->findOrFail($data['witnessed_by']);

        if (! $witness->canDo('medications.controlled.witness')) {
            throw ValidationException::withMessages([
                'witnessed_by' => 'This staff member is not approved to countersign medication.',
            ]);
        }

        if (! Hash::check((string) $data['witness_credential'], $witness->password)) {
            throw ValidationException::withMessages([
                'witness_credential' => 'The second checker password or PIN did not match.',
            ]);
        }

        return $witness;
    }

    /**
     * @return array<int, string>
     */
    private function handoverBypassPermissions(): array
    {
        return ['shifts.manageAny', 'handovers.viewAny', 'reports.viewAny'];
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

    private function findControlledMedication(int $clientId, string $medicationName): ?ClientMedication
    {
        return ClientMedication::query()
            ->where('client_id', $clientId)
            ->controlled()
            ->where('name', 'like', '%'.$medicationName.'%')
            ->first();
    }

    // ─── Dashboard ─────────────────────────────────────────
    public function dashboard(Request $request, MedicationOverviewService $overview)
    {
        $scheduleDate = app(MarScheduleService::class)->dateFromInput($request->input('date'));

        $user = $request->user();

        return Inertia::render('emar/Index', array_merge(
            $overview->payload($scheduleDate),
            [
                'canManageSettings' => (bool) $user && (
                    $user->canDo('medications.settings.manage')
                    || $user->canDo('medications.orders.manage')
                    || $user->canDo('clients.update')
                ),
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
        $scheduleService = app(MarScheduleService::class);
        $scheduleDate = $scheduleService->dateFromInput($request->input('date'));
        $date = $scheduleDate->toDateString();
        [$dayStartUtc, $dayEndUtc] = $scheduleService->utcDayWindow($scheduleDate);
        $clients = Client::query()
            ->withCount(['medications as active_medications_count' => fn ($q) => $q->active()])
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

        if ($request->filled('client_id')) {
            $selectedClient = Client::with([
                'site:id,name,brand_colour',
                'medications' => fn ($q) => $q->active()->orderBy('name'),
                'medications.stock',
                'medications.administrations' => fn ($q) => $q->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                    $query->whereBetween('scheduled_for', [$dayStartUtc, $dayEndUtc])
                        ->orWhereBetween('administered_at', [$dayStartUtc, $dayEndUtc]);
                }),
                'medications.administrations.attachments.uploadedBy:id,name',
            ])->findOrFail($request->client_id);

            $this->authorize('viewMedications', $selectedClient);

            $marData = $this->buildMarData($selectedClient, $scheduleDate);
            $clientContext = $this->buildClientMedicationContext($selectedClient);
            $breakGlassAccess = $this->getBreakGlassState($selectedClient);
            $pendingCorrections = $this->getPendingCorrections($selectedClient);
            $alerts = $this->getClientAlerts($selectedClient);
            $controlledDiscrepancies = $this->getOpenControlledDiscrepancies($selectedClient);

            $boardClientIds = [$selectedClient->id];
            $boardNow = Carbon::now($scheduleService->workerTimezone());
            $boardSchedule = $this->boardPayload->scheduleForDate(
                $boardClientIds,
                $scheduleDate,
                $boardNow,
                $this->boardPayload->slotIndex(
                    $this->boardPayload->administrationsForDay($boardClientIds, $scheduleDate)
                ),
            );
            $boardPrn = $this->boardPayload->prnMedications($boardClientIds, $boardNow);
            $selectedClientInfo = $this->boardPayload->clientsPayload($boardClientIds)[0] ?? null;
            $siteBrandColour = $selectedClient->site?->brand_colour;
        }

        return Inertia::render('emar/MarCharts', [
            'clients' => $clients,
            'selectedClient' => $selectedClient,
            'marData' => $marData,
            'date' => $date,
            'staff' => $this->getStaffList(),
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
            'can' => $this->buildMedicationPermissions($request->user()),
            // Shared meds-board payload (mirrors /meds/today) powering the
            // time-grid + reused RecordDoseWizard / PrnWizard on the MAR chart.
            'schedule' => $boardSchedule,
            'prn_medications' => $boardPrn,
            'selected_client_info' => $selectedClientInfo,
            'site_brand_colour' => $siteBrandColour,
            'witnesses' => $this->boardPayload->witnesses($request->user()),
            'not_given_reasons' => $this->boardPayload->notGivenReasons(),
            'board_user' => $this->boardPayload->boardUser($request->user()),
        ]);
    }

    private function buildMarData(Client $client, Carbon $date): array
    {
        $ruleService = app(MedicationRuleService::class);
        $scheduleService = app(MarScheduleService::class);
        $medications = $client->medications()->active()->with([
            'stock',
            'administrations' => function ($q) use ($date, $scheduleService) {
                [$dayStartUtc, $dayEndUtc] = $scheduleService->utcDayWindow($date);

                $q->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
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
            'attention_alerts' => $this->getClientMedicationAttentionAlerts($client),
            'inr_records' => $this->getClientInrRecords($client),
            'syringe_drivers' => $this->getRunningSyringeDrivers($client),
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
        $dateFrom = $request->input('from', now()->subDays(7)->toDateString());
        $dateTo = $request->input('to', today()->toDateString());

        $prnAdministrations = ClientMedicationAdministration::query()
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->whereBetween('administered_at', [$dateFrom, $dateTo.' 23:59:59'])
            ->with(['client:id,first_name,last_name', 'medication:id,name,dosage,max_per_day,indication', 'administeredBy:id,name'])
            ->latest('administered_at')
            ->paginate(50);

        $pendingEffectivenessReviews = ClientMedicationAdministration::query()
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->where('status', 'given')
            ->where('administered_at', '>=', now()->subHours(4))
            ->whereDoesntHave('prnEffectiveness')
            ->with(['client:id,first_name,last_name', 'medication:id,name'])
            ->latest('administered_at')
            ->limit(20)
            ->get();

        $prnStats = [
            'total_given_period' => ClientMedicationAdministration::query()
                ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
                ->whereBetween('administered_at', [$dateFrom, $dateTo.' 23:59:59'])
                ->where('status', 'given')
                ->count(),
            'effectiveness_reviews_pending' => $pendingEffectivenessReviews->count(),
            'near_limit_medications' => ClientMedication::active()->prn()
                ->get()
                ->filter(fn ($m) => $m->isPrnNearLimit())
                ->count(),
        ];

        return Inertia::render('emar/PrnRecords', [
            'administrations' => $prnAdministrations,
            'pendingReviews' => $pendingEffectivenessReviews,
            'stats' => $prnStats,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    // ─── Controlled Drugs ──────────────────────────────────
    public function controlled(Request $request)
    {
        $controlledMedications = ClientMedication::query()
            ->active()
            ->controlled()
            ->with([
                'client:id,first_name,last_name',
                'stock',
            ])
            ->orderBy('name')
            ->get();

        $recentEntries = ClientControlledDrugEntry::query()
            ->with(['client:id,first_name,last_name', 'medication:id,name', 'recordedBy:id,name', 'witnessedBy:id,name'])
            ->latest('recorded_at')
            ->limit(50)
            ->get();

        $discrepancies = ClientControlledDrugDiscrepancy::query()
            ->whereIn('status', ['open', 'under_review'])
            ->with([
                'client:id,first_name,last_name',
                'medication:id,name',
                'attachments.uploadedBy:id,name',
            ])
            ->latest()
            ->get();

        $destructions = MedicationDestruction::query()
            ->controlled()
            ->with(['client:id,first_name,last_name', 'destroyedByUser:id,name', 'witness1:id,name'])
            ->latest('destroyed_at')
            ->limit(20)
            ->get();

        $lossReports = ControlledDrugLossReport::with([
            'client:id,first_name,last_name',
            'discoveredBy:id,name',
            'attachments.uploadedBy:id,name',
        ])
            ->latest()
            ->get();

        return Inertia::render('emar/ControlledDrugs', [
            'medications' => $controlledMedications,
            'recentEntries' => $recentEntries,
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
                'reason' => $discrepancy->reason,
                'notes' => $discrepancy->notes,
                'status' => $discrepancy->status,
                'reported_at' => $discrepancy->reported_at?->toIso8601String(),
                'attachments' => $discrepancy->attachments
                    ->map(fn ($attachment) => $this->serializeSupportingAttachment(
                        $attachment,
                        'api.medications.supporting_attachments.download',
                    ))
                    ->values()
                    ->all(),
            ])->values(),
            'destructions' => $destructions,
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
                'reported_to_police' => (bool) $report->reported_to_police,
                'police_reference' => $report->police_reference,
                'reported_to_pharmacy' => (bool) $report->reported_to_pharmacy,
                'pharmacy_name' => $report->pharmacy_name,
                'discovered_at' => $report->discovered_at?->toIso8601String(),
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
            'staff' => $this->getStaffList(),
            'clients' => $this->getClientsList(),
            'can' => [
                'manage_evidence' => (bool) $request->user() && (
                    $request->user()->canDo('medications.controlled.record')
                    || $request->user()->canDo('medications.administer.correct')
                    || $request->user()->canDo('clients.update')
                ),
            ],
        ]);
    }

    // ─── Medications Database ──────────────────────────────
    public function medications(Request $request)
    {
        $selectedClient = null;

        $medications = ClientMedication::query()
            ->with(['client:id,first_name,last_name', 'stock'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($request->status === 'active', fn ($q) => $q->active())
            ->when($request->status === 'ceased', fn ($q) => $q->where('state', 'ceased'))
            ->when($request->status === 'paused', fn ($q) => $q->where('state', 'paused'))
            ->when($request->type === 'prn', fn ($q) => $q->prn())
            ->when($request->type === 'controlled', fn ($q) => $q->controlled())
            ->when($request->type === 'high_risk', fn ($q) => $q->highRisk())
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->latest()
            ->paginate(50);

        $clients = Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        if ($request->filled('client_id')) {
            $selectedClient = Client::findOrFail($request->client_id);
            $this->authorize('viewMedications', $selectedClient);
        }

        // Build a map of medication IDs that have known interactions with other active meds for the same client
        $interactionMap = [];
        $medsByClient = $medications->getCollection()->groupBy('client_id');
        foreach ($medsByClient as $clientId => $clientMeds) {
            $names = $clientMeds->pluck('name')->map(fn ($n) => strtolower($n))->toArray();
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

        return Inertia::render('emar/Medications', [
            'medications' => $medications,
            'clients' => $clients,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['search', 'status', 'type', 'client_id']),
            'interactionMap' => $interactionMap,
            'selectedClient' => $selectedClient ? [
                'id' => $selectedClient->id,
                'first_name' => $selectedClient->first_name,
                'last_name' => $selectedClient->last_name,
            ] : null,
            'clientContext' => $selectedClient ? $this->buildClientMedicationContext($selectedClient) : null,
            'can' => $this->buildMedicationPermissions($request->user()),
        ]);
    }

    // ─── Stock Management ──────────────────────────────────
    public function stock(Request $request)
    {
        $stockItems = ClientMedicationStock::query()
            ->with(['medication' => fn ($q) => $q->with('client:id,first_name,last_name')])
            ->whereHas('medication', fn ($q) => $q->active())
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'medication_id' => $s->client_medication_id,
                'medication_name' => $s->medication?->name,
                'client_name' => $s->medication?->client?->first_name.' '.$s->medication?->client?->last_name,
                'client_id' => $s->medication?->client_id,
                'on_hand' => $s->on_hand,
                'unit' => $s->unit,
                'reorder_level' => $s->reorder_level,
                'last_counted_at' => $s->last_counted_at,
                'is_low' => $s->isLowStock(),
                'controlled' => $s->medication?->controlled_drug,
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
            ]);

        $lowStockCount = $stockItems->where('is_low', true)->count();

        $pharmacyOrders = MedicationPharmacyOrder::query()
            ->pending()
            ->with(['client:id,first_name,last_name', 'medication:id,name'])
            ->latest()
            ->limit(20)
            ->get();

        return Inertia::render('emar/StockManagement', [
            'stockItems' => $stockItems,
            'lowStockCount' => $lowStockCount,
            'expiringCount' => ClientMedicationStock::expiringSoon()->count(),
            'expiredCount' => ClientMedicationStock::expired()->count(),
            'pharmacyOrders' => $pharmacyOrders,
            'clients' => $this->getClientsList(),
            'activeMedications' => ClientMedication::active()
                ->with('client:id,first_name,last_name')
                ->orderBy('name')
                ->get(['id', 'name', 'client_id', 'dosage', 'barcode', 'nzulm_code'])
                ->map(fn (ClientMedication $medication) => [
                    'id' => $medication->id,
                    'name' => $medication->name,
                    'client_id' => $medication->client_id,
                    'client' => $medication->client ? [
                        'first_name' => $medication->client->first_name,
                        'last_name' => $medication->client->last_name,
                    ] : null,
                    'scan_verification' => $medication->client
                        ? $this->buildMedicationScanPayload($medication->client, $medication)
                        : null,
                ])
                ->values(),
            'witnesses' => $this->getStaffList(),
        ]);
    }

    // ─── Prescriptions / Prescriber Orders ─────────────────
    public function prescriptions(Request $request)
    {
        $orders = MedicationPrescriberOrder::query()
            ->with(['client:id,first_name,last_name', 'medication:id,name', 'receivedByUser:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->latest('order_date')
            ->paginate(50);

        $pendingCountersigns = MedicationPrescriberOrder::awaitingCountersign()->count();

        $covertAuthorisations = MedicationCovertAuthorisation::query()
            ->active()
            ->with(['client:id,first_name,last_name', 'medication:id,name'])
            ->get();

        $clients = Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        return Inertia::render('emar/Prescriptions', [
            'orders' => $orders,
            'pendingCountersigns' => $pendingCountersigns,
            'covertAuthorisations' => $covertAuthorisations,
            'clients' => $clients,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['status', 'client_id']),
        ]);
    }

    // ─── Competency Assessments ────────────────────────────
    public function competency(Request $request)
    {
        $assessments = MedicationCompetencyAssessment::query()
            ->with(['user:id,name,email', 'assessor:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('assessment_date')
            ->paginate(50);

        $expiringSoon = MedicationCompetencyAssessment::expiringSoon(30)->with('user:id,name')->get();
        $expired = MedicationCompetencyAssessment::expired()->with('user:id,name')->get();

        $staffWithoutAssessment = User::query()
            ->whereDoesntHave('medicationCompetencyAssessments', fn ($q) => $q->active())
            ->get(['id', 'name', 'email']);

        return Inertia::render('emar/Competency', [
            'assessments' => $assessments,
            'expiringSoon' => $expiringSoon,
            'expired' => $expired,
            'staffWithoutAssessment' => $staffWithoutAssessment,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['status']),
        ]);
    }

    // ─── Medication Reviews ────────────────────────────────
    public function reviews(Request $request)
    {
        $reviews = MedicationReview::query()
            ->with(['client:id,first_name,last_name', 'reviewer:id,name', 'requestedBy:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->latest('scheduled_date')
            ->paginate(50);

        $overdueReviews = MedicationReview::overdue()
            ->with('client:id,first_name,last_name')
            ->get();

        $upcomingReviews = MedicationReview::upcoming(30)
            ->with('client:id,first_name,last_name')
            ->get();

        $clients = Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        return Inertia::render('emar/Reviews', [
            'reviews' => $reviews,
            'overdueReviews' => $overdueReviews,
            'upcomingReviews' => $upcomingReviews,
            'clients' => $clients,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['status', 'client_id']),
        ]);
    }

    // ─── Medication Rounds ─────────────────────────────────
    public function rounds(Request $request)
    {
        $date = $request->input('date', today()->toDateString());

        $rounds = MedicationRound::query()
            ->forDate($date)
            ->with(['assignedTo:id,name', 'startedBy:id,name', 'completedBy:id,name'])
            ->orderBy('scheduled_time')
            ->get();

        $templates = MedicationRoundTemplate::query()
            ->with('defaultAssignedTo:id,name')
            ->orderBy('scheduled_time')
            ->get();

        // Last auto-generated round timestamp
        $lastGenerated = MedicationRound::whereNotNull('round_template_id')
            ->latest('created_at')
            ->value('created_at');

        return Inertia::render('emar/Rounds', [
            'rounds' => $rounds,
            'templates' => $templates,
            'staff' => $this->getStaffList(),
            'date' => $date,
            'lastGenerated' => $lastGenerated?->toIso8601String(),
        ]);
    }

    // ─── Self-Administration Assessments ───────────────────
    public function selfAdmin(Request $request)
    {
        $assessments = MedicationSelfAdminAssessment::query()
            ->with(['client:id,first_name,last_name', 'assessor:id,name'])
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->latest('assessment_date')
            ->paginate(50);

        $dueReassessments = MedicationSelfAdminAssessment::query()
            ->where('status', 'completed')
            ->where('reassessment_date', '<=', today()->toDateString())
            ->with('client:id,first_name,last_name')
            ->get();

        $clients = Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        return Inertia::render('emar/SelfAdmin', [
            'assessments' => $assessments,
            'dueReassessments' => $dueReassessments,
            'clients' => $clients,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['client_id']),
        ]);
    }

    // ─── Destruction Records ───────────────────────────────
    public function destructions(Request $request)
    {
        $destructions = MedicationDestruction::query()
            ->with(['client:id,first_name,last_name', 'medication:id,name', 'destroyedByUser:id,name', 'witness1:id,name', 'witness2:id,name'])
            ->when($request->controlled_only, fn ($q) => $q->controlled())
            ->latest('destroyed_at')
            ->paginate(50);

        return Inertia::render('emar/Destructions', [
            'destructions' => $destructions,
            'staff' => $this->getStaffList(),
            'clients' => $this->getClientsList(),
            'medications' => ClientMedication::active()->orderBy('name')->get(['id', 'name', 'client_id']),
            'filters' => $request->only(['controlled_only']),
        ]);
    }

    // ─── Handovers ─────────────────────────────────────────
    public function handovers(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $handovers = ShiftHandover::query()
            ->when($auth->organization_id, fn ($query) => $query->where('organization_id', $auth->organization_id))
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            ->with([
                'client:id,first_name,last_name',
                'outgoingShift:id,client_id,user_id,service_context_id,starts_at,ends_at,location,shift_type,is_sleepover,is_on_call,status',
                'outgoingShift.staff:id,name',
                'outgoingShift.serviceContext:id,name',
                'incomingShift:id,user_id,starts_at,ends_at,status',
                'incomingShift.staff:id,name',
                'outgoingStaff:id,name',
                'incomingStaff:id,name',
                'acknowledger:id,name',
            ])
            ->when(! $this->handoverService->canViewAny($auth), function ($query) use ($auth) {
                $query->where(function ($nested) use ($auth) {
                    $nested->where('outgoing_staff_id', $auth->id)
                        ->orWhere('incoming_staff_id', $auth->id)
                        ->orWhereHas('outgoingShift', fn ($shiftQuery) => $shiftQuery->where('user_id', $auth->id))
                        ->orWhereHas('incomingShift', fn ($shiftQuery) => $shiftQuery->where('user_id', $auth->id));
                });
            })
            ->latest('created_at')
            ->paginate(25)
            ->through(function (ShiftHandover $handover) use ($auth) {
                $incomingStaff = $handover->incomingShift?->staff ?? $handover->incomingStaff;

                return [
                    'id' => $handover->id,
                    'status' => $handover->status,
                    'handover_notes' => $handover->handover_notes,
                    'client_mood' => $handover->client_mood,
                    'created_at' => $handover->created_at?->toIso8601String(),
                    'submitted_at' => $handover->submitted_at?->toIso8601String(),
                    'acknowledged_at' => $handover->acknowledged_at?->toIso8601String(),
                    'client' => $handover->client ? [
                        'id' => $handover->client->id,
                        'name' => trim(($handover->client->first_name ?? '').' '.($handover->client->last_name ?? '')),
                    ] : null,
                    'outgoing_staff' => $handover->outgoingStaff ? [
                        'id' => $handover->outgoingStaff->id,
                        'name' => $handover->outgoingStaff->name,
                    ] : null,
                    'incoming_staff' => $incomingStaff ? [
                        'id' => $incomingStaff->id,
                        'name' => $incomingStaff->name,
                    ] : null,
                    'acknowledger' => $handover->acknowledger ? [
                        'id' => $handover->acknowledger->id,
                        'name' => $handover->acknowledger->name,
                    ] : null,
                    'outgoing_shift' => $handover->outgoingShift ? [
                        'id' => $handover->outgoingShift->id,
                        'starts_at' => $handover->outgoingShift->starts_at?->toIso8601String(),
                        'ends_at' => $handover->outgoingShift->ends_at?->toIso8601String(),
                        'location' => $handover->outgoingShift->location,
                        'shift_type' => $handover->outgoingShift->shift_type,
                        'service_context_name' => $handover->outgoingShift->serviceContext?->name,
                    ] : null,
                    'incoming_shift' => $handover->incomingShift ? [
                        'id' => $handover->incomingShift->id,
                        'starts_at' => $handover->incomingShift->starts_at?->toIso8601String(),
                        'ends_at' => $handover->incomingShift->ends_at?->toIso8601String(),
                    ] : null,
                    'medications_due' => $this->stringifyStructuredItems($handover->medications_due),
                    'follow_up_items' => $this->stringifyStructuredItems($handover->follow_up_items),
                    'incidents_to_note' => $this->stringifyStructuredItems($handover->incidents_to_note),
                    'tasks_pending' => $this->stringifyStructuredItems($handover->tasks_pending),
                    'can_submit' => $this->handoverService->canSubmit($handover, $auth),
                    'can_acknowledge' => $this->handoverService->canAcknowledge($handover, $auth),
                    'can_edit' => $handover->status === ShiftHandoverService::STATUS_DRAFT
                        && $this->handoverService->canSubmit($handover, $auth),
                    'can_delete' => $handover->status === ShiftHandoverService::STATUS_DRAFT
                        && $this->handoverService->canSubmit($handover, $auth),
                ];
            })
            ->withQueryString();

        $shifts = Shift::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name', 'serviceContext:id,name'])
            ->whereBetween('starts_at', [now()->subDay(), now()->addDays(2)])
            ->orderBy('starts_at')
            ->limit(100)
            ->get()
            ->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'starts_at' => $shift->starts_at?->toISOString(),
                'ends_at' => $shift->ends_at?->toISOString(),
                'status' => $shift->status,
                'shift_type' => $shift->shift_type ?? 'standard',
                'is_sleepover' => (bool) $shift->is_sleepover,
                'is_on_call' => (bool) $shift->is_on_call,
                'location' => $shift->location,
                'service_context_name' => $shift->serviceContext?->name,
                'client_name' => trim(($shift->client?->first_name ?? '').' '.($shift->client?->last_name ?? '')),
                'staff_name' => $shift->staff?->name,
            ])
            ->values();

        return Inertia::render('emar/Handovers', [
            'handovers' => $handovers,
            'shifts' => $shifts,
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // CRUD / Workflow Methods
    // ═════════════════════════════════════════════════════════

    // ─── Prescriber Orders CRUD ─────────────────────────────

    public function storePrescription(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_medication_id' => 'nullable|exists:client_medications,id',
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
            'effective_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
        ]);

        $validated['received_by'] = auth()->id();
        $validated['status'] = 'pending';
        $validated['requires_countersign'] = in_array($validated['order_type'], ['verbal', 'telephone']);

        MedicationPrescriberOrder::create($validated);

        return redirect()->back();
    }

    public function updatePrescription(Request $request, MedicationPrescriberOrder $order)
    {
        $validated = $request->validate([
            'status' => 'nullable|string|max:255',
            'pharmacy_notes' => 'nullable|string',
            'pharmacy_name' => 'nullable|string|max:255',
            'batch_number' => 'nullable|string|max:255',
            'batch_expiry' => 'nullable|date',
            'dispensed_by' => 'nullable|exists:users,id',
            'dispensed_at' => 'nullable|date',
            'clinical_notes' => 'nullable|string',
            'instructions' => 'nullable|string',
        ]);

        $order->update($validated);

        return redirect()->back();
    }

    public function countersignPrescription(MedicationPrescriberOrder $order)
    {
        $order->update([
            'countersigned_at' => now(),
            'countersigned_by' => auth()->id(),
        ]);

        return redirect()->back();
    }

    public function destroyPrescription(MedicationPrescriberOrder $order)
    {
        $order->update(['status' => 'cancelled']);

        return redirect()->back();
    }

    // ─── Covert Authorisations CRUD ─────────────────────────

    public function storeCovert(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_medication_id' => 'required|exists:client_medications,id',
            'authorised_by_name' => 'required|string|max:255',
            'authorised_by_registration' => 'nullable|string|max:255',
            'clinical_justification' => 'required|string',
            'legal_basis' => 'nullable|string',
            'administration_method' => 'nullable|string|max:255',
            'pharmacist_advice' => 'nullable|string',
            'authorised_date' => 'required|date',
            'review_date' => 'required|date|after:authorised_date',
        ]);

        $validated['status'] = 'active';
        $validated['recorded_by'] = auth()->id();

        MedicationCovertAuthorisation::create($validated);

        return redirect()->back();
    }

    public function revokeCovert(MedicationCovertAuthorisation $authorisation)
    {
        $authorisation->update(['status' => 'revoked']);

        return redirect()->back();
    }

    // ─── Reviews CRUD ───────────────────────────────────────

    public function storeReview(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'review_type' => 'required|string|max:255',
            'scheduled_date' => 'required|date',
            'reviewer_name' => 'nullable|string|max:255',
            'reviewer_role' => 'nullable|string|max:255',
            'reviewer_user_id' => 'nullable|exists:users,id',
            'trigger_reason' => 'nullable|string',
        ]);

        $validated['status'] = 'scheduled';
        $validated['requested_by'] = auth()->id();

        MedicationReview::create($validated);

        return redirect()->back();
    }

    public function updateReview(Request $request, MedicationReview $review)
    {
        $validated = $request->validate([
            'review_type' => 'nullable|string|max:255',
            'scheduled_date' => 'nullable|date',
            'reviewer_name' => 'nullable|string|max:255',
            'reviewer_role' => 'nullable|string|max:255',
            'reviewer_user_id' => 'nullable|exists:users,id',
            'trigger_reason' => 'nullable|string',
        ]);

        $review->update($validated);

        return redirect()->back();
    }

    public function completeReview(Request $request, MedicationReview $review)
    {
        $validated = $request->validate([
            'clinical_summary' => 'required|string',
            'medications_reviewed' => 'nullable|array',
            'recommendations' => 'nullable|string',
            'actions' => 'nullable|array',
            'whanau_involved' => 'nullable|boolean',
            'whanau_notes' => 'nullable|string',
            'next_review_date' => 'nullable|date',
        ]);

        $validated['status'] = 'completed';
        $validated['completed_date'] = today();
        $validated['next_review_date'] = $validated['next_review_date']
            ?? today()->addMonthsNoOverflow((int) ($review->client?->chart_review_interval_months ?: 3))->toDateString();

        $review->update($validated);
        $review->client?->forceFill([
            'next_chart_review_date' => $validated['next_review_date'],
        ])->save();

        return redirect()->back();
    }

    public function destroyReview(MedicationReview $review)
    {
        $review->update(['status' => 'cancelled']);

        return redirect()->back();
    }

    // ─── 1CHART Attention / INR / Syringe Driver Workflows ─────

    public function storeAttentionAlert(Request $request, Client $client)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['paper_prescription', 'chart_warning', 'warfarin'])],
            'title' => ['required', 'string', 'max:255'],
            'detail' => ['nullable', 'string', 'max:2000'],
            'prompt_on_open' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $client->medicationAlerts()->create([
            ...$validated,
            'prompt_on_open' => (bool) ($validated['prompt_on_open'] ?? false),
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'created_by' => $request->user()?->id,
        ]);

        app(MedicationAlertService::class)->generateClientAlerts($client->fresh());

        return redirect()->back()->with('success', 'Medication chart alert added.');
    }

    public function updateAttentionAlert(Request $request, ClientMedicationAlert $alert)
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::in(['paper_prescription', 'chart_warning', 'warfarin'])],
            'title' => ['nullable', 'string', 'max:255'],
            'detail' => ['nullable', 'string', 'max:2000'],
            'prompt_on_open' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $alert->update($validated);
        app(MedicationAlertService::class)->generateClientAlerts($alert->client);

        return redirect()->back()->with('success', 'Medication chart alert updated.');
    }

    public function resolveAttentionAlert(Request $request, ClientMedicationAlert $alert)
    {
        $alert->resolve($request->user()->id);

        return redirect()->back()->with('success', 'Medication chart alert resolved.');
    }

    public function toggleMedicationAlertSuppression(Request $request, Client $client)
    {
        $validated = $request->validate([
            'suppress_med_admin_alerts' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['suppress_med_admin_alerts'] && blank($validated['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => 'Enter why medication administration alerts are being suppressed.',
            ]);
        }

        $client->forceFill([
            'suppress_med_admin_alerts' => (bool) $validated['suppress_med_admin_alerts'],
            'med_alerts_suppressed_reason' => $validated['suppress_med_admin_alerts'] ? $validated['reason'] : null,
            'med_alerts_suppressed_by' => $validated['suppress_med_admin_alerts'] ? $request->user()?->id : null,
            'med_alerts_suppressed_at' => $validated['suppress_med_admin_alerts'] ? now() : null,
        ])->save();

        app(MedicationAlertService::class)->generateClientAlerts($client->fresh());

        return redirect()->back()->with('success', 'Medication alert settings updated.');
    }

    public function updateMedicationSettings(Request $request, Client $client)
    {
        $validated = $request->validate([
            'care_level' => ['nullable', 'string', 'max:60'],
            'chart_review_interval_months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'next_chart_review_date' => ['nullable', 'date'],
        ]);

        $client->forceFill([
            'care_level' => $validated['care_level'] ?? null,
            'chart_review_interval_months' => $validated['chart_review_interval_months'] ?? $client->chart_review_interval_months ?? 3,
            'next_chart_review_date' => $validated['next_chart_review_date'] ?? null,
        ])->save();

        app(MedicationAlertService::class)->generateClientAlerts($client->fresh());

        return redirect()->back()->with('success', 'Medication chart settings updated.');
    }

    public function inrHistory(Client $client)
    {
        return response()->json([
            'records' => $this->getClientInrRecords($client),
        ]);
    }

    public function storeInr(Request $request, Client $client)
    {
        $validated = $request->validate([
            'client_medication_id' => ['nullable', 'integer', 'exists:client_medications,id'],
            'inr_value' => ['required', 'numeric', 'min:0.5', 'max:20'],
            'target_range_low' => ['nullable', 'numeric', 'min:0.5', 'max:20'],
            'target_range_high' => ['nullable', 'numeric', 'min:0.5', 'max:20', 'gte:target_range_low'],
            'dose_mg' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'tested_on' => ['required', 'date'],
            'next_test_date' => ['nullable', 'date', 'after_or_equal:tested_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! empty($validated['client_medication_id'])) {
            $belongsToClient = ClientMedication::query()
                ->whereKey($validated['client_medication_id'])
                ->where('client_id', $client->id)
                ->exists();

            abort_unless($belongsToClient, 404);
        }

        $client->inrRecords()->create([
            ...$validated,
            'recorded_by' => $request->user()->id,
        ]);

        app(MedicationAlertService::class)->generateClientAlerts($client->fresh());

        return redirect()->back()->with('success', 'INR result recorded.');
    }

    public function disableInr(Request $request, ClientInrRecord $inr)
    {
        if (! $inr->disabled_at) {
            $inr->disable($request->user()->id);
        }

        app(MedicationAlertService::class)->generateClientAlerts($inr->client);

        return redirect()->back()->with('success', 'INR result disabled.');
    }

    public function storeSyringeDriver(Request $request, Client $client)
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'commenced_at' => ['required', 'date'],
            'rate' => ['nullable', 'string', 'max:80'],
            'rate_unit' => ['nullable', 'string', 'max:40'],
            'duration_hours' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'contents' => ['required', 'array', 'min:1'],
            'contents.*.client_medication_id' => ['nullable', 'integer', 'exists:client_medications,id'],
            'contents.*.name' => ['nullable', 'string', 'max:255'],
            'contents.*.dose' => ['nullable', 'string', 'max:80'],
            'contents.*.unit' => ['nullable', 'string', 'max:40'],
            'contents.*.requires_witness' => ['nullable', 'boolean'],
            'site_of_insertion' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'witnessed_by' => ['nullable', 'integer', 'exists:users,id'],
            'witness_credential' => ['nullable', 'string'],
        ]);

        $contents = $this->normaliseSyringeDriverContents($client, $validated['contents']);
        $requiresWitness = collect($contents)->contains(fn ($item) => (bool) ($item['requires_witness'] ?? false));
        $witness = $requiresWitness
            ? $this->validateWorkflowWitness($validated, $request->user()->id)
            : null;

        $driver = $client->syringeDrivers()->create([
            'site_id' => $validated['site_id'] ?? $client->site_id,
            'status' => 'running',
            'commenced_at' => $validated['commenced_at'],
            'commenced_by' => $request->user()->id,
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
    }

    public function addSyringeDriverCheck(Request $request, MedicationSyringeDriver $driver)
    {
        $validated = $request->validate([
            'checked_at' => ['nullable', 'date'],
            'infusion_running' => ['required', 'boolean'],
            'site_condition' => ['nullable', 'string', 'max:255'],
            'volume_remaining' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $driver->checks()->create([
            ...$validated,
            'checked_at' => $validated['checked_at'] ?? now(),
            'checked_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Syringe driver check recorded.');
    }

    public function completeSyringeDriver(Request $request, MedicationSyringeDriver $driver)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['completed', 'stopped'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $driver->forceFill([
            'status' => $validated['status'] ?? 'completed',
            'completed_at' => now(),
            'completed_by' => $request->user()->id,
            'notes' => trim($driver->notes."\n".($validated['notes'] ?? '')) ?: $driver->notes,
        ])->save();

        return redirect()->back()->with('success', 'Syringe driver completed.');
    }

    // ─── Competency CRUD ────────────────────────────────────

    public function storeCompetency(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
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
            'can_administer_unsupervised' => 'nullable|boolean',
            'can_witness_controlled' => 'nullable|boolean',
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
        $validated['assessor_id'] = auth()->id();
        $validated['expiry_date'] = $validated['expiry_date']
            ?? Carbon::parse($validated['assessment_date'])->addYear()->toDateString();
        $validated['can_administer_unsupervised'] = (bool) ($validated['can_administer_unsupervised'] ?? false);
        $validated['can_witness_controlled'] = (bool) ($validated['can_witness_controlled'] ?? false);

        MedicationCompetencyAssessment::create($validated);

        return redirect()->back();
    }

    public function updateCompetency(Request $request, MedicationCompetencyAssessment $assessment)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
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
            'expiry_date' => 'nullable|date|after_or_equal:assessment_date',
            'can_administer_unsupervised' => 'nullable|boolean',
            'can_witness_controlled' => 'nullable|boolean',
        ]);

        $booleanFields = [
            'medication_knowledge', 'five_rights', 'safety_checks', 'documentation',
            'controlled_drugs', 'prn_assessment', 'insulin_competent', 'inhaler_competent',
            'topical_competent', 'covert_admin_knowledge', 'error_reporting', 'allergy_awareness',
        ];

        if (collect($booleanFields)->contains(fn ($field) => array_key_exists($field, $validated))) {
            $merged = array_merge($assessment->only($booleanFields), $validated);
            $totalScore = collect($booleanFields)->filter(fn ($field) => ! empty($merged[$field]))->count();
            $validated['total_score'] = $totalScore;
            $validated['pass_threshold'] = 10;
            $validated['status'] = $totalScore >= 10 ? 'passed' : 'failed';
        }

        if (! array_key_exists('expiry_date', $validated) && ! empty($validated['assessment_date'])) {
            $validated['expiry_date'] = Carbon::parse($validated['assessment_date'])->addYear()->toDateString();
        }

        $assessment->update($validated);

        return redirect()->back();
    }

    public function destroyCompetency(MedicationCompetencyAssessment $assessment)
    {
        $assessment->delete();

        return redirect()->back();
    }

    // ─── Rounds CRUD / Workflow ─────────────────────────────

    public function storeRoundTemplate(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'scheduled_time' => 'required|date_format:H:i',
            'window_minutes' => 'required|integer|min:5|max:120',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'site_id' => 'nullable|exists:sites,id',
            'default_assigned_to' => 'nullable|exists:users,id',
        ]);

        $validated['active'] = true;

        MedicationRoundTemplate::create($validated);

        return redirect()->back();
    }

    public function updateRoundTemplate(Request $request, MedicationRoundTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'scheduled_time' => 'nullable|date_format:H:i',
            'window_minutes' => 'nullable|integer|min:5|max:120',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'active' => 'nullable|boolean',
            'default_assigned_to' => 'nullable|exists:users,id',
        ]);

        $template->update($validated);

        return redirect()->back();
    }

    public function destroyRoundTemplate(MedicationRoundTemplate $template)
    {
        $template->delete();

        return redirect()->back();
    }

    public function generateRounds(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'generate_all' => 'nullable|boolean',
        ]);

        $date = Carbon::parse($validated['date']);
        $dayOfWeek = $date->dayOfWeekIso; // 1=Mon, 7=Sun
        $generateAll = (bool) ($validated['generate_all'] ?? false);

        $templates = MedicationRoundTemplate::active()->get();
        $created = 0;

        foreach ($templates as $template) {
            if (! $generateAll && ! $template->appliesToDay($dayOfWeek)) {
                continue;
            }

            // Skip if round already exists for this template on this date
            $exists = MedicationRound::where('round_template_id', $template->id)
                ->whereDate('round_date', $date)
                ->exists();

            if ($exists) {
                continue;
            }

            MedicationRound::create([
                'name' => $template->name,
                'round_template_id' => $template->id,
                'round_type' => 'scheduled',
                'scheduled_time' => $template->scheduled_time,
                'window_minutes' => $template->window_minutes ?? 60,
                'round_date' => $date->toDateString(),
                'status' => 'pending',
                'assigned_to' => $template->default_assigned_to,
                'total_medications' => $template->applicableMedicationCountForDate($date),
                'site_id' => $template->site_id,
                'service_context_id' => $template->service_context_id,
            ]);

            $created++;
        }

        return redirect()->back();
    }

    public function startRound(MedicationRound $round)
    {
        $round->update([
            'status' => 'in_progress',
            'started_by' => auth()->id(),
            'started_at' => now(),
        ]);

        return redirect()->back();
    }

    public function completeRound(MedicationRound $round)
    {
        $round->update([
            'status' => 'completed',
            'completed_by' => auth()->id(),
            'completed_at' => now(),
        ]);

        $round->updateCounts();

        return redirect()->back();
    }

    public function assignRound(Request $request, MedicationRound $round)
    {
        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $round->update($validated);

        return redirect()->back();
    }

    // ─── Self-Admin CRUD ────────────────────────────────────

    public function storeSelfAdmin(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
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
            'safe_storage_notes' => 'nullable|string',
            'assessor_notes' => 'nullable|string',
            'reassessment_date' => 'nullable|date',
            'reassessment_trigger' => 'nullable|string',
        ]);

        $totalScore = $validated['cognitive_capacity']
            + $validated['physical_dexterity']
            + $validated['vision_ability']
            + $validated['swallowing_ability']
            + $validated['understanding_score'];

        $validated['outcome'] = match (true) {
            $totalScore >= 21 => 'independent',
            $totalScore >= 16 => 'prompted',
            $totalScore >= 11 => 'supervised',
            default => 'administered',
        };

        $validated['assessed_by'] = auth()->id();
        $validated['assessment_date'] = today();
        $validated['status'] = 'completed';

        MedicationSelfAdminAssessment::create($validated);

        return redirect()->back();
    }

    public function updateSelfAdmin(Request $request, MedicationSelfAdminAssessment $assessment)
    {
        $validated = $request->validate([
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
            'safe_storage_notes' => 'nullable|string',
            'assessor_notes' => 'nullable|string',
            'reassessment_date' => 'nullable|date',
            'reassessment_trigger' => 'nullable|string',
            'outcome' => 'nullable|string|in:independent,prompted,supervised,administered',
        ]);

        $assessment->update($validated);

        return redirect()->back();
    }

    public function destroySelfAdmin(MedicationSelfAdminAssessment $assessment)
    {
        $assessment->delete();

        return redirect()->back();
    }

    // ─── Destructions CRUD ──────────────────────────────────

    public function storeDestruction(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_medication_id' => 'nullable|exists:client_medications,id',
            'medication_name' => 'required|string|max:255',
            'form' => 'nullable|string|max:255',
            'strength' => 'nullable|string|max:255',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'required|string|max:50',
            'batch_number' => 'nullable|string|max:255',
            'expiry_date' => 'nullable|date',
            'reason' => 'required|string|max:255',
            'disposal_method' => 'required|string|max:255',
            'is_controlled_drug' => 'nullable|boolean',
            'controlled_drug_class' => 'nullable|string|max:50',
            'witness_1_id' => 'required|exists:users,id',
            'witness_2_id' => 'nullable|exists:users,id',
            'authorised_by_name' => 'nullable|string|max:255',
            'authorised_by_registration' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // For controlled drugs, require second witness and authorisation
        if (! empty($validated['is_controlled_drug'])) {
            $request->validate([
                'witness_2_id' => 'required|exists:users,id',
                'authorised_by_name' => 'required|string|max:255',
            ]);
        }

        // Ensure witness 1 is not the current user
        if ($validated['witness_1_id'] == auth()->id()) {
            return redirect()->back()->withErrors(['witness_1_id' => 'Witness must be a different person from the person destroying the medication.']);
        }

        $validated['destroyed_by'] = auth()->id();
        $validated['destroyed_at'] = now();

        DB::transaction(function () use ($validated) {
            MedicationDestruction::create($validated);

            if (! empty($validated['client_medication_id'])) {
                $stock = ClientMedicationStock::where('client_medication_id', $validated['client_medication_id'])->first();
                if ($stock) {
                    $stock->decrement('on_hand', $validated['quantity']);
                }
            }
        });

        return redirect()->back();
    }

    // ─── Handovers CRUD ─────────────────────────────────────

    public function storeHandover(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $validated = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'incoming_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'incoming_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'handover_notes' => ['required', 'string', 'max:5000'],
            'client_mood' => ['nullable', 'string', 'max:255'],
            'medications_due_text' => ['nullable', 'string'],
            'follow_up_items_text' => ['nullable', 'string'],
            'incidents_to_note_text' => ['nullable', 'string'],
            'tasks_pending_text' => ['nullable', 'string'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $shift = Shift::query()
            ->with(['tasks:id,shift_id,label,is_completed', 'incidents:id,shift_id,type,severity,status,occurred_at'])
            ->findOrFail($validated['shift_id']);

        $this->siteAccess()->assertCanAccessShift(
            $auth,
            $shift,
            $this->handoverBypassPermissions(),
            'You are not authorized to create handovers for this site.',
        );

        if (! $auth->canDo('shifts.manageAny') && (int) $shift->user_id !== (int) $auth->id) {
            abort(403);
        }

        $result = $this->handoverService->save($shift, $auth, [
            'incoming_shift_id' => $validated['incoming_shift_id'] ?? null,
            'incoming_staff_id' => $validated['incoming_staff_id'] ?? null,
            'handover_notes' => $validated['handover_notes'],
            'client_mood' => $validated['client_mood'] ?? null,
            'medications_due' => $this->parseHandoverText($validated['medications_due_text'] ?? null),
            'follow_up_items' => $this->parseHandoverText($validated['follow_up_items_text'] ?? null),
            'incidents_to_note' => $this->parseHandoverText($validated['incidents_to_note_text'] ?? null),
            'tasks_pending' => $this->parseHandoverText($validated['tasks_pending_text'] ?? null),
            'submit' => (bool) ($validated['submit'] ?? true),
        ]);

        return redirect()->back()->with(
            'success',
            $result['action'] === 'draft_saved' ? 'Medication handover draft saved.' : 'Medication handover submitted.',
        );
    }

    public function submitHandover(Request $request, ShiftHandover $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
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

    public function acknowledgeHandover(Request $request, ShiftHandover $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
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

    // ─── Pharmacy Orders + Stock CRUD ───────────────────────

    public function storePharmacyOrder(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_medication_id' => 'required|exists:client_medications,id',
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

        if (! isset($validated['batch_expiry']) && ! empty($validated['expiry_date'])) {
            $validated['batch_expiry'] = $validated['expiry_date'];
        }

        unset($validated['expiry_date']);
        $validated['status'] = 'draft';
        $validated['ordered_by'] = auth()->id();

        MedicationPharmacyOrder::create($validated);

        return redirect()->back();
    }

    public function updatePharmacyOrder(Request $request, MedicationPharmacyOrder $order)
    {
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

        if (! isset($validated['batch_expiry']) && ! empty($validated['expiry_date'])) {
            $validated['batch_expiry'] = $validated['expiry_date'];
        }

        unset($validated['expiry_date']);
        $order->update($validated);

        return redirect()->back();
    }

    public function advancePharmacyOrder(Request $request, MedicationPharmacyOrder $order)
    {
        $transitions = [
            'draft' => 'submitted',
            'submitted' => 'confirmed',
            'confirmed' => 'dispensed',
            'dispensed' => 'delivered',
        ];

        $nextStatus = $transitions[$order->status] ?? null;

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
                $request->validate([
                    'batch_number' => 'nullable|string|max:255',
                    'batch_expiry' => 'nullable|date',
                ]);
                $updateData['dispensed_at'] = now();
                $updateData['batch_number'] = $request->input('batch_number');
                $updateData['batch_expiry'] = $request->input('batch_expiry');
                break;
            case 'delivered':
                $request->validate([
                    'quantity_received' => 'nullable|integer|min:0',
                    'delivery_notes' => 'nullable|string',
                ]);
                $updateData['delivered_at'] = now();
                $updateData['received_by'] = auth()->id();
                $updateData['quantity_received'] = $request->input('quantity_received', $order->quantity_ordered);
                $updateData['delivery_notes'] = $request->input('delivery_notes');

                break;
        }

        DB::transaction(function () use ($order, $updateData, $nextStatus) {
            $order->update($updateData);

            if ($nextStatus === 'delivered') {
                $quantityReceived = $updateData['quantity_received'] ?? 0;
                if ($order->client_medication_id) {
                    $stock = ClientMedicationStock::firstOrCreate(
                        ['client_medication_id' => $order->client_medication_id],
                        ['on_hand' => 0, 'unit' => 'units']
                    );

                    if ($quantityReceived > 0) {
                        $stock->increment('on_hand', $quantityReceived);
                    }

                    $stock->fill([
                        'batch_number' => $order->batch_number,
                        'expiry_date' => $order->batch_expiry,
                        'supplier_name' => $order->pharmacy_name,
                        'last_counted_at' => now(),
                    ]);
                    $stock->save();
                }
            }
        });

        return redirect()->back();
    }

    public function receiveStock(Request $request)
    {
        $validated = $request->validate([
            'client_medication_id' => 'required|exists:client_medications,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:2000',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'scan_code' => 'nullable|string|max:255',
            'scan_source' => 'nullable|string|in:manual,scanner',
            'scan_verified' => 'nullable|boolean',
            'scan_match_source' => 'nullable|string|max:50',
            'client_request_uuid' => 'nullable|uuid',
            'captured_offline_at' => 'nullable|date',
            'origin_device_id' => 'nullable|string|max:255',
            'queued_offline' => 'nullable|boolean',
        ]);

        $scope = 'emar-stock-receive';

        if (
            $this->medicationSyncRequested($validated)
            && ($cached = $this->getCachedMedicationSyncResponse($scope, $validated))
        ) {
            return response()->json($cached);
        }

        $medication = ClientMedication::query()
            ->with(['client:id,first_name,last_name', 'stock'])
            ->findOrFail((int) $validated['client_medication_id']);

        $scanAudit = $this->verifyMedicationScanOrFail(
            $medication->client,
            $medication,
            $validated,
        );

        $stock = null;

        DB::transaction(function () use ($validated, &$stock) {
            $stock = ClientMedicationStock::firstOrCreate(
                ['client_medication_id' => $validated['client_medication_id']],
                ['on_hand' => 0, 'unit' => 'units']
            );

            $stock->increment('on_hand', $validated['quantity']);
            $stock->update([
                'last_counted_at' => now(),
                'notes' => $validated['notes'] ?? $stock->notes,
                'batch_number' => $validated['batch_number'] ?? $stock->batch_number,
                'expiry_date' => $validated['expiry_date'] ?? $stock->expiry_date,
            ]);
        });

        AuditLogger::log('medications.stock.receive', $stock, array_filter([
            'client_id' => $medication->client_id,
            'client_medication_id' => $medication->id,
            'quantity_received' => (int) $validated['quantity'],
            'scan_source' => $scanAudit['scan_source'] ?? null,
            'scan_match_source' => $scanAudit['scan_match_source'] ?? null,
            'scan_match_label' => $scanAudit['scan_match_label'] ?? null,
            'entered_code_suffix' => $scanAudit['scan_code_suffix'] ?? null,
            'batch_number' => $validated['batch_number'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        $payload = [
            'success' => true,
            'stock' => [
                'id' => $stock?->id,
                'client_medication_id' => $medication->id,
                'on_hand' => $stock?->on_hand,
                'unit' => $stock?->unit,
                'batch_number' => $stock?->batch_number,
                'expiry_date' => $stock?->expiry_date?->toDateString(),
            ],
        ];

        if ($this->medicationSyncRequested($validated)) {
            return response()->json(
                $this->rememberMedicationSyncResponse(
                    $scope,
                    $validated,
                    $this->withMedicationSync(
                        $payload,
                        $validated,
                        $this->medicationProcessedStatus($validated),
                    ),
                ),
            );
        }

        return redirect()->back()->with('success', 'Stock received successfully.');
    }

    public function updateStockItem(Request $request, ClientMedicationStock $stock)
    {
        $validated = $request->validate([
            'reorder_level' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:1',
            'expiry_date' => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
            'supplier_name' => 'nullable|string|max:255',
        ]);

        $stock->update($validated);

        return redirect()->back();
    }

    public function adjustStock(Request $request)
    {
        $validated = $request->validate([
            'client_medication_id' => 'required|exists:client_medications,id',
            'new_quantity' => 'required|integer|min:0',
            'reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($validated) {
            $stock = ClientMedicationStock::firstOrCreate(
                ['client_medication_id' => $validated['client_medication_id']],
                ['on_hand' => 0, 'unit' => 'units']
            );
            $stock->update([
                'on_hand' => $validated['new_quantity'],
                'last_counted_at' => now(),
                'notes' => 'Stock adjustment: '.$validated['reason'],
            ]);
        });

        return redirect()->back();
    }

    // ─── PRN Effectiveness CRUD ─────────────────────────────

    public function storePrnEffectiveness(Request $request)
    {
        $validated = $request->validate([
            'client_medication_administration_id' => 'required|exists:client_medication_administrations,id',
            'effectiveness' => 'required|in:effective,partially_effective,not_effective',
            'review_minutes_after' => 'nullable|integer|min:0',
            'observations' => 'nullable|string',
            'escalation_needed' => 'nullable|boolean',
            'escalation_action' => 'nullable|string',
        ]);

        // Get administration to populate client and medication IDs
        $administration = ClientMedicationAdministration::findOrFail($validated['client_medication_administration_id']);

        $validated['client_id'] = $administration->client_id;
        $validated['client_medication_id'] = $administration->client_medication_id;
        $validated['reviewed_by'] = auth()->id();
        $validated['reviewed_at'] = now();

        MedicationPrnEffectiveness::create($validated);

        return redirect()->back();
    }

    // ─── Medications CRUD ─────────────────────────────────

    public function storeMedication(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
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

        $canVerify = $this->canVerifyMedicationOrders($request->user());

        $medication = ClientMedication::create(array_merge(
            $this->buildMedicationPayload($validated),
            [
                'start_date' => $validated['start_date'] ?? now()->toDateString(),
                'state' => 'active',
                'active' => true,
                'approval_status' => $canVerify ? 'verified' : 'pending_verification',
                'verified_by' => $canVerify ? $request->user()?->id : null,
                'verified_at' => $canVerify ? now() : null,
            ],
        ));

        return redirect()->back();
    }

    public function updateMedication(Request $request, ClientMedication $medication)
    {
        $validated = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
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

        $payload = $this->buildMedicationPayload($validated);

        if (! $this->canVerifyMedicationOrders($request->user())) {
            $payload['approval_status'] = 'pending_verification';
            $payload['verified_by'] = null;
            $payload['verified_at'] = null;
            $payload['rejection_reason'] = null;
        }

        $medication->update($payload);

        return redirect()->back();
    }

    public function verifyMedication(Request $request, ClientMedication $medication)
    {
        abort_unless($this->canVerifyMedicationOrders($request->user()), 403);

        $medication->forceFill([
            'approval_status' => 'verified',
            'verified_by' => $request->user()?->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ])->save();

        return redirect()->back()->with('success', 'Medication order verified.');
    }

    public function rejectMedication(Request $request, ClientMedication $medication)
    {
        abort_unless($this->canVerifyMedicationOrders($request->user()), 403);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $medication->forceFill([
            'approval_status' => 'rejected',
            'verified_by' => null,
            'verified_at' => null,
            'rejection_reason' => $validated['rejection_reason'],
        ])->save();

        return redirect()->back()->with('success', 'Medication order rejected.');
    }

    public function discontinueMedication(Request $request, ClientMedication $medication)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $medication->update([
            'state' => 'ceased',
            'active' => false,
            'end_date' => now()->toDateString(),
            'ceased_reason' => $request->reason,
            'ceased_at' => now(),
        ]);

        return redirect()->back();
    }

    // ─── Controlled Drug Entry CRUD ──────────────────────

    public function storeCDEntry(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'medication_name' => 'required|string|max:255',
            'entry_type' => 'required|in:receipt,administration,disposal,transfer_in,transfer_out,balance_check,adjustment',
            'quantity' => 'required|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'on_hand_before' => 'nullable|numeric|min:0',
            'on_hand_after' => 'nullable|numeric|min:0',
            'balance_before' => 'nullable|numeric|min:0',
            'balance_after' => 'nullable|numeric|min:0',
            'witnessed_by' => 'required|exists:users,id|different:'.auth()->id(),
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'client_request_uuid' => 'nullable|uuid',
            'captured_offline_at' => 'nullable|date',
            'origin_device_id' => 'nullable|string|max:255',
            'queued_offline' => 'nullable|boolean',
        ]);

        $scope = 'emar-controlled-entry';

        if (
            $this->medicationSyncRequested($validated)
            && ($cached = $this->getCachedMedicationSyncResponse($scope, $validated))
        ) {
            return response()->json($cached);
        }

        $client = Client::findOrFail($validated['client_id']);
        $medication = $this->findControlledMedication($validated['client_id'], $validated['medication_name']);
        $onHandBefore = $validated['on_hand_before'] ?? $validated['balance_before'] ?? null;
        $onHandAfter = $validated['on_hand_after'] ?? $validated['balance_after'] ?? null;
        $unit = $validated['unit'] ?? $medication?->stock?->unit ?? 'tablets';

        if (
            $this->medicationSyncRequested($validated)
            && $medication?->stock
            && $onHandBefore !== null
            && (float) $medication->stock->on_hand !== (float) $onHandBefore
        ) {
            return response()->json(
                $this->buildMedicationConflictPayload(
                    $validated,
                    'Controlled drug stock changed before this entry could be applied. Please review the current balance before recording it again.',
                ),
                409,
            );
        }

        $entry = ClientControlledDrugEntry::create([
            'client_id' => $validated['client_id'],
            'client_medication_id' => $medication?->id,
            'service_context_id' => $client->service_context_id,
            'entry_type' => $validated['entry_type'],
            'quantity' => $validated['quantity'],
            'unit' => $unit,
            'batch_number' => $validated['batch_number'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'on_hand_before' => $onHandBefore,
            'on_hand_after' => $onHandAfter,
            'reason' => ucwords(str_replace('_', ' ', $validated['entry_type'])),
            'recorded_by' => auth()->id(),
            'witnessed_by' => $validated['witnessed_by'],
            'notes' => $validated['notes'],
            'recorded_at' => now(),
        ]);

        if ($medication && $onHandAfter !== null) {
            $stock = $medication->stock ?? $medication->stock()->create([
                'on_hand' => 0,
                'unit' => $unit,
            ]);
            $stock->update([
                'on_hand' => $onHandAfter,
                'unit' => $unit,
                'batch_number' => $validated['batch_number'] ?? $stock->batch_number,
                'expiry_date' => $validated['expiry_date'] ?? $stock->expiry_date,
                'last_counted_at' => now(),
            ]);
        }

        $refreshedStock = $medication?->stock()->first();

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
            return response()->json(
                $this->rememberMedicationSyncResponse(
                    $scope,
                    $validated,
                    $this->withMedicationSync(
                        $payload,
                        $validated,
                        $this->medicationProcessedStatus($validated),
                    ),
                ),
            );
        }

        return redirect()->back()->with('success', 'Controlled drug entry recorded.');
    }

    public function storeBalanceCheck(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'medication_name' => 'required|string|max:255',
            'on_hand_before' => 'nullable|numeric|min:0',
            'on_hand_after' => 'nullable|numeric|min:0',
            'expected_balance' => 'required|numeric|min:0',
            'actual_balance' => 'required|numeric|min:0',
            'witnessed_by' => 'required|exists:users,id|different:'.auth()->id(),
            'discrepancy_notes' => 'nullable|string|max:2000',
            'client_request_uuid' => 'nullable|uuid',
            'captured_offline_at' => 'nullable|date',
            'origin_device_id' => 'nullable|string|max:255',
            'queued_offline' => 'nullable|boolean',
        ]);

        $scope = 'emar-controlled-balance-check';

        if (
            $this->medicationSyncRequested($validated)
            && ($cached = $this->getCachedMedicationSyncResponse($scope, $validated))
        ) {
            return response()->json($cached);
        }

        $client = Client::findOrFail($validated['client_id']);
        $medication = $this->findControlledMedication($validated['client_id'], $validated['medication_name']);
        $expectedBalance = $validated['on_hand_before'] ?? $validated['expected_balance'];
        $actualBalance = $validated['on_hand_after'] ?? $validated['actual_balance'];

        if (
            $this->medicationSyncRequested($validated)
            && $medication?->stock
            && (float) $medication->stock->on_hand !== (float) $expectedBalance
        ) {
            return response()->json(
                $this->buildMedicationConflictPayload(
                    $validated,
                    'Controlled drug stock changed before this balance check could be applied. Please review the current balance before recording it again.',
                ),
                409,
            );
        }

        $entry = null;
        $discrepancy = null;

        DB::transaction(function () use ($validated, $client, $medication, $expectedBalance, $actualBalance, &$discrepancy, &$entry) {
            $entry = ClientControlledDrugEntry::create([
                'client_id' => $validated['client_id'],
                'client_medication_id' => $medication?->id,
                'service_context_id' => $client->service_context_id,
                'entry_type' => 'balance_check',
                'quantity' => $actualBalance,
                'unit' => $medication?->stock?->unit ?? 'units',
                'on_hand_before' => $expectedBalance,
                'on_hand_after' => $actualBalance,
                'reason' => 'Balance check',
                'recorded_by' => auth()->id(),
                'witnessed_by' => $validated['witnessed_by'],
                'notes' => $validated['discrepancy_notes'],
                'recorded_at' => now(),
            ]);

            if ($medication) {
                $stock = $medication->stock ?? $medication->stock()->create([
                    'on_hand' => 0,
                    'unit' => 'units',
                ]);
                $stock->update([
                    'on_hand' => $actualBalance,
                    'last_counted_at' => now(),
                ]);
            }

            if ($expectedBalance != $actualBalance) {
                $discrepancy = ClientControlledDrugDiscrepancy::create([
                    'client_id' => $validated['client_id'],
                    'client_medication_id' => $medication?->id,
                    'service_context_id' => $client->service_context_id,
                    'on_hand_before' => $expectedBalance,
                    'on_hand_after' => $actualBalance,
                    'difference' => $actualBalance - $expectedBalance,
                    'reason' => 'Balance check discrepancy',
                    'reported_by' => auth()->id(),
                    'witnessed_by' => $validated['witnessed_by'],
                    'notes' => $validated['discrepancy_notes'],
                    'status' => 'open',
                    'reported_at' => now(),
                ]);
            }
        });

        if ($discrepancy) {
            app(MedicationIncidentIntegrationService::class)
                ->handleControlledDiscrepancy($discrepancy, auth()->id());
        }

        $refreshedStock = $medication?->stock()->first();

        $payload = [
            'success' => true,
            'entry' => [
                'id' => $entry?->id,
                'entry_type' => $entry?->entry_type,
                'quantity' => $entry?->quantity,
                'recorded_at' => $entry?->recorded_at?->toIso8601String(),
            ],
            'discrepancy' => $discrepancy ? [
                'id' => $discrepancy->id,
                'status' => $discrepancy->status,
                'difference' => $discrepancy->difference,
                'reported_at' => $discrepancy->reported_at?->toIso8601String(),
            ] : null,
            'stock' => $refreshedStock ? [
                'client_medication_id' => $medication->id,
                'on_hand' => $refreshedStock->on_hand,
                'unit' => $refreshedStock->unit,
            ] : null,
        ];

        if ($this->medicationSyncRequested($validated)) {
            return response()->json(
                $this->rememberMedicationSyncResponse(
                    $scope,
                    $validated,
                    $this->withMedicationSync(
                        $payload,
                        $validated,
                        $this->medicationProcessedStatus($validated),
                    ),
                ),
            );
        }

        return redirect()->back()->with('success', 'Controlled drug balance check recorded.');
    }

    public function resolveDiscrepancy(Request $request, ClientControlledDrugDiscrepancy $discrepancy)
    {
        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:2000',
            'resolution_action' => 'nullable|string|max:255',
        ]);

        $discrepancy->update([
            'status' => 'closed',
            'resolution_notes' => trim(
                ($validated['resolution_action'] ? 'Action: '.$validated['resolution_action']."\n\n" : '')
                .$validated['resolution_notes']
            ),
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ]);

        app(MedicationIncidentIntegrationService::class)->resolveControlledDiscrepancy(
            $discrepancy,
            'Controlled drug discrepancy resolved.',
            auth()->id()
        );

        return redirect()->back();
    }

    public function dismissAlert(MedicationDashboardAlert $alert)
    {
        app(MedicationAlertService::class)->acknowledgeAlert($alert->id, auth()->id());

        return redirect()->back();
    }

    // ─── Handover Update/Delete ──────────────────────────

    public function updateHandover(Request $request, ShiftHandover $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
        $this->siteAccess()->assertCanAccessHandover(
            $auth,
            $handover,
            $this->handoverBypassPermissions(),
            'You are not authorized to access handovers for this site.',
        );
        abort_unless($handover->status === ShiftHandoverService::STATUS_DRAFT, 422, 'Only draft handovers can be edited.');
        abort_unless($this->handoverService->canSubmit($handover, $auth), 403);

        $validated = $request->validate([
            'incoming_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'incoming_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'handover_notes' => ['required', 'string', 'max:5000'],
            'client_mood' => ['nullable', 'string', 'max:255'],
            'medications_due_text' => ['nullable', 'string'],
            'follow_up_items_text' => ['nullable', 'string'],
            'incidents_to_note_text' => ['nullable', 'string'],
            'tasks_pending_text' => ['nullable', 'string'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $result = $this->handoverService->save($handover->outgoingShift, $auth, [
            'incoming_shift_id' => $validated['incoming_shift_id'] ?? null,
            'incoming_staff_id' => $validated['incoming_staff_id'] ?? null,
            'handover_notes' => $validated['handover_notes'],
            'client_mood' => $validated['client_mood'] ?? null,
            'medications_due' => $this->parseHandoverText($validated['medications_due_text'] ?? null),
            'follow_up_items' => $this->parseHandoverText($validated['follow_up_items_text'] ?? null),
            'incidents_to_note' => $this->parseHandoverText($validated['incidents_to_note_text'] ?? null),
            'tasks_pending' => $this->parseHandoverText($validated['tasks_pending_text'] ?? null),
            'submit' => (bool) ($validated['submit'] ?? false),
        ]);

        return redirect()->back()->with(
            'success',
            $result['action'] === 'draft_saved' ? 'Medication handover draft updated.' : 'Medication handover submitted.',
        );
    }

    public function destroyHandover(Request $request, ShiftHandover $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);
        $this->siteAccess()->assertCanAccessHandover(
            $auth,
            $handover,
            $this->handoverBypassPermissions(),
            'You are not authorized to access handovers for this site.',
        );
        abort_unless($handover->status === ShiftHandoverService::STATUS_DRAFT, 422, 'Only draft handovers can be deleted.');
        abort_unless($this->handoverService->canSubmit($handover, $auth), 403);

        $handover->delete();

        return redirect()->back()->with('success', 'Medication handover draft deleted.');
    }

    // ─── Destruction Delete ──────────────────────────────

    public function destroyDestruction(MedicationDestruction $destruction)
    {
        $destruction->delete();

        return redirect()->back();
    }

    // ─── Medications CSV Import ──────────────────────────

    public function importMedications(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return redirect()->back()->withErrors(['csv_file' => 'Unable to read the CSV file.']);
        }

        $imported = 0;
        $skipped = 0;
        $rowNumber = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Skip header row
            if ($rowNumber === 1 && stripos($row[0] ?? '', 'client') !== false) {
                continue;
            }

            // Expect: client_name, medication_name, dose, frequency, route
            if (count($row) < 4) {
                $skipped++;

                continue;
            }

            $clientName = trim($row[0] ?? '');
            $medicationName = trim($row[1] ?? '');
            $dose = trim($row[2] ?? '');
            $frequency = trim($row[3] ?? '');
            $route = trim($row[4] ?? 'oral');

            if (! $clientName || ! $medicationName || ! $dose || ! $frequency) {
                $skipped++;

                continue;
            }

            // Try to match client by name ("Last, First" or "First Last")
            $client = null;
            if (str_contains($clientName, ',')) {
                [$lastName, $firstName] = array_map('trim', explode(',', $clientName, 2));
                $client = Client::where('last_name', $lastName)
                    ->where('first_name', $firstName)
                    ->first();
            } else {
                $parts = explode(' ', $clientName, 2);
                if (count($parts) === 2) {
                    $client = Client::where('first_name', $parts[0])
                        ->where('last_name', $parts[1])
                        ->first();
                }
            }

            if (! $client) {
                $skipped++;

                continue;
            }

            // Calculate dose times from frequency
            $doseTimes = DoseSchedulingService::calculateDoseTimes($frequency);

            $canVerify = $this->canVerifyMedicationOrders($request->user());

            ClientMedication::create([
                'client_id' => $client->id,
                'name' => $medicationName,
                'dosage' => $dose,
                'frequency' => $frequency,
                'dose_times' => $doseTimes,
                'route' => $route,
                'state' => 'active',
                'active' => true,
                'start_date' => now()->toDateString(),
                'approval_status' => $canVerify ? 'verified' : 'pending_verification',
                'verified_by' => $canVerify ? $request->user()?->id : null,
                'verified_at' => $canVerify ? now() : null,
            ]);

            $imported++;
        }

        fclose($handle);

        return redirect()->back();
    }
}
