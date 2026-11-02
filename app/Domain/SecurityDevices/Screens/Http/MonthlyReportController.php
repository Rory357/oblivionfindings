<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Reports\MonthlyReport;
use App\Domain\SecurityDevices\Reports\MonthlyReportEmail;
use App\Domain\SecurityDevices\Reports\MonthlyReportPdf;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Overview › Reports (pg-sdr-18b): the month's report as a PDF or CSV, the camera viewing log's
 * Export, and the monthly email switch. Each download is the figures this person gets on the page,
 * for their Sites only; downloads are audited because the PDF and the log carry who watched cameras.
 */
final class MonthlyReportController extends Controller
{
    public function __construct(
        private readonly MonthlyReport $report,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function pdf(Request $request, MonthlyReportPdf $pdf): Response
    {
        [$user, $site, $month, $now] = $this->read($request);
        $figures = $this->report->figures($user, $site, $month, $now);
        $views = $this->report->cameraViews($user, $site, $month);
        $this->audit($user, 'pdf', $month['key'], $site, $views !== null);
        if ($views !== null && count($views['rows']) > MonthlyReport::LOG_ROWS) {
            // A busy month stays a readable PDF; the camera viewing CSV has every row.
            $views['more'] = count($views['rows']) - MonthlyReport::LOG_ROWS;
            $views['rows'] = array_slice($views['rows'], 0, MonthlyReport::LOG_ROWS);
        }

        return response($pdf->output($month, $figures, $views, $user, 'Downloaded by', $now), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="security-devices-report-'.$month['key'].'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$user, $site, $month, $now] = $this->read($request);
        $figures = $this->report->figures($user, $site, $month, $now);
        $this->audit($user, 'csv', $month['key'], $site, false);

        return $this->streamSanitizedCsv('security-devices-report-'.$month['key'].'.csv', ['Section', 'What', 'Value', 'Unit'], $this->rows($month, $figures, $now));
    }

    /** The camera viewing log's Export: every row of the month, for people who may see it. */
    public function cameraViews(Request $request): StreamedResponse
    {
        [$user, $site, $month] = $this->read($request);
        $views = $this->report->cameraViews($user, $site, $month);
        abort_if($views === null, 403);
        $this->audit($user, 'camera_views_csv', $month['key'], $site, true);

        return $this->streamSanitizedCsv(
            'security-devices-camera-viewing-'.$month['key'].'.csv',
            ['When (NZ time)', 'Who', 'Camera', 'What', 'Reason'],
            array_map(fn (array $row): array => [
                $row['at']->setTimezone(MonthlyReport::NZ)->format('Y-m-d H:i'),
                $row['who'],
                $row['camera'],
                MonthlyReportPdf::what($row),
                $row['reason'],
            ], $views['rows']),
        );
    }

    /** Turn the monthly email on or off and choose who gets it (audited; Setup › Change history). */
    public function email(Request $request, MonthlyReportEmail $email): JsonResponse
    {
        $user = $this->viewer($request);
        $validated = $request->validate([
            'on' => ['required', 'boolean'],
            'recipients' => ['present', 'array', 'max:'.MonthlyReportEmail::MAX_RECIPIENTS],
            'recipients.*' => ['integer', 'min:1'],
        ]);
        try {
            $changed = $email->update($user, (bool) $validated['on'], $validated['recipients']);
        } catch (AuthorizationException) {
            abort(403, 'You can’t change the monthly email.');
        }

        return response()->json(['changed' => $changed, 'email' => $email->forViewer($user, CarbonImmutable::now('UTC'))]);
    }

    /** @return array{0: User, 1: int|null, 2: array<string, mixed>, 3: CarbonImmutable} */
    private function read(Request $request): array
    {
        $user = $this->viewer($request);
        $site = $request->query('site');
        $siteId = is_string($site) && ctype_digit($site) ? (int) $site : null;
        if ($siteId !== null) {
            $this->access->assertCanViewSite($user, $siteId);
        }
        $now = CarbonImmutable::now('UTC');

        return [$user, $siteId, $this->report->month($request->query('month'), $now), $now];
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && MonthlyReport::canView($user), 403);

        return $user;
    }

    private function audit(User $user, string $format, string $month, ?int $siteId, bool $cameraViews): void
    {
        AuditLogger::logOrFail('security_devices.report.downloaded', null, [
            'actor_id' => (int) $user->id,
            'format' => $format,
            'month' => $month,
            'site_id' => $siteId,
            'camera_viewing_log' => $cameraViews,
        ]);
    }

    /**
     * The figures as rows: section, what, value, unit. "Not available" where nothing backs a figure.
     *
     * @param  array<string, mixed>  $month
     * @param  array<string, mixed>  $figures
     * @return list<list<string|int|float>>
     */
    private function rows(array $month, array $figures, CarbonImmutable $now): array
    {
        $rows = [
            ['Report', 'Month', $month['label'], ''],
            ['Report', 'Sites', $figures['site_label'], ''],
            ['Report', 'Figures', $month['current'] ? 'Run to '.$now->setTimezone(MonthlyReport::NZ)->format('Y-m-d H:i').' NZ time' : 'The full month', ''],
        ];
        if ($figures['offline'] === null) {
            $rows[] = ['Time offline by Site', 'Not available', '', 'No router or gateway is watched'];
        } else {
            foreach (array_reverse($figures['offline']['sites']) as $site) {
                $rows[] = ['Time offline by Site', $site['name'], $site['minutes'], 'minutes'];
            }
        }
        $rows[] = ['Alerts by kind', 'All alerts', $figures['alerts']['total'], 'alerts'];
        foreach ($figures['alerts']['kinds'] as $kind) {
            $rows[] = ['Alerts by kind', $kind['label'], $kind['count'], 'alerts'];
        }
        if ($figures['safety'] === null) {
            $rows[] = ['Time to pick up safety alerts', 'Not available', '', 'No safety alerts in these weeks'];
        } else {
            $rows[] = ['Time to pick up safety alerts', 'Safety alerts in '.$month['short'], $figures['safety']['month']['count'], 'alerts'];
            $rows[] = ['Time to pick up safety alerts', 'Not picked up', $figures['safety']['month']['not_picked'], 'alerts'];
            $rows[] = ['Time to pick up safety alerts', 'Average in '.$month['short'], $figures['safety']['month']['average_minutes'] ?? 'Not available', 'minutes'];
            foreach ($figures['safety']['weeks'] as $week) {
                $rows[] = ['Time to pick up safety alerts', 'Week of '.$week['label'], $week['minutes'] ?? 'No safety alerts', 'average minutes'];
            }
        }
        if ($figures['upkeep'] === null) {
            $rows[] = ['Upkeep', 'Not available', '', 'No devices'];
        } else {
            $rows[] = ['Upkeep', 'Batteries changed', $figures['upkeep']['batteries'], ''];
            $rows[] = ['Upkeep', 'Software updates', $figures['upkeep']['software'], ''];
            $rows[] = ['Upkeep', 'Settings changes', $figures['upkeep']['settings'], ''];
        }
        $cameras = $figures['cameras'];
        if ($cameras === null || $cameras['percent'] === null) {
            $rows[] = ['Camera picture time', 'Not available', '', 'No camera is watched'];
        } else {
            $rows[] = ['Camera picture time', 'Cameras', $cameras['cameras'], ''];
            $rows[] = ['Camera picture time', 'With a picture', $cameras['with_hours'], 'hours'];
            $rows[] = ['Camera picture time', 'No picture', $cameras['without_hours'], 'hours'];
            $rows[] = ['Camera picture time', 'Share with a picture', $cameras['percent'], '%'];
        }

        return $rows;
    }
}
