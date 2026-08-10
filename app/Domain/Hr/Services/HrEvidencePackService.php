<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

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
        private readonly UserSiteAccessService $siteAccess,
        private readonly HrCurrentStaffService $currentStaff,
        private readonly ComplianceMatrixService $complianceMatrix,
    ) {}

    /**
     * Generate a compliance audit evidence pack for a single employee.
     *
     * Collects all compliance statuses, training records, credentials,
     * background checks, and policy attestations. Applies redaction
     * based on the requesting user's permissions.
     *
     * @param  User  $requestedBy  The user generating the pack (for permission checks)
     * @param  array  $options  Options: include_documents, include_training_certs, redact_pii
     * @return array{employee: array, compliance: array, documents: array, generated_at: string}
     *
     * @throws AuthorizationException If user lacks permission
     */
    public function generateEmployeePack(HrEmployeeProfile $profile, User $requestedBy, array $options = []): array
    {
        if (! $this->canGeneratePack($requestedBy, $profile)) {
            throw new AuthorizationException('You do not have permission to generate this evidence pack.');
        }

        $redactPii = $options['redact_pii'] ?? true;
        $includeDocuments = $options['include_documents'] ?? true;
        $includeTrainingCerts = $options['include_training_certs'] ?? true;

        if (! $this->canViewUnredactedProfile($requestedBy, $profile)) {
            $redactPii = true;
        }

        $employeeData = $this->buildEmployeeSection($profile, $redactPii);
        $complianceData = $this->buildComplianceSection($profile);
        $documents = $includeDocuments
            ? $this->buildDocumentsSection($profile, $includeTrainingCerts, $requestedBy)
            : [];

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
     * Generate a bulk compliance evidence pack for the application or one Site.
     *
     * Iterates all active employees (optionally filtered by site) and
     * generates individual packs, combining them into a single report.
     *
     * @param  int|null  $siteId  Filter to a specific visible Site
     * @return array{summary: array, employees: array, generated_at: string}
     */
    public function generateBulkPack(User $requestedBy, ?int $siteId = null, array $options = []): array
    {
        if (! $requestedBy->canDo('hr.compliance.manage')) {
            throw new AuthorizationException('Only HR/compliance administrators can generate bulk evidence packs.');
        }

        $staff = $this->currentStaff->currentUsersQuery()->select('users.id');
        $this->siteAccess->applyStaffScope($staff, $requestedBy);
        $query = HrEmployeeProfile::query()->whereIn('user_id', $staff);
        if ($siteId) {
            abort_unless(
                in_array($siteId, $this->siteAccess->accessibleSiteIds($requestedBy), true),
                404,
            );
            $query->atSite($siteId);
        }

        $profiles = $query->get();
        $employees = [];
        $stats = ['compliant' => 0, 'non_compliant' => 0, 'expiring_soon' => 0];

        foreach ($profiles as $profile) {
            $pack = $this->generateEmployeePack($profile, $requestedBy, $options);
            $employees[] = $pack;

            $statuses = collect($pack['compliance']);
            if ($statuses->isEmpty()
                || $statuses->where('status', 'expired')->isNotEmpty()
                || $statuses->where('status', 'not_started')->isNotEmpty()
            ) {
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
     * @param  array  $pack  The pack data from generateEmployeePack or generateBulkPack
     * @param  string  $filename  Optional custom filename
     * @return string Storage path
     */
    public function storePack(array $pack, ?string $filename = null): string
    {
        $filename = $filename ?? sprintf(
            'evidence-packs/application_%s_%s.json',
            now()->format('Y-m-d_His'),
            $pack['generated_by'] ?? 'system',
        );

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
        $staff = User::query()
            ->with(['roles:id,name', 'complianceStatuses'])
            ->findOrFail($profile->user_id);
        $snapshots = $this->complianceMatrix
            ->snapshotsForUsers(collect([$staff]))
            ->get((int) $staff->id, collect());

        return $snapshots->map(fn (array $snapshot) => [
            'requirement_code' => $snapshot['requirement']->code,
            'requirement_name' => $snapshot['requirement']->name,
            'category' => $snapshot['requirement']->category,
            'hard_stop' => (bool) $snapshot['requirement']->hard_stop,
            'status' => $snapshot['status'],
            'evidence_type' => $snapshot['status_row']?->evidence_type,
            'valid_from' => $snapshot['status_row']?->valid_from?->toDateString(),
            'expires_at' => $snapshot['status_row']?->expires_at?->toDateString(),
            'last_checked_at' => $snapshot['status_row']?->last_checked_at?->toIso8601String(),
        ])->toArray();
    }

    /**
     * Build the documents section (non-restricted documents only for non-privileged users).
     */
    protected function buildDocumentsSection(
        HrEmployeeProfile $profile,
        bool $includeTrainingCerts,
        User $requestedBy,
    ): array {
        $isOwnPack = (int) $requestedBy->id === (int) $profile->user_id;
        $canViewDocuments = $isOwnPack
            || $requestedBy->canDo('hr.documents.view')
            || $requestedBy->canDo('hr.documents.manage');
        if (! $canViewDocuments) {
            return [];
        }

        $query = HrDocument::where('employee_profile_id', $profile->id);
        if (! $requestedBy->canDo('hr.documents.manage')) {
            $query->where('is_restricted', false);
        }

        if ($includeTrainingCerts) {
            $query->whereIn('category', ['compliance', 'training', 'certificate', 'background_check']);
        } else {
            $query->whereIn('category', ['compliance', 'background_check']);
        }

        return $query->get()->map(fn ($doc) => [
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
    protected function canViewUnredactedProfile(User $user, HrEmployeeProfile $profile): bool
    {
        if ((int) $user->id === (int) $profile->user_id) {
            return true;
        }

        return $user->canDo('hr.employees.viewRestricted');
    }

    protected function canGeneratePack(User $requestedBy, HrEmployeeProfile $profile): bool
    {
        if ((int) $requestedBy->id === (int) $profile->user_id) {
            return true;
        }

        if (! $requestedBy->canDo('hr.compliance.view')
            && ! $requestedBy->canDo('hr.compliance.manage')
        ) {
            return false;
        }

        if (! $this->currentStaff->isCurrent((int) $profile->user_id)) {
            return false;
        }

        $visibleStaff = User::query()->whereKey($profile->user_id);
        $this->siteAccess->applyStaffScope($visibleStaff, $requestedBy);

        return $visibleStaff->exists();
    }
}
