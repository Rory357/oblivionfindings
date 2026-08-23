<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Enums\ComplianceExportDataset;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Http\Controllers\Controller;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-side, uncapped CSV export for the Compliance hub tabs. Streams rows so a
 * large register doesn't buffer in memory. Access is gated per dataset.
 */
class ComplianceExportController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly ComplianceMatrixService $complianceMatrix,
    ) {}

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'dataset' => ['required', Rule::enum(ComplianceExportDataset::class)],
            'format' => ['nullable', 'string', Rule::in(['csv'])],
        ]);

        $dataset = ComplianceExportDataset::from($validated['dataset']);
        abort_unless($dataset->allows($user), 403);

        $filename = "compliance-{$dataset->value}-".date('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($dataset, $user) {
            $out = fopen('php://output', 'w');

            match ($dataset) {
                ComplianceExportDataset::Staff => $this->streamStaff($out, $user),
                ComplianceExportDataset::Vetting => $this->streamVetting($out, $user),
                ComplianceExportDataset::Drivers => $this->streamDrivers($out, $user),
                ComplianceExportDataset::Renewals => $this->streamRenewals($out, $user),
            };

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @param resource $out */
    private function streamStaff($out, User $viewer): void
    {
        $this->putCsv($out, ['Staff member', 'Email', 'Requirement', 'Code', 'Status', 'Valid from', 'Expires', 'Exempted until', 'Notes']);

        $staff = User::query()
            ->with([
                'roles:id,name',
                'hrEmployeeProfile:id,user_id,work_email',
                'complianceStatuses',
            ]);
        $this->siteAccess->applyStaffScope($staff, $viewer);
        $staff->orderBy('users.id')
            ->chunkById(200, function ($users) use ($out): void {
                $snapshots = $this->complianceMatrix->snapshotsForUsers($users);
                foreach ($users as $user) {
                    foreach ($snapshots->get((int) $user->id, collect()) as $snapshot) {
                        $requirement = $snapshot['requirement'];
                        $status = $snapshot['status_row'];
                        $this->putCsv($out, [
                            $user->name,
                            $user->hrEmployeeProfile?->work_email ?? '',
                            $requirement->name,
                            $requirement->code,
                            $snapshot['status'],
                            optional($status?->valid_from)->toDateString(),
                            optional($status?->expires_at)->toDateString(),
                            optional($status?->exempted_until)->toDateString(),
                            str_replace(["\n", "\r"], ' ', (string) ($status?->notes ?? '')),
                        ]);
                    }
                }
            }, 'users.id', 'id');
    }

    /** @param resource $out */
    private function streamVetting($out, User $viewer): void
    {
        $this->putCsv($out, ['Staff member', 'Check type', 'Provider', 'Reference', 'Status', 'Check date', 'Expires']);

        StaffBackgroundCheck::query()
            ->whereIn('user_id', $this->visibleCurrentStaffIds($viewer))
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    $this->putCsv($out, [
                        $r->user?->name ?? '',
                        ucfirst(str_replace('_', ' ', (string) $r->check_type)),
                        $r->provider,
                        $r->reference_number,
                        $r->status,
                        optional($r->check_date)->toDateString(),
                        optional($r->expires_at)->toDateString(),
                    ]);
                }
            });
    }

    /** @param resource $out */
    private function streamDrivers($out, User $viewer): void
    {
        $this->putCsv($out, ['Driver', 'Licence class', 'Licence number', 'Endorsements', 'Status', 'Can drive clients', 'Expires']);

        HrDriverEligibility::query()
            ->whereIn('user_id', $this->visibleCurrentStaffIds($viewer))
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    $this->putCsv($out, [
                        $r->user?->name ?? '',
                        $r->licence_class,
                        $r->licence_number,
                        is_array($r->licence_endorsements) ? implode(' ', $r->licence_endorsements) : '',
                        $r->status,
                        $r->can_drive_clients ? 'Yes' : 'No',
                        optional($r->licence_expires_at)->toDateString(),
                    ]);
                }
            });
    }

    /** @param resource $out */
    private function streamRenewals($out, User $viewer): void
    {
        $this->putCsv($out, ['Type', 'Staff member', 'Item', 'Due date', 'Status']);

        $staff = User::query()
            ->with(['roles:id,name', 'complianceStatuses']);
        $this->siteAccess->applyHrEmployeeStaffScope($staff, $viewer);
        $staff->orderBy('users.id')
            ->chunkById(200, function ($users) use ($out): void {
                $snapshots = $this->complianceMatrix->snapshotsForUsers($users);
                foreach ($users as $user) {
                    foreach ($snapshots->get((int) $user->id, collect()) as $snapshot) {
                        $status = $snapshot['status_row'];
                        if (! $status?->expires_at) {
                            continue;
                        }
                        $this->putCsv($out, [
                            'Compliance',
                            $user->name,
                            $snapshot['requirement']->name,
                            $status->expires_at->toDateString(),
                            $snapshot['status'],
                        ]);
                    }
                }
            }, 'users.id', 'id');

        StaffBackgroundCheck::query()
            ->whereIn('user_id', $this->visibleRenewalStaffIds($viewer))
            ->whereNotNull('expires_at')
            ->with('user:id,name')
            ->orderBy('expires_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    $this->putCsv($out, [
                        'Vetting',
                        $r->user?->name ?? '',
                        ucfirst(str_replace('_', ' ', (string) $r->check_type)),
                        optional($r->expires_at)->toDateString(),
                        $r->status,
                    ]);
                }
            });

        HrDriverEligibility::query()
            ->whereIn('user_id', $this->visibleRenewalStaffIds($viewer))
            ->whereNotNull('licence_expires_at')
            ->with('user:id,name')
            ->orderBy('licence_expires_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    $this->putCsv($out, [
                        'Driver',
                        $r->user?->name ?? '',
                        'Driver licence',
                        optional($r->licence_expires_at)->toDateString(),
                        $this->renewalTimingStatus($r->licence_expires_at),
                    ]);
                }
            });
    }

    private function renewalTimingStatus(CarbonInterface $dueDate): string
    {
        return $dueDate->isBefore(today()) ? 'overdue' : 'upcoming';
    }

    /** @return Builder<User> */
    private function visibleRenewalStaffIds(User $viewer): Builder
    {
        $query = User::query()->select('id');
        $this->siteAccess->applyHrEmployeeStaffScope($query, $viewer);

        return $query;
    }

    /** @return Builder<User> */
    private function visibleCurrentStaffIds(User $viewer): Builder
    {
        $query = User::query()->select('id');
        $this->siteAccess->applyStaffScope($query, $viewer);

        return $query;
    }
}
