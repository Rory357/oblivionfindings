<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\AuditEvidencePack;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\RiskRegisterEntry;
use Illuminate\Support\Facades\Storage;

class AuditEvidencePackService
{
    public function generate(string $type, ?string $periodStart = null, ?string $periodEnd = null, ?string $framework = null): AuditEvidencePack
    {
        $start = $periodStart ? \Carbon\Carbon::parse($periodStart) : now()->subYear();
        $end = $periodEnd ? \Carbon\Carbon::parse($periodEnd) : now();

        $contents = match($type) {
            'compliance' => $this->gatherComplianceEvidence($start, $end, $framework),
            'risk' => $this->gatherRiskEvidence($start, $end),
            'meeting' => $this->gatherMeetingEvidence($start, $end),
            'full_governance' => $this->gatherFullGovernanceEvidence($start, $end),
            default => [],
        };

        return AuditEvidencePack::create([
            'pack_type' => $type,
            'period_start' => $start,
            'period_end' => $end,
            'framework' => $framework,
            'contents' => $contents,
            'generated_by' => auth()->id(),
            'generated_at' => now(),
            'status' => 'ready',
        ]);
    }

    public function download(int $packId)
    {
        $pack = AuditEvidencePack::findOrFail($packId);

        $pack->update(['downloaded_at' => now(), 'downloaded_by' => auth()->id()]);

        // Return JSON manifest of evidence (in production, this would zip files)
        return response()->json([
            'pack' => $pack,
            'contents' => $pack->contents,
        ]);
    }

    private function gatherComplianceEvidence($start, $end, ?string $framework): array
    {
        $query = ComplianceObligation::with(['evidence', 'owner'])
            ->whereBetween('due_date', [$start, $end]);

        if ($framework) {
            $query->where('framework', $framework);
        }

        $obligations = $query->get();

        return [
            'type' => 'compliance',
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'obligations' => $obligations->map(fn($o) => [
                'title' => $o->obligation_title,
                'framework' => $o->getFrameworkLabel(),
                'status' => $o->status,
                'due_date' => $o->due_date?->toDateString(),
                'owner' => $o->owner?->name,
                'evidence_count' => $o->evidence->count(),
                'signed_off' => $o->signed_off_at !== null,
                'evidence' => $o->evidence->map(fn($e) => [
                    'title' => $e->title,
                    'file_path' => $e->file_path,
                    'uploaded_at' => $e->created_at?->toDateString(),
                ])->toArray(),
            ])->toArray(),
            'summary' => [
                'total' => $obligations->count(),
                'complete' => $obligations->where('status', 'complete')->count(),
                'overdue' => $obligations->where('status', 'overdue')->count(),
            ],
        ];
    }

    private function gatherRiskEvidence($start, $end): array
    {
        $risks = RiskRegisterEntry::with(['treatments', 'acceptances', 'events'])
            ->get();

        return [
            'type' => 'risk',
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'risks' => $risks->map(fn($r) => [
                'reference' => $r->risk_reference,
                'title' => $r->title,
                'category' => $r->category,
                'scores' => [
                    'inherent' => $r->inherent_score,
                    'residual' => $r->residual_score,
                    'within_appetite' => $r->within_appetite,
                ],
                'treatments' => $r->treatments->count(),
                'acceptances' => $r->acceptances->count(),
                'linked_events' => $r->events->count(),
            ])->toArray(),
            'summary' => [
                'total_active' => $risks->where('status', 'active')->count(),
                'critical' => $risks->where('residual_score', '>=', 20)->count(),
                'above_appetite' => $risks->where('within_appetite', false)->count(),
            ],
        ];
    }

    private function gatherMeetingEvidence($start, $end): array
    {
        $meetings = GovernanceMeeting::with(['minutes', 'attendances', 'resolutions'])
            ->whereBetween('scheduled_at', [$start, $end])
            ->get();

        return [
            'type' => 'meetings',
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'meetings' => $meetings->map(fn($m) => [
                'title' => $m->title,
                'date' => $m->scheduled_at?->toDateString(),
                'type' => $m->meeting_type,
                'status' => $m->status,
                'quorum_met' => $m->quorum_met,
                'minutes_approved' => $m->minutes?->isApproved() ?? false,
                'resolutions_count' => $m->resolutions->count(),
                'attendance_count' => $m->attendances->count(),
            ])->toArray(),
        ];
    }

    private function gatherFullGovernanceEvidence($start, $end): array
    {
        return [
            'type' => 'full_governance',
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'compliance' => $this->gatherComplianceEvidence($start, $end, null),
            'risk' => $this->gatherRiskEvidence($start, $end),
            'meetings' => $this->gatherMeetingEvidence($start, $end),
        ];
    }
}
