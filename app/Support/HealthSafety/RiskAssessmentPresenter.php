<?php

namespace App\Support\HealthSafety;

use App\Models\Client;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for serialising a risk assessment to the React layer —
 * reused by the standalone register (HsRiskAssessmentController), the Client profile
 * (ClientController) and the Site profile (SiteController) so the row + detail shapes
 * never diverge. Tone mapping lives in the row-kit on the client; the presenter only
 * ships raw values + a computed review_state.
 */
class RiskAssessmentPresenter
{
    /** Compact row for register tables. Expects assessable/hsEvent eager-loaded + attachments_count. */
    public static function row(HsRiskAssessment $ra): array
    {
        return [
            'id' => $ra->id,
            'reference_number' => $ra->reference_number,
            'title' => $ra->title,
            'risk_description' => $ra->risk_description,
            'status' => $ra->status,
            'attached_to' => self::attachedTo($ra),
            'likelihood' => $ra->likelihood,
            'consequence' => $ra->consequence,
            'risk_score' => $ra->risk_score,
            'risk_level' => $ra->risk_level,
            'residual_likelihood' => $ra->residual_likelihood,
            'residual_consequence' => $ra->residual_consequence,
            'residual_risk_score' => $ra->residual_risk_score,
            'residual_risk_level' => $ra->residual_risk_level,
            'risk_acceptable' => $ra->risk_acceptable,
            'assessed_by_name' => $ra->assessedBy?->name,
            'review_due_at' => optional($ra->review_due_at)->toDateString(),
            'review_state' => self::reviewState($ra),
            'is_due_for_review' => $ra->isDueForReview(),
            'attachments_count' => $ra->attachments_count ?? 0,
            'superseded_by_id' => $ra->superseded_by_id,
        ];
    }

    /** Full detail for the detail-as-modal. Lazily eager-loads what it needs. */
    public static function detail(HsRiskAssessment $ra, bool $canManage = false): array
    {
        $ra->loadMissing([
            'assessable', 'hsEvent', 'assessedBy', 'approvedBy', 'supersededBy', 'creator', 'attachments.uploader',
        ]);

        return array_merge(self::row($ra), [
            'existing_controls' => $ra->existing_controls,
            'additional_controls' => $ra->additional_controls,
            'review_frequency_days' => $ra->review_frequency_days,
            'approval_note' => $ra->approval_note,
            'last_review_note' => $ra->last_review_note,
            'assessed_at' => optional($ra->assessed_at)->toIso8601String(),
            'approved_by_name' => $ra->approvedBy?->name,
            'approved_at' => optional($ra->approved_at)->toIso8601String(),
            'created_by_name' => $ra->creator?->name,
            'created_at' => optional($ra->created_at)->toIso8601String(),
            'updated_at' => optional($ra->updated_at)->toIso8601String(),
            'superseded_by' => $ra->supersededBy ? [
                'id' => $ra->supersededBy->id,
                'reference_number' => $ra->supersededBy->reference_number,
                'status' => $ra->supersededBy->status,
            ] : null,
            'hs_event' => $ra->hsEvent ? [
                'id' => $ra->hsEvent->id,
                'reference_number' => $ra->hsEvent->reference_number,
            ] : null,
            'attachments' => $ra->attachments->map(fn (HsRiskAssessmentAttachment $a) => self::attachment($ra, $a))->values(),
            'attachments_count' => $ra->attachments->count(),
            'can' => ['manage' => $canManage],
            'form' => self::formPrefill($ra),
        ]);
    }

    public static function attachment(HsRiskAssessment $ra, HsRiskAssessmentAttachment $a): array
    {
        return [
            'id' => $a->id,
            'original_name' => $a->original_name,
            'mime' => $a->mime,
            'size' => $a->size,
            'kind' => $a->kind,
            'notes' => $a->notes,
            'is_image' => $a->isImage(),
            'uploaded_by_name' => $a->uploader?->name,
            'created_at' => optional($a->created_at)->toIso8601String(),
            'download_url' => "/health-safety/risk-assessments/{$ra->id}/attachments/{$a->id}/download",
        ];
    }

    /**
     * Lightweight picker datasets for the create / supersede wizards and the
     * site/client filters. Shared by the register, Client profile and Site profile.
     *
     * @return array{sites:Collection,clients:Collection,events:Collection}
     */
    public static function pickers(?int $organizationId = null): array
    {
        return [
            'sites' => Site::query()
                ->when($organizationId !== null, fn ($query) => $query->where('tenant_id', $organizationId))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (Site $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'clients' => Client::query()
                ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
                ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)])->values(),
            'events' => HsEvent::query()
                ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
                ->latest()->limit(50)->get(['id', 'reference_number'])
                ->map(fn (HsEvent $e) => ['id' => $e->id, 'name' => $e->reference_number])->values(),
        ];
    }

    /** Prefill bag for the Edit / Supersede wizards. */
    public static function formPrefill(HsRiskAssessment $ra): array
    {
        $attached = self::attachedTo($ra);

        return [
            'title' => $ra->title,
            'risk_description' => $ra->risk_description,
            'attach_type' => $attached['type'],
            'attach_id' => $attached['id'],
            'likelihood' => $ra->likelihood,
            'consequence' => $ra->consequence,
            'existing_controls' => $ra->existing_controls,
            'additional_controls' => $ra->additional_controls,
            'residual_likelihood' => $ra->residual_likelihood ?? 2,
            'residual_consequence' => $ra->residual_consequence ?? 2,
            'risk_acceptable' => $ra->risk_acceptable ?? true,
            'review_frequency_days' => $ra->review_frequency_days ?? 90,
            'review_due_at' => optional($ra->review_due_at)->toDateString(),
        ];
    }

    /** @return array{type:string,id:int|null,name:string} */
    protected static function attachedTo(HsRiskAssessment $ra): array
    {
        if ($ra->assessable_type && $ra->assessable_id) {
            $base = class_basename($ra->assessable_type);
            $entity = $ra->relationLoaded('assessable') ? $ra->assessable : null;
            // Client has no `name` column — only a `full_name` accessor; Site has `name`.
            $name = $entity
                ? ($base === 'Client'
                    ? trim((string) ($entity->full_name ?? ($entity->first_name.' '.$entity->last_name)))
                    : ($entity->name ?? null))
                : null;

            return [
                'type' => strtolower($base),
                'id' => $ra->assessable_id,
                'name' => $name ?: ($base.' #'.$ra->assessable_id),
            ];
        }

        if ($ra->hs_event_id) {
            return [
                'type' => 'event',
                'id' => $ra->hs_event_id,
                'name' => ($ra->relationLoaded('hsEvent') && $ra->hsEvent)
                    ? $ra->hsEvent->reference_number
                    : 'H&S event',
            ];
        }

        return ['type' => 'standalone', 'id' => null, 'name' => 'Standalone'];
    }

    /** @return array{kind:string,days:int|null} overdue / soon (≤30d) / ok / none. */
    protected static function reviewState(HsRiskAssessment $ra): array
    {
        $terminal = in_array($ra->status, [
            HsRiskAssessment::STATUS_DRAFT,
            HsRiskAssessment::STATUS_SUPERSEDED,
            HsRiskAssessment::STATUS_ARCHIVED,
        ], true);

        if (! $ra->review_due_at || $terminal) {
            return ['kind' => 'none', 'days' => null];
        }

        $days = (int) Carbon::now()->startOfDay()->diffInDays($ra->review_due_at->copy()->startOfDay(), false);

        return match (true) {
            $days < 0 => ['kind' => 'overdue', 'days' => $days],
            $days <= 30 => ['kind' => 'soon', 'days' => $days],
            default => ['kind' => 'ok', 'days' => $days],
        };
    }
}
