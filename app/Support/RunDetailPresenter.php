<?php

namespace App\Support;

use App\Models\SiteChecklistRun;

class RunDetailPresenter
{
    public static function for(?int $runId, ?int $siteId = null): ?array
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
            ])
            ->find($runId);

        if (! $run || ! $run->site || ! $run->template) {
            return null;
        }

        $items = $run->template->items->sortBy('sort_order')->values();
        $settings = $run->template->settings ?? [];
        $flags = [
            'hazard' => $items->contains(fn ($item) => (bool) $item->failure_creates_hazard),
            'photo' => $items->contains(fn ($item) => $item->response_type === 'photo')
                || ! empty($settings['requires_photo']),
            'sign' => ! empty($settings['requires_signature']),
        ];

        return [
            'id' => $run->id,
            'status' => $run->status,
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
