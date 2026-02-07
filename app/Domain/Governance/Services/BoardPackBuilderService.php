<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

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
    public function build(GovernanceMeeting $meeting, ?\App\Domain\Governance\Models\DashboardSnapshot $snapshot = null): BoardPack
    {
        // Capture or use provided snapshot
        $snapshot = $snapshot ?? $this->dashboardService->captureSnapshot('month');

        // Generate document manifest
        $manifest = $this->buildDocumentManifest($meeting);

        // Generate PDF
        $pdf = $this->generatePdf($meeting, $snapshot, $manifest);

        // Create board pack record
        $pack = BoardPack::create([
            'governance_meeting_id' => $meeting->id,
            'dashboard_snapshot_id' => $snapshot->id,
            'document_manifest' => $manifest,
            'generated_at' => now(),
            'generated_by' => auth()->id() ?? $meeting->created_by,
            'file_path' => $pdf['path'],
            'file_size' => $pdf['size'],
            'checksum' => $pdf['checksum'],
            'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
        ]);

        return $pack;
    }

    /**
     * Build the document manifest
     */
    protected function buildDocumentManifest(GovernanceMeeting $meeting): array
    {
        $manifest = [
            ['id' => 'cover', 'title' => 'Cover & Agenda', 'type' => 'auto', 'included' => true],
            ['id' => 'dashboard', 'title' => 'Dashboard Snapshot', 'type' => 'auto', 'included' => true],
            ['id' => 'risk_report', 'title' => 'Risk Report', 'type' => 'auto', 'included' => true],
        ];

        // Add CEO performance if People Committee or Full Board
        if ($meeting->isFullBoard() || $meeting->meeting_type === 'people') {
            $manifest[] = ['id' => 'ceo_performance', 'title' => 'CEO Performance Scorecard', 'type' => 'auto', 'included' => true];
        }

        // Add finance report if Finance Committee or Full Board
        if ($meeting->isFullBoard() || $meeting->meeting_type === 'finance') {
            $manifest[] = ['id' => 'finance_report', 'title' => 'Finance Committee Report', 'type' => 'auto', 'included' => true];
        }

        // Add committee reports
        if ($meeting->isFullBoard()) {
            $manifest[] = ['id' => 'committee_reports', 'title' => 'Committee Reports', 'type' => 'auto', 'included' => true];
        }

        // Add agenda item supporting docs
        foreach ($meeting->agendaItems as $item) {
            if ($item->supporting_doc_ids) {
                $manifest[] = [
                    'id' => 'agenda_' . $item->id,
                    'title' => $item->title . ' - Supporting Documents',
                    'type' => 'attachment',
                    'included' => true,
                    'agenda_item_id' => $item->id,
                ];
            }
        }

        return $manifest;
    }

    /**
     * Generate the PDF board pack
     */
    protected function generatePdf(GovernanceMeeting $meeting, $snapshot, array $manifest): array
    {
        if (!class_exists(Pdf::class)) {
            throw new \RuntimeException('PDF generation library not installed. Run: composer require barryvdh/laravel-dompdf');
        }

        // Build PDF content
        $content = $this->buildPdfContent($meeting, $snapshot, $manifest);
        $watermark = 'CONFIDENTIAL - BOARD ONLY';

        // Generate filename
        $filename = sprintf(
            'board-pack-%s-%s.pdf',
            $meeting->scheduled_at->format('Y-m-d'),
            str_replace(' ', '-', strtolower($meeting->title))
        );

        $path = 'board-packs/' . $filename;

        // Create PDF (using Laravel PDF facade)
        $pdf = Pdf::loadView('governance.board-pack.pdf', [
            'meeting' => $meeting,
            'snapshot' => $snapshot,
            'manifest' => $manifest,
            'content' => $content,
            'generated_at' => now(),
            'watermark' => $watermark,
        ]);
        $pdf->setPaper('a4', 'portrait');

        // Save to storage
        Storage::put($path, $pdf->output());

        return [
            'path' => $path,
            'size' => Storage::size($path),
            'checksum' => hash_file('sha256', Storage::path($path)),
        ];
    }

    /**
     * Build PDF content sections
     */
    protected function buildPdfContent(GovernanceMeeting $meeting, $snapshot, array $manifest): array
    {
        return [
            'cover' => [
                'title' => $meeting->title,
                'date' => $meeting->scheduled_at->format('l, j F Y'),
                'type' => $this->getMeetingTypeLabel($meeting->meeting_type),
            ],
            'agenda' => $meeting->agendaItems->map(fn($item) => [
                'order' => $item->order,
                'title' => $item->title,
                'presenter' => $item->presenter?->name,
                'duration' => $item->duration_minutes,
                'type' => $item->item_type,
            ])->toArray(),
            'dashboard' => $snapshot->snapshot_data['widgets'] ?? [],
            'risk_report' => $this->riskService->generateBoardReport(),
        ];
    }

    /**
     * Get human-readable meeting type label
     */
    protected function getMeetingTypeLabel(string $type): string
    {
        return match($type) {
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
        
        // Get all board members or specified ones
        $recipients = $boardMemberIds 
            ? \App\Domain\Governance\Models\BoardMember::whereIn('id', $boardMemberIds)->get()
            : \App\Domain\Governance\Models\BoardMember::active()->get();

        $ids = $recipients->pluck('id')->toArray();
        $pack->markAsDistributed($ids);

        // Send notifications
        foreach ($recipients as $member) {
            \App\Domain\Governance\Jobs\SendBoardPackNotification::dispatch($pack, $member);
        }

        // Update meeting status
        $meeting->update(['pack_distributed_at' => now()]);
    }

    /**
     * Regenerate a pack (with new snapshot)
     */
    public function regenerate(BoardPack $pack): BoardPack
    {
        // Delete old file
        if ($pack->file_path && Storage::exists($pack->file_path)) {
            Storage::delete($pack->file_path);
        }

        // Delete old snapshot
        $pack->snapshot->delete();

        // Build new pack
        return $this->build($pack->meeting);
    }

    /**
     * Get pack download URL
     */
    public function getDownloadUrl(BoardPack $pack, BoardMember $boardMember): ?string
    {
        // Verify board member is authorized
        if (!in_array($boardMember->id, $pack->distributed_to ?? [])) {
            return null;
        }

        // Record download
        $pack->recordDownload($boardMember->id);

        // Generate temporary URL
        return Storage::temporaryUrl($pack->file_path, now()->addHour());
    }

    /**
     * Preview pack (without saving)
     */
    public function preview(GovernanceMeeting $meeting): array
    {
        $snapshot = $this->dashboardService->captureSnapshot('month');
        $manifest = $this->buildDocumentManifest($meeting);

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
            $pages += match($item['id']) {
                'dashboard' => 3,
                'risk_report' => 4,
                'ceo_performance' => 3,
                'finance_report' => 5,
                'committee_reports' => 3,
                default => 1,
            };
        }

        return $pages;
    }
}
