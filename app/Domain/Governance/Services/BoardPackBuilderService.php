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
     * Build a complete board pack (creates next revision if one already exists)
     */
    public function build(GovernanceMeeting $meeting, ?DashboardSnapshot $snapshot = null): BoardPack
    {
        $latestPack = BoardPack::where('governance_meeting_id', $meeting->id)
            ->orderByDesc('revision_number')
            ->first();

        $revisionNumber = $latestPack ? (int) $latestPack->revision_number + 1 : 1;
        $supersedesId = $latestPack?->id;

        return $this->createPackRevision($meeting, $revisionNumber, $supersedesId, $snapshot);
    }

    /**
     * Create an immutable revision of a board pack with unique storage path and snapshot.
     * Retains old revisions, files, snapshots, and read/download tracking intact.
     */
    public function createPackRevision(
        GovernanceMeeting $meeting,
        int $revisionNumber,
        ?int $supersedesId = null,
        ?DashboardSnapshot $snapshot = null
    ): BoardPack {
        $viewer = auth()->user() ?? $meeting->creator;
        $snapshot = $snapshot ?? $this->dashboardService->captureSnapshot('month', viewer: $viewer);
        $content = $this->buildPackContent($meeting, $snapshot);
        $manifest = $this->buildDocumentManifest($content);

        try {
            $fileData = $this->generateFile($meeting, $content, $revisionNumber);
        } catch (\Throwable $e) {
            BoardPack::create([
                'governance_meeting_id' => $meeting->id,
                'revision_number' => $revisionNumber,
                'supersedes_id' => $supersedesId,
                'build_status' => 'failed',
                'error_reference' => $e->getMessage(),
                'is_current' => false,
                'dashboard_snapshot_id' => $snapshot->id,
                'document_manifest' => [
                    'manifest_sections' => $manifest,
                    'content_sections' => $content,
                ],
                'generated_at' => now(),
                'generated_by' => auth()->id() ?? $meeting->created_by,
                'checksum' => $this->generateContentChecksum($content),
                'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
            ]);

            throw $e;
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use (
            $meeting,
            $snapshot,
            $content,
            $manifest,
            $fileData,
            $revisionNumber,
            $supersedesId
        ) {
            // Atomic pointer switch: previous versions for this meeting become non-current
            BoardPack::where('governance_meeting_id', $meeting->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $pack = BoardPack::create([
                'governance_meeting_id' => $meeting->id,
                'revision_number' => $revisionNumber,
                'supersedes_id' => $supersedesId,
                'build_status' => 'published',
                'is_current' => true,
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
                'read_tracking' => [],
                'download_tracking' => [],
            ]);

            if ($meeting->status === 'scheduled') {
                $meeting->update(['status' => 'pack_draft']);
            }

            return $pack;
        });
    }

    /**
     * Build the document manifest
     */
    protected function buildDocumentManifest(array $content): array
    {
        $manifest = [];
        foreach ($content as $key => $section) {
            if ($key === 'supporting_documents' && ! empty($section['items'])) {
                foreach ($section['items'] as $idx => $doc) {
                    $manifest[] = [
                        'id' => "doc_{$idx}",
                        'title' => $doc['title'] ?? "Supporting Document " . ($idx + 1),
                        'type' => 'attachment',
                        'included' => true,
                    ];
                }
            } elseif ($key === 'resolutions' && ! empty($section['items'])) {
                foreach ($section['items'] as $res) {
                    $manifest[] = [
                        'id' => "res_{$res['id']}",
                        'title' => "Paper: {$res['title']}",
                        'type' => 'paper',
                        'included' => true,
                    ];
                }
            } else {
                $manifest[] = [
                    'id' => $key,
                    'title' => $this->sectionTitle($key),
                    'type' => 'section',
                    'included' => true,
                ];
            }
        }

        return $manifest;
    }

    /**
     * Build pack content sections
     */
    protected function buildPackContent(GovernanceMeeting $meeting, $snapshot): array
    {
        $meeting->loadMissing(['agendaItems.presenter', 'ceoReport.submittedBy', 'resolutions']);

        $viewer = auth()->user();
        $agendaItems = $meeting->agendaItems->filter(function ($item) use ($meeting, $viewer) {
            if (! $item->is_confidential) {
                return true;
            }
            if (! $viewer) {
                return false;
            }
            return app(ExecutiveMeetingAccessService::class)->canViewAgendaItem($viewer, $meeting, $item);
        });

        $content = [
            'cover' => [
                'title' => $meeting->title,
                'date' => $meeting->scheduled_at->format('l, j F Y'),
                'type' => $this->getMeetingTypeLabel($meeting->meeting_type),
            ],
            'agenda' => $agendaItems->map(fn ($item) => [
                'order' => $item->order,
                'title' => $item->title,
                'presenter' => $item->presenter?->name,
                'duration' => $item->duration_minutes,
                'type' => $item->item_type,
                'is_confidential' => (bool) $item->is_confidential,
            ])->values()->toArray(),
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
                    'exact_motion' => $resolution->exact_motion,
                    'purpose' => $resolution->purpose,
                    'context' => $resolution->context,
                    'recommendation' => $resolution->recommendation,
                    'options' => $resolution->options ?? [],
                    'single_option_reason' => $resolution->single_option_reason,
                    'cost_impact' => $resolution->cost_impact,
                    'risk_impact' => $resolution->risk_impact,
                    'service_user_implications' => $resolution->service_user_implications,
                    'risk_equity_implications' => $resolution->risk_equity_implications,
                    'attachments' => $resolution->attachments ?? [],
                    'version_number' => $resolution->version_number,
                    'voting_threshold' => $resolution->voting_threshold,
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
    protected function generateFile(GovernanceMeeting $meeting, array $content, int $revisionNumber = 1): array
    {
        // Try PDF generation if dompdf is available
        if (class_exists(Pdf::class)) {
            return $this->generatePdf($meeting, $content, $revisionNumber);
        }

        // Fallback: store as JSON file
        return $this->generateJsonPack($meeting, $content, $revisionNumber);
    }

    /**
     * Generate a JSON-based board pack file with collision-free path
     */
    protected function generateJsonPack(GovernanceMeeting $meeting, array $content, int $revisionNumber = 1): array
    {
        $unique = Str::random(8);
        $filename = sprintf(
            'board-pack-m%d-rev%d-%s.json',
            $meeting->id,
            $revisionNumber,
            $unique
        );

        $path = 'board-packs/'.$filename;
        $jsonContent = json_encode($content, JSON_PRETTY_PRINT);

        Storage::disk('local')->put($path, $jsonContent);

        return [
            'path' => $path,
            'size' => Storage::disk('local')->size($path),
            'checksum' => hash('sha256', $jsonContent),
        ];
    }

    /**
     * Generate PDF board pack (requires barryvdh/laravel-dompdf) with collision-free path
     */
    protected function generatePdf(GovernanceMeeting $meeting, array $content, int $revisionNumber = 1): array
    {
        $unique = Str::random(8);
        $filename = sprintf(
            'board-pack-m%d-rev%d-%s.pdf',
            $meeting->id,
            $revisionNumber,
            $unique
        );

        $path = 'board-packs/'.$filename;

        $pdf = Pdf::loadView('governance.board-pack.pdf', [
            'meeting' => $meeting,
            'content' => $content,
            'revision' => $revisionNumber,
            'generated_at' => now(),
            'watermark' => 'CONFIDENTIAL - BOARD ONLY',
        ]);
        $pdf->setPaper('a4', 'portrait');

        Storage::disk('local')->put($path, $pdf->output());

        return [
            'path' => $path,
            'size' => Storage::disk('local')->size($path),
            'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
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
     * Distribute pack to board members with audience intersection and after-commit queueing.
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

        // If executive session or pack contains confidential items, intersect with executive access
        $manifest = $pack->document_manifest ?? [];
        $contentSections = $manifest['content_sections'] ?? [];
        $agenda = $contentSections['agenda'] ?? [];

        $hasConfidential = false;
        foreach ($agenda as $item) {
            if (! empty($item['is_confidential'])) {
                $hasConfidential = true;
                break;
            }
        }

        if ($hasConfidential || $meeting->isExecutiveSession()) {
            $executiveAccess = app(\App\Domain\Governance\Services\ExecutiveMeetingAccessService::class);
            $recipients = $recipients->filter(function (BoardMember $member) use ($executiveAccess, $meeting) {
                if (! $member->user) {
                    return false;
                }
                return $executiveAccess->canViewMeeting($member->user, $meeting) && (
                    $executiveAccess->hasExecutiveAuthority($member->user) ||
                    (int) $meeting->chair_id === (int) $member->id ||
                    (int) $meeting->secretary_id === (int) $member->id ||
                    ($meeting->board_committee_id && $member->committeeMemberships()->where('board_committee_id', $meeting->board_committee_id)->where('is_active', true)->exists())
                );
            })->values();
        }

        $ids = $recipients->pluck('id')->toArray();
        $pack->markAsDistributed($ids);

        // Send notifications queued after database commit
        \Illuminate\Support\Facades\DB::afterCommit(function () use ($pack, $recipients) {
            foreach ($recipients as $member) {
                if (class_exists(SendBoardPackNotification::class)) {
                    SendBoardPackNotification::dispatch($pack, $member);
                }
            }
        });

        // Update meeting status
        $meeting->update(['pack_distributed_at' => now()]);
    }

    /**
     * Regenerate a pack: creates a new immutable revision N+1.
     * Retains the existing pack file, snapshot, and receipt records intact.
     */
    public function regenerate(BoardPack $pack): BoardPack
    {
        $meeting = $pack->meeting;
        $nextRevision = (int) ($pack->revision_number ?? 1) + 1;
        $supersedesId = $pack->id;

        return $this->createPackRevision($meeting, $nextRevision, $supersedesId);
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
