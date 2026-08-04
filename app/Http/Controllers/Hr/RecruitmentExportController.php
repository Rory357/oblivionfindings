<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Services\HrRecruitmentAccessService;
use App\Domain\Hr\Services\RecruitmentAnalyticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-side, uncapped CSV export for the recruitment hub tabs. Streams rows so
 * a large pipeline doesn't buffer in memory. Access is gated on view.
 */
class RecruitmentExportController extends Controller
{
    private const DATASETS = ['pipeline', 'requisitions', 'offers', 'analytics'];

    public function __construct(
        private readonly HrRecruitmentAccessService $access,
    ) {}

    public function export(Request $request, RecruitmentAnalyticsService $analytics): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $validated = $request->validate([
            'dataset' => ['required', 'string', Rule::in(self::DATASETS)],
            'format' => ['nullable', 'string', Rule::in(['csv'])],
        ]);

        $scope = $this->access->scope($user);
        $dataset = $validated['dataset'];
        $filename = "recruitment-{$dataset}-".date('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($dataset, $scope, $analytics) {
            $out = fopen('php://output', 'w');

            match ($dataset) {
                'pipeline' => $this->streamPipeline($out, $scope['candidate_ids']),
                'requisitions' => $this->streamRequisitions($out, $scope['requisition_ids'], $scope['application_ids']),
                'offers' => $this->streamOffers($out, $scope['offer_ids']),
                'analytics' => $this->streamAnalytics($out, $scope['candidate_ids'], $scope['application_ids'], $analytics),
            };

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @param resource $out */
    private function streamPipeline($out, array $candidateIds): void
    {
        $this->putCsv($out, ['Name', 'Email', 'Stage', 'Requisition', 'Source', 'Days in stage', 'Created']);

        HrCandidate::query()
            ->whereKey($candidateIds)
            ->whereNotIn('status', ['withdrawn', 'rejected', 'hired'])
            ->with(['applications' => fn ($q) => $q->select('id', 'candidate_id', 'requisition_id')->latest('id'), 'applications.requisition:id,title'])
            ->orderByDesc('current_stage_entered_at')
            ->chunk(500, function ($candidates) use ($out) {
                foreach ($candidates as $c) {
                    $days = $c->current_stage_entered_at ? (int) $c->current_stage_entered_at->diffInDays(now()) : 0;
                    $this->putCsv($out, [
                        $c->full_name,
                        $c->personal_email,
                        $c->status,
                        $c->applications->first()?->requisition?->title ?? '',
                        $c->source,
                        $days,
                        optional($c->created_at)->toDateString(),
                    ]);
                }
            });
    }

    /** @param resource $out */
    private function streamRequisitions($out, array $requisitionIds, array $applicationIds): void
    {
        $this->putCsv($out, ['Title', 'Site', 'Status', 'Openings', 'Applicants', 'Hiring manager', 'Employment type']);

        HrJobRequisition::query()
            ->whereKey($requisitionIds)
            ->with(['site:id,name', 'hiringManager:id,name'])
            ->withCount(['applications' => fn ($applications) => $applications->whereIn('id', $applicationIds)])
            ->orderByDesc('created_at')
            ->chunk(500, function ($reqs) use ($out) {
                foreach ($reqs as $r) {
                    $this->putCsv($out, [
                        $r->title,
                        $r->site?->name ?? '',
                        $r->status,
                        (int) $r->openings,
                        (int) $r->applications_count,
                        $r->hiringManager?->name ?? '',
                        $r->employment_type,
                    ]);
                }
            });
    }

    /** @param resource $out */
    private function streamOffers($out, array $offerIds): void
    {
        $this->putCsv($out, ['Candidate', 'Role', 'Approval', 'Response', 'Hourly rate', 'Annual salary', 'Sent at', 'Created']);

        HrOffer::query()
            ->whereKey($offerIds)
            ->with(['application.candidate:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->chunk(500, function ($offers) use ($out) {
                foreach ($offers as $o) {
                    $this->putCsv($out, [
                        $o->application?->candidate?->full_name ?? '',
                        $o->position_title,
                        $o->approval_status,
                        $o->response ?? 'pending',
                        $o->hourly_rate,
                        $o->annual_salary,
                        optional($o->sent_at)->toDateTimeString(),
                        optional($o->created_at)->toDateString(),
                    ]);
                }
            });
    }

    /** @param resource $out */
    private function streamAnalytics($out, array $candidateIds, array $applicationIds, RecruitmentAnalyticsService $analytics): void
    {
        $this->putCsv($out, ['Section', 'Label', 'Count', 'Detail']);

        foreach ($analytics->getPipelineConversion($candidateIds) as $row) {
            $this->putCsv($out, ['Conversion funnel', $row['stage'], $row['count'], ($row['percentage'] ?? 0).'%']);
        }
        foreach ($analytics->getSourceEffectiveness($candidateIds) as $row) {
            $this->putCsv($out, ['Source', $row['source'] ?: 'Unknown', $row['total'], $row['hired'].' hired · '.$row['conversion_rate'].'%']);
        }
        foreach ($analytics->getOpenPositionsSummary($applicationIds) as $row) {
            $this->putCsv($out, ['Open position', $row['position_title'], $row['applications'], $row['days_open'].' days open']);
        }
    }
}
