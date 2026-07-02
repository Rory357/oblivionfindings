<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Services\RecruitmentAnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-side, uncapped CSV export for the recruitment hub tabs. Streams rows so
 * a large pipeline doesn't buffer in memory. Tenant-scoped, gated on view.
 */
class RecruitmentExportController extends Controller
{
    use ResolvesHrTenant;

    private const DATASETS = ['pipeline', 'requisitions', 'offers', 'analytics'];

    public function export(Request $request, RecruitmentAnalyticsService $analytics): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $validated = $request->validate([
            'dataset' => ['required', 'string', Rule::in(self::DATASETS)],
            'format' => ['nullable', 'string', Rule::in(['csv'])],
        ]);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $dataset = $validated['dataset'];
        $filename = "recruitment-{$dataset}-".date('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($dataset, $tenantId, $analytics) {
            $out = fopen('php://output', 'w');

            match ($dataset) {
                'pipeline' => $this->streamPipeline($out, $tenantId),
                'requisitions' => $this->streamRequisitions($out, $tenantId),
                'offers' => $this->streamOffers($out, $tenantId),
                'analytics' => $this->streamAnalytics($out, $tenantId, $analytics),
            };

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @param resource $out */
    private function streamPipeline($out, ?int $tenantId): void
    {
        $this->putCsv($out, ['Name', 'Email', 'Stage', 'Requisition', 'Source', 'Days in stage', 'Created']);

        HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
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
    private function streamRequisitions($out, ?int $tenantId): void
    {
        $this->putCsv($out, ['Title', 'Site', 'Status', 'Openings', 'Applicants', 'Hiring manager', 'Employment type']);

        HrJobRequisition::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->with(['site:id,name', 'hiringManager:id,name'])
            ->withCount('applications')
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
    private function streamOffers($out, ?int $tenantId): void
    {
        $this->putCsv($out, ['Candidate', 'Role', 'Approval', 'Response', 'Hourly rate', 'Annual salary', 'Sent at', 'Created']);

        HrOffer::query()
            ->with(['application.candidate:id,first_name,last_name,tenant_id'])
            ->when($tenantId !== null, fn ($q) => $q->whereHas('application.candidate', fn ($c) => $c->where('tenant_id', $tenantId)))
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
    private function streamAnalytics($out, ?int $tenantId, RecruitmentAnalyticsService $analytics): void
    {
        $this->putCsv($out, ['Section', 'Label', 'Count', 'Detail']);

        foreach ($analytics->getPipelineConversion($tenantId) as $row) {
            $this->putCsv($out, ['Conversion funnel', $row['stage'], $row['count'], ($row['percentage'] ?? 0).'%']);
        }
        foreach ($analytics->getSourceEffectiveness($tenantId) as $row) {
            $this->putCsv($out, ['Source', $row['source'] ?: 'Unknown', $row['total'], $row['hired'].' hired · '.$row['conversion_rate'].'%']);
        }
        foreach ($analytics->getOpenPositionsSummary($tenantId) as $row) {
            $this->putCsv($out, ['Open position', $row['position_title'], $row['applications'], $row['days_open'].' days open']);
        }
    }
}
