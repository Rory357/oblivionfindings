<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralReportAges;
use App\Domain\Referrals\Services\ReferralReportPrivacy;
use App\Domain\Referrals\Services\ReferralReports;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Support\CsvCell;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ReferralReportController extends Controller
{
    public function __construct(private readonly ReferralReports $reports, private readonly ReferralAccess $access) {}

    public function index(Request $request, ReferralPresenter $presenter)
    {
        $actor = $request->user();
        $this->reports->authorize($actor);
        $filters = $this->reports->filters($request->all());
        $data = DB::transaction(function () use ($actor, $filters, $presenter) {
            $cutoff = CarbonImmutable::now('UTC');
            $report = $this->reports->build($actor, $filters, $cutoff);
            $query = $this->reports->metric($this->reports->query($actor, $filters, $cutoff), $actor, $filters['metric'], $cutoff);
            $records = $query->with(['site:id,name', 'owner:id,name'])->orderBy('created_at')->orderBy('id')->paginate(25, ['*'], 'page', $filters['page'])->withQueryString();
            $records->through(fn ($row) => [...$presenter->summary($row), 'stage_age_label' => ReferralReportAges::stageLabel($row, $cutoff)]);

            return ['report' => $report, 'records' => $records];
        });
        $sites = Site::query()->whereIn('id', $this->access->query($actor)->whereNotNull('site_id')->select('site_id'))->orderBy('name')->get(['id', 'name']);

        return Inertia::render('operations/referrals/reports', [...$data, 'sites' => $sites, 'permissions' => $this->access->permissions($actor)])
            ->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $request, ReferralPresenter $presenter)
    {
        $actor = $request->user();
        $this->reports->authorize($actor);
        abort_unless($this->access->can($actor, 'reports.export'), 403);
        $format = $request->validate(['format' => ['required', Rule::in(['summary', 'records'])]])['format'];
        $filters = $this->reports->filters($request->all());
        $csv = DB::transaction(function () use ($actor, $filters, $format, $request, $presenter) {
            $cutoff = CarbonImmutable::now('UTC');
            $report = $this->reports->build($actor, $filters, $cutoff);
            // The summary export prints exactly what the page shows, including the small-group rule.
            $privacy = app(ReferralReportPrivacy::class);
            $shown = fn (array $row, string $key = 'count', ?string $flag = null) => $privacy->display($row[$key], $row[$flag ?? 'suppressed'] ?? null);
            $mean = fn (array $clock, string $key, string $missing) => isset($clock['suppressed']) ? $privacy->hiddenMeasure() : ($clock[$key] ?? $missing);
            $rows = [['Referrals report', $format], ['Generated at (UTC)', $report['generated_at']], ['Scope', $report['definitions']['scope']],
                ['Received from (NZ)', $filters['scope'] === 'received' ? $filters['from'] : 'Not limited'], ['Received to (NZ)', $filters['scope'] === 'received' ? $filters['to'] : 'Not limited'],
                ['Site filter', $filters['site']], ['Owner filter', $filters['owner']], ['Source filter', $filters['source']], ['Stage filter', $filters['stage']],
                ['Stage age filter', $filters['stage_age']], ['Funding age filter', $filters['funding_age']], ['Funding kind filter', $filters['funding_kind']],
                ['Reported region filter', $filters['region']], ['Region definition', $report['definitions']['region']],
                ['Classification filter', $filters['classification']], ['Classification definition', $report['definitions']['classification']],
                ['Stage age definition', $report['definitions']['stage_age']], ['Outcomes', $report['definitions']['cutoff']],
                ...($format === 'summary' ? [['Small groups', $report['definitions']['privacy']]] : []), []];
            if ($format === 'summary') {
                $rows[] = ['Measure', 'Value', 'Definition'];
                foreach ($report['metrics'] as $metric) {
                    $rows[] = [$metric['label'], $shown($metric, 'value'), $metric['definition']];
                }
                foreach ($report['delays'] as $key => $delay) {
                    $rows[] = ['Mean elapsed hours to '.$key, $mean($delay, 'mean_hours', 'Not recorded'), $shown($delay).' timestamped referrals; '.$report['definitions']['delays']];
                }
                $rows[] = [];
                $rows[] = ['Working time definition', $report['definitions']['working_time']];
                $rows[] = ['Measure', 'Mean elapsed hours', 'Elapsed referrals', 'Mean company-working hours', 'Working-time referrals', 'Calendar unavailable', 'Missing or invalid dates'];
                foreach ($report['working_time'] as $measure) {
                    $rows[] = [$measure['label'], $mean($measure['elapsed'], 'mean_hours', 'Not recorded'), $shown($measure['elapsed']), $mean($measure['working'], 'mean_hours', 'Unavailable'), $shown($measure['working']),
                        $shown($measure, 'unknown_calendar', 'unknown_calendar_suppressed'), $shown($measure, 'unknown_dates', 'unknown_dates_suppressed')];
                }
                $rows[] = [];
                $rows[] = ['Historical visits definition', $report['definitions']['history']];
                $rows[] = ['Reopening events', $shown($report['history'], 'reopenings', 'reopenings_suppressed')];
                $rows[] = ['Distinct reopened referrals', $shown($report['history'], 'reopened_referrals', 'reopened_referrals_suppressed')];
                $rows[] = ['Referrals with incomplete history', $shown($report['history'], 'incomplete_referrals', 'incomplete_referrals_suppressed')];
                $rows[] = ['History type', 'Stable category', 'Completed intervals', 'Total elapsed hours', 'Mean elapsed hours', 'Measured elapsed intervals', 'Total working hours', 'Mean working hours', 'Measured working intervals', 'Calendar unavailable', 'Missing interval dates'];
                foreach (['stages', 'holds'] as $kind) {
                    foreach ($report['history'][$kind] as $measure) {
                        $rows[] = [$kind, $measure['key'], $shown($measure), $mean($measure['elapsed'], 'total_hours', 'Unavailable'), $mean($measure['elapsed'], 'mean_hours', 'Unavailable'), $shown($measure['elapsed']),
                            $mean($measure['working'], 'total_hours', 'Unavailable'), $mean($measure['working'], 'mean_hours', 'Unavailable'), $shown($measure['working']),
                            $shown($measure, 'unknown_calendar', 'unknown_calendar_suppressed'), $shown($measure, 'unknown_dates', 'unknown_dates_suppressed')];
                    }
                }
                $rows[] = [];
                $rows[] = ['Received date (NZ)', 'Received', 'Successful contact by generation time'];
                foreach ($report['trend'] as $point) {
                    $rows[] = [$point['date'], $shown($point, 'received', 'received_suppressed'), $shown($point, 'contacted', 'contacted_suppressed')];
                }
                foreach ($report['groups'] as $group => $values) {
                    $rows[] = [];
                    $rows[] = [$group, 'Referrals'];
                    foreach ($values as $value) {
                        $rows[] = [$value['label'] ?? $value['key'], $shown($value)];
                    }
                }
                if ($report['funding'] !== null) {
                    $rows[] = [];
                    $rows[] = ['Funding definition', $report['definitions']['funding']];
                    foreach (['provider_cases', 'income_cases'] as $key) {
                        $rows[] = [$key, $shown($report['funding'], $key, $key.'_suppressed')];
                    }
                    foreach (['by_pathway', 'by_status', 'outcomes'] as $group) {
                        $rows[] = [$group, 'Cases'];
                        foreach ($report['funding'][$group] as $value) {
                            $rows[] = [$value['label'] ?? $value['key'], $shown($value)];
                        }
                    }
                    $rows[] = ['Pending funding age definition', $report['definitions']['funding_age']];
                    foreach ($report['funding']['pending_age'] as $kind => $buckets) {
                        $rows[] = [$kind.' pending age', 'Cases'];
                        foreach ($buckets as $bucket) {
                            $rows[] = [$bucket['label'], $shown($bucket)];
                        }
                    }
                }
                $count = count($rows);
            } else {
                $query = $this->reports->metric($this->reports->query($actor, $filters, $cutoff), $actor, $filters['metric'], $cutoff);
                $count = (clone $query)->count();
                abort_if($count > 10000, 422, 'Narrow this report to 10,000 matching referrals or fewer before exporting records.');
                $rows[] = ['Record measure', ReferralReports::METRICS[$filters['metric']][0]];
                $rows[] = ['Reference', 'Person / enquiry', 'Stage', 'Current stage age', 'Source', 'Site', 'Owner', 'Received (UTC)', 'Next due (UTC)'];
                foreach ($query->with(['site:id,name', 'owner:id,name'])->orderBy('id')->lazyById(200) as $record) {
                    $row = $presenter->summary($record);
                    $rows[] = [$row['reference'], $row['name'], $row['stage'], ReferralReportAges::stageLabel($record, $cutoff), $row['source'], $row['site_name'] ?? 'Central intake', $row['owner_name'] ?? 'Unassigned', $row['created_at'], $row['next_due_at']];
                }
            }
            AuditLogger::logOrFail('referrals.report_export_prepared', null, ['format' => $format, 'scope' => $filters['scope'], 'from' => $filters['from'], 'to' => $filters['to'], 'site' => $filters['site'], 'owner' => $filters['owner'], 'source' => $filters['source'], 'stage' => $filters['stage'], 'region' => $filters['region'], 'classification' => $filters['classification'], 'metric' => $filters['metric'], 'stage_age' => $filters['stage_age'], 'funding_age' => $filters['funding_age'], 'funding_kind' => $filters['funding_kind'], 'row_count' => $count, 'funding_included' => $format === 'summary' && $report['funding'] !== null], $request);
            $stream = fopen('php://memory', 'w+');
            foreach ($rows as $row) {
                fputcsv($stream, array_map([CsvCell::class, 'sanitize'], $row), ',', '"', '');
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            fclose($stream);

            return $content;
        });

        return response("\xEF\xBB\xBF".$csv)->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="referrals-'.$format.'-'.now()->format('Ymd-His').'.csv"')
            ->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff');
    }
}
