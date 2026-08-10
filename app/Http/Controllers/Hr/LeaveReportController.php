<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\LeaveReportService;
use App\Domain\Hr\Services\LeaveService;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaveReportController extends Controller
{
    public function __construct(
        private LeaveReportService $reportService,
        private LeaveService $leaveService,
    ) {}

    /**
     * Display leave reports: absenteeism, Bradford Factor, utilisation.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $year = (int) $request->query('year', now()->year);

        $absenteeism = $this->reportService->getAbsenteeismReport($year);
        $bradfordFactor = $this->reportService->getBradfordFactor($year);
        $utilization = $this->reportService->getLeaveUtilizationReport($year);

        // Leave-by-type breakdown for the donut (approved + pending, this year).
        $typeBreakdown = HrLeaveRequest::query()
            ->whereIn('status', ['approved', 'pending'])
            ->whereYear('starts_at', $year)
            ->selectRaw('leave_type, COUNT(*) as count')
            ->groupBy('leave_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['type' => (string) $row->leave_type, 'value' => (int) $row->count])
            ->all();

        $canManage = $user->canDo('hr.leave.manage');
        $canApprove = $user->canDo('hr.leave.approve') || $canManage;

        return Inertia::render('hr/leave/reports', [
            'absenteeism' => $absenteeism,
            'bradfordFactor' => $bradfordFactor,
            'utilization' => $utilization,
            'typeBreakdown' => $typeBreakdown,
            'year' => $year,
            'hero' => $this->leaveService->hubHeroData($user, $canApprove),
            'can' => [
                'manage' => $canManage,
                'approve' => $canApprove,
                'create' => $canManage,
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
        $year = (int) $request->query('year', now()->year);
        $format = strtolower((string) $request->query('format', 'csv'));

        $bradford = $this->reportService->getBradfordFactor($year)['employees'] ?? [];
        $utilization = $this->reportService->getLeaveUtilizationReport($year)['employees'] ?? [];

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

        if ($format === 'xls' || $format === 'xlsx' || $format === 'excel') {
            return $this->streamSpreadsheetMl($sections, $filename);
        }

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
        return $this->neutraliseFormula((string) $value);
    }

    /**
     * Prefix a leading =, +, -, @, tab, or CR with a single quote so spreadsheet
     * apps treat the cell as literal text rather than evaluating it as a formula.
     */
    private function neutraliseFormula(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * Stream a genuine Excel file as SpreadsheetML 2003 (a plain XML format
     * Excel opens natively) — one worksheet per section, header row bold.
     * Used because no spreadsheet library (PhpSpreadsheet / Laravel Excel) is
     * installed; this needs no extra dependency.
     *
     * @param  array<int,array{title:string,headers:array<int,string>,rows:array<int,array<int,mixed>>}>  $sections
     */
    private function streamSpreadsheetMl(array $sections, string $filename)
    {
        return response()->streamDownload(function () use ($sections) {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<?mso-application progid="Excel.Sheet"?>'."\n";
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
                .' xmlns:o="urn:schemas-microsoft-com:office:office"'
                .' xmlns:x="urn:schemas-microsoft-com:office:excel"'
                .' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";
            echo '<Styles><Style ss:ID="hdr"><Font ss:Bold="1"/></Style></Styles>'."\n";

            foreach ($sections as $i => $section) {
                // Worksheet names must be unique, <=31 chars, and exclude : \ / ? * [ ].
                $name = preg_replace('/[:\\\\\/?*\[\]]/', ' ', (string) $section['title']);
                $name = trim(mb_substr($name, 0, 28));
                if ($name === '') {
                    $name = 'Sheet'.($i + 1);
                }

                echo '<Worksheet ss:Name="'.e($name).'"><Table>'."\n";

                echo '<Row>';
                foreach ($section['headers'] as $header) {
                    echo '<Cell ss:StyleID="hdr"><Data ss:Type="String">'.e((string) $header).'</Data></Cell>';
                }
                echo '</Row>'."\n";

                foreach ($section['rows'] as $row) {
                    echo '<Row>';
                    foreach ($row as $cell) {
                        if (is_int($cell) || is_float($cell)) {
                            echo '<Cell><Data ss:Type="Number">'.e((string) $cell).'</Data></Cell>';
                        } else {
                            echo '<Cell><Data ss:Type="String">'.e($this->neutraliseFormula((string) $cell)).'</Data></Cell>';
                        }
                    }
                    echo '</Row>'."\n";
                }

                echo '</Table></Worksheet>'."\n";
            }

            echo '</Workbook>'."\n";
        }, $filename.'.xls', ['Content-Type' => 'application/vnd.ms-excel']);
    }
}
