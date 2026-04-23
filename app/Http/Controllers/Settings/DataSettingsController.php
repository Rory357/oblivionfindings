<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DataSettingsController extends Controller
{
    private const PRIVACY_SETTINGS_KEY = 'settings.data.privacy';

    private const COMPLIANCE_SETTINGS_KEY = 'settings.data.compliance';

    private const PROCESSORS_KEY = 'settings.data.processors';

    /**
     * @var array<string, array{label: string, model_type: string, default: string}>
     */
    private const RETENTION_ROWS = [
        'audit-logs' => ['label' => 'Audit logs', 'model_type' => 'audit_logs', 'default' => '5yr'],
        'timesheets' => ['label' => 'Completed timesheets', 'model_type' => 'timesheets', 'default' => '7yr'],
        'incidents' => ['label' => 'Closed incidents', 'model_type' => 'incidents', 'default' => '5yr'],
        'archived-clients' => ['label' => 'Archived clients', 'model_type' => 'archived_clients', 'default' => 'never'],
        'notifications' => ['label' => 'Old notifications', 'model_type' => 'notifications', 'default' => '90d'],
        'session-logs' => ['label' => 'Session logs', 'model_type' => 'session_logs', 'default' => '90d'],
        'deleted-docs' => ['label' => 'Deleted documents (trash)', 'model_type' => 'deleted_documents', 'default' => '30d'],
    ];

    /**
     * @var array<string, mixed>
     */
    private const PRIVACY_DEFAULTS = [
        'anonymisation' => false,
        'consent_required' => true,
        'data_portability' => true,
        'right_to_erasure' => true,
        'privacy_url' => '',
        'dpo_name' => '',
        'privacy_email' => '',
    ];

    /**
     * @var array<string, mixed>
     */
    private const COMPLIANCE_DEFAULTS = [
        'privacy_act_mode' => true,
        'nzdsf_reporting' => false,
        'health_info_code' => true,
        'data_sovereignty' => 'nz-only',
        'health_custodian' => '',
        'privacy_officer' => '',
        'require_privacy_officer_approval' => false,
        'log_medical_access' => true,
    ];

    /**
     * @var array<string, string>
     */
    private const PROCESSOR_PURPOSE_LABELS = [
        'sso' => 'SSO / Authentication',
        'email' => 'Email delivery',
        'sms' => 'SMS delivery',
        'cloud_hosting' => 'Cloud hosting',
        'backup' => 'Backup',
        'analytics' => 'Analytics',
        'payroll' => 'Payroll integration',
        'calendar' => 'Calendar sync',
    ];

    /**
     * @var array<string, array{label: string, flag: string}>
     */
    private const COUNTRY_LABELS = [
        'nz' => ['label' => 'New Zealand', 'flag' => 'NZ'],
        'au' => ['label' => 'Australia', 'flag' => 'AU'],
        'us' => ['label' => 'USA', 'flag' => 'US'],
        'uk' => ['label' => 'UK', 'flag' => 'UK'],
        'other' => ['label' => 'Other', 'flag' => 'OT'],
    ];

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('settings/data', [
            'retention_values' => $this->loadRetentionValues(),
            'privacy_settings' => $this->loadObjectSetting(self::PRIVACY_SETTINGS_KEY, self::PRIVACY_DEFAULTS),
            'compliance_settings' => $this->loadObjectSetting(self::COMPLIANCE_SETTINGS_KEY, self::COMPLIANCE_DEFAULTS),
            'dsar_requests' => DataSubjectRequest::query()
                ->with('assignedTo:id,name')
                ->orderByDesc('received_at')
                ->limit(5)
                ->get()
                ->map(fn (DataSubjectRequest $request) => $this->mapDsarRequest($request))
                ->all(),
            'breaches' => DataBreachLog::query()
                ->orderByDesc('discovered_at')
                ->limit(5)
                ->get()
                ->map(fn (DataBreachLog $breach) => $this->mapBreach($breach))
                ->all(),
            'processors' => $this->loadProcessors(),
        ]);
    }

    public function updateRetention(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'values' => ['required', 'array'],
        ]);

        $userId = $request->user()?->id;
        $values = is_array($validated['values']) ? $validated['values'] : [];

        foreach (self::RETENTION_ROWS as $id => $config) {
            $selectedValue = isset($values[$id]) && is_string($values[$id]) ? $values[$id] : $config['default'];

            DataRetentionPolicy::updateOrCreate(
                ['model_type' => $config['model_type']],
                [
                    'policy_name' => $config['label'],
                    'description' => 'Managed from the Settings > Data & Privacy retention matrix.',
                    'retention_period_years' => $this->parseRetentionYears($selectedValue),
                    'retention_conditions' => ['setting_value' => $selectedValue],
                    'active' => true,
                    'updated_by' => $userId,
                    'created_by' => $userId,
                ],
            );
        }

        return response()->json([
            'message' => 'Retention policies saved.',
            'values' => $this->loadRetentionValues(),
        ]);
    }

    public function updatePrivacy(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'anonymisation' => ['required', 'boolean'],
            'consent_required' => ['required', 'boolean'],
            'data_portability' => ['required', 'boolean'],
            'right_to_erasure' => ['required', 'boolean'],
            'privacy_url' => ['nullable', 'url', 'max:255'],
            'dpo_name' => ['nullable', 'string', 'max:255'],
            'privacy_email' => ['nullable', 'email', 'max:255'],
        ]);

        $settings = array_merge(self::PRIVACY_DEFAULTS, $validated);
        $this->storeObjectSetting(self::PRIVACY_SETTINGS_KEY, $settings);

        return response()->json([
            'message' => 'Privacy settings saved.',
            'settings' => $settings,
        ]);
    }

    public function updateCompliance(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'privacy_act_mode' => ['required', 'boolean'],
            'nzdsf_reporting' => ['required', 'boolean'],
            'health_info_code' => ['required', 'boolean'],
            'data_sovereignty' => ['required', 'in:nz-only,au-nz,none'],
            'health_custodian' => ['nullable', 'string', 'max:255'],
            'privacy_officer' => ['nullable', 'string', 'max:255'],
            'require_privacy_officer_approval' => ['required', 'boolean'],
            'log_medical_access' => ['required', 'boolean'],
        ]);

        $settings = array_merge(self::COMPLIANCE_DEFAULTS, $validated);
        $this->storeObjectSetting(self::COMPLIANCE_SETTINGS_KEY, $settings);

        return response()->json([
            'message' => 'Compliance settings saved.',
            'settings' => $settings,
        ]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'request_type' => ['required', 'in:access,correction,rectification,erasure,restriction,portability,objection'],
            'requester_name' => ['required', 'string', 'max:255'],
            'requester_email' => ['required', 'email', 'max:255'],
            'requester_phone' => ['nullable', 'string', 'max:255'],
            'relationship' => ['required', 'in:self,whanau,legal_rep,advocate'],
            'details' => ['nullable', 'string'],
            'identity_verified' => ['required', 'boolean'],
        ]);

        $requestType = $validated['request_type'] === 'correction'
            ? 'rectification'
            : $validated['request_type'];

        $details = trim((string) ($validated['details'] ?? ''));
        $contextLines = array_values(array_filter([
            ! empty($validated['requester_phone']) ? 'Phone: ' . $validated['requester_phone'] : null,
            ($validated['relationship'] ?? 'self') !== 'self'
                ? 'Relationship: ' . Str::headline((string) $validated['relationship'])
                : null,
        ]));

        if ($contextLines !== []) {
            $details = trim($details . PHP_EOL . PHP_EOL . implode(PHP_EOL, $contextLines));
        }

        $dsar = DataSubjectRequest::create([
            'request_type' => $requestType,
            'subject_name' => $validated['requester_name'],
            'subject_email' => $validated['requester_email'],
            'request_details' => $details !== '' ? $details : 'Created from the Settings > Data & Privacy screen.',
            'identity_verified' => $validated['identity_verified'] ? 'verified' : 'pending',
            'identity_verified_at' => $validated['identity_verified'] ? now() : null,
            'verified_by_user_id' => $validated['identity_verified'] ? $request->user()?->id : null,
            'verification_method' => $validated['identity_verified'] ? 'Verified from settings intake' : null,
            'status' => $validated['identity_verified'] ? 'in_progress' : 'identity_verification',
            'received_at' => now(),
            'due_date' => $this->addWorkingDays(20)->toDateString(),
            'created_by' => $request->user()?->id,
        ]);

        $dsar->load('assignedTo:id,name');

        return response()->json([
            'message' => 'Privacy request created.',
            'request' => $this->mapDsarRequest($dsar),
        ]);
    }

    public function storeBreach(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'breach_type' => ['required', 'in:unauthorised_access,data_loss,system_compromise,employee_error,third_party'],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'description' => ['required', 'string'],
            'data_types' => ['nullable', 'array'],
            'data_types.*' => ['string', 'max:255'],
            'individuals_affected' => ['nullable', 'integer', 'min:0'],
            'discovery_date' => ['required', 'date'],
            'commissioner_notified' => ['required', 'boolean'],
            'individuals_notified' => ['required', 'boolean'],
        ]);

        $notificationRequired = $validated['commissioner_notified']
            || in_array($validated['severity'], ['high', 'critical'], true);

        $breach = DataBreachLog::create([
            'breach_reference' => 'BR-' . now()->year . '-' . str_pad(
                DataBreachLog::whereYear('created_at', now()->year)->count() + 1,
                4,
                '0',
                STR_PAD_LEFT
            ),
            'breach_type' => $validated['breach_type'],
            'severity' => $validated['severity'],
            'discovered_at' => Carbon::parse($validated['discovery_date']),
            'discovered_by_user_id' => $request->user()?->id,
            'nature_of_breach' => $validated['description'],
            'affected_data_categories' => array_values($validated['data_types'] ?? []),
            'approximate_individuals_affected' => $validated['individuals_affected'] ?? null,
            'likely_consequences' => 'Assessment in progress.',
            'measures_taken' => 'Initial breach record created from the Settings > Data & Privacy screen.',
            'requires_authority_notification' => $notificationRequired,
            'authority_notified_at' => $validated['commissioner_notified'] ? now() : null,
            'requires_subject_notification' => $validated['individuals_notified'],
            'subjects_notified_at' => $validated['individuals_notified'] ? now() : null,
            'status' => $validated['commissioner_notified'] || $validated['individuals_notified']
                ? 'notified'
                : 'under_investigation',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Data breach recorded.',
            'breach' => $this->mapBreach($breach),
        ]);
    }

    public function storeProcessor(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $record = $this->normaliseProcessorPayload($request);
        $processors = $this->loadStoredArray(self::PROCESSORS_KEY);
        $processors[] = $record;
        $this->storeObjectSetting(self::PROCESSORS_KEY, array_values($processors));

        return response()->json([
            'message' => 'Processor added.',
            'processor' => $record,
        ]);
    }

    public function updateProcessor(Request $request, string $processorId): JsonResponse
    {
        $this->authorizeManage($request);

        $updatedRecord = $this->normaliseProcessorPayload($request, $processorId);
        $processors = collect($this->loadStoredArray(self::PROCESSORS_KEY))
            ->map(function (array $record) use ($processorId, $updatedRecord) {
                return ($record['id'] ?? null) === $processorId ? $updatedRecord : $record;
            })
            ->values()
            ->all();

        $this->storeObjectSetting(self::PROCESSORS_KEY, $processors);

        return response()->json([
            'message' => 'Processor updated.',
            'processor' => $updatedRecord,
        ]);
    }

    public function destroyProcessor(Request $request, string $processorId): JsonResponse
    {
        $this->authorizeManage($request);

        $processors = collect($this->loadStoredArray(self::PROCESSORS_KEY))
            ->reject(fn (array $record) => ($record['id'] ?? null) === $processorId)
            ->values()
            ->all();

        $this->storeObjectSetting(self::PROCESSORS_KEY, $processors);

        return response()->json([
            'message' => 'Processor removed.',
        ]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
    }

    /**
     * @return array<string, string>
     */
    private function loadRetentionValues(): array
    {
        $storedPolicies = DataRetentionPolicy::query()
            ->whereIn('model_type', array_column(self::RETENTION_ROWS, 'model_type'))
            ->get()
            ->keyBy('model_type');

        $values = [];

        foreach (self::RETENTION_ROWS as $id => $config) {
            $policy = $storedPolicies->get($config['model_type']);
            $settingValue = $policy?->retention_conditions['setting_value'] ?? $config['default'];
            $values[$id] = is_string($settingValue) ? $settingValue : $config['default'];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function loadObjectSetting(string $key, array $defaults): array
    {
        $stored = AppSetting::query()->where('key', $key)->value('value');

        return is_array($stored)
            ? array_merge($defaults, $stored)
            : $defaults;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $value
     */
    private function storeObjectSetting(string $key, array $value): void
    {
        AppSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadStoredArray(string $key): array
    {
        $value = AppSetting::query()->where('key', $key)->value('value');

        return is_array($value) ? $value : [];
    }

    private function parseRetentionYears(string $value): ?int
    {
        if (str_ends_with($value, 'yr')) {
            return (int) Str::before($value, 'yr');
        }

        return null;
    }

    private function mapDsarRequest(DataSubjectRequest $request): array
    {
        $deadline = $request->extended_due_date ?? $request->due_date;
        $status = $request->isOverdue()
            ? 'overdue'
            : match ($request->status) {
                'completed' => 'completed',
                'rejected' => 'rejected',
                'withdrawn' => 'withdrawn',
                'received', 'identity_verification' => 'new',
                default => 'in_progress',
            };

        return [
            'id' => $request->reference_number,
            'type' => match ($request->request_type) {
                'rectification' => 'Correction',
                default => Str::headline($request->request_type),
            },
            'requester' => $request->subject_name ?: $request->subject_email ?: 'Unknown requester',
            'dateReceived' => optional($request->received_at)->toDateString() ?? now()->toDateString(),
            'dueDate' => $deadline?->toDateString() ?? '',
            'workingDaysLeft' => $deadline ? $this->workingDaysDifference($deadline) : 0,
            'status' => $status,
            'assignedTo' => $request->assignedTo?->name ?? 'Unassigned',
        ];
    }

    private function mapBreach(DataBreachLog $breach): array
    {
        $status = match ($breach->status) {
            'contained' => 'contained',
            'resolved' => 'resolved',
            'notified' => 'reported',
            default => 'investigating',
        };

        return [
            'id' => $breach->breach_reference,
            'date' => optional($breach->discovered_at)->toDateString() ?? now()->toDateString(),
            'type' => Str::headline((string) ($breach->breach_type ?: 'incident')),
            'severity' => in_array($breach->severity, ['low', 'medium', 'high', 'critical'], true)
                ? $breach->severity
                : 'medium',
            'individualsAffected' => (int) ($breach->approximate_individuals_affected ?? 0),
            'commissionerNotified' => $breach->authority_notified_at !== null,
            'commissionerNotificationRequired' => (bool) $breach->requires_authority_notification,
            'status' => $status,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProcessors(): array
    {
        return collect($this->loadStoredArray(self::PROCESSORS_KEY))
            ->map(function ($record) {
                return is_array($record) ? $record : [];
            })
            ->filter(fn (array $record) => isset($record['id'], $record['company']))
            ->values()
            ->all();
    }

    /**
     * @param string|null $processorId
     * @return array<string, mixed>
     */
    private function normaliseProcessorPayload(Request $request, ?string $processorId = null): array
    {
        $validated = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'purpose' => ['required', 'in:' . implode(',', array_keys(self::PROCESSOR_PURPOSE_LABELS))],
            'data_categories' => ['nullable', 'array'],
            'data_categories.*' => ['string', 'max:255'],
            'agreement_status' => ['required', 'in:dpa_signed,standard_terms,negotiating,no_agreement'],
            'country' => ['required', 'in:' . implode(',', array_keys(self::COUNTRY_LABELS))],
            'review_date' => ['required', 'date'],
        ]);

        $country = self::COUNTRY_LABELS[$validated['country']] ?? self::COUNTRY_LABELS['other'];
        $reviewDate = Carbon::parse($validated['review_date'])->toDateString();

        return [
            'id' => $processorId ?? (string) Str::uuid(),
            'company' => $validated['company'],
            'contact' => $validated['contact'],
            'email' => $validated['email'] ?? null,
            'purpose' => $validated['purpose'],
            'purposes' => [self::PROCESSOR_PURPOSE_LABELS[$validated['purpose']] ?? Str::headline($validated['purpose'])],
            'dataCategories' => array_values($validated['data_categories'] ?? []),
            'agreementStatus' => $validated['agreement_status'],
            'countryCode' => $validated['country'],
            'country' => $country['label'],
            'countryFlag' => $country['flag'],
            'reviewDate' => $reviewDate,
            'overdue' => Carbon::parse($reviewDate)->startOfDay()->lt(now()->startOfDay()),
        ];
    }

    private function addWorkingDays(int $days): Carbon
    {
        $date = now()->copy();
        $added = 0;

        while ($added < $days) {
            $date->addDay();

            if (! $date->isWeekend()) {
                $added++;
            }
        }

        return $date;
    }

    private function workingDaysDifference(Carbon $deadline): int
    {
        $today = now()->startOfDay();
        $target = $deadline->copy()->startOfDay();
        $sign = $today->greaterThan($target) ? -1 : 1;
        $cursor = $sign === 1 ? $today->copy() : $target->copy();
        $end = $sign === 1 ? $target : $today;
        $days = 0;

        while ($cursor->lt($end)) {
            $cursor->addDay();

            if (! $cursor->isWeekend()) {
                $days++;
            }
        }

        return $days * $sign;
    }
}
