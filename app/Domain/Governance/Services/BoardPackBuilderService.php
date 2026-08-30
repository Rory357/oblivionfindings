<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Jobs\SendBoardPackNotification;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\DashboardSnapshot;
use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\GovernanceMeeting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BoardPackBuilderService
{
    protected DashboardAggregatorService $dashboardService;

    protected RiskScoringService $riskService;

    public function __construct(
        DashboardAggregatorService $dashboardService,
        RiskScoringService $riskService
    ) {
        $this->dashboardService = $dashboardService;
        $this->riskService = $riskService;
    }

    /**
     * Build a complete board pack
     */
    public function build(GovernanceMeeting $meeting, ?DashboardSnapshot $snapshot = null): BoardPack
    {
        $snapshot = $snapshot ?? $this->dashboardService->captureSnapshot('month');
        $content = $this->buildPackContent($meeting, $snapshot);
        $manifest = $this->buildDocumentManifest($content);
        $fileData = $this->generateFile($meeting, $content);

        $pack = BoardPack::create([
            'governance_meeting_id' => $meeting->id,
            'dashboard_snapshot_id' => $snapshot->id,
            'document_manifest' => [
                'manifest_sections' => $manifest,
                'content_sections' => $content,
            ],
            'generated_at' => now(),
            'generated_by' => auth()->id() ?? $meeting->created_by,
            'file_path' => $fileData['path'] ?? null,
            'file_size' => $fileData['size'] ?? null,
            'checksum' => $fileData['checksum'] ?? $this->generateContentChecksum($content),
            'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
        ]);

        return $pack;
    }

    /**
     * Build the document manifest
     */
    protected function buildDocumentManifest(array $content): array
    {
        return collect($content)
            ->map(function ($section, $key) {
                return [
                    'id' => $key,
                    'title' => $this->sectionTitle($key),
                    'type' => $key === 'supporting_documents' ? 'attachment' : 'auto',
                    'included' => true,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build pack content sections
     */
    protected function buildPackContent(GovernanceMeeting $meeting, $snapshot): array
    {
        $meeting->loadMissing(['agendaItems.presenter', 'ceoReport.submittedBy', 'resolutions']);

        $content = [
            'cover' => [
                'title' => $meeting->title,
                'date' => $meeting->scheduled_at->format('l, j F Y'),
                'type' => $this->getMeetingTypeLabel($meeting->meeting_type),
            ],
            'agenda' => $meeting->agendaItems->map(fn ($item) => [
                'order' => $item->order,
                'title' => $item->title,
                'presenter' => $item->presenter?->name,
                'duration' => $item->duration_minutes,
                'type' => $item->item_type,
            ])->toArray(),
            'dashboard' => $snapshot->snapshot_data['widgets'] ?? [],
            'risk_report' => $this->riskService->generateBoardReport(),
        ];

        if ($financeSection = $this->buildFinanceSection($snapshot)) {
            $content['finance_report'] = $financeSection;
        }

        if ($meeting->ceoReport) {
            $content['ceo_report'] = [
                'status' => $meeting->ceoReport->status,
                'submitted_at' => $meeting->ceoReport->submitted_at?->toIso8601String(),
                'submitted_by' => $meeting->ceoReport->submittedBy?->name,
                'operational_summary' => $meeting->ceoReport->operational_summary,
                'key_achievements' => $meeting->ceoReport->key_achievements,
                'challenges_and_risks' => $meeting->ceoReport->challenges_and_risks,
                'staffing_update' => $meeting->ceoReport->staffing_update,
                'compliance_status' => $meeting->ceoReport->compliance_status,
                'financial_summary' => $meeting->ceoReport->financial_summary,
                'recommendations' => $meeting->ceoReport->recommendations,
            ];
        }

        if ($committeeReports = $this->buildCommitteeReports($meeting)) {
            $content['committee_reports'] = ['items' => $committeeReports];
        }

        if ($supportingDocs = $this->buildSupportingDocuments($meeting)) {
            $content['supporting_documents'] = ['items' => $supportingDocs];
        }

        if ($meeting->resolutions->isNotEmpty()) {
            $content['resolutions'] = [
                'items' => $meeting->resolutions->map(fn ($resolution) => [
                    'id' => $resolution->id,
                    'reference' => $resolution->resolution_reference,
                    'title' => $resolution->title,
                    'status' => $resolution->status,
                    'deadline' => $resolution->deadline?->toDateString(),
                ])->values()->all(),
            ];
        }

        return $content;
    }

    /**
     * Generate file output - PDF if library available, JSON fallback
     */
    protected function generateFile(GovernanceMeeting $meeting, array $content): array
    {
        // Try PDF generation if dompdf is available
        if (class_exists(Pdf::class)) {
            return $this->generatePdf($meeting, $content);
        }

        // Fallback: store as JSON file
        return $this->generateJsonPack($meeting, $content);
    }

    /**
     * Generate a JSON-based board pack file
     */
    protected function generateJsonPack(GovernanceMeeting $meeting, array $content): array
    {
        $filename = sprintf(
            'board-pack-%s-%s.json',
            $meeting->scheduled_at->format('Y-m-d'),
            Str::slug($meeting->title)
        );

        $path = 'board-packs/'.$filename;
        $jsonContent = json_encode($content, JSON_PRETTY_PRINT);

        Storage::put($path, $jsonContent);

        return [
            'path' => $path,
            'size' => Storage::size($path),
            'checksum' => hash('sha256', $jsonContent),
        ];
    }

    /**
     * Generate PDF board pack (requires barryvdh/laravel-dompdf)
     */
    protected function generatePdf(GovernanceMeeting $meeting, array $content): array
    {
        $filename = sprintf(
            'board-pack-%s-%s.pdf',
            $meeting->scheduled_at->format('Y-m-d'),
            Str::slug($meeting->title)
        );

        $path = 'board-packs/'.$filename;

        $pdf = Pdf::loadView('governance.board-pack.pdf', [
            'meeting' => $meeting,
            'content' => $content,
            'generated_at' => now(),
            'watermark' => 'CONFIDENTIAL - BOARD ONLY',
        ]);
        $pdf->setPaper('a4', 'portrait');

        Storage::put($path, $pdf->output());

        return [
            'path' => $path,
            'size' => Storage::size($path),
            'checksum' => hash_file('sha256', Storage::path($path)),
        ];
    }

    /**
     * Generate a checksum for content data
     */
    protected function generateContentChecksum(array $content): string
    {
        return hash('sha256', json_encode($content));
    }

    /**
     * Get human-readable meeting type label
     */
    protected function getMeetingTypeLabel(string $type): string
    {
        return match ($type) {
            'full_board' => 'Full Board Meeting',
            'audit_risk' => 'Audit & Risk Committee',
            'people' => 'People Committee',
            'finance' => 'Finance Committee',
            'special_general' => 'Special General Meeting',
            'executive_session' => 'Executive Session',
            default => 'Board Meeting',
        };
    }

    /**
     * Distribute pack to board members
     */
    public function distribute(BoardPack $pack, ?array $boardMemberIds = null): void
    {
        $meeting = $pack->meeting;

        // Explicit and default recipient lists use the same canonical active-term boundary.
        $recipientQuery = BoardMember::query()->active();
        if (! empty($boardMemberIds)) {
            $recipientQuery->whereIn('id', array_unique(array_map('intval', $boardMemberIds)));
        }

        $recipients = $recipientQuery->get();

        $ids = $recipients->pluck('id')->toArray();
        $pack->markAsDistributed($ids);

        // Send notifications
        foreach ($recipients as $member) {
            if (class_exists(SendBoardPackNotification::class)) {
                SendBoardPackNotification::dispatch($pack, $member);
            }
        }

        // Update meeting status
        $meeting->update(['pack_distributed_at' => now()]);
    }

    /**
     * Regenerate a pack (with new snapshot)
     */
    public function regenerate(BoardPack $pack): BoardPack
    {
        $meeting = $pack->meeting;
        if ($pack->file_path && Storage::exists($pack->file_path)) {
            Storage::delete($pack->file_path);
        }

        if ($pack->snapshot) {
            $pack->snapshot->delete();
        }

        $snapshot = $this->dashboardService->captureSnapshot('month');
        $content = $this->buildPackContent($meeting, $snapshot);
        $manifest = $this->buildDocumentManifest($content);
        $fileData = $this->generateFile($meeting, $content);

        $pack->update([
            'dashboard_snapshot_id' => $snapshot->id,
            'document_manifest' => [
                'manifest_sections' => $manifest,
                'content_sections' => $content,
            ],
            'generated_at' => now(),
            'generated_by' => auth()->id() ?? $meeting->created_by,
            'file_path' => $fileData['path'] ?? null,
            'file_size' => $fileData['size'] ?? null,
            'checksum' => $fileData['checksum'] ?? $this->generateContentChecksum($content),
            'distributed_at' => null,
            'distributed_to' => null,
        ]);

        return $pack->fresh();
    }

    /**
     * Preview pack (without saving)
     */
    public function preview(GovernanceMeeting $meeting): array
    {
        $snapshot = $this->dashboardService->captureSnapshot('month');
        $content = $this->buildPackContent($meeting, $snapshot);
        $manifest = $this->buildDocumentManifest($content);

        return [
            'meeting' => [
                'title' => $meeting->title,
                'date' => $meeting->scheduled_at->format('Y-m-d'),
            ],
            'snapshot_period' => [
                'start' => $snapshot->period_start->toDateString(),
                'end' => $snapshot->period_end->toDateString(),
            ],
            'manifest' => $manifest,
            'dashboard_summary' => $snapshot->snapshot_data['widgets'] ?? [],
            'estimated_pages' => $this->estimatePageCount($manifest),
        ];
    }

    /**
     * Estimate page count for pack
     */
    protected function estimatePageCount(array $manifest): int
    {
        $pages = 2; // Cover + agenda

        foreach ($manifest as $item) {
            $pages += match ($item['id']) {
                'dashboard' => 3,
                'risk_report' => 4,
                'ceo_report' => 3,
                'finance_report' => 5,
                'committee_reports' => 3,
                'supporting_documents' => 2,
                default => 1,
            };
        }

        return $pages;
    }

    protected function buildFinanceSection($snapshot): ?array
    {
        $financial = $snapshot->snapshot_data['widgets']['financial'] ?? null;
        if (! is_array($financial)) {
            return null;
        }

        return [
            'fiscal_year' => $financial['fiscal_year'] ?? null,
            'utilization' => isset($financial['budget_utilization']) ? round((float) $financial['budget_utilization'], 1).'%' : 'Unavailable',
            'variance' => isset($financial['variance']) ? round((float) $financial['variance'], 1).'%' : 'Unavailable',
            'budget_total' => $financial['budget_total'] ?? null,
            'actual_total' => $financial['actual_total'] ?? null,
            'roadmap_forecast_total' => $financial['roadmap_forecast_total'] ?? null,
            'governance_envelope_total' => $financial['governance_envelope_total'] ?? null,
        ];
    }

    protected function buildCommitteeReports(GovernanceMeeting $meeting): array
    {
        if (! $meeting->isFullBoard()) {
            return [];
        }

        return BoardCommittee::query()
            ->with('chair.user')
            ->where('is_active', true)
            ->get()
            ->map(fn (BoardCommittee $committee) => [
                'id' => $committee->id,
                'name' => $committee->name,
                'chair' => $committee->chair?->user?->name,
                'meeting_frequency' => $committee->meeting_frequency,
                'description' => $committee->description,
            ])
            ->values()
            ->all();
    }

    protected function buildSupportingDocuments(GovernanceMeeting $meeting): array
    {
        $documentIds = $meeting->agendaItems
            ->pluck('supporting_doc_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        if ($documentIds->isEmpty()) {
            return [];
        }

        return GovernanceDocument::query()
            ->whereIn('id', $documentIds)
            ->get()
            ->map(fn (GovernanceDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => $document->category,
                'document_type' => $document->document_type,
                'version_number' => $document->version_number,
            ])
            ->values()
            ->all();
    }

    protected function sectionTitle(string $key): string
    {
        return match ($key) {
            'cover' => 'Cover & Meeting Overview',
            'agenda' => 'Agenda',
            'dashboard' => 'Executive Dashboard Snapshot',
            'risk_report' => 'Risk Report',
            'finance_report' => 'Financial Summary',
            'ceo_report' => 'CEO Board Report',
            'committee_reports' => 'Committee Updates',
            'supporting_documents' => 'Supporting Documents',
            'resolutions' => 'Decision Papers',
            default => str($key)->replace('_', ' ')->title()->toString(),
        };
    }
}
