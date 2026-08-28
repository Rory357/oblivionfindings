<?php

namespace App\Http\Controllers\Api;

use App\Enums\Medication\SafetyOverrideReason;
use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationAllergy;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationError;
use App\Models\MedicationInteraction;
use App\Models\MedicationMarAttachment;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationScheduledStockCount;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnhancedMarService;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MedicationReportingService;
use App\Services\MedicationSafetyService;
use App\Services\MedicationScanVerificationService;
use App\Services\Timeline\TimelineEmitter;
use App\Services\UserSiteAccessService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class MedicationsApiController extends Controller
{
    use HandlesMedicationSync;

    private const CONTROLLED_ALERT_TYPES = [
        'controlled_discrepancy',
        'controlled_overdue_check',
        'controlled_loss',
    ];

    public function __construct(
        protected EnhancedMarService $marService,
        protected MedicationSafetyService $safetyService,
        protected MedicationReportingService $reportingService,
        protected MedicationIncidentIntegrationService $incidentService,
        protected MedicationAlertService $alertService,
        protected MedicationScanVerificationService $scanVerificationService,
        protected MarScheduleService $scheduleService,
        protected MedicationScopeDecisionService $medicationScope,
        protected UserSiteAccessService $siteAccess,
        protected MedicationGovernanceScopeService $governanceScope,
    ) {}

    private function idempotencyKey(string $scope, string $requestUuid): string
    {
        return "emar:idempotency:{$scope}:{$requestUuid}";
    }

    private function constrainControlledAlertVisibility($query, bool $canViewControlled): void
    {
        if ($canViewControlled) {
            return;
        }

        $query->whereNotIn('alert_type', self::CONTROLLED_ALERT_TYPES);
        $this->governanceScope->scopeWithoutControlledMedicationRows($query);
    }

    /** @return array<int, int> */
    private function reportSiteIds(User $user, ?int $requestedSiteId, ?int $requestedClientId): array
    {
        abort_unless(
            $user->canDo('reports.viewAny') || $user->canDo('medications.reports.export'),
            403,
        );

        $siteIds = $this->siteAccess->accessibleSiteIds(
            $user,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );

        if ($requestedSiteId !== null) {
            abort_unless(in_array($requestedSiteId, $siteIds, true), 404);
        }

        if ($requestedClientId !== null) {
            $client = Client::query()
                ->whereKey($requestedClientId)
                ->whereIn('site_id', $siteIds)
                ->first(['id', 'site_id']);
            abort_unless(
                $client !== null
                && ($requestedSiteId === null || (int) $client->site_id === $requestedSiteId),
                404,
            );
        }

        return $requestedSiteId !== null ? [$requestedSiteId] : $siteIds;
    }

    /**
     * Rebuild alert aggregates without controlled-only alert types so totals,
     * severities, and active counts cannot disclose controlled activity.
     *
     * @param  array<int, int>  $siteIds
     * @return array<string, mixed>
     */
    private function ordinaryAuditSafetyAlerts(
        ?int $clientId,
        Carbon $dateFrom,
        Carbon $dateTo,
        array $siteIds,
    ): array {
        $query = MedicationDashboardAlert::query()
            ->whereBetween('created_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->when($clientId, fn ($builder) => $builder->where('client_id', $clientId));
        $query = $this->governanceScope->scopeCanonicalClientMedicationRows($query, $siteIds);
        $this->constrainControlledAlertVisibility($query, false);

        return [
            'total_alerts' => (clone $query)->count(),
            'by_type' => (clone $query)->selectRaw('alert_type, COUNT(*) as count')
                ->groupBy('alert_type')
                ->pluck('count', 'alert_type')
                ->toArray(),
            'by_severity' => (clone $query)->selectRaw('severity, COUNT(*) as count')
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),
            'active_count' => (clone $query)->where('status', 'active')->count(),
        ];
    }

    private function authorizeMedicationRecordingTarget(
        User $user,
        Client $client,
        ClientMedication $medication,
        Carbon $actionAt,
    ): void {
        abort_unless((int) $medication->client_id === (int) $client->id, 404);

        if (! $user->can('viewMedications', $client)) {
            // Exact record-only workers do not gain reader access. Resolve their
            // action target through the canonical Site + covering-shift boundary.
            $this->medicationScope->forMedication(
                $user,
                $medication,
                $actionAt,
                static fn (MedicationScopeDecision $scope) => null,
                requireAdministrable: true,
                submittedClientId: (int) $client->id,
            );
        }

        abort_unless(
            ! (bool) $medication->controlled_drug
            || $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
            404,
        );
    }

    private function authorizeReadableMedication(
        Request $request,
        Client $client,
        ClientMedication $medication,
    ): void {
        $this->authorize('viewMedications', $client);
        abort_unless((int) $medication->client_id === (int) $client->id, 404);
        abort_unless(
            ! (bool) $medication->controlled_drug
            || $request->user()->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
            404,
        );
    }

    private function preflightMedicationRecordingTarget(
        Request $request,
        User $user,
        Client $client,
        ClientMedication $medication,
    ): void {
        // Resolve the controlled classification canonically before validating
        // user input or attempting shift/break-glass authority. Otherwise an
        // unauthorised controlled target can be distinguished by the 403 from
        // the assignment path before the later locked scope check conceals it.
        $canonicalMedication = ClientMedication::query()
            ->whereKey($medication->id)
            ->where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->whereNull('superseded_by')
            ->first(['id', 'controlled_drug']);
        abort_unless($canonicalMedication !== null, 404);
        abort_unless(
            ! (bool) $canonicalMedication->controlled_drug
            || $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
            404,
        );

        $actionAt = $this->medicationConcealmentActionAt(
            $request,
            $this->scheduleService,
            now(),
        );

        $this->medicationScope->forMedication(
            $user,
            $medication,
            $actionAt,
            static function (MedicationScopeDecision $scope) use ($user): void {
                abort_unless(
                    ! (bool) $scope->medication->controlled_drug
                    || $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                    404,
                );
            },
            submittedClientId: (int) $client->id,
        );
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
        if (! $requestUuid) {
            return null;
        }

        $payload = Cache::get($this->idempotencyKey($scope, $requestUuid));
        if (! $payload) {
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
            default => abort(404),
        };
    }

    private function attachmentTargetTypeForAttachment(MedicationMarAttachment $attachment): string
    {
        if ($attachment->attachable) {
            if (
                $attachment->attachable instanceof ClientMedicationAdministration
                && $attachment->client_medication_administration_id !== null
            ) {
                abort_unless(
                    (int) $attachment->client_medication_administration_id === (int) $attachment->attachable->id,
                    404,
                );
            }

            return $this->attachmentTargetTypeForModel($attachment->attachable);
        }

        if ($attachment->administration) {
            return $attachment->administration->is_correction ? 'correction' : 'administration';
        }

        abort(404);
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

        if ($targetType === 'administration') {
            abort_unless($target instanceof ClientMedicationAdministration && ! $target->is_correction, 404);
        }

        return $target;
    }

    private function assertCanManageSupportingAttachment(Request $request, string $targetType): void
    {
        $user = $request->user();

        $canManage = match ($targetType) {
            'administration' => $user->canDo('medications.administer.record')
                || $user->canDo('medications.administer.correct'),
            'correction' => $user->canDo('medications.administer.correct'),
            'error' => $user->canDo('medications.administer.record')
                || $user->canDo('medications.administer.correct'),
            'discrepancy', 'loss_report' => $user->canDo('medications.controlled.record'),
            default => false,
        };

        abort_unless($canManage, 403);
    }

    private function canDeleteSupportingAttachment(Request $request, MedicationMarAttachment $attachment): bool
    {
        $user = $request->user();
        $targetType = $this->attachmentTargetTypeForAttachment($attachment);

        return match ($targetType) {
            'administration', 'correction', 'error' => $user->canDo('medications.administer.correct'),
            'discrepancy', 'loss_report' => $user->canDo('medications.controlled.record'),
            default => false,
        };
    }

    private function assertCanAccessAttachmentTarget(Request $request, Model $target, bool $write): void
    {
        $targetType = $this->attachmentTargetTypeForModel($target);
        $inherentlyControlled = in_array($targetType, ['discrepancy', 'loss_report'], true);
        $medicationId = $target->getAttribute('client_medication_id');
        $medication = null;

        if ($medicationId !== null) {
            $medication = ClientMedication::withTrashed()
                ->whereKey($medicationId)
                ->where('client_id', $target->getAttribute('client_id'))
                ->first(['id', 'controlled_drug']);
            abort_unless($medication !== null, 404);
        }

        if (! $inherentlyControlled && ! (bool) $medication?->controlled_drug) {
            return;
        }

        $capability = $write
            ? MedicationGovernanceScopeService::CONTROLLED_CAPABILITY
            : MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY;
        abort_unless($request->user()->canDo($capability), 404);
    }

    private function assertCanAccessSupportingAttachment(
        Request $request,
        MedicationMarAttachment $attachment,
        bool $write,
    ): void {
        $attachment->loadMissing(['attachable', 'administration']);
        $this->attachmentTargetTypeForAttachment($attachment);
        $target = $attachment->attachable ?? $attachment->administration;
        abort_unless($target instanceof Model, 404);
        abort_unless((int) $target->getAttribute('client_id') === (int) $attachment->client_id, 404);
        $this->assertCanAccessAttachmentTarget($request, $target, $write);
    }

    private function assertAdministrationAttachmentNested(
        MedicationMarAttachment $attachment,
        ClientMedicationAdministration $administration,
    ): void {
        abort_unless(
            (int) $attachment->client_medication_administration_id === (int) $administration->id,
            404,
        );
        abort_unless((int) $attachment->client_id === (int) $administration->client_id, 404);

        $attachment->loadMissing('attachable');
        if ($attachment->attachable !== null) {
            abort_unless(
                $attachment->attachable instanceof ClientMedicationAdministration
                && (int) $attachment->attachable->id === (int) $administration->id,
                404,
            );
        }
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
            $request->user()->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
        );

        // Add permissions
        $user = $request->user();
        $marData['can'] = [
            'record' => $user->canDo('medications.administer.record'),
            'record_controlled' => $user->canDo('medications.controlled.record'),
            'view_controlled' => $user->canDo('medications.controlled.view'),
            'override_safety' => $user->canDo('medications.administer.override_safety'),
            'correct' => $user->canDo('medications.administer.correct'),
            'witness' => $user->canDo('medications.controlled.witness'),
        ];

        // Add active alerts for this client
        $alerts = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationDashboardAlert::query(),
            $client->site_id ? [(int) $client->site_id] : [],
            true,
        )
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->orderByDesc('severity')
            ->orderByDesc('created_at');
        $this->constrainControlledAlertVisibility(
            $alerts,
            $user->canDo('medications.controlled.view'),
        );
        $marData['alerts'] = $alerts->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'message' => $a->message,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        // Add controlled drug discrepancies
        $marData['controlled_discrepancies'] = $user->canDo('medications.controlled.view')
            ? $this->governanceScope->scopeCanonicalClientMedicationRows(
                ClientControlledDrugDiscrepancy::query(),
                $client->site_id ? [(int) $client->site_id] : [],
                false,
            )
                ->where('client_id', $client->id)
                ->whereIn('status', ['open', 'under_review'])
                ->with('medication:id,name')
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'medication_name' => $d->medication?->name,
                    'difference' => $d->difference,
                    'reason' => $d->reason,
                    'reported_at' => $d->reported_at?->toIso8601String(),
                ])
            : collect();

        return response()->json($marData);
    }

    /**
     * Perform safety check
     */
    public function safetyCheck(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorizeReadableMedication($request, $client, $medication);

        $check = $this->safetyService->performSafetyCheck(
            $client,
            $medication,
            includeControlled: $request->user()->canDo(
                MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            ),
        );

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
        $this->authorizeReadableMedication($request, $client, $medication);

        $filters = $request->validate([
            'hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
        ]);
        $hours = (int) ($filters['hours'] ?? 24);
        $history = $this->safetyService->getPrnHistory($medication, $hours);

        return response()->json($history);
    }

    public function getScanCode(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorizeReadableMedication($request, $client, $medication);

        return response()->json($this->buildMedicationScanPayload($client, $medication));
    }

    public function getScanCodeSvg(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorizeReadableMedication($request, $client, $medication);

        $payload = $this->scanVerificationService->payload($client, $medication);

        $result = new Builder(
            writer: new SvgWriter,
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
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('medications.administer.record'), 403);
        $this->authorizeMedicationRecordingTarget($user, $client, $medication, now());

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
        $this->assertCanAccessAttachmentTarget($request, $administration, true);

        $user = $request->user();
        $this->assertCanManageSupportingAttachment(
            $request,
            $this->attachmentTargetTypeForModel($administration),
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
            'attachment' => $this->serializeAttachment(
                $attachment,
                $this->canDeleteSupportingAttachment($request, $attachment),
            ),
        ]);
    }

    public function uploadSupportingAttachment(Request $request, Client $client)
    {
        $this->authorize('viewMedications', $client);

        $locator = $request->validate([
            'target_type' => ['required', 'string', 'in:administration,correction,discrepancy,loss_report,error'],
            'target_id' => ['required', 'integer'],
        ]);

        $this->assertCanManageSupportingAttachment($request, $locator['target_type']);

        $target = $this->resolveAttachmentTarget(
            $client,
            $locator['target_type'],
            (int) $locator['target_id'],
        );
        $this->assertCanAccessAttachmentTarget($request, $target, true);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

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
            'target_type' => $locator['target_type'],
            'file_name' => $attachment->file_name,
        ]);

        return response()->json([
            'success' => true,
            'attachment' => $this->serializeAttachment(
                $attachment,
                $this->canDeleteSupportingAttachment($request, $attachment),
            ),
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
        $this->assertAdministrationAttachmentNested($attachment, $administration);
        $this->assertCanAccessAttachmentTarget($request, $administration, false);

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
        $this->assertCanAccessSupportingAttachment($request, $attachment, false);

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
        $this->assertAdministrationAttachmentNested($attachment, $administration);
        $this->assertCanAccessAttachmentTarget($request, $administration, true);

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
        $this->assertCanAccessSupportingAttachment($request, $attachment, true);
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
        $user = $request->user();
        abort_unless(
            $user instanceof User && $user->canDo('medications.administer.record'),
            403,
            'You do not have permission to record medication administrations.'
        );
        abort_unless((int) $medication->client_id === (int) $client->id, 404);

        $this->preflightMedicationRecordingTarget($request, $user, $client, $medication);

        $data = $request->validate([
            'status' => ['required', 'in:given,refused,missed,withheld'],
            'reason' => ['nullable', 'string', 'max:500'],
            'reason_code' => ['nullable', 'string', 'max:60'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'quantity_administered' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date'],
            'administered_at' => ['nullable', 'date'],
            'witnessed_by' => ['nullable', 'integer', 'min:1'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'shift_id' => ['nullable', 'integer'],
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
            ...$this->medicationOfflineSubmissionRules($request),
            'scan_code' => ['nullable', 'string', 'max:255'],
            'scan_source' => ['nullable', 'string', 'in:manual,scanner'],
            'scan_verified' => ['nullable', 'boolean'],
            'scan_match_source' => ['nullable', 'string', 'max:50'],
        ]);

        $scheduledFor = filled($data['scheduled_for'] ?? null)
            ? $this->scheduleService->parseWorkerDateTime((string) $data['scheduled_for'])
            : null;
        $submittedAdministrationAt = $this->medicationSubmittedAdministrationAt($data);
        $actionAt = $this->scheduleService->parseWorkerDateTime((string) (
            $submittedAdministrationAt ?? now()->toIso8601String()
        ));

        return $this->medicationScope->forAdministration(
            $user,
            $client,
            $medication,
            $actionAt,
            $scheduledFor,
            isset($data['shift_id']) ? (int) $data['shift_id'] : null,
            null,
            function (MedicationScopeDecision $scope) use ($data, $user, $submittedAdministrationAt) {
                $client = $scope->client;
                $medication = $scope->medication;
                $data['administered_at'] = $submittedAdministrationAt;
                $data['shift_id'] = $scope->shiftId();
                $data['scope_authorized'] = true;

                if (array_key_exists('safety_override', $data)) {
                    abort_unless(
                        $user->canDo('medications.administer.override_safety'),
                        403,
                        'You do not have permission to authorise a blocked medication safety check.'
                    );
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

                if (($data['queued_offline'] ?? false) && ! $medication->is_prn && ! empty($data['scheduled_for'])) {
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
                            ->effectiveClinicalEvidence()
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
                    $data['shift_id'] ?? null,
                    $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                    prelockedPresenceShifts: $scope->lockedPresenceShifts,
                    prelockedPresenceEffectiveAt: $scope->lockedPresenceEffectiveAt,
                );

                if (! $result['success']) {
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
                app(TimelineEmitter::class)->record([
                    'source_type' => ClientMedicationAdministration::class,
                    'source_id' => $administration->id,
                    'occurred_at' => $administration->administered_at ?? now(),
                    'type' => 'medication_'.$data['status'],
                    'actor_user_id' => $user->id,
                    'client_id' => $client->id,
                    'shift_id' => $data['shift_id'] ?? null,
                    'site_id' => $client->site_id,
                    'subject' => $statusLabel.': '.$medication->name.($medication->dosage ? ' '.$medication->dosage : ''),
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
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'recorded_dose',
                    'Administration '.$administration->id,
                );

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
            },
            authorizationUserIds: array_filter([
                is_numeric($data['witnessed_by'] ?? null) ? (int) $data['witnessed_by'] : null,
            ]),
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
        $user = $request->user();

        abort_unless(
            $user?->canDo('medications.administer.correct'),
            403,
            'You do not have permission to correct medication administrations.'
        );

        return DB::transaction(function () use ($request, $client, $administration, $user) {
            // Read only the submitted child identity first, then lock the
            // canonical aggregate in parent-first order. A forged nested owner,
            // cross-client medication, foreign Site, and a missing row all
            // collapse to the same concealed response before validation/writes.
            $snapshot = ClientMedicationAdministration::query()
                ->whereKey($administration->getKey())
                ->first([
                    'id',
                    'client_id',
                    'client_medication_id',
                    'is_correction',
                    'corrected_of_id',
                ]);
            abort_unless(
                $snapshot !== null
                && (int) $snapshot->client_id === (int) $client->getKey()
                && is_numeric($snapshot->client_medication_id)
                && (int) $snapshot->client_medication_id > 0,
                404,
            );

            $canonicalClient = Client::query()
                ->whereKey($snapshot->client_id)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $canonicalClient !== null
                && is_numeric($canonicalClient->site_id)
                && (int) $canonicalClient->site_id > 0
                && in_array(
                    (int) $canonicalClient->site_id,
                    $this->siteAccess->accessibleSiteIds(
                        $user,
                        MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
                    ),
                    true,
                ),
                404,
            );

            $canonicalMedication = ClientMedication::withTrashed()
                ->whereKey($snapshot->client_medication_id)
                ->where('client_id', $canonicalClient->id)
                ->lockForUpdate()
                ->first();
            abort_unless($canonicalMedication !== null, 404);
            abort_unless(
                ! (bool) $canonicalMedication->controlled_drug
                || $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                404,
            );

            $rootId = $snapshot->is_correction
                ? (int) $snapshot->corrected_of_id
                : (int) $snapshot->id;
            abort_unless($rootId > 0, 404);

            $rootAdministration = ClientMedicationAdministration::query()
                ->whereKey($rootId)
                ->where('client_id', $canonicalClient->id)
                ->where('client_medication_id', $canonicalMedication->id)
                ->where(function ($query): void {
                    $query->where('is_correction', false)
                        ->orWhereNull('is_correction');
                })
                ->lockForUpdate()
                ->first();
            abort_unless($rootAdministration !== null, 404);

            $corrections = ClientMedicationAdministration::query()
                ->where('corrected_of_id', $rootAdministration->id)
                ->where('client_id', $canonicalClient->id)
                ->where('client_medication_id', $canonicalMedication->id)
                ->where('is_correction', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $submittedAdministration = $snapshot->is($rootAdministration)
                ? $rootAdministration
                : $corrections->first(
                    fn (ClientMedicationAdministration $candidate): bool => $candidate->is($snapshot),
                );
            abort_unless($submittedAdministration instanceof ClientMedicationAdministration, 404);

            $approvedWinner = $corrections
                ->filter(
                    fn (ClientMedicationAdministration $candidate): bool => $candidate->correction_status === 'approved',
                )
                ->sort(function (
                    ClientMedicationAdministration $left,
                    ClientMedicationAdministration $right,
                ): int {
                    $leftApprovedAt = $left->correction_approved_at?->getTimestamp() ?? PHP_INT_MIN;
                    $rightApprovedAt = $right->correction_approved_at?->getTimestamp() ?? PHP_INT_MIN;

                    return $leftApprovedAt === $rightApprovedAt
                        ? (int) $right->id <=> (int) $left->id
                        : $rightApprovedAt <=> $leftApprovedAt;
                })
                ->first();
            $effectiveAdministration = $approvedWinner ?? $rootAdministration;

            abort_unless(
                $submittedAdministration->is($rootAdministration)
                || $submittedAdministration->is($effectiveAdministration),
                404,
            );

            $pendingSibling = $corrections->first(
                fn (ClientMedicationAdministration $candidate): bool => $candidate->correction_status === 'pending',
            );
            if ($pendingSibling !== null) {
                return response()->json([
                    'success' => false,
                    'error' => 'A correction for this administration is already awaiting approval.',
                ], 409);
            }

            $data = $request->validate([
                'status' => ['required', 'in:given,refused,missed,withheld'],
                'reason' => ['nullable', 'string', 'max:500'],
                'dose_given' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'administered_at' => ['nullable', 'date'],
                'correction_reason' => ['required', 'string', 'max:500'],
            ]);

            if (
                (bool) $canonicalMedication->controlled_drug
                && (($effectiveAdministration->status === 'given') !== ($data['status'] === 'given'))
            ) {
                throw ValidationException::withMessages([
                    'status' => 'A controlled medication correction cannot change whether stock was administered without a separate witnessed register reconciliation.',
                ]);
            }
            $this->assertCorrectionClinicalIntegrity($canonicalMedication, $effectiveAdministration, $data);
            if (
                (bool) $canonicalMedication->controlled_drug
                && $effectiveAdministration->witnessed_by !== null
                && (int) $effectiveAdministration->administered_by === (int) $effectiveAdministration->witnessed_by
            ) {
                throw ValidationException::withMessages([
                    'administration' => 'Controlled medication evidence must retain an administering worker distinct from its witness.',
                ]);
            }

            // Check correction time window
            $windowAnchor = $rootAdministration->administered_at
                ?? $rootAdministration->updated_at
                ?? $rootAdministration->created_at;
            $minutesSince = $windowAnchor?->diffInMinutes(now()) ?? 999999;
            if ($minutesSince > 30 && empty($data['correction_reason'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Correction reason is required when correcting after 30 minutes.',
                ], 422);
            }

            // Create correction record
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
            $correction->correction_reason = $data['correction_reason'];
            $correction->status = $data['status'];
            $correction->reason = $data['reason'] ?? $effectiveAdministration->reason;
            $correction->dose_given = $data['dose_given'] ?? $effectiveAdministration->dose_given;
            $correction->notes = $data['notes'] ?? $effectiveAdministration->notes;
            $correction->administered_at = $data['administered_at'] ?? $effectiveAdministration->administered_at;
            if ($correction->status !== 'given') {
                foreach (ClientMedicationAdministration::ADMINISTRATION_ONLY_EVIDENCE_FIELDS as $field) {
                    $correction->{$field} = null;
                }
            }
            $correction->correction_requested_by = $user->id;
            $correction->correction_status = 'pending';
            $correction->save();

            // Timeline event
            app(TimelineEmitter::class)->record([
                'source_type' => ClientMedicationAdministration::class,
                'source_id' => $correction->id,
                'occurred_at' => now(),
                'type' => 'medication_correction',
                'actor_user_id' => $user->id,
                'client_id' => $canonicalClient->id,
                'shift_id' => $effectiveAdministration->shift_id,
                'site_id' => $canonicalClient->site_id,
                'subject' => 'Medication corrected: '.$canonicalMedication->name,
                'body' => $data['correction_reason'],
                'meta' => array_filter([
                    'corrected_from' => $effectiveAdministration->status,
                    'corrected_to' => $data['status'],
                    'original_administration_id' => $rootAdministration->id,
                ]),
                'visibility' => 'internal',
                'is_pinned' => false,
                'created_by' => $user->id,
            ]);

            // Handle incident for significant corrections
            if ($minutesSince > 240) { // 4 hours
                $this->incidentService->handleUnsafeCorrection($rootAdministration, $data, $user->id, $correction);
            }

            return response()->json([
                'success' => true,
                'correction' => [
                    'id' => $correction->id,
                    'status' => $correction->status,
                    'is_correction' => true,
                    'correction_status' => $correction->correction_status,
                ],
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    private function assertCorrectionClinicalIntegrity(
        ClientMedication $medication,
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
        if (! $medication->controlled_drug || $currentStatus !== 'given' || $correctedStatus !== 'given') {
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

    /**
     * Get dashboard alerts
     */
    public function getDashboardAlerts(Request $request, ?Client $client = null)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $clientId = $client ? (int) $client->id : null;
        $siteIds = $this->governanceScope->readerSiteIds(
            $user,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            requestedClientId: $clientId,
        );

        $alertsQuery = MedicationDashboardAlert::query()
            ->active()
            ->when($clientId, fn ($query) => $query->where('client_id', $clientId));
        $alertsQuery = $this->governanceScope
            ->scopeCanonicalClientMedicationRows($alertsQuery, $siteIds)
            ->with(['medication:id,name', 'client:id,first_name,last_name'])
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('created_at')
            ->limit(50);

        $this->constrainControlledAlertVisibility(
            $alertsQuery,
            $user->canDo('medications.controlled.view'),
        );
        $alerts = $alertsQuery->get();

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
        abort_unless($user, 403);

        [$alert] = $this->authorizedDashboardAlert($user, $alertId);

        $success = $this->alertService->acknowledgeAlert($alert, $user);

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
        abort_unless($user, 403);
        [$alert] = $this->authorizedDashboardAlert($user, $alertId);

        $data = $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $success = $this->alertService->resolveAlert($alert, $data['resolution_notes'] ?? null, $user);

        return response()->json([
            'success' => $success,
        ]);
    }

    /** @return array{MedicationDashboardAlert, array<int, int>} */
    private function authorizedDashboardAlert(User $user, int $alertId): array
    {
        $siteIds = $this->governanceScope->readerSiteIds(
            $user,
            'medications.administer.correct',
        );

        $alertQuery = $this->governanceScope
            ->scopeCanonicalClientMedicationRows(
                MedicationDashboardAlert::query()->whereKey($alertId),
                $siteIds,
            )
            ->with('client:id,site_id');
        $this->constrainControlledAlertVisibility(
            $alertQuery,
            $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                && $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
        );
        $alert = $alertQuery->firstOrFail();

        return [$alert, $siteIds];
    }

    /**
     * Get dashboard widgets
     */
    public function getDashboardWidgets(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 403);
        $filters = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $siteIds = $this->governanceScope->readerSiteIds(
            $user,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            requestedClientId: $clientId,
        );

        $widgets = $this->alertService->getGlobalDashboardWidgets(
            $clientId,
            $siteIds,
            $user->canDo('medications.controlled.view'),
        );

        return response()->json($widgets);
    }

    /**
     * Get medication reports
     */
    public function getReports(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user
            && ($user->canDo('reports.viewAny') || $user->canDo('medications.reports.export')),
            403,
        );

        $filters = $request->validate([
            'type' => ['sometimes', 'string', 'in:mar,prn,missed,late,controlled_balance,controlled_discrepancies,changes,incidents,audit'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $reportType = $filters['type'] ?? 'mar';
        if (in_array($reportType, ['controlled_balance', 'controlled_discrepancies'], true)) {
            abort_unless($user->canDo('medications.controlled.view'), 403);
        }
        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $siteId = isset($filters['site_id']) ? (int) $filters['site_id'] : null;
        $readerSiteIds = $this->reportSiteIds($user, $siteId, $clientId);
        $includeControlled = $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $dateFrom = filled($filters['date_from'] ?? null) ? Carbon::parse($filters['date_from']) : null;
        $dateTo = filled($filters['date_to'] ?? null) ? Carbon::parse($filters['date_to']) : null;
        if ($dateFrom !== null && $dateTo !== null && $dateTo->lt($dateFrom)) {
            throw ValidationException::withMessages([
                'date_to' => 'The date to field must be a date after or equal to date from.',
            ]);
        }

        $report = match ($reportType) {
            'mar' => $this->reportingService->exportMar($clientId, $dateFrom, $dateTo, null, null, null, $readerSiteIds, $includeControlled),
            'prn' => $this->reportingService->reportPrnUsage($clientId, $dateFrom, $dateTo, null, $readerSiteIds, $includeControlled),
            'missed' => $this->reportingService->reportMissedDoses($clientId, $dateFrom, $dateTo, $readerSiteIds, $includeControlled),
            'late' => $this->reportingService->reportLateDoses($clientId, $dateFrom, $dateTo, 30, $readerSiteIds, $includeControlled),
            'controlled_balance' => $this->reportingService->reportControlledDrugBalance($clientId, null, $readerSiteIds),
            'controlled_discrepancies' => $this->reportingService->reportControlledDiscrepancies($clientId, null, $dateFrom, $dateTo, $readerSiteIds),
            'changes' => $this->reportingService->reportMedicationChanges($clientId, null, $dateFrom, $dateTo, $readerSiteIds, $includeControlled),
            'incidents' => $this->reportingService->reportMedicationIncidents(
                $clientId,
                $dateFrom,
                $dateTo,
                $readerSiteIds,
                $includeControlled,
            ),
            'audit' => $this->reportingService->generateAuditReport($clientId, $dateFrom, $dateTo, $readerSiteIds, $includeControlled),
            default => throw new \InvalidArgumentException('Invalid report type'),
        };

        if ($reportType === 'audit' && ! $user->canDo('medications.controlled.view')) {
            unset($report['controlled_summary']);
            unset($report['compliance_metrics']['witness_compliance_percentage']);
            $report['safety_alerts'] = $this->ordinaryAuditSafetyAlerts(
                $clientId,
                ($dateFrom ?? now()->subDays(30))->copy(),
                ($dateTo ?? now())->copy(),
                $readerSiteIds,
            );
        }

        return response()->json($report);
    }

    /**
     * Export report to CSV
     */
    public function exportReportCsv(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user
            && ($user->canDo('reports.viewAny') || $user->canDo('medications.reports.export')),
            403,
        );

        $filters = $request->validate([
            'type' => ['sometimes', 'string', 'in:mar,prn,missed,late,controlled_discrepancies,changes'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $reportType = $filters['type'] ?? 'mar';
        if ($reportType === 'controlled_discrepancies') {
            abort_unless($user->canDo('medications.controlled.view'), 403);
        }
        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $siteId = isset($filters['site_id']) ? (int) $filters['site_id'] : null;
        $readerSiteIds = $this->reportSiteIds($user, $siteId, $clientId);
        $includeControlled = $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $dateFrom = filled($filters['date_from'] ?? null) ? Carbon::parse($filters['date_from']) : null;
        $dateTo = filled($filters['date_to'] ?? null) ? Carbon::parse($filters['date_to']) : null;
        if ($dateFrom !== null && $dateTo !== null && $dateTo->lt($dateFrom)) {
            throw ValidationException::withMessages([
                'date_to' => 'The date to field must be a date after or equal to date from.',
            ]);
        }

        $report = match ($reportType) {
            'mar' => $this->reportingService->exportMar($clientId, $dateFrom, $dateTo, null, null, null, $readerSiteIds, $includeControlled),
            'prn' => $this->reportingService->reportPrnUsage($clientId, $dateFrom, $dateTo, null, $readerSiteIds, $includeControlled),
            'missed' => $this->reportingService->reportMissedDoses($clientId, $dateFrom, $dateTo, $readerSiteIds, $includeControlled),
            'late' => $this->reportingService->reportLateDoses($clientId, $dateFrom, $dateTo, 30, $readerSiteIds, $includeControlled),
            'controlled_discrepancies' => $this->reportingService->reportControlledDiscrepancies($clientId, null, $dateFrom, $dateTo, $readerSiteIds),
            'changes' => $this->reportingService->reportMedicationChanges($clientId, null, $dateFrom, $dateTo, $readerSiteIds, $includeControlled),
            default => throw new \InvalidArgumentException('Invalid report type'),
        };

        $filename = "medication_report_{$reportType}_".now()->format('Ymd_His').'.csv';

        return $this->reportingService->exportToCsv($report, $filename);
    }

    /**
     * Get shift summary
     */
    public function getShiftSummary(Request $request, int $shiftId)
    {
        abort_unless($request->user()?->canDo('medications.view'), 403);

        $user = $request->user();

        $shift = $this->siteAccess
            ->applyShiftScope(
                Shift::query(),
                $user,
                ['clinical.accessAllSites', 'sites.viewAll'],
            )
            ->findOrFail($shiftId);

        if (! $user->canDo('shifts.viewAny') && (int) $shift->user_id !== (int) $user->id) {
            abort(404);
        }

        $summary = $this->marService->getShiftSummary(
            $shiftId,
            $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
            (int) $shift->client_id,
            (int) $shift->site_id,
            is_numeric($shift->user_id) ? (int) $shift->user_id : null,
        );

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
        $this->authorizeReadableMedication($request, $client, $medication);

        $versions = MedicationOrderVersion::query()
            ->where('client_id', $client->id)
            ->where('client_medication_id', $medication->id)
            ->when(
                ! $request->user()->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                fn ($query) => $query->where('controlled_drug', false),
            )
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
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            requestedClientId: (int) $client->id,
        );
        $client = Client::query()->whereKey($client->id)->whereIn('site_id', $siteIds)->firstOrFail();
        $medicationQuery = ClientMedication::query()
            ->current()
            ->whereKey($medication->id)
            ->where('client_id', $client->id);
        if (! $actor->canDo('medications.controlled.view')) {
            $medicationQuery->where('controlled_drug', false);
        }
        $medication = $medicationQuery->firstOrFail();

        $counts = MedicationScheduledStockCount::query()
            ->where('client_id', $client->id)
            ->where('client_medication_id', $medication->id)
            ->orderByDesc('scheduled_date')
            ->orderByDesc('scheduled_time')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'scheduled_date' => $c->scheduled_date?->toDateString(),
                'scheduled_time' => $c->scheduled_time?->format('H:i'),
                'status' => $c->status,
                'expected_quantity' => $c->expected_quantity !== null
                    ? MedicationStockQuantity::toFloat($c->expected_quantity)
                    : null,
                'actual_quantity' => $c->actual_quantity !== null
                    ? MedicationStockQuantity::toFloat($c->actual_quantity)
                    : null,
                'discrepancy' => $c->discrepancy !== null
                    ? MedicationStockQuantity::toFloat($c->discrepancy)
                    : null,
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
                'on_hand' => $medication->stock?->on_hand !== null
                    ? MedicationStockQuantity::toFloat($medication->stock->on_hand)
                    : null,
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
        $user = $request->user();
        abort_unless($user, 403);

        $payload = $this->governanceScope->forMedication(
            $user,
            (int) $medication->id,
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
            function (Client $canonicalClient, ClientMedication $canonicalMedication) use ($request, $user): array {
                if ((bool) $canonicalMedication->controlled_drug) {
                    abort_unless(
                        $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                        404,
                    );
                    throw ValidationException::withMessages([
                        'client_medication_id' => 'Controlled drug stock counts must be recorded through the controlled-drug balance check with a second witness.',
                    ]);
                }

                $data = $request->validate([
                    'scheduled_date' => ['required', 'date'],
                    'scheduled_time' => ['nullable', 'date_format:H:i'],
                    'expected_quantity' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
                    'notes' => ['nullable', 'string'],
                    ...$this->medicationOfflineSubmissionRules($request),
                ]);
                if (($data['expected_quantity'] ?? null) !== null) {
                    $data['expected_quantity'] = MedicationStockQuantity::normalize($data['expected_quantity']);
                }

                // Keep the replay namespace global for this queueable action so
                // a request UUID cannot be reused by another actor or target.
                // Actor and canonical target identity belong in the fingerprint.
                $scope = 'scheduled-count:create';
                $fingerprint = hash('sha256', json_encode([
                    'actor_id' => (int) $user->id,
                    'client_id' => (int) $canonicalClient->id,
                    'client_medication_id' => (int) $canonicalMedication->id,
                    'scheduled_date' => $data['scheduled_date'],
                    'scheduled_time' => $data['scheduled_time'] ?? null,
                    'expected_quantity' => $data['expected_quantity'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'captured_offline_at' => $data['captured_offline_at'] ?? null,
                    'origin_device_id' => $data['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                ], JSON_THROW_ON_ERROR));

                try {
                    $replayed = $this->governanceScope->idempotencyResult(
                        $scope,
                        $data,
                        $fingerprint,
                        durable: true,
                    );
                } catch (ValidationException) {
                    return $this->buildConflictPayload(
                        $data,
                        'This stock count request was already used for a different target or payload. Please submit it again with a new request identifier.',
                    );
                }

                if ($replayed) {
                    return $this->withSync(
                        $replayed,
                        $data,
                        'duplicate',
                        true,
                        'This medication request was already processed.',
                    );
                }

                $stock = ClientMedicationStock::query()
                    ->where('client_medication_id', $canonicalMedication->id)
                    ->lockForUpdate()
                    ->first();
                $expectedQuantity = $data['expected_quantity']
                    ?? ($stock?->on_hand !== null ? MedicationStockQuantity::normalize($stock->on_hand) : null);

                $count = MedicationScheduledStockCount::create([
                    'client_id' => $canonicalClient->id,
                    'client_medication_id' => $canonicalMedication->id,
                    'scheduled_date' => $data['scheduled_date'],
                    'scheduled_time' => ($data['scheduled_time'] ?? null)
                        ? $data['scheduled_date'].' '.$data['scheduled_time']
                        : null,
                    'expected_quantity' => $expectedQuantity,
                    'status' => 'pending',
                    'notes' => $data['notes'] ?? null,
                ]);

                AuditLogger::logOrFail('medications.stock.count.scheduled', $count, array_filter([
                    'actor_id' => $user->id,
                    'client_id' => $canonicalClient->id,
                    'client_medication_id' => $canonicalMedication->id,
                    'expected_quantity' => $expectedQuantity,
                    'scheduled_date' => $data['scheduled_date'],
                    'scheduled_time' => $data['scheduled_time'] ?? null,
                    'client_request_uuid' => $data['client_request_uuid'] ?? null,
                    'captured_offline_at' => $data['captured_offline_at'] ?? null,
                    'origin_device_id' => $data['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                ], fn ($value) => $value !== null && $value !== ''));

                $payload = $this->withSync([
                    'success' => true,
                    'count' => [
                        'id' => $count->id,
                        'scheduled_date' => $count->scheduled_date->toDateString(),
                        'status' => $count->status,
                    ],
                ], $data, $this->medicationProcessedStatus($data));

                return $this->governanceScope->rememberIdempotencyResult(
                    $scope,
                    $data,
                    $payload,
                    $fingerprint,
                    durable: true,
                );
            },
            (int) $client->id,
        );

        return response()->json(
            $payload,
            data_get($payload, 'sync.status') === 'conflict' ? 409 : 200,
        );
    }

    /**
     * Complete a scheduled stock count
     */
    public function completeScheduledStockCount(Request $request, Client $client, MedicationScheduledStockCount $count)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $payload = $this->governanceScope->forScheduledStockCount(
            $user,
            (int) $client->id,
            $count,
            function (
                Client $canonicalClient,
                ClientMedication $medication,
                MedicationScheduledStockCount $lockedCount,
                ?ClientMedicationStock $stock,
            ) use ($request, $user): array {
                if ((bool) $medication->controlled_drug) {
                    abort_unless(
                        $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                        404,
                    );
                    throw ValidationException::withMessages([
                        'client_medication_id' => 'Controlled drug stock counts must be recorded through the controlled-drug balance check with a second witness.',
                    ]);
                }

                $data = $request->validate([
                    'actual_quantity' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
                    'notes' => ['nullable', 'string'],
                    ...$this->medicationOfflineSubmissionRules($request),
                    'scan_code' => ['nullable', 'string', 'max:255'],
                    'scan_source' => ['nullable', 'string', 'in:manual,scanner'],
                    'scan_verified' => ['nullable', 'boolean'],
                    'scan_match_source' => ['nullable', 'string', 'max:50'],
                ]);
                $data['actual_quantity'] = MedicationStockQuantity::normalize($data['actual_quantity']);

                // Completion UUIDs are likewise globally action-scoped. Actor
                // and locked aggregate identity live in the fingerprint so
                // cross-worker/count replay is detected rather than executed.
                $scope = 'scheduled-count:complete';
                $fingerprint = hash('sha256', json_encode([
                    'actor_id' => (int) $user->id,
                    'client_id' => (int) $canonicalClient->id,
                    'client_medication_id' => (int) $medication->id,
                    'scheduled_stock_count_id' => (int) $lockedCount->id,
                    'actual_quantity' => $data['actual_quantity'],
                    'notes' => $data['notes'] ?? null,
                    'scan_code' => $data['scan_code'] ?? null,
                    'scan_source' => $data['scan_source'] ?? null,
                    'scan_verified' => (bool) ($data['scan_verified'] ?? false),
                    'scan_match_source' => $data['scan_match_source'] ?? null,
                    'captured_offline_at' => $data['captured_offline_at'] ?? null,
                    'origin_device_id' => $data['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                ], JSON_THROW_ON_ERROR));

                try {
                    $replayed = $this->governanceScope->idempotencyResult(
                        $scope,
                        $data,
                        $fingerprint,
                        durable: true,
                    );
                } catch (ValidationException) {
                    return $this->buildConflictPayload(
                        $data,
                        'This stock count completion request was already used for a different target or payload. Please submit it again with a new request identifier.',
                    );
                }

                if ($replayed) {
                    return $this->withSync(
                        $replayed,
                        $data,
                        'duplicate',
                        true,
                        'This medication request was already processed.',
                    );
                }

                if ($lockedCount->status === 'completed') {
                    throw ValidationException::withMessages([
                        'actual_quantity' => 'This stock count was already completed before the current request could be applied.',
                    ]);
                }

                $scanAudit = $this->verifyMedicationScanOrFail($canonicalClient, $medication, $data);
                $beforeOnHand = $stock?->on_hand !== null
                    ? MedicationStockQuantity::normalize($stock->on_hand)
                    : null;

                if (! $stock) {
                    $stock = new ClientMedicationStock(['client_medication_id' => $medication->id]);
                }

                $lockedCount->complete(
                    $data['actual_quantity'],
                    $data['notes'] ?? null,
                    $user->id,
                    null,
                );
                $stock->on_hand = $data['actual_quantity'];
                $stock->last_counted_at = now();
                $stock->save();

                AuditLogger::logOrFail('medications.stock.count.completed', $lockedCount, array_filter([
                    'actor_id' => $user->id,
                    'client_id' => $canonicalClient->id,
                    'client_medication_id' => $medication->id,
                    'stock_id' => $stock->id,
                    'on_hand_before' => $beforeOnHand,
                    'on_hand_after' => $data['actual_quantity'],
                    'actual_quantity' => $data['actual_quantity'],
                    'discrepancy' => $lockedCount->discrepancy,
                    'scan_source' => $scanAudit['scan_source'] ?? null,
                    'scan_match_source' => $scanAudit['scan_match_source'] ?? null,
                    'scan_match_label' => $scanAudit['scan_match_label'] ?? null,
                    'entered_code_suffix' => $scanAudit['scan_code_suffix'] ?? null,
                    'client_request_uuid' => $data['client_request_uuid'] ?? null,
                    'captured_offline_at' => $data['captured_offline_at'] ?? null,
                    'origin_device_id' => $data['origin_device_id'] ?? null,
                    'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                ], fn ($value) => $value !== null && $value !== ''));

                $payload = $this->withSync([
                    'success' => true,
                    'count' => [
                        'id' => $lockedCount->id,
                        'status' => 'completed',
                        'discrepancy' => $lockedCount->discrepancy,
                    ],
                ], $data, ($data['queued_offline'] ?? false) ? 'synced' : 'processed');

                return $this->governanceScope->rememberIdempotencyResult(
                    $scope,
                    $data,
                    $payload,
                    $fingerprint,
                    durable: true,
                );
            },
        );

        return response()->json(
            $payload,
            data_get($payload, 'sync.status') === 'conflict' ? 409 : 200,
        );
    }

    /**
     * Get drug interactions list
     */
    public function getDrugInteractions(Request $request)
    {
        abort_unless($request->user()?->canDo('medications.view'), 403);

        $user = $request->user();

        $interactions = MedicationInteraction::active()
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
        abort_unless($user?->canDo('medications.administer.correct'), 403);

        $data = $request->validate([
            'medication_a' => ['required', 'string', 'max:255'],
            'medication_b' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'in:minor,moderate,major,contraindicated'],
            'description' => ['required', 'string'],
            'clinical_effects' => ['nullable', 'string'],
            'management' => ['nullable', 'string'],
        ]);

        $interaction = MedicationInteraction::create([
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
