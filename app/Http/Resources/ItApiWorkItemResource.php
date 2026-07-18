<?php

namespace App\Http\Resources;

use App\Domain\It\Services\ItApiFieldPolicy;
use App\Models\ItServiceIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItApiWorkItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ItServiceIdentity $identity */
        $identity = $request->attributes->get('it_service_identity');
        $readable = app(ItApiFieldPolicy::class)->readableFields($identity);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'work_type' => $this->work_type,
            'status' => $this->status,
            'workflow_state' => $this->workflow_state,
            'priority' => $this->priority,
            'site_id' => $this->site_id,
            'it_service_id' => $this->it_service_id,
            'context' => [
                'site' => $this->site ? ['id' => $this->site->id, 'name' => $this->site->name] : null,
                'service' => $this->service ? ['id' => $this->service->id, 'name' => $this->service->name] : null,
                'asset' => $this->asset ? [
                    'id' => $this->asset->id,
                    'name' => $this->asset->name,
                    'asset_tag' => $this->asset->asset_tag,
                ] : null,
            ],
            'description' => $this->when(in_array('description', $readable, true), $this->description),
            'category' => $this->when(in_array('category', $readable, true), $this->category),
            'subcategory' => $this->when(in_array('subcategory', $readable, true), $this->subcategory),
            'impact' => $this->when(in_array('impact', $readable, true), $this->impact),
            'urgency' => $this->when(in_array('urgency', $readable, true), $this->urgency),
            'queue' => $this->when(in_array('queue', $readable, true), fn () => $this->queue ? ['id' => $this->queue->id, 'name' => $this->queue->name] : null),
            'team' => $this->when(in_array('team', $readable, true), fn () => $this->team ? ['id' => $this->team->id, 'name' => $this->team->name] : null),
            'owner' => $this->when(in_array('owner', $readable, true), fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null),
            'assignee' => $this->when(in_array('assignee', $readable, true), fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'sla' => $this->when(in_array('sla', $readable, true), fn () => [
                'state' => $this->sla_state,
                'first_response_due_at' => $this->first_response_due_at?->toIso8601String(),
                'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
            ]),
            'resolution' => $this->when(in_array('resolution', $readable, true), fn () => [
                'code' => $this->resolution_code,
                'summary' => $this->resolution_summary,
                'resolved_at' => $this->resolved_at?->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
