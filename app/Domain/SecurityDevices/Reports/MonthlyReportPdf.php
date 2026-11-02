<?php

namespace App\Domain\SecurityDevices\Reports;

use App\Models\User;
use App\Services\Fleet\VehicleTripReportExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * The monthly report as a PDF (pg-sdr-18b "Download PDF", and the monthly email's attachment), in
 * the Fleet reports' style like the safety review's and incident PDFs: dompdf, DejaVu Sans for
 * macrons, the brand from Settings › Branding. Built only from the figures this person gets on the
 * page; the camera viewing log is added only when they may see it, and never to the email.
 */
final class MonthlyReportPdf
{
    public function __construct(private readonly VehicleTripReportExporter $fleet) {}

    /**
     * @param  array{key: string, label: string, short: string, range: string, current: bool}  $month
     * @param  array<string, mixed>  $figures  MonthlyReport::figures()
     * @param  array{rows: list<array<string, mixed>>}|null  $views  MonthlyReport::cameraViews(), or null to leave it out
     */
    public function output(array $month, array $figures, ?array $views, User $viewer, string $how, CarbonImmutable $now): string
    {
        return Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])
            ->loadHTML($this->html($month, $figures, $views, $viewer, $how, $now))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @param  array{key: string, label: string, short: string, range: string, current: bool}  $month
     * @param  array<string, mixed>  $figures
     * @param  array{rows: list<array<string, mixed>>}|null  $views
     */
    public function html(array $month, array $figures, ?array $views, User $viewer, string $how, CarbonImmutable $now): string
    {
        $nz = $now->setTimezone(MonthlyReport::NZ);
        $safety = $figures['safety'] ?? null;
        $cameras = $figures['cameras'] ?? null;
        $upkeep = $figures['upkeep'] ?? null;

        return view('pdf.security-devices-monthly-report', [
            'title' => $month['label'].' report',
            'brand' => $this->fleet->branding(),
            'month' => $month,
            'siteLabel' => (string) ($figures['site_label'] ?? 'All your Sites'),
            'runs' => $month['current'] ? 'Figures run to '.$nz->format('D j M, g:i a') : 'The full month',
            'tiles' => [
                ['Alerts', (string) ($figures['alerts']['total'] ?? 0)],
                ['Safety alerts picked up', $safety && $safety['month']['average_minutes'] !== null ? 'In '.self::wait((float) $safety['month']['average_minutes']).' on average' : 'Not available'],
                ['Camera picture time', $cameras && $cameras['percent'] !== null ? $cameras['percent'].'%' : 'Not available'],
                ['Upkeep', $upkeep ? (string) array_sum($upkeep) : 'Not available'],
            ],
            'offline' => $figures['offline'] === null ? null : array_map(fn (array $site): array => [
                $site['name'],
                self::minutes((int) $site['minutes']),
                $site['still_offline'] ? 'Still offline now' : '',
            ], array_reverse($figures['offline']['sites'])),
            'kinds' => array_map(fn (array $kind): array => [$kind['label'], (string) $kind['count']], $figures['alerts']['kinds'] ?? []),
            'safety' => $safety === null ? null : [
                'weeks' => array_map(fn (array $week): array => [$week['label'], $week['minutes'] === null ? 'No safety alerts' : self::wait((float) $week['minutes'])], $safety['weeks']),
                'line' => $safety['month']['count'].' in '.$month['short'].', '.$safety['month']['picked'].' picked up'
                    .($safety['month']['not_picked'] > 0 ? ', '.$safety['month']['not_picked'].' not picked up' : ''),
            ],
            'upkeep' => $upkeep === null ? null : [
                ['Batteries changed', (string) $upkeep['batteries'], 'A low-battery alert closed when a new battery reported'],
                ['Software updates', (string) $upkeep['software'], 'Seen on the devices'],
                ['Settings changes', (string) $upkeep['settings'], 'Found by settings backups'],
            ],
            'cameras' => $cameras,
            'views' => $views === null ? null : array_map(fn (array $row): array => [
                $row['at']->setTimezone(MonthlyReport::NZ)->format('D j M, g:i a'),
                $row['who'],
                $row['camera'],
                self::what($row),
                $row['reason'] !== '' ? $row['reason'] : 'No reason given',
            ], $views['rows']),
            'views_more' => $views === null ? 0 : (int) ($views['more'] ?? 0),
            'footer' => 'Confidential · '.$how.' '.$viewer->name.' · '.$nz->format('D j M Y, g:i a').' · NZ time',
        ])->render();
    }

    /** "Asked to watch live" or "Clip saved, 9:31 – 9:33 am". @param  array<string, mixed>  $row */
    public static function what(array $row): string
    {
        if ($row['kind'] === 'live') {
            return 'Asked to watch live';
        }
        if (! $row['from'] instanceof CarbonImmutable || ! $row['to'] instanceof CarbonImmutable) {
            return 'Clip saved';
        }
        $from = $row['from']->setTimezone(MonthlyReport::NZ);
        $to = $row['to']->setTimezone(MonthlyReport::NZ);

        return 'Clip saved, '.$from->format('D j M, g:i a').' – '.($from->isSameDay($to) ? $to->format('g:i a') : $to->format('D j M, g:i a'));
    }

    /** 38 → "38 min", 120 → "2 h", 125 → "2 h 5 min". */
    public static function minutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest > 0 && $hours < 10 ? "{$hours} h {$rest} min" : "{$hours} h";
    }

    /** 2.1 → "2 min 6 s", 0.5 → "30 s". */
    public static function wait(float $minutes): string
    {
        $seconds = (int) round($minutes * 60);
        if ($seconds < 60) {
            return $seconds.' s';
        }
        $whole = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $whole >= 10 || $rest === 0 ? "{$whole} min" : "{$whole} min {$rest} s";
    }
}
