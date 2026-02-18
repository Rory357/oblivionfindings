<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Auth\Access\AuthorizationException;

class HrEvidencePackService
{
    /**
     * Fields that must be redacted in compliance evidence packs.
     */
    public const REDACTED_FIELDS = [
        'date_of_birth',
        'ird_number',
        'bank_account',
        'home_address',
        'hourly_rate',
        'annual_salary',
        'personal_phone',
    ];

    public function __construct(
        private readonly ComplianceMatrixService $complianceService,
    ) {}

    /**
     * Generate a compliance audit evidence pack for a single employee.
     *
     * Collects all compliance statuses, training records, credentials,
     * background checks, and policy attestations. Applies redaction
     * based on the requesting user's permissions.
     *
     * @param  HrEmployeeProfile  $profile
     * @param  User               $requestedBy  The user generating the pack (for permission checks)
     * @param  array              $options       Options: include_documents, include_training_certs, redact_pii
     * @return array{employee: array, compliance: array, documents: array, generated_at: string}
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If user lacks permission
     */
    public function generateEmployeePack(HrEmployeeProfile $profile, User $requestedBy, array $options = []): array
    {
        if (! $this->canGeneratePack($requestedBy, $profile)) {
            throw new AuthorizationException('You do not have permission to generate this evidence pack.');
        }

        $redactPii = $options['redact_pii'] ?? true;
        $includeDocuments = $options['include_documents'] ?? true;
        $includeTrainingCerts = $options['include_training_certs'] ?? true;

        $isPrivileged = $this->hasPrivilegedAccess($requestedBy);
        if (! $isPrivileged) {
            $redactPii = true;
        }

        $employeeData = $this->buildEmployeeSection($profile, $redactPii);
        $complianceData = $this->buildComplianceSection($profile);
        $documents = $includeDocuments ? $this->buildDocumentsSection($profile, $includeTrainingCerts) : [];

        return [
            'employee' => $employeeData,
            'compliance' => $complianceData,
            'documents' => $documents,
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $requestedBy->id,
            'redacted' => $redactPii,
        ];
    }

    /**
     * Generate a bulk compliance evidence pack for a site or tenant.
     *
     * Iterates all active employees (optionally filtered by site) and
     * generates individual packs, combining them into a single report.
     *
     * @param  int        $tenantId
     * @param  User       $requestedBy
     * @param  int|null   $siteId       Filter to a specific site
     * @param  array      $options
     * @return array{summary: array, employees: array, generated_at: string}
     */
    public function generateBulkPack(?int $tenantId, User $requestedBy, ?int $siteId = null, array $options = []): array
    {
        if (! $this->hasPrivilegedAccess($requestedBy)) {
            throw new AuthorizationException('Only HR/compliance administrators can generate bulk evidence packs.');
        }

        $query = HrEmployeeProfile::where('tenant_id', $tenantId)->active();
        if ($siteId) {
            $query->atSite($siteId);
        }

        $profiles = $query->get();
        $employees = [];
        $stats = ['compliant' => 0, 'non_compliant' => 0, 'expiring_soon' => 0];

        foreach ($profiles as $profile) {
            $pack = $this->generateEmployeePack($profile, $requestedBy, $options);
            $employees[] = $pack;

            $statuses = collect($pack['compliance']);
            if ($statuses->where('status', 'expired')->isNotEmpty() || $statuses->where('status', 'not_started')->isNotEmpty()) {
                $stats['non_compliant']++;
            } elseif ($statuses->where('status', 'expiring_soon')->isNotEmpty()) {
                $stats['expiring_soon']++;
            } else {
                $stats['compliant']++;
            }
        }

        return [
            'summary' => [
                'total_employees' => $profiles->count(),
                ...$stats,
            ],
            'employees' => $employees,
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $requestedBy->id,
        ];
    }

    /**
     * Store a generated evidence pack to disk for audit retention.
     *
     * @param  array   $pack      The pack data from generateEmployeePack or generateBulkPack
     * @param  string  $filename  Optional custom filename
     * @return string  Storage path
     */
    public function storePack(array $pack, ?string $filename = null): string
    {
        $tenantPrefix = data_get($pack, 'summary.tenant_id', data_get($pack, 'employee.tenant_id', 'global'));
        $filename = $filename ?? sprintf('evidence-packs/%s_%s_%s.json', $tenantPrefix, now()->format('Y-m-d_His'), $pack['generated_by'] ?? 'system');

        Storage::disk('private')->put($filename, json_encode($pack, JSON_PRETTY_PRINT));

        return $filename;
    }

    /**
     * Build the employee summary section with optional PII redaction.
     */
    protected function buildEmployeeSection(HrEmployeeProfile $profile, bool $redact): array
    {
        $data = [
            'id' => $profile->id,
            'employee_number' => $profile->employee_number,
            'name' => $profile->full_name,
            'position_title' => $profile->position_title,
            'position_role' => $profile->position_role,
            'employment_type' => $profile->employment_type,
            'start_date' => $profile->start_date?->toDateString(),
            'is_active' => $profile->is_active,
            'primary_site_id' => $profile->primary_site_id,
        ];

        if (! $redact) {
            $data['personal_email'] = $profile->personal_email;
            $data['personal_phone'] = $profile->personal_phone;
            $data['date_of_birth'] = $profile->date_of_birth;
        } else {
            foreach (self::REDACTED_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = '[REDACTED]';
                }
            }
        }

        return $data;
    }

    /**
     * Build the compliance statuses section.
     */
    protected function buildComplianceSection(HrEmployeeProfile $profile): array
    {
        $statuses = HrStaffComplianceStatus::where('user_id', $profile->user_id)
            ->with('requirement:id,code,name,category,hard_stop')
            ->get();

        return $statuses->map(fn($s) => [
            'requirement_code' => $s->requirement?->code,
            'requirement_name' => $s->requirement?->name,
            'category' => $s->requirement?->category,
            'hard_stop' => $s->requirement?->hard_stop,
            'status' => $s->status,
            'evidence_type' => $s->evidence_type,
            'valid_from' => $s->valid_from?->toDateString(),
            'expires_at' => $s->expires_at?->toDateString(),
            'last_checked_at' => $s->last_checked_at?->toIso8601String(),
        ])->toArray();
    }

    /**
     * Build the documents section (non-restricted documents only for non-privileged users).
     */
    protected function buildDocumentsSection(HrEmployeeProfile $profile, bool $includeTrainingCerts): array
    {
        $query = HrDocument::where('employee_profile_id', $profile->id);

        if ($includeTrainingCerts) {
            $query->whereIn('category', ['compliance', 'training', 'certificate', 'background_check']);
        } else {
            $query->whereIn('category', ['compliance', 'background_check']);
        }

        return $query->get()->map(fn($doc) => [
            'id' => $doc->id,
            'title' => $doc->title,
            'category' => $doc->category,
            'original_name' => $doc->original_name,
            'is_restricted' => $doc->is_restricted,
            'created_at' => $doc->created_at?->toIso8601String(),
        ])->toArray();
    }

    /**
     * Check if the requesting user has privileged access (can see unredacted data).
     */
    protected function hasPrivilegedAccess(User $user): bool
    {
        if ($user->canDo('hr.compliance.manage') || $user->canDo('hr.compliance.view')) {
            return true;
        }

        return $user->hasRole('admin', 'hr', 'provider_manager', 'compliance_lead');
    }

    protected function canGeneratePack(User $requestedBy, HrEmployeeProfile $profile): bool
    {
        if ($this->hasPrivilegedAccess($requestedBy)) {
            return true;
        }

        return (int) $requestedBy->id === (int) $profile->user_id;
    }
}
