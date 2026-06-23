<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Services\LeaveReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaveReportController extends Controller
{
    use Concerns\ResolvesHrTenant;

    public function __construct(
        private LeaveReportService $reportService,
    ) {}

    /**
     * Display leave reports: absenteeism, Bradford Factor, utilisation.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        abort_unless($tenantId, 403, 'Unable to determine tenant context for leave reports.');
        $year = (int) $request->query('year', now()->year);

        $absenteeism = $this->reportService->getAbsenteeismReport($tenantId, $year);
        $bradfordFactor = $this->reportService->getBradfordFactor($tenantId, $year);
        $utilization = $this->reportService->getLeaveUtilizationReport($tenantId, $year);

        return Inertia::render('hr/leave/reports', [
            'absenteeism' => $absenteeism,
            'bradfordFactor' => $bradfordFactor,
            'utilization' => $utilization,
            'year' => $year,
            'can' => [
                'manage' => $user->canDo('hr.leave.manage'),
            ],
        ]);
    }

    /**
     * Export the leave reports (Bradford Factor + utilisation) as CSV (Excel-openable) or PDF.
     */
    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        abort_unless($tenantId, 403);
        $year = (int) $request->query('year', now()->year);
        $format = strtolower((string) $request->query('format', 'csv'));

        $bradford = $this->reportService->getBradfordFactor($tenantId, $year)['employees'] ?? [];
        $utilization = $this->reportService->getLeaveUtilizationReport($tenantId, $year)['employees'] ?? [];

        $sections = [
            [
                'title' => 'Bradford Factor',
                'headers' => ['Staff', 'Spells', 'Days', 'Factor', 'Risk'],
                'rows' => array_map(fn ($e) => [$e['name'], $e['spells'], $e['days'], $e['factor'], $e['risk_level']], $bradford),
            ],
            [
                'title' => 'Annual leave utilisation',
                'headers' => ['Staff', 'Entitlement', 'Taken', 'Remaining', '% used'],
                'rows' => array_map(fn ($e) => [$e['name'], $e['total_entitlement'], $e['total_used'], $e['total_remaining'], $e['overall_pct'].'%'], $utilization),
            ],
        ];

        $filename = 'leave-reports-'.$year;

        if ($format === 'pdf') {
            $html = '';
            foreach ($sections as $s) {
                $head = collect($s['headers'])->map(fn ($h) => '<th style="text-align:left;border:1px solid #ccc;padding:4px;background:#f3f3f3">'.e($h).'</th>')->implode('');
                $body = collect($s['rows'])->map(fn ($r) => '<tr>'.collect($r)->map(fn ($c) => '<td style="border:1px solid #ccc;padding:4px">'.e((string) $c).'</td>')->implode('').'</tr>')->implode('');
                $html .= '<h2 style="font-family:sans-serif">'.e($s['title']).'</h2><table style="width:100%;border-collapse:collapse;font-family:sans-serif;font-size:11px"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table><br>';
            }

            return Pdf::loadHtml($html)->download($filename.'.pdf');
        }

        return response()->streamDownload(function () use ($sections) {
            $out = fopen('php://output', 'w');
            foreach ($sections as $s) {
                fputcsv($out, [$s['title']]);
                fputcsv($out, $s['headers']);
                foreach ($s['rows'] as $r) {
                    fputcsv($out, array_map(fn ($c) => $this->csvCell((string) $c), $r));
                }
                fputcsv($out, []);
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Neutralise spreadsheet formula injection in a free-text CSV cell. */
    private function csvCell(?string $value): string
    {
        $v = (string) $value;

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
