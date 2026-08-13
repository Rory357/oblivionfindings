<?php

namespace App\Http\Controllers;

use App\Models\AnonymizationLog;
use App\Models\Client;
use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use App\Models\LegalHold;
use App\Models\PrivacyImpactAssessment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Privacy command-centre.
 *
 * Feeds the redesigned /privacy/dashboard: a hero (live + needs-attention
 * clusters + NZ compliance badges), per-domain tabs with badge counts, a
 * paginated worklist for the active tab, a detail payload for the modal, and a
 * permission map. NZ-only framing (Privacy Act 2020, OPC, IPP 6/7, 20 working
 * days). Lifecycle/CRUD still live on the per-domain controllers — this only
 * assembles the read model.
 */
class PrivacyDashboardController extends Controller
{
    /** Tabs, in display order. */
    private const TABS = ['overview', 'requests', 'breaches', 'legal_holds', 'retention', 'dpia', 'deletion_logs'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canDo('privacy.viewRequests'), 403);

        $tab = in_array($request->get('tab'), self::TABS, true) ? $request->get('tab') : 'overview';
        // Per-domain least-privilege: a view-only user lands on the requests
        // worklist; a domain tab they can't view falls back to overview so the
        // worklist below never returns records the dedicated page would deny.
        if (! $this->canViewTab($request, $tab)) {
            $tab = 'overview';
        }
        $periodStart = $this->periodStart($request->get('period', 'month'));

        return Inertia::render('privacy/dashboard', [
            'tab' => $tab,
            'tabCounts' => $this->tabCounts(),
            'hero' => $this->hero($periodStart),
            'filters' => [
                'q' => $request->get('q', ''),
                'period' => $request->get('period', 'month'),
                'site_id' => $request->filled('site_id') ? (int) $request->get('site_id') : null,
                'tab' => $tab,
            ],
            'sites' => Site::query()->select('id', 'name')->orderBy('name')->get(),
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
            'clients' => Client::query()->select('id', 'first_name', 'last_name')->orderBy('first_name')->get()
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $this->clientName($c)]),
            'worklist' => $this->worklist($tab, $request, $periodStart),
            'detail' => $this->detail($request),
            'new' => in_array($request->get('new'), ['request', 'breach', 'hold', 'retention', 'dpia'], true)
                ? $request->get('new') : null,
            'can' => $this->canMap($request),
        ]);
    }

    /* ---------------------------------------------------------------- */
    /*  Hero */
    /* ---------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function hero(?Carbon $periodStart): array
    {
        return [
            'live' => [
                'new_requests' => DataSubjectRequest::query()
                    ->when($periodStart, fn ($q) => $q->where('received_at', '>=', $periodStart))->count(),
                'in_progress' => DataSubjectRequest::whereIn('status', ['under_review', 'identity_verification', 'in_progress'])->count(),
                'completed' => DataSubjectRequest::where('status', 'completed')
                    ->when($periodStart, fn ($q) => $q->where('completed_at', '>=', $periodStart))->count(),
                'breaches' => DataBreachLog::query()
                    ->when($periodStart, fn ($q) => $q->where('discovered_at', '>=', $periodStart))->count(),
            ],
            'attention' => [
                'overdue' => DataSubjectRequest::overdue()->count(),
                'opc_notify' => DataBreachLog::where('requires_authority_notification', true)->whereNull('authority_notified_at')->count(),
                'subject_notify' => DataBreachLog::where('requires_subject_notification', true)->whereNull('subjects_notified_at')->count(),
                'active_holds' => LegalHold::active()->count(),
                'high_risk_dpia' => PrivacyImpactAssessment::whereIn('overall_risk_level', ['high', 'very_high'])->whereNull('outcome')->count(),
                'retention_review' => DataRetentionPolicy::where('active', true)
                    ->whereNotNull('next_review_at')->whereDate('next_review_at', '<=', now())->count(),
            ],
            'badges' => [
                'privacy_act_ok' => true,
                'opc_open' => DataBreachLog::where('requires_authority_notification', true)->whereNull('authority_notified_at')->count(),
                'overdue_requests' => DataSubjectRequest::overdue()->count(),
                'active_holds' => LegalHold::active()->count(),
                'retention_active' => DataRetentionPolicy::where('active', true)->count(),
            ],
        ];
    }

    /** @return array<string, int> */
    private function tabCounts(): array
    {
        return [
            'overview' => DataSubjectRequest::open()->count(),
            'requests' => DataSubjectRequest::open()->count(),
            'breaches' => DataBreachLog::where('status', '!=', 'resolved')->count(),
            'legal_holds' => LegalHold::active()->count(),
            'retention' => DataRetentionPolicy::where('active', true)->count(),
            'dpia' => PrivacyImpactAssessment::whereNull('outcome')->count(),
            'deletion_logs' => AnonymizationLog::where('anonymized_at', '>=', now()->subDays(30))->count(),
        ];
    }

    /* ---------------------------------------------------------------- */
    /*  Worklist (per active tab) */
    /* ---------------------------------------------------------------- */

    private function worklist(string $tab, Request $request, ?Carbon $periodStart): LengthAwarePaginator
    {
        return match ($tab) {
            'breaches' => $this->breachRows($request, $periodStart),
            'legal_holds' => $this->holdRows($request),
            'retention' => $this->retentionRows($request),
            'dpia' => $this->dpiaRows($request),
            'deletion_logs' => $this->deletionRows($request, $periodStart),
            default => $this->requestRows($request, $periodStart),
        };
    }

    private function requestRows(Request $request, ?Carbon $periodStart): LengthAwarePaginator
    {
        $q = trim((string) $request->get('q'));
        $siteId = $request->filled('site_id') ? (int) $request->get('site_id') : null;

        return DataSubjectRequest::query()
            ->with(['client:id,first_name,last_name', 'assignedTo:id,name'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('reference_number', 'like', "%{$q}%")
                ->orWhere('subject_name', 'like', "%{$q}%")
                ->orWhere('subject_email', 'like', "%{$q}%")))
            ->when($siteId, fn ($query) => $query->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))
            ->when($periodStart, fn ($query) => $query->where('received_at', '>=', $periodStart))
            ->orderByRaw("CASE WHEN status IN ('completed','rejected','withdrawn') THEN 1 ELSE 0 END")
            ->orderByDesc('received_at')
            ->paginate(25)->withQueryString()
            ->through(fn (DataSubjectRequest $r) => [
                'id' => $r->id,
                'reference' => $r->reference_number,
                'request_type' => $r->request_type,
                'status' => $r->status,
                'subject_name' => $r->subject_name,
                'subject_email' => $r->subject_email,
                'client' => $r->client ? ['id' => $r->client->id, 'name' => $this->clientName($r->client)] : null,
                'due_date' => optional($r->extended_due_date ?: $r->due_date)->toDateString(),
                'is_overdue' => $r->isOverdue(),
                'assigned_to' => $r->assignedTo?->name,
            ]);
    }

    private function breachRows(Request $request, ?Carbon $periodStart): LengthAwarePaginator
    {
        $q = trim((string) $request->get('q'));

        return DataBreachLog::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('breach_reference', 'like', "%{$q}%")
                ->orWhere('nature_of_breach', 'like', "%{$q}%")))
            ->when($periodStart, fn ($query) => $query->where('discovered_at', '>=', $periodStart))
            ->orderByRaw("CASE WHEN status = 'resolved' THEN 1 ELSE 0 END")
            ->orderByDesc('discovered_at')
            ->paginate(25)->withQueryString()
            ->through(fn (DataBreachLog $b) => [
                'id' => $b->id,
                'reference' => $b->breach_reference,
                'nature_of_breach' => $b->nature_of_breach,
                'severity' => $b->severity,
                'status' => $b->status,
                'discovered_at' => optional($b->discovered_at)->toDateString(),
                'affected' => $b->approximate_individuals_affected,
                'opc_required' => (bool) $b->requires_authority_notification,
                'opc_notified' => $b->authority_notified_at !== null,
                'subject_required' => (bool) $b->requires_subject_notification,
                'subject_notified' => $b->subjects_notified_at !== null,
            ]);
    }

    private function holdRows(Request $request): LengthAwarePaginator
    {
        $q = trim((string) $request->get('q'));

        return LegalHold::query()
            ->with('imposedBy:id,name')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('hold_reference', 'like', "%{$q}%")
                ->orWhere('reason', 'like', "%{$q}%")))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('imposed_at')
            ->paginate(25)->withQueryString()
            ->through(fn (LegalHold $h) => [
                'id' => $h->id,
                'reference' => $h->hold_reference,
                'hold_type' => $h->hold_type,
                'reason' => $h->reason,
                'legal_authority' => $h->legal_authority,
                'status' => $h->status,
                'review_date' => optional($h->review_date)->toDateString(),
            ]);
    }

    private function retentionRows(Request $request): LengthAwarePaginator
    {
        $q = trim((string) $request->get('q'));

        return DataRetentionPolicy::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('policy_name', 'like', "%{$q}%")
                ->orWhere('model_type', 'like', "%{$q}%")))
            ->orderBy('model_type')
            ->paginate(25)->withQueryString()
            ->through(fn (DataRetentionPolicy $p) => [
                'id' => $p->id,
                'policy_name' => $p->policy_name,
                'model_type' => class_basename($p->model_type),
                'retention_period_years' => $p->retention_period_years,
                'legal_basis' => $p->legal_basis,
                'active' => (bool) $p->active,
                'execution_state' => $p->execution_state ?? 'draft',
                'preview_eligible_count' => (int) data_get($p->preview_snapshot, 'eligible_count', 0),
                'next_review_at' => optional($p->next_review_at)->toDateString(),
                'review_due' => $p->next_review_at !== null && $p->next_review_at->lte(now()),
            ]);
    }

    private function dpiaRows(Request $request): LengthAwarePaginator
    {
        $q = trim((string) $request->get('q'));

        return PrivacyImpactAssessment::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('assessment_name', 'like', "%{$q}%")
                ->orWhere('project_or_process', 'like', "%{$q}%")))
            ->orderByRaw('CASE WHEN outcome IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('assessment_date')
            ->paginate(25)->withQueryString()
            ->through(fn (PrivacyImpactAssessment $d) => [
                'id' => $d->id,
                'reference' => 'DPIA-'.str_pad((string) $d->id, 4, '0', STR_PAD_LEFT),
                'assessment_name' => $d->assessment_name,
                'project_or_process' => $d->project_or_process,
                'assessment_type' => $d->assessment_type,
                'overall_risk_level' => $d->overall_risk_level,
                'outcome' => $d->outcome,
            ]);
    }

    private function deletionRows(Request $request, ?Carbon $periodStart): LengthAwarePaginator
    {
        $q = trim((string) $request->get('q'));

        return AnonymizationLog::query()
            ->with('anonymizedBy:id,name')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('reason', 'like', "%{$q}%")
                ->orWhere('model_type', 'like', "%{$q}%")))
            ->when($periodStart, fn ($query) => $query->where('anonymized_at', '>=', $periodStart))
            ->orderByDesc('anonymized_at')
            ->paginate(25)->withQueryString()
            ->through(fn (AnonymizationLog $log) => [
                'id' => $log->id,
                'reference' => 'DEL-'.str_pad((string) $log->id, 4, '0', STR_PAD_LEFT),
                'model_type' => class_basename($log->model_type),
                'model_id' => $log->model_id,
                'reason' => $log->reason,
                'fields_count' => is_array($log->fields_anonymized) ? count($log->fields_anonymized) : 0,
                'anonymized_at' => optional($log->anonymized_at)->toIso8601String(),
                'anonymized_by' => $log->anonymizedBy?->name,
                'reversible' => (bool) $log->reversible,
            ]);
    }

    /* ---------------------------------------------------------------- */
    /*  Detail (detail-as-modal — only when ?<kind>=<id> present) */
    /* ---------------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    private function detail(Request $request): ?array
    {
        $user = $request->user();

        if ($id = $request->integer('request')) {
            return $this->requestDetail($request, $id);
        }
        // Per-domain detail is gated by the domain permission — the same gate
        // the dedicated breach/hold/DPIA pages enforce — so a view-only user
        // can't drill into records they otherwise can't see.
        if (($id = $request->integer('breach')) && $user?->canDo('privacy.reportBreaches')) {
            return $this->breachDetail($request, $id);
        }
        if (($id = $request->integer('hold')) && $user?->canDo('privacy.manageLegalHolds')) {
            return $this->holdDetail($request, $id);
        }
        if (($id = $request->integer('dpia')) && $user?->canDo('privacy.conductDPIA')) {
            return $this->dpiaDetail($request, $id);
        }

        return null;
    }

    private function canViewTab(Request $request, string $tab): bool
    {
        $perm = match ($tab) {
            'breaches' => 'privacy.reportBreaches',
            'legal_holds' => 'privacy.manageLegalHolds',
            'retention', 'deletion_logs' => 'privacy.manageRetention',
            'dpia' => 'privacy.conductDPIA',
            default => 'privacy.viewRequests',
        };

        return (bool) $request->user()?->canDo($perm);
    }

    /** @return array<string, mixed> */
    private function requestDetail(Request $request, int $id): array
    {
        $r = DataSubjectRequest::with([
            'client:id,first_name,last_name', 'assignedTo:id,name', 'verifiedBy:id,name',
            'completedBy:id,name', 'attachments.uploader:id,name',
        ])->findOrFail($id);

        $deadline = $r->extended_due_date ?: $r->due_date;
        $timeline = [];
        $timeline[] = ['at' => $r->received_at, 'label' => 'Request received', 'tone' => 'info'];
        if ($r->identity_verified === 'verified' && $r->identity_verified_at) {
            $timeline[] = ['at' => $r->identity_verified_at, 'label' => 'Identity verified'.($r->verification_method ? " · {$r->verification_method}" : ''), 'tone' => 'success'];
        }
        if ($r->assigned_at && $r->assignedTo) {
            $timeline[] = ['at' => $r->assigned_at, 'label' => 'Assigned to '.$r->assignedTo->name, 'tone' => 'neutral'];
        }
        if ($r->status === 'completed' && $r->completed_at) {
            $timeline[] = ['at' => $r->completed_at, 'label' => 'Request completed', 'tone' => 'success'];
        }
        if ($r->status === 'rejected') {
            $timeline[] = ['at' => $r->updated_at, 'label' => 'Request refused'.($r->rejection_legal_basis ? " · {$r->rejection_legal_basis}" : ''), 'tone' => 'critical'];
        }

        return [
            'kind' => 'request',
            'id' => $r->id,
            'reference' => $r->reference_number,
            'request_type' => $r->request_type,
            'status' => $r->status,
            'subject_name' => $r->subject_name,
            'subject_email' => $r->subject_email,
            'client' => $r->client ? ['id' => $r->client->id, 'name' => $this->clientName($r->client)] : null,
            'request_details' => $r->request_details,
            'identity_verified' => $r->identity_verified,
            'identity_verified_at' => optional($r->identity_verified_at)->toIso8601String(),
            'verification_method' => $r->verification_method,
            'verified_by' => $r->verifiedBy?->name,
            'assigned_to' => $r->assignedTo?->name,
            'received_at' => optional($r->received_at)->toDateString(),
            'due_date' => optional($r->due_date)->toDateString(),
            'extended_due_date' => optional($r->extended_due_date)->toDateString(),
            'deadline' => optional($deadline)->toDateString(),
            'is_overdue' => $r->isOverdue(),
            'days_remaining' => $r->daysRemaining(),
            'completed_at' => optional($r->completed_at)->toDateString(),
            'completion_notes' => $r->completion_notes,
            'rejection_reason' => $r->rejection_reason,
            'rejection_legal_basis' => $r->rejection_legal_basis,
            'export_generated_at' => optional($r->export_generated_at)->toIso8601String(),
            'attachments' => $this->serializeAttachments($r, $request->user()?->canDo('privacy.processRequests') ?? false),
            'timeline' => $this->formatTimeline($timeline),
        ];
    }

    /** @return array<string, mixed> */
    private function breachDetail(Request $request, int $id): array
    {
        $b = DataBreachLog::with(['discoveredBy:id,name', 'creator:id,name', 'attachments.uploader:id,name'])->findOrFail($id);

        $timeline = [];
        $timeline[] = ['at' => $b->discovered_at, 'label' => 'Breach discovered', 'tone' => 'critical'];
        if ($b->authority_notified_at) {
            $timeline[] = ['at' => $b->authority_notified_at, 'label' => 'OPC notified'.($b->authority_reference ? " · {$b->authority_reference}" : ''), 'tone' => 'info'];
        }
        if ($b->subjects_notified_at) {
            $timeline[] = ['at' => $b->subjects_notified_at, 'label' => 'Affected individuals notified'.($b->notification_method ? " · {$b->notification_method}" : ''), 'tone' => 'info'];
        }
        if ($b->status === 'resolved' && $b->resolved_at) {
            $timeline[] = ['at' => $b->resolved_at, 'label' => 'Breach resolved', 'tone' => 'success'];
        }

        return [
            'kind' => 'breach',
            'id' => $b->id,
            'reference' => $b->breach_reference,
            'status' => $b->status,
            'severity' => $b->severity,
            'breach_type' => $b->breach_type,
            'nature_of_breach' => $b->nature_of_breach,
            'discovered_at' => optional($b->discovered_at)->toDateString(),
            'discovered_by' => $b->discoveredBy?->name,
            'affected_data_categories' => $b->affected_data_categories ?? [],
            'approximate_individuals_affected' => $b->approximate_individuals_affected,
            'likely_consequences' => $b->likely_consequences,
            'measures_taken' => $b->measures_taken,
            'opc_required' => (bool) $b->requires_authority_notification,
            'opc_notified_at' => optional($b->authority_notified_at)->toDateString(),
            'authority_reference' => $b->authority_reference,
            'subject_required' => (bool) $b->requires_subject_notification,
            'subject_notified_at' => optional($b->subjects_notified_at)->toDateString(),
            'notification_method' => $b->notification_method,
            'resolution_notes' => $b->resolution_notes,
            'attachments' => $this->serializeAttachments($b, $request->user()?->canDo('privacy.reportBreaches') ?? false),
            'timeline' => $this->formatTimeline($timeline),
        ];
    }

    /** @return array<string, mixed> */
    private function holdDetail(Request $request, int $id): array
    {
        $h = LegalHold::with(['imposedBy:id,name', 'releasedBy:id,name', 'attachments.uploader:id,name'])->findOrFail($id);

        $timeline = [];
        $timeline[] = ['at' => $h->imposed_at, 'label' => 'Hold imposed'.($h->imposedBy ? ' by '.$h->imposedBy->name : ''), 'tone' => 'warning'];
        if ($h->status === 'released' && $h->released_at) {
            $timeline[] = ['at' => $h->released_at, 'label' => 'Hold released'.($h->releasedBy ? ' by '.$h->releasedBy->name : ''), 'tone' => 'success'];
        }

        return [
            'kind' => 'hold',
            'id' => $h->id,
            'reference' => $h->hold_reference,
            'hold_type' => $h->hold_type,
            'status' => $h->status,
            'reason' => $h->reason,
            'legal_authority' => $h->legal_authority,
            'related_records' => $h->related_records ?? [],
            'review_date' => optional($h->review_date)->toDateString(),
            'imposed_at' => optional($h->imposed_at)->toDateString(),
            'imposed_by' => $h->imposedBy?->name,
            'released_at' => optional($h->released_at)->toDateString(),
            'released_by' => $h->releasedBy?->name,
            'release_reason' => $h->release_reason,
            'attachments' => $this->serializeAttachments($h, $request->user()?->canDo('privacy.manageLegalHolds') ?? false),
            'timeline' => $this->formatTimeline($timeline),
        ];
    }

    /** @return array<string, mixed> */
    private function dpiaDetail(Request $request, int $id): array
    {
        $d = PrivacyImpactAssessment::with(['assessor:id,name', 'approvedBy:id,name', 'attachments.uploader:id,name'])->findOrFail($id);

        $timeline = [];
        $timeline[] = ['at' => $d->assessment_date, 'label' => 'Assessment started'.($d->assessor ? ' by '.$d->assessor->name : ''), 'tone' => 'info'];
        if ($d->outcome === 'approved' && $d->approved_at) {
            $timeline[] = ['at' => $d->approved_at, 'label' => 'DPIA approved'.($d->approvedBy ? ' by '.$d->approvedBy->name : ''), 'tone' => 'success'];
        } elseif ($d->outcome === 'requires_dpo_review') {
            $timeline[] = ['at' => $d->updated_at, 'label' => 'Sent for Privacy Officer review', 'tone' => 'warning'];
        } elseif ($d->outcome === 'rejected') {
            $timeline[] = ['at' => $d->updated_at, 'label' => 'DPIA rejected', 'tone' => 'critical'];
        }

        return [
            'kind' => 'dpia',
            'id' => $d->id,
            'reference' => 'DPIA-'.str_pad((string) $d->id, 4, '0', STR_PAD_LEFT),
            'assessment_name' => $d->assessment_name,
            'project_or_process' => $d->project_or_process,
            'description' => $d->description,
            'assessment_type' => $d->assessment_type,
            'outcome' => $d->outcome,
            'assessor' => $d->assessor?->name,
            'assessment_date' => optional($d->assessment_date)->toDateString(),
            'personal_data_types' => $d->personal_data_types ?? [],
            'data_subjects' => $d->data_subjects ?? [],
            'processing_purpose' => $d->processing_purpose,
            'legal_basis' => $d->legal_basis,
            'identified_risks' => $d->identified_risks ?? [],
            'overall_risk_level' => $d->overall_risk_level,
            'mitigation_measures' => $d->mitigation_measures ?? [],
            'residual_risk_level' => $d->residual_risk_level,
            'review_notes' => $d->review_notes,
            'review_date' => optional($d->review_date)->toDateString(),
            'approved_by' => $d->approvedBy?->name,
            'attachments' => $this->serializeAttachments($d, $request->user()?->canDo('privacy.conductDPIA') ?? false),
            'timeline' => $this->formatTimeline($timeline),
        ];
    }

    /* ---------------------------------------------------------------- */
    /*  Helpers */
    /* ---------------------------------------------------------------- */

    private function periodStart(string $period): ?Carbon
    {
        return match ($period) {
            'quarter' => now()->startOfQuarter(),
            'year' => now()->startOfYear(),
            'all' => null,
            default => now()->startOfMonth(),
        };
    }

    private function clientName(Client $c): string
    {
        return trim(($c->first_name ?? '').' '.($c->last_name ?? '')) ?: 'Client #'.$c->id;
    }

    /**
     * Serialize polymorphic attachments to the FE shape. Sensitive files are
     * need-to-know: without the owning domain's write permission they return a
     * locked shell (no name/path).
     *
     * @return array<int, array<string, mixed>>
     */
    private function serializeAttachments(object $model, bool $canViewSensitive): array
    {
        return $model->attachments->map(function ($a) use ($canViewSensitive) {
            if ($a->is_sensitive && ! $canViewSensitive) {
                return ['id' => $a->id, 'locked' => true, 'is_sensitive' => true];
            }

            return [
                'id' => $a->id,
                'locked' => false,
                'name' => $a->original_name,
                'mime' => $a->mime,
                'is_image' => $a->isImage(),
                'size' => $a->size,
                'notes' => $a->notes,
                'is_sensitive' => (bool) $a->is_sensitive,
                'uploaded_by' => $a->uploader?->name,
                'created_at' => optional($a->created_at)->toIso8601String(),
                'download_url' => "/privacy/attachments/{$a->id}/download",
            ];
        })->all();
    }

    /**
     * Drop empty events, sort ascending, ISO-format.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function formatTimeline(array $events): array
    {
        return collect($events)
            ->filter(fn ($e) => ! empty($e['at']))
            ->sortBy(fn ($e) => $e['at'])
            ->map(fn ($e) => [
                'at' => Carbon::parse($e['at'])->toIso8601String(),
                'label' => $e['label'],
                'tone' => $e['tone'],
            ])
            ->values()->all();
    }

    /** @return array<string, bool> */
    private function canMap(Request $request): array
    {
        $user = $request->user();

        return [
            'viewRequests' => (bool) $user?->canDo('privacy.viewRequests'),
            'processRequests' => (bool) $user?->canDo('privacy.processRequests'),
            'reportBreaches' => (bool) $user?->canDo('privacy.reportBreaches'),
            'manageRetention' => (bool) $user?->canDo('privacy.manageRetention'),
            'manageLegalHolds' => (bool) $user?->canDo('privacy.manageLegalHolds'),
            'conductDPIA' => (bool) $user?->canDo('privacy.conductDPIA'),
            // Generic "can act" used to gate the hero/right-click create menu.
            'manage' => (bool) $user?->canDo('privacy.processRequests'),
        ];
    }
}
