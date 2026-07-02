<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\StaffBackgroundCheck;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-side, uncapped CSV export for the Compliance hub tabs. Streams rows so a
 * large register doesn't buffer in memory. Tenant-scoped, gated per dataset.
 */
class ComplianceExportController extends Controller
{
    use ResolvesHrTenant;

    private const DATASETS = ['staff', 'vetting', 'drivers', 'renewals'];

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'dataset' => ['required', 'string', Rule::in(self::DATASETS)],
            'format' => ['nullable', 'string', Rule::in(['csv'])],
        ]);

        $dataset = $validated['dataset'];

        // Per-dataset permission gate.
        $perm = match ($dataset) {
            'vetting' => 'hr.vetting.view',
            'drivers' => 'hr.driver.view',
            default => 'hr.compliance.view',
        };
        abort_unless($user->canDo($perm), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $filename = "compliance-{$dataset}-" . date('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($dataset, $tenantId) {
            $out = fopen('php://output', 'w');

            match ($dataset) {
                'staff' => $this->streamStaff($out, $tenantId),
                'vetting' => $this->streamVetting($out, $tenantId),
                'drivers' => $this->streamDrivers($out, $tenantId),
                'renewals' => $this->streamRenewals($out, $tenantId),
            };

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @param resource $out */
    private function streamStaff($out, ?int $tenantId): void
    {
        $this->putCsv($out, ['Staff member', 'Email', 'Requirement', 'Code', 'Status', 'Valid from', 'Expires', 'Exempted until', 'Notes']);

        HrStaffComplianceStatus::where('tenant_id', $tenantId)
            ->with(['user:id,name,email', 'requirement:id,code,name'])
            ->orderBy('user_id')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    $this->putCsv($out, [
                        $r->user?->name ?? '',
                        $r->user?->email ?? '',
                        $r->requirement?->name ?? '',
                        $r->requirement?->code ?? '',
                        $r->status,
                        optional($r->valid_from)->toDateString(),
                        optional($r->expires_at)->toDateString(),
                        optional($r->exempted_until)->toDateString(),
                        str_replace(["\n", "\r"], ' ', (string) $r->notes),
                    ]);
                }
            });
    }

    /** @param resource $out */
    private function streamVetting($out, ?int $tenantId): void
    {
        $this->putCsv($out, ['Staff member', 'Check type', 'Provider', 'Reference', 'Status', 'Check date', 'Expires']);

        StaffBackgroundCheck::query()
            ->whereHas('user.hrEmployeeProfile', fn ($q) => $q->where('tenant_id', $tenantId))
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
    private function streamDrivers($out, ?int $tenantId): void
    {
        $this->putCsv($out, ['Driver', 'Licence class', 'Licence number', 'Endorsements', 'Status', 'Can drive clients', 'Expires']);

        HrDriverEligibility::where('tenant_id', $tenantId)
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
    private function streamRenewals($out, ?int $tenantId): void
    {
        $this->putCsv($out, ['Type', 'Staff member', 'Item', 'Due date', 'Status']);

        HrStaffComplianceStatus::where('tenant_id', $tenantId)
            ->whereNotNull('expires_at')
            ->with(['user:id,name', 'requirement:id,name'])
            ->orderBy('expires_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    $this->putCsv($out, ['Compliance', $r->user?->name ?? '', $r->requirement?->name ?? '', optional($r->expires_at)->toDateString(), $r->status]);
                }
            });

        HrDriverEligibility::where('tenant_id', $tenantId)
            ->whereNotNull('licence_expires_at')
            ->with('user:id,name')
            ->orderBy('licence_expires_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    $this->putCsv($out, ['Driver', $r->user?->name ?? '', 'Class ' . $r->licence_class . ' licence', optional($r->licence_expires_at)->toDateString(), $r->status]);
                }
            });
    }
}
