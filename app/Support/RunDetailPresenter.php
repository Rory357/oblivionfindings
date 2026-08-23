<?php

namespace App\Support;

use App\Models\SiteChecklistRun;
use App\Models\User;
use App\Services\Sites\SiteChecklistFailureRiskMapper;
use Illuminate\Support\Facades\Gate;

class RunDetailPresenter
{
    public static function for(?int $runId, ?int $siteId = null, ?User $user = null): ?array
    {
        if (! $runId || $runId <= 0) {
            return null;
        }

        $run = SiteChecklistRun::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->with([
                'site:id,name,type',
                'template:id,name,frequency,category,settings',
                'template.items',
                'responses',
                'assignment:id,site_id,template_id,assigned_to_user_id',
            ])
            ->find($runId);

        if (! $run
            || ! $run->site
            || ! $run->template
            || ! $run->hasCanonicalExecutionProvenance()
            || ($user && Gate::forUser($user)->denies('view', $run))) {
            return null;
        }

        $items = $run->template->items->sortBy('sort_order')->values();
        $itemIds = $items->pluck('id')->map(fn ($id): int => (int) $id);
        if ($run->responses->contains(
            fn ($response): bool => ! $itemIds->containsStrict((int) $response->template_item_id),
        )) {
            return null;
        }

        $settings = $run->template->settings ?? [];
        $flags = [
            'hazard' => $items->contains(fn ($item) => (bool) $item->failure_creates_hazard
                || $item->failure_risk_level === SiteChecklistFailureRiskMapper::CRITICAL),
            'photo' => $items->contains(fn ($item) => $item->response_type === 'photo')
                || ! empty($settings['requires_photo']),
            'sign' => ! empty($settings['requires_signature']),
        ];

        return [
            'id' => $run->id,
            'status' => $run->status,
            'can_run' => $user !== null
                && in_array($run->status, ['scheduled', 'in_progress', 'overdue'], true)
                && Gate::forUser($user)->allows('execute', $run),
            'scheduled_date' => $run->scheduled_date?->toDateString(),
            'completion_percentage' => (float) $run->completion_percentage,
            'overall_notes' => $run->overall_notes,
            'site' => ['id' => $run->site->id, 'name' => $run->site->name, 'type' => $run->site->type],
            'template' => [
                'id' => $run->template->id,
                'name' => $run->template->name,
                'frequency' => $run->template->frequency,
                'category' => $run->template->category,
                'flags' => $flags,
            ],
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'question' => $item->question,
                'response_type' => $item->response_type,
                'response_config' => $item->response_config,
                'is_required' => (bool) $item->is_required,
                'guidance' => $item->guidance,
                'failure_creates_hazard' => (bool) $item->failure_creates_hazard,
                'failure_creates_damage' => (bool) $item->failure_creates_damage,
                'failure_risk_level' => $item->failure_risk_level,
            ])->all(),
            'responses' => $run->responses->map(fn ($response) => [
                'template_item_id' => $response->template_item_id,
                'response_value' => $response->response_value,
                'notes' => $response->notes,
                'photo_path' => $response->photo_path,
                'is_failed' => (bool) $response->is_failed,
            ])->all(),
        ];
    }
}
