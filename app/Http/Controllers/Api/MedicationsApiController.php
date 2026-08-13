<?php

namespace App\Http\Controllers\Api;

use App\Enums\Medication\SafetyOverrideReason;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationAllergy;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationError;
use App\Models\MedicationMarAttachment;
use App\Models\TimelineEvent;
use App\Services\AuditLogger;
use App\Services\EnhancedMarService;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MarScheduleService;
use App\Services\MedicationReportingService;
use App\Services\MedicationScanVerificationService;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class MedicationsApiController extends Controller
{
    public function __construct(
        protected EnhancedMarService $marService,
        protected MedicationSafetyService $safetyService,
        protected MedicationReportingService $reportingService,
        protected MedicationIncidentIntegrationService $incidentService,
        protected MedicationAlertService $alertService,
        protected MedicationScanVerificationService $scanVerificationService,
        protected MarScheduleService $scheduleService,
    ) {}

    private function idempotencyKey(string $scope, string $requestUuid): string
    {
        return "emar:idempotency:{$scope}:{$requestUuid}";
    }

    private function syncPayload(array $data, string $status, bool $duplicate = false, ?string $message = null): array
    {
        return array_filter([
            'status' => $status,
            'duplicate' => $duplicate,
            'queued_offline' => (bool) ($data['queued_offline'] ?? false),
            'client_request_uuid' => $data['client_request_uuid'] ?? null,
            'captured_offline_at' => $data['captured_offline_at'] ?? null,
            'origin_device_id' => $data['origin_device_id'] ?? null,
            'message' => $message,
        ], fn ($value) => $value !== null);
    }

    private function withSync(array $payload, array $data, string $status, bool $duplicate = false, ?string $message = null): array
    {
        $payload['sync'] = $this->syncPayload($data, $status, $duplicate, $message);

        return $payload;
    }

    private function getCachedIdempotentResponse(string $scope, array $data): ?array
    {
        $requestUuid = $data['client_request_uuid'] ?? null;
        if (!$requestUuid) {
            return null;
        }

        $payload = Cache::get($this->idempotencyKey($scope, $requestUuid));
        if (!$payload) {
            return null;
        }

        return $this->withSync(
            $payload,
            $data,
            'duplicate',
            true,
            'This medication request was already processed.',
        );
    }

    private function rememberIdempotentResponse(string $scope, array $data, array $payload): array
    {
        $requestUuid = $data['client_request_uuid'] ?? null;
        if ($requestUuid) {
            Cache::put($this->idempotencyKey($scope, $requestUuid), $payload, now()->addDays(7));
        }

        return $payload;
    }

    private function buildConflictPayload(array $data, string $message): array
    {
        return $this->withSync([
            'success' => false,
            'error' => $message,
        ], $data, 'conflict', false, $message);
    }

    private function attachmentTargetTypeForModel(Model $target): string
    {
        return match ($target::class) {
            ClientMedicationAdministration::class => $target->is_correction ? 'correction' : 'administration',
            ClientControlledDrugDiscrepancy::class => 'discrepancy',
            ControlledDrugLossReport::class => 'loss_report',
            MedicationError::class => 'error',
            default => 'administration',
        };
    }

    private function attachmentTargetTypeForAttachment(MedicationMarAttachment $attachment): string
    {
        if ($attachment->attachable) {
            return $this->attachmentTargetTypeForModel($attachment->attachable);
        }

        if ($attachment->administration) {
            return $attachment->administration->is_correction ? 'correction' : 'administration';
        }

        return 'administration';
    }

    private function resolveAttachmentTarget(Client $client, string $targetType, int $targetId): Model
    {
        $target = match ($targetType) {
            'administration', 'correction' => ClientMedicationAdministration::query()
                ->with('attachments.uploadedBy:id,name')
                ->findOrFail($targetId),
            'discrepancy' => ClientControlledDrugDiscrepancy::query()
                ->with('attachments.uploadedBy:id,name')
                ->findOrFail($targetId),
            'loss_report' => ControlledDrugLossReport::query()
                ->with('attachments.uploadedBy:id,name')
                ->findOrFail($targetId),
            'error' => MedicationError::query()
                ->with('attachments.uploadedBy:id,name')
                ->findOrFail($targetId),
            default => throw ValidationException::withMessages([
                'target_type' => 'Unsupported medication evidence target.',
            ]),
        };

        abort_unless((int) $target->client_id === (int) $client->id, 404);

        if ($targetType === 'correction') {
            abort_unless($target instanceof ClientMedicationAdministration && $target->is_correction, 404);
        }

        return $target;
    }

    private function assertCanManageSupportingAttachment(Request $request, string $targetType): void
    {
        $user = $request->user();

        $canManage = match ($targetType) {
            'administration', 'correction', 'error' => $user->canDo('medications.administer.record')
                || $user->canDo('medications.administer.correct')
                || $user->canDo('clients.update'),
            'discrepancy', 'loss_report' => $user->canDo('medications.controlled.record')
                || $user->canDo('medications.controlled.view')
                || $user->canDo('clients.update'),
            default => false,
        };

        abort_unless($canManage, 403);
    }

    private function canDeleteSupportingAttachment(Request $request, MedicationMarAttachment $attachment): bool
    {
        $user = $request->user();
        $targetType = $this->attachmentTargetTypeForAttachment($attachment);

        $canManage = match ($targetType) {
            'administration', 'correction', 'error' => $user->canDo('medications.administer.correct')
                || $user->canDo('clients.update'),
            'discrepancy', 'loss_report' => $user->canDo('medications.controlled.record')
                || $user->canDo('clients.update'),
            default => false,
        };

        return $canManage || (int) $attachment->uploaded_by === (int) $user->id;
    }

    private function serializeAttachment(MedicationMarAttachment $attachment, bool $canDelete = false): array
    {
        $attachment->loadMissing([
            'uploadedBy:id,name',
            'attachable',
            'administration:id,client_id,is_correction',
        ]);

        $targetType = $this->attachmentTargetTypeForAttachment($attachment);
        $downloadUrl = in_array($targetType, ['administration', 'correction'], true)
            ? route('api.medications.attachments.download', [
                'client' => $attachment->client_id,
                'administration' => $attachment->client_medication_administration_id,
                'attachment' => $attachment->id,
            ])
            : route('api.medications.supporting_attachments.download', [
                'client' => $attachment->client_id,
                'attachment' => $attachment->id,
            ]);

        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
            'formatted_size' => $attachment->formatted_size,
            'description' => $attachment->description,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'uploaded_by' => $attachment->uploadedBy?->name,
            'download_url' => $downloadUrl,
            'target_type' => $targetType,
            'target_id' => $attachment->attachable_id ?? $attachment->client_medication_administration_id,
            'can_delete' => $canDelete,
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
        array $data,
        string $errorKey = 'scan_code'
    ): array {
        if (! ($data['scan_verified'] ?? false) || blank($data['scan_code'] ?? null)) {
            throw ValidationException::withMessages([
                $errorKey => 'Verify the medication code before continuing.',
            ]);
        }

        $result = $this->scanVerificationService->verify(
            $client,
            $medication,
            (string) $data['scan_code']
        );

        if (! $result['matched']) {
            throw ValidationException::withMessages([
                $errorKey => $result['message'],
            ]);
        }

        if (
            filled($data['scan_match_source'] ?? null)
            && ($data['scan_match_source'] ?? null) !== $result['match_source']
        ) {
            throw ValidationException::withMessages([
                $errorKey => 'The medication verification needs to be repeated.',
            ]);
        }

        return [
            'scan_source' => $data['scan_source'] ?? 'manual',
            'scan_match_source' => $result['match_source'],
            'scan_match_label' => $result['match_label'],
            'scan_code_suffix' => substr(
                $this->scanVerificationService->normalize((string) $data['scan_code']),
                -6
            ),
        ];
    }

    /**
     * Get enhanced MAR data
     */
    public function getMar(Request $request, Client $client)
    {
        $this->authorize('viewMedications', $client);

        $date = $this->scheduleService->dateFromInput($request->input('date'));

        $activeShiftId = $request->input('shift_id');

        $marData = $this->marService->build(
            $client,
            $date,
            now($this->scheduleService->workerTimezone()),
            $activeShiftId,
        );

        // Add permissions
        $user = $request->user();
        $marData['can'] = [
            'record' => $user->canDo('medications.administer.record')
                || $user->canDo('clients.update')
                || $user->canDo('medications.orders.manage'),
            'override_safety' => $user->canDo('medications.administer.override_safety'),
            'correct' => $user->canDo('medications.administer.correct') || $user->canDo('clients.update'),
            'witness' => $user->canDo('medications.controlled.witness'),
        ];

        // Add active alerts for this client
        $marData['alerts'] = MedicationDashboardAlert::where('client_id', $client->id)
            ->where('status', 'active')
            ->orderByDesc('severity')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'message' => $a->message,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        // Add controlled drug discrepancies
        $marData['controlled_discrepancies'] = \App\Models\ClientControlledDrugDiscrepancy::where('client_id', $client->id)
            ->whereIn('status', ['open', 'under_review'])
            ->with('medication:id,name')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'medication_name' => $d->medication?->name,
                'difference' => $d->difference,
                'reason' => $d->reason,
                'reported_at' => $d->reported_at?->toIso8601String(),
            ]);

        return response()->json($marData);
    }

    /**
     * Perform safety check
     */
    public function safetyCheck(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $check = $this->safetyService->performSafetyCheck($client, $medication);

        return response()->json([
            ...$check,
            'can_override_safety' => $request->user()->canDo('medications.administer.override_safety'),
            'override_reason_options' => SafetyOverrideReason::options(),
        ]);
    }

    /**
     * Get PRN history
     */
    public function getPrnHistory(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $hours = $request->input('hours', 24);
        $history = $this->safetyService->getPrnHistory($medication, $hours);

        return response()->json($history);
    }

    public function getScanCode(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        return response()->json($this->buildMedicationScanPayload($client, $medication));
    }

    public function getScanCodeSvg(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $payload = $this->scanVerificationService->payload($client, $medication);

        $result = new Builder(
            writer: new SvgWriter(),
            data: $payload['qr_value'],
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 320,
            margin: 10,
        );

        $svg = $result->build();

        return Response::make($svg->getString(), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function verifyScan(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'in:manual,scanner'],
        ]);

        $result = $this->scanVerificationService->verify($client, $medication, $data['code']);

        AuditLogger::log('medications.scan.verify', $medication, [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'matched' => $result['matched'],
            'scan_source' => $data['source'] ?? 'manual',
            'match_source' => $result['match_source'],
            'match_label' => $result['match_label'],
            'entered_code_suffix' => substr($this->scanVerificationService->normalize($data['code']), -6),
        ]);

        return response()->json([
            ...$result,
            'scan_reference' => $this->scanVerificationService->payload($client, $medication),
        ], $result['matched'] ? 200 : 422);
    }

    public function uploadAdministrationAttachment(
        Request $request,
        Client $client,
        ClientMedicationAdministration $administration
    ) {
        $this->authorize('viewMedications', $client);
        abort_unless($administration->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless(
            $user->canDo('medications.administer.record')
            || $user->canDo('medications.administer.correct')
            || $user->canDo('clients.update'),
            403
        );

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('file');
        $path = $file->store('medication_mar_attachments');

        $attachment = MedicationMarAttachment::create([
            'client_medication_administration_id' => $administration->id,
            'client_id' => $client->id,
            'attachable_type' => ClientMedicationAdministration::class,
            'attachable_id' => $administration->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'description' => $data['description'] ?? null,
            'uploaded_by' => $user->id,
        ]);

        $attachment->load('uploadedBy:id,name');

        AuditLogger::log('medications.administration.attachment.uploaded', $administration, [
            'attachment_id' => $attachment->id,
            'client_id' => $client->id,
            'file_name' => $attachment->file_name,
        ]);

        return response()->json([
            'success' => true,
            'attachment' => $this->serializeAttachment($attachment, true),
        ]);
    }

    public function uploadSupportingAttachment(Request $request, Client $client)
    {
        $this->authorize('viewMedications', $client);

        $data = $request->validate([
            'target_type' => ['required', 'string', 'in:administration,correction,discrepancy,loss_report,error'],
            'target_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->assertCanManageSupportingAttachment($request, $data['target_type']);

        $target = $this->resolveAttachmentTarget(
            $client,
            $data['target_type'],
            (int) $data['target_id'],
        );

        $file = $request->file('file');
        $path = $file->store('medication_mar_attachments');

        $attachment = MedicationMarAttachment::create([
            'client_medication_administration_id' => $target instanceof ClientMedicationAdministration
                ? $target->id
                : null,
            'client_id' => $client->id,
            'attachable_type' => $target::class,
            'attachable_id' => $target->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'description' => $data['description'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        $attachment->load('uploadedBy:id,name');

        AuditLogger::log('medications.supporting_attachment.uploaded', $target, [
            'attachment_id' => $attachment->id,
            'client_id' => $client->id,
            'target_type' => $data['target_type'],
            'file_name' => $attachment->file_name,
        ]);

        return response()->json([
            'success' => true,
            'attachment' => $this->serializeAttachment($attachment, true),
        ]);
    }

    public function downloadAdministrationAttachment(
        Request $request,
        Client $client,
        ClientMedicationAdministration $administration,
        MedicationMarAttachment $attachment
    ) {
        $this->authorize('viewMedications', $client);
        abort_unless($administration->client_id === $client->id, 404);
        abort_unless($attachment->client_medication_administration_id === $administration->id, 404);
        abort_unless($attachment->client_id === $client->id, 404);

        return Storage::download($attachment->file_path, $attachment->file_name);
    }

    public function downloadSupportingAttachment(
        Request $request,
        Client $client,
        MedicationMarAttachment $attachment
    ) {
        $this->authorize('viewMedications', $client);
        abort_unless($attachment->client_id === $client->id, 404);

        $attachment->loadMissing('attachable');
        abort_unless($attachment->attachable, 404);
        abort_unless((int) $attachment->attachable->client_id === (int) $client->id, 404);

        return Storage::download($attachment->file_path, $attachment->file_name);
    }

    public function deleteAdministrationAttachment(
        Request $request,
        Client $client,
        ClientMedicationAdministration $administration,
        MedicationMarAttachment $attachment
    ) {
        $this->authorize('viewMedications', $client);
        abort_unless($administration->client_id === $client->id, 404);
        abort_unless($attachment->client_medication_administration_id === $administration->id, 404);
        abort_unless($attachment->client_id === $client->id, 404);

        abort_unless($this->canDeleteSupportingAttachment($request, $attachment), 403);

        AuditLogger::log('medications.administration.attachment.deleted', $administration, [
            'attachment_id' => $attachment->id,
            'client_id' => $client->id,
            'file_name' => $attachment->file_name,
        ]);

        $attachment->delete();

        return response()->json(['success' => true]);
    }

    public function deleteSupportingAttachment(
        Request $request,
        Client $client,
        MedicationMarAttachment $attachment
    ) {
        $this->authorize('viewMedications', $client);
        abort_unless($attachment->client_id === $client->id, 404);

        $attachment->loadMissing('attachable');
        abort_unless($attachment->attachable, 404);
        abort_unless((int) $attachment->attachable->client_id === (int) $client->id, 404);
        abort_unless($this->canDeleteSupportingAttachment($request, $attachment), 403);

        AuditLogger::log('medications.supporting_attachment.deleted', $attachment->attachable, [
            'attachment_id' => $attachment->id,
            'client_id' => $client->id,
            'target_type' => $this->attachmentTargetTypeForAttachment($attachment),
            'file_name' => $attachment->file_name,
        ]);

        $attachment->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Record medication administration
     */
    public function recordAdministration(
        Request $request,
        Client $client,
        ClientMedication $medication
    ) {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.administer.record')
                || $user->canDo('clients.update')
                || $user->canDo('medications.orders.manage'),
            403,
            'You do not have permission to record medication administrations.'
        );

        $data = $request->validate([
            'status' => ['required', 'in:given,refused,missed,withheld'],
            'reason' => ['nullable', 'string', 'max:500'],
            'reason_code' => ['nullable', 'string', 'max:60'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'quantity_administered' => ['nullable', 'numeric', 'min:0.01', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date'],
            'administered_at' => ['nullable', 'date'],
            'witnessed_by' => ['nullable', 'integer', 'exists:users,id'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'outcome' => ['nullable', 'string', 'in:effective,ineffective,adverse_reaction'],
            'site' => ['nullable', 'string', 'max:100'],
            'blood_glucose_level' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'pulse_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
            // The legacy client boolean was an authority-confusion flaw. An
            // override is now a structured, capability-checked request.
            'override_safety' => ['prohibited'],
            'safety_override' => ['sometimes', 'array:reason_code,reason', 'required_array_keys:reason_code,reason'],
            'safety_override.reason_code' => ['required_with:safety_override', new Enum(SafetyOverrideReason::class)],
            'safety_override.reason' => ['required_with:safety_override', 'string', 'min:10', 'max:1000'],
            'override_window' => ['nullable', 'boolean'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:255'],
            'queued_offline' => ['nullable', 'boolean'],
            'scan_code' => ['nullable', 'string', 'max:255'],
            'scan_source' => ['nullable', 'string', 'in:manual,scanner'],
            'scan_verified' => ['nullable', 'boolean'],
            'scan_match_source' => ['nullable', 'string', 'max:50'],
        ]);

        if (array_key_exists('safety_override', $data)) {
            abort_unless(
                $user->canDo('medications.administer.override_safety'),
                403,
                'You do not have permission to authorise a blocked medication safety check.'
            );
        }

        if ($cached = $this->getCachedIdempotentResponse('administration', $data)) {
            return response()->json($cached);
        }

        // Require a structured reason for non-given administrations. Free text
        // remains available for detail but is no longer the primary audit code.
        if ($data['status'] !== 'given' && empty($data['reason_code'])) {
            return response()->json([
                'success' => false,
                'error' => 'Select the reason this medication was not given.',
                'error_field' => 'reason_code',
            ], 422);
        }

        // Require reason for PRN given
        if ($medication->is_prn && $data['status'] === 'given' && empty($data['reason'])) {
            return response()->json([
                'success' => false,
                'error' => 'PRN indication (reason) is required for as-needed medication.',
            ], 422);
        }

        // Controlled drug witness validation
        if ($medication->requiresWitness() && $data['status'] === 'given') {
            if (empty($data['witnessed_by'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Witness is required for this medication.',
                    'error_field' => 'witnessed_by',
                ], 422);
            }

            if ($data['witnessed_by'] === $user->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Witness must be a different user.',
                    'error_field' => 'witnessed_by',
                ], 422);
            }

            $witness = \App\Models\User::find($data['witnessed_by']);
            if (!$witness || !$witness->canDo('medications.controlled.witness')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Selected witness is not authorized to witness controlled drug administrations.',
                    'error_field' => 'witnessed_by',
                ], 422);
            }
        }

        if (($data['queued_offline'] ?? false) && !$medication->is_prn && !empty($data['scheduled_for'])) {
            $isDurableReplay = filled($data['client_request_uuid'] ?? null)
                && ClientMedicationAdministration::withTrashed()
                    ->where('client_id', $client->id)
                    ->where('client_medication_id', $medication->id)
                    ->where('client_request_uuid', $data['client_request_uuid'])
                    ->exists();

            if (! $isDurableReplay) {
                $scheduledFor = $this->scheduleService->parseWorkerDateTime((string) $data['scheduled_for']);
                [$slotStartUtc, $slotEndUtc] = $this->scheduleService->utcSlotWindow($scheduledFor);
                $conflictingAdministration = ClientMedicationAdministration::query()
                ->where('client_id', $client->id)
                ->where('client_medication_id', $medication->id)
                ->whereBetween('scheduled_for', [$slotStartUtc, $slotEndUtc])
                ->latest('id')
                ->first();

                if ($conflictingAdministration) {
                    return response()->json(
                        $this->buildConflictPayload(
                            $data,
                            'Medication state changed before this offline administration could sync. Supervisor review is required.',
                        ),
                        409
                    );
                }
            }
        }

        if (! empty($data['scan_code'])) {
            $scanResult = $this->scanVerificationService->verify($client, $medication, $data['scan_code']);

            AuditLogger::log('medications.scan.verify', $medication, [
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'matched' => $scanResult['matched'],
                'scan_source' => $data['scan_source'] ?? 'manual',
                'match_source' => $scanResult['match_source'],
                'match_label' => $scanResult['match_label'],
                'entered_code_suffix' => substr($this->scanVerificationService->normalize($data['scan_code']), -6),
            ]);

            if (! $scanResult['matched']) {
                return response()->json([
                    'success' => false,
                    'error' => $scanResult['message'],
                ], 422);
            }
        }

        $result = $this->marService->recordAdministration(
            $client,
            $medication,
            $data,
            $user->id,
            $data['shift_id'] ?? null
        );

        if (!$result['success']) {
            // PRN over-limit incidents are raised inside EnhancedMarService
            // (shared across all recording surfaces), so no handling here.
            return response()->json($this->withSync($result, $data, 'rejected', false, $result['error'] ?? null), 422);
        }

        $administration = $result['administration'];

        if ($result['duplicate'] ?? false) {
            $payload = $this->withSync([
                'success' => true,
                'administration' => [
                    'id' => $administration->id,
                    'status' => $administration->status,
                    'administered_at' => $administration->administered_at?->toIso8601String(),
                ],
                'safety_check' => $result['safety_check'] ?? null,
            ], $data, 'duplicate', true, 'This medication request was already processed.');

            return response()->json(
                $this->rememberIdempotentResponse('administration', $data, $payload),
            );
        }

        // Timeline event
        $statusLabel = ucfirst(str_replace('_', ' ', $data['status']));
        app(\App\Services\Timeline\TimelineEmitter::class)->record([
            'source_type' => ClientMedicationAdministration::class,
            'source_id' => $administration->id,
            'occurred_at' => $administration->administered_at ?? now(),
            'type' => 'medication_' . $data['status'],
            'actor_user_id' => $user->id,
            'client_id' => $client->id,
            'shift_id' => $data['shift_id'] ?? null,
            'site_id' => $client->site_id,
            'subject' => $statusLabel . ': ' . $medication->name . ($medication->dosage ? ' ' . $medication->dosage : ''),
            'body' => $data['notes'] ?? null,
            'meta' => array_filter([
                'medication_name' => $medication->name,
                'dosage' => $medication->dosage,
                'dose_given' => $data['dose_given'] ?? null,
                'status' => $data['status'],
                'reason' => $data['reason'] ?? null,
                'reason_code' => $data['reason_code'] ?? null,
                'witnessed_by' => $data['witnessed_by'] ?? null,
                'witness_method' => $administration->witness_method,
                'pulse_bpm' => $data['pulse_bpm'] ?? null,
                'blood_pressure_systolic' => $data['blood_pressure_systolic'] ?? null,
                'blood_pressure_diastolic' => $data['blood_pressure_diastolic'] ?? null,
                'client_request_uuid' => $data['client_request_uuid'] ?? null,
                'captured_offline_at' => $data['captured_offline_at'] ?? null,
                'origin_device_id' => $data['origin_device_id'] ?? null,
                'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                'scan_source' => $data['scan_source'] ?? null,
                'scan_match_source' => $data['scan_match_source'] ?? null,
                'scan_verified' => (bool) ($data['scan_verified'] ?? false),
            ]),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $user->id,
        ]);

        // Missed/refused/late incident creation now lives in
        // EnhancedMarService::recordAdministration so every recording surface
        // (MAR wizard, My Day, guided rounds) raises the same incidents.

        // Generate fresh alerts
        $this->alertService->generateClientAlerts($client);

        $payload = $this->withSync([
            'success' => true,
            'administration' => [
                'id' => $administration->id,
                'status' => $administration->status,
                'administered_at' => $administration->administered_at?->toIso8601String(),
            ],
            'safety_check' => $result['safety_check'] ?? null,
        ], $data, ($data['queued_offline'] ?? false) ? 'synced' : 'processed');

        return response()->json(
            $this->rememberIdempotentResponse('administration', $data, $payload)
        );
    }

    /**
     * Correct an administration
     */
    public function correctAdministration(
        Request $request,
        Client $client,
        ClientMedicationAdministration $administration
    ) {
        $this->authorize('viewMedications', $client);
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.administer.correct') || $user->canDo('clients.update'),
            403,
            'You do not have permission to correct medication administrations.'
        );

        abort_unless($administration->client_id === $client->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:given,refused,missed,withheld'],
            'reason' => ['nullable', 'string', 'max:500'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'administered_at' => ['nullable', 'date'],
            'correction_reason' => ['required', 'string', 'max:500'],
        ]);

        // Check correction time window
        $minutesSince = $administration->created_at->diffInMinutes(now());
        if ($minutesSince > 30 && empty($data['correction_reason'])) {
            return response()->json([
                'success' => false,
                'error' => 'Correction reason is required when correcting after 30 minutes.',
            ], 422);
        }

        // Create correction record
        $correction = $administration->replicate([
            'id',
            'created_at',
            'updated_at',
        ]);
        $correction->is_correction = true;
        $correction->corrected_of_id = $administration->id;
        $correction->correction_reason = $data['correction_reason'];
        $correction->status = $data['status'];
        $correction->reason = $data['reason'] ?? $administration->reason;
        $correction->dose_given = $data['dose_given'] ?? $administration->dose_given;
        $correction->notes = $data['notes'] ?? $administration->notes;
        $correction->administered_at = $data['administered_at'] ?? $administration->administered_at;
        $correction->administered_by = $user->id;
        $correction->save();

        // Timeline event
        app(\App\Services\Timeline\TimelineEmitter::class)->record([
            'source_type' => ClientMedicationAdministration::class,
            'source_id' => $correction->id,
            'occurred_at' => now(),
            'type' => 'medication_correction',
            'actor_user_id' => $user->id,
            'client_id' => $client->id,
            'shift_id' => $administration->shift_id,
            'site_id' => $client->site_id,
            'subject' => 'Medication corrected: ' . ($administration->medication?->name ?? 'Unknown'),
            'body' => $data['correction_reason'],
            'meta' => array_filter([
                'corrected_from' => $administration->status,
                'corrected_to' => $data['status'],
                'original_administration_id' => $administration->id,
            ]),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $user->id,
        ]);

        // Handle incident for significant corrections
        if ($minutesSince > 240) { // 4 hours
            $this->incidentService->handleUnsafeCorrection($administration, $data, $user->id, $correction);
        }

        return response()->json([
            'success' => true,
            'correction' => [
                'id' => $correction->id,
                'status' => $correction->status,
                'is_correction' => true,
            ],
        ]);
    }

    /**
     * Get dashboard alerts
     */
    public function getDashboardAlerts(Request $request, ?Client $client = null)
    {
        $user = $request->user();

        if ($client) {
            $this->authorize('viewMedications', $client);
            $alerts = MedicationDashboardAlert::forClient($client->id)
                ->active()
                ->with(['medication:id,name', 'client:id,first_name,last_name'])
                ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
                ->orderByDesc('created_at')
                ->get();
        } else {
            // Global alerts - check permissions
            abort_unless(
                $user->canDo('medications.view') || $user->canDo('clients.viewAny'),
                403
            );

            $alerts = MedicationDashboardAlert::active()
                ->with(['medication:id,name', 'client:id,first_name,last_name'])
                ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        }

        return response()->json([
            'alerts' => $alerts->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->alert_type,
                'type_label' => $a->alertTypeLabel,
                'severity' => $a->severity,
                'severity_info' => $a->severityInfo,
                'message' => $a->message,
                'client' => $a->client ? [
                    'id' => $a->client->id,
                    'name' => trim("{$a->client->first_name} {$a->client->last_name}"),
                ] : null,
                'medication' => $a->medication ? [
                    'id' => $a->medication->id,
                    'name' => $a->medication->name,
                ] : null,
                'created_at' => $a->created_at?->toIso8601String(),
                'status' => $a->status,
            ]),
        ]);
    }

    /**
     * Acknowledge alert
     */
    public function acknowledgeAlert(Request $request, int $alertId)
    {
        $user = $request->user();

        $alert = MedicationDashboardAlert::findOrFail($alertId);

        // Verify user can access this client's medication data
        $client = Client::findOrFail($alert->client_id);
        $this->authorize('viewMedications', $client);

        $success = $this->alertService->acknowledgeAlert($alertId, $user->id);

        return response()->json([
            'success' => $success,
        ]);
    }

    /**
     * Resolve alert
     */
    public function resolveAlert(Request $request, int $alertId)
    {
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.administer.correct') || $user->canDo('clients.update'),
            403
        );

        $alert = MedicationDashboardAlert::findOrFail($alertId);

        // Verify user can access this client's medication data
        $client = Client::findOrFail($alert->client_id);
        $this->authorize('viewMedications', $client);

        $data = $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $success = $this->alertService->resolveAlert($alertId, $data['resolution_notes'] ?? null);

        return response()->json([
            'success' => $success,
        ]);
    }

    /**
     * Get dashboard widgets
     */
    public function getDashboardWidgets(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.view') || $user->canDo('clients.viewAny'),
            403
        );

        $clientId = $request->input('client_id');

        // If not global view, restrict to assigned clients
        if (!$user->canDo('clients.viewAny') && $clientId) {
            $assignedIds = $user->assignedClients()->pluck('clients.id')->toArray();
            if (!in_array((int) $clientId, $assignedIds)) {
                abort(403);
            }
        }

        $widgets = $this->alertService->getGlobalDashboardWidgets($clientId);

        return response()->json($widgets);
    }

    /**
     * Get medication reports
     */
    public function getReports(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.reports.export') || $user->canDo('reports.viewAny'),
            403
        );

        $reportType = $request->input('type', 'mar');
        $clientId = $request->input('client_id');
        $dateFrom = $request->input('date_from') ? Carbon::parse($request->input('date_from')) : null;
        $dateTo = $request->input('date_to') ? Carbon::parse($request->input('date_to')) : null;

        $report = match ($reportType) {
            'mar' => $this->reportingService->exportMar($clientId, $dateFrom, $dateTo),
            'prn' => $this->reportingService->reportPrnUsage($clientId, $dateFrom, $dateTo),
            'missed' => $this->reportingService->reportMissedDoses($clientId, $dateFrom, $dateTo),
            'late' => $this->reportingService->reportLateDoses($clientId, $dateFrom, $dateTo),
            'controlled_balance' => $this->reportingService->reportControlledDrugBalance($clientId),
            'controlled_discrepancies' => $this->reportingService->reportControlledDiscrepancies($clientId, null, $dateFrom, $dateTo),
            'changes' => $this->reportingService->reportMedicationChanges($clientId, null, $dateFrom, $dateTo),
            'incidents' => $this->reportingService->reportMedicationIncidents($clientId, $dateFrom, $dateTo),
            'audit' => $this->reportingService->generateAuditReport($clientId, $dateFrom, $dateTo),
            default => throw new \InvalidArgumentException('Invalid report type'),
        };

        return response()->json($report);
    }

    /**
     * Export report to CSV
     */
    public function exportReportCsv(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.reports.export') || $user->canDo('reports.viewAny'),
            403
        );

        $reportType = $request->input('type', 'mar');
        $clientId = $request->input('client_id');
        $dateFrom = $request->input('date_from') ? Carbon::parse($request->input('date_from')) : null;
        $dateTo = $request->input('date_to') ? Carbon::parse($request->input('date_to')) : null;

        $report = match ($reportType) {
            'mar' => $this->reportingService->exportMar($clientId, $dateFrom, $dateTo),
            'prn' => $this->reportingService->reportPrnUsage($clientId, $dateFrom, $dateTo),
            'missed' => $this->reportingService->reportMissedDoses($clientId, $dateFrom, $dateTo),
            'late' => $this->reportingService->reportLateDoses($clientId, $dateFrom, $dateTo),
            'controlled_discrepancies' => $this->reportingService->reportControlledDiscrepancies($clientId, null, $dateFrom, $dateTo),
            'changes' => $this->reportingService->reportMedicationChanges($clientId, null, $dateFrom, $dateTo),
            default => throw new \InvalidArgumentException('Invalid report type'),
        };

        $filename = "medication_report_{$reportType}_" . now()->format('Ymd_His') . '.csv';

        return $this->reportingService->exportToCsv($report, $filename);
    }

    /**
     * Get shift summary
     */
    public function getShiftSummary(Request $request, int $shiftId)
    {
        $user = $request->user();

        $shift = \App\Models\Shift::findOrFail($shiftId);

        // Check permissions
        if (!$user->canDo('shifts.viewAny')) {
            if ($shift->staff_id !== $user->id) {
                abort(403, 'You can only view summaries for your own shifts.');
            }
        }

        $summary = $this->marService->getShiftSummary($shiftId);

        return response()->json($summary);
    }

    /**
     * Get medication allergies
     */
    public function getAllergies(Request $request, Client $client)
    {
        $this->authorize('viewMedications', $client);

        $allergies = MedicationAllergy::where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->with('recordedBy:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'allergies' => $allergies->map(fn ($a) => [
                'id' => $a->id,
                'allergen' => $a->allergen,
                'reaction' => $a->reaction,
                'severity' => $a->severity,
                'is_severe' => $a->isSevere(),
                'notes' => $a->notes,
                'identified_date' => $a->identified_date?->toDateString(),
                'recorded_by' => $a->recordedBy?->name,
            ]),
        ]);
    }

    /**
     * Create allergy
     */
    public function createAllergy(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'allergen' => ['required', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:500'],
            'severity' => ['nullable', 'in:mild,moderate,severe,life_threatening'],
            'notes' => ['nullable', 'string'],
            'identified_date' => ['nullable', 'date'],
        ]);

        $allergy = MedicationAllergy::create([
            'client_id' => $client->id,
            'allergen' => $data['allergen'],
            'reaction' => $data['reaction'] ?? null,
            'severity' => $data['severity'] ?? null,
            'notes' => $data['notes'] ?? null,
            'identified_date' => $data['identified_date'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'allergy' => [
                'id' => $allergy->id,
                'allergen' => $allergy->allergen,
                'severity' => $allergy->severity,
            ],
        ]);
    }

    /**
     * Get medication version history
     */
    public function getMedicationVersions(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $versions = \App\Models\MedicationOrderVersion::where('client_medication_id', $medication->id)
            ->orderByDesc('version_number')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'name' => $v->name,
                'dosage' => $v->dosage,
                'frequency' => $v->frequency,
                'route' => $v->route,
                'instructions' => $v->instructions,
                'state' => $v->state,
                'change_reason' => $v->change_reason,
                'changed_by' => $v->changedBy?->name ?? 'System',
                'changed_at' => $v->changed_at?->toIso8601String(),
            ]);

        return response()->json([
            'medication' => [
                'id' => $medication->id,
                'name' => $medication->name,
                'current_version' => $medication->version ?? 1,
            ],
            'versions' => $versions,
        ]);
    }

    /**
     * Get scheduled stock counts for a medication
     */
    public function getScheduledStockCounts(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $counts = \App\Models\MedicationScheduledStockCount::where('client_medication_id', $medication->id)
            ->orderByDesc('scheduled_date')
            ->orderByDesc('scheduled_time')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'scheduled_date' => $c->scheduled_date?->toDateString(),
                'scheduled_time' => $c->scheduled_time?->format('H:i'),
                'status' => $c->status,
                'expected_quantity' => $c->expected_quantity,
                'actual_quantity' => $c->actual_quantity,
                'discrepancy' => $c->discrepancy,
                'notes' => $c->notes,
                'completed_by' => $c->completedBy?->name,
                'witnessed_by' => $c->witnessedBy?->name,
                'completed_at' => $c->completed_at?->toIso8601String(),
                'is_overdue' => $c->isOverdue(),
            ]);

        return response()->json([
            'medication' => [
                'id' => $medication->id,
                'name' => $medication->name,
                'on_hand' => $medication->stock?->on_hand,
                'scan_verification' => $this->buildMedicationScanPayload($client, $medication),
            ],
            'counts' => $counts,
        ]);
    }

    /**
     * Create a scheduled stock count
     */
    public function createScheduledStockCount(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless($user->canDo('medications.stock.update') || $user->canDo('clients.update'), 403);

        $data = $request->validate([
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'expected_quantity' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:255'],
            'queued_offline' => ['nullable', 'boolean'],
        ]);

        if ($cached = $this->getCachedIdempotentResponse('scheduled-stock-count:create', $data)) {
            return response()->json($cached);
        }

        $count = \App\Models\MedicationScheduledStockCount::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => $data['scheduled_date'],
            'scheduled_time' => $data['scheduled_time'] ? $data['scheduled_date'] . ' ' . $data['scheduled_time'] : null,
            'expected_quantity' => $data['expected_quantity'] ?? $medication->stock?->on_hand,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        $payload = $this->withSync([
            'success' => true,
            'count' => [
                'id' => $count->id,
                'scheduled_date' => $count->scheduled_date->toDateString(),
                'status' => $count->status,
            ],
        ], $data, 'processed');

        return response()->json(
            $this->rememberIdempotentResponse('scheduled-stock-count:create', $data, $payload)
        );
    }

    /**
     * Complete a scheduled stock count
     */
    public function completeScheduledStockCount(Request $request, Client $client, \App\Models\MedicationScheduledStockCount $count)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($count->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless($user->canDo('medications.stock.update') || $user->canDo('clients.update'), 403);

        // Controlled drugs require witness
        $medication = $count->medication;
        $requiresWitness = $medication && $medication->controlled_drug;

        $data = $request->validate([
            'actual_quantity' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'witnessed_by' => $requiresWitness ? ['required', 'integer', 'exists:users,id'] : ['nullable'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:255'],
            'queued_offline' => ['nullable', 'boolean'],
            'scan_code' => ['nullable', 'string', 'max:255'],
            'scan_source' => ['nullable', 'string', 'in:manual,scanner'],
            'scan_verified' => ['nullable', 'boolean'],
            'scan_match_source' => ['nullable', 'string', 'max:50'],
        ]);

        if ($cached = $this->getCachedIdempotentResponse('scheduled-stock-count:complete', $data)) {
            return response()->json($cached);
        }

        if ($count->status === 'completed') {
            return response()->json(
                $this->buildConflictPayload(
                    $data,
                    'This stock count was already completed before the current request could be applied.',
                ),
                409
            );
        }

        if ($requiresWitness && $data['witnessed_by'] === $user->id) {
            return response()->json([
                'success' => false,
                'error' => 'Witness must be a different user.',
            ], 422);
        }

        $scanAudit = $medication
            ? $this->verifyMedicationScanOrFail($client, $medication, $data)
            : null;

        $count->complete(
            $data['actual_quantity'],
            $data['notes'] ?? null,
            $user->id,
            $data['witnessed_by'] ?? null
        );

        // Update actual stock if different
        if ($medication && $medication->stock && $data['actual_quantity'] !== $medication->stock->on_hand) {
            $medication->stock->on_hand = $data['actual_quantity'];
            $medication->stock->last_counted_at = now();
            $medication->stock->save();
        }

        AuditLogger::log('medications.stock.count.completed', $count, array_filter([
            'client_id' => $client->id,
            'client_medication_id' => $medication?->id,
            'actual_quantity' => (int) $data['actual_quantity'],
            'discrepancy' => $count->discrepancy,
            'witnessed_by' => $data['witnessed_by'] ?? null,
            'scan_source' => $scanAudit['scan_source'] ?? null,
            'scan_match_source' => $scanAudit['scan_match_source'] ?? null,
            'scan_match_label' => $scanAudit['scan_match_label'] ?? null,
            'entered_code_suffix' => $scanAudit['scan_code_suffix'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        $payload = $this->withSync([
            'success' => true,
            'count' => [
                'id' => $count->id,
                'status' => 'completed',
                'discrepancy' => $count->discrepancy,
            ],
        ], $data, ($data['queued_offline'] ?? false) ? 'synced' : 'processed');

        return response()->json(
            $this->rememberIdempotentResponse('scheduled-stock-count:complete', $data, $payload)
        );
    }

    /**
     * Get drug interactions list
     */
    public function getDrugInteractions(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('medications.view') || $user->canDo('clients.viewAny'), 403);

        $interactions = \App\Models\MedicationInteraction::active()
            ->orderByRaw("FIELD(severity, 'contraindicated', 'major', 'moderate', 'minor')")
            ->orderBy('medication_a')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'medication_a' => $i->medication_a,
                'medication_b' => $i->medication_b,
                'severity' => $i->severity,
                'severity_info' => $i->severityInfo,
                'description' => $i->description,
                'clinical_effects' => $i->clinical_effects,
                'management' => $i->management,
                'active' => $i->active,
            ]);

        return response()->json([
            'interactions' => $interactions,
        ]);
    }

    /**
     * Create drug interaction
     */
    public function createDrugInteraction(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('medications.administer.correct') || $user->canDo('clients.update'), 403);

        $data = $request->validate([
            'medication_a' => ['required', 'string', 'max:255'],
            'medication_b' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'in:minor,moderate,major,contraindicated'],
            'description' => ['required', 'string'],
            'clinical_effects' => ['nullable', 'string'],
            'management' => ['nullable', 'string'],
        ]);

        $interaction = \App\Models\MedicationInteraction::create([
            'medication_a' => $data['medication_a'],
            'medication_b' => $data['medication_b'],
            'severity' => $data['severity'],
            'description' => $data['description'],
            'clinical_effects' => $data['clinical_effects'] ?? null,
            'management' => $data['management'] ?? null,
            'active' => true,
        ]);

        return response()->json([
            'success' => true,
            'interaction' => [
                'id' => $interaction->id,
                'medication_a' => $interaction->medication_a,
                'medication_b' => $interaction->medication_b,
                'severity' => $interaction->severity,
            ],
        ]);
    }
}
