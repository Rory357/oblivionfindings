<?php

namespace App\Services\Assurance;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Enums\AssuranceStatus;
use App\Models\Shift;
use App\Models\SiteCertification;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class NzsAssuranceResolver
{
    public const CERTIFICATION_TYPE = 'healthcert_certification';

    public const FIRST_AID_REQUIREMENT_CODE = 'FIRST_AID';

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** @return array{certification_status:string,first_aid_coverage_status:string} */
    public function resolveSites(iterable $siteIds): array
    {
        $ids = collect($siteIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        return [
            'certification_status' => $this->certificationForSites($ids)->value,
            'first_aid_coverage_status' => $this->firstAidCoverageForSites($ids)->value,
        ];
    }

    public function certificationForSite(int $siteId): AssuranceStatus
    {
        try {
            if (! $this->hasCertificationSchema()) {
                return AssuranceStatus::UNKNOWN;
            }

            $head = SiteCertification::withTrashed()
                ->where('site_id', $siteId)
                ->where('certification_type', self::CERTIFICATION_TYPE)
                ->orderByDesc('id')
                ->first();

            if (! $head) {
                return AssuranceStatus::UNKNOWN;
            }

            if ($head->trashed() || $head->revoked_at !== null || $head->status !== 'current') {
                return AssuranceStatus::ACTION_REQUIRED;
            }

            $today = now()->startOfDay();
            if (! $head->issued_date || $head->issued_date->startOfDay()->isAfter($today)
                || ! $head->expiry_date || $head->expiry_date->startOfDay()->isBefore($today)
                || blank($head->issuing_body) || blank($head->reference_number)
                || ! $head->reviewed_by || ! $head->reviewed_at
                || $head->reviewed_at->isFuture()
                || $head->reviewed_at->startOfDay()->isBefore($head->issued_date->startOfDay())
                || $head->document_disk !== 'private' || blank($head->document_path)
                || ! self::evidencePathBelongsToSite($head->document_path, $siteId)
                || ! is_string($head->evidence_sha256)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $head->evidence_sha256)) {
                return AssuranceStatus::ACTION_REQUIRED;
            }

            $reviewer = User::query()->whereNotNull('approved_at')->find($head->reviewed_by);
            if (! $reviewer || ! in_array(
                $siteId,
                $this->siteAccess->accessibleSiteIds($reviewer, ['sites.viewAll']),
                true,
            )) {
                return AssuranceStatus::ACTION_REQUIRED;
            }

            return $this->evidenceDigestMatches(
                $head->document_disk,
                $head->document_path,
                $head->evidence_sha256,
            );
        } catch (Throwable $exception) {
            $this->reportUnavailable('certification', $exception, ['site_id' => $siteId]);

            return AssuranceStatus::UNKNOWN;
        }
    }

    public function certificationForSites(iterable $siteIds): AssuranceStatus
    {
        return $this->aggregate($siteIds, fn (int $siteId) => $this->certificationForSite($siteId));
    }

    public function firstAidCoverageForSites(
        iterable $siteIds,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): AssuranceStatus {
        $from ??= now();
        $to ??= $from->copy()->addDays(7);

        return $this->aggregate(
            $siteIds,
            fn (int $siteId) => $this->firstAidCoverageForSite($siteId, $from, $to),
        );
    }

    public function firstAidCoverageForSite(
        int $siteId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): AssuranceStatus {
        $from ??= now();
        $to ??= $from->copy()->addDays(7);

        try {
            if (! $this->hasFirstAidCoverageSchema()) {
                return AssuranceStatus::UNKNOWN;
            }

            /** @var Collection<int, Shift> $shifts */
            $shifts = Shift::query()
                ->where('site_id', $siteId)
                ->whereIn('status', ['scheduled', 'in_progress'])
                ->where('starts_at', '<', $to)
                ->where('ends_at', '>', $from)
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get(['id', 'site_id', 'user_id', 'starts_at', 'ends_at', 'status']);

            if ($shifts->isEmpty()) {
                return AssuranceStatus::UNKNOWN;
            }

            $competency = [];
            foreach ($shifts as $shift) {
                if (! $shift->starts_at || ! $shift->ends_at || $shift->ends_at->lessThanOrEqualTo($shift->starts_at)) {
                    return AssuranceStatus::ACTION_REQUIRED;
                }

                $candidateStates = $shifts
                    ->filter(fn (Shift $candidate) => $candidate->user_id
                        && $candidate->starts_at?->lessThanOrEqualTo($shift->starts_at)
                        && $candidate->ends_at?->greaterThanOrEqualTo($shift->ends_at))
                    ->map(function (Shift $candidate) use (&$competency, $siteId, $shift): AssuranceStatus {
                        $key = implode(':', [
                            $candidate->user_id,
                            $siteId,
                            $shift->starts_at->toISOString(),
                            $shift->ends_at->toISOString(),
                        ]);

                        return $competency[$key] ??= $this->firstAiderCompetency(
                            (int) $candidate->user_id,
                            $siteId,
                            $shift->starts_at,
                            $shift->ends_at,
                        );
                    });

                if ($candidateStates->containsStrict(AssuranceStatus::CERTIFIED)) {
                    continue;
                }

                if ($candidateStates->containsStrict(AssuranceStatus::UNKNOWN)) {
                    return AssuranceStatus::UNKNOWN;
                }

                return AssuranceStatus::ACTION_REQUIRED;
            }

            return AssuranceStatus::CERTIFIED;
        } catch (Throwable $exception) {
            $this->reportUnavailable('first_aid_coverage', $exception, ['site_id' => $siteId]);

            return AssuranceStatus::UNKNOWN;
        }
    }

    private function firstAiderCompetency(
        int $userId,
        int $siteId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): AssuranceStatus {
        $profileExists = HrEmployeeProfile::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('is_first_aider', true)
            ->whereDate('start_date', '<=', $startsAt->toDateString())
            ->where(function ($query) use ($endsAt): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $endsAt->toDateString());
            })
            ->whereHas('user', fn ($query) => $query->whereNotNull('approved_at'))
            ->where(function ($query) use ($siteId): void {
                $query->where('primary_site_id', $siteId)
                    ->orWhereJsonContains('secondary_site_ids', $siteId);
            })
            ->exists();

        if (! $profileExists) {
            return AssuranceStatus::ACTION_REQUIRED;
        }

        $status = HrStaffComplianceStatus::query()
            ->where('user_id', $userId)
            ->whereHas('requirement', fn ($query) => $query
                ->where('code', self::FIRST_AID_REQUIREMENT_CODE)
                ->where('is_active', true))
            ->orderByDesc('id')
            ->first();

        if (! $status || ! in_array($status->status, ['compliant', 'expiring_soon'], true)
            || $status->evidence_type !== 'training_record' || ! $status->evidence_id
            || ! $status->valid_from || $status->valid_from->startOfDay()->isAfter($startsAt)
            || ! $status->expires_at || $status->expires_at->endOfDay()->isBefore($endsAt)) {
            return AssuranceStatus::ACTION_REQUIRED;
        }

        $record = StaffTrainingRecord::withTrashed()
            ->with('hrCourse.complianceRequirement')
            ->find($status->evidence_id);

        if (! $record || $record->trashed() || (int) $record->user_id !== $userId
            || ! in_array($record->status, ['completed', 'passed'], true)
            || $record->renewed_by_record_id !== null
            || ! $record->completed_at || $record->completed_at->isAfter($startsAt)
            || ! $record->expires_at || $record->expires_at->isBefore($endsAt)
            || blank($record->certificate_number) || blank($record->certificate_path)
            || ! self::evidencePathBelongsToPrefix(
                $record->certificate_path,
                "hr/training/certificates/{$record->id}/",
            )
            || ! $record->hrCourse?->is_active
            || $record->hrCourse?->complianceRequirement?->code !== self::FIRST_AID_REQUIREMENT_CODE
            || ! $record->hrCourse?->complianceRequirement?->is_active) {
            return AssuranceStatus::ACTION_REQUIRED;
        }

        try {
            return Storage::disk('private')->exists($record->certificate_path)
                ? AssuranceStatus::CERTIFIED
                : AssuranceStatus::ACTION_REQUIRED;
        } catch (Throwable $exception) {
            $this->reportUnavailable('first_aid_competency_evidence', $exception, [
                'site_id' => $siteId,
                'user_id' => $userId,
            ]);

            return AssuranceStatus::UNKNOWN;
        }
    }

    private function evidenceDigestMatches(string $disk, string $path, string $expected): AssuranceStatus
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            return AssuranceStatus::ACTION_REQUIRED;
        }

        $stream = $storage->readStream($path);
        if (! is_resource($stream)) {
            return AssuranceStatus::ACTION_REQUIRED;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            $actual = hash_final($context);
        } finally {
            fclose($stream);
        }

        return hash_equals(strtolower($expected), strtolower($actual))
            ? AssuranceStatus::CERTIFIED
            : AssuranceStatus::ACTION_REQUIRED;
    }

    public static function evidencePathBelongsToSite(string $path, int $siteId): bool
    {
        return self::evidencePathBelongsToPrefix($path, "site-certifications/{$siteId}/");
    }

    private static function evidencePathBelongsToPrefix(string $path, string $prefix): bool
    {
        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        $relative = substr($path, strlen($prefix));
        $segments = explode('/', $relative);

        return $relative !== '' && collect($segments)->every(
            fn (string $segment) => ! in_array($segment, ['', '.', '..'], true)
                && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $segment) === 1,
        );
    }

    private function aggregate(iterable $siteIds, callable $resolve): AssuranceStatus
    {
        $ids = collect($siteIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return AssuranceStatus::UNKNOWN;
        }

        $sawUnknown = false;
        foreach ($ids as $siteId) {
            $status = $resolve($siteId);
            if ($status === AssuranceStatus::ACTION_REQUIRED) {
                return $status;
            }
            $sawUnknown = $sawUnknown || $status === AssuranceStatus::UNKNOWN;
        }

        return $sawUnknown ? AssuranceStatus::UNKNOWN : AssuranceStatus::CERTIFIED;
    }

    private function hasCertificationSchema(): bool
    {
        return Schema::hasTable('site_certifications')
            && Schema::hasColumns('site_certifications', [
                'document_disk', 'evidence_sha256', 'supersedes_certification_id',
                'revoked_at', 'revoked_by', 'revocation_reason',
            ]);
    }

    private function hasFirstAidCoverageSchema(): bool
    {
        foreach (['shifts', 'hr_employee_profiles', 'hr_staff_compliance_status', 'hr_compliance_requirements', 'staff_training_records', 'hr_courses'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, int> $context */
    private function reportUnavailable(string $signal, Throwable $exception, array $context): void
    {
        Log::warning('NZS assurance signal unavailable.', [
            'signal' => $signal,
            ...$context,
            'exception' => $exception::class,
        ]);
    }
}
