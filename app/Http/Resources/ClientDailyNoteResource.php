<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ClientDailyNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gate = $request->user() ? Gate::forUser($request->user()) : null;

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'shift_id' => $this->shift_id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'category' => $this->category,
            'subject' => $this->subject,
            'goal' => $this->goal,
            'body' => $this->body,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'visibility' => $this->visibility,
            'is_pinned' => (bool) $this->is_pinned,
            'is_flagged' => (bool) $this->is_flagged,
            'flagged_reason' => $this->flagged_reason,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'reviewed_by' => $this->reviewed_by,
            'is_private' => (bool) $this->is_private,
            'attachments' => $this->attachments ?? [],
            'mood_rating' => $this->mood_rating,
            'behaviour_tags' => $this->behaviour_tags ?? [],
            'concerns_flags' => $this->concerns_flags ?? [],
            'follow_up_action' => $this->follow_up_action,
            'follow_up_due_at' => $this->follow_up_due_at?->toISOString(),
            'follow_up_completed_at' => $this->follow_up_completed_at?->toISOString(),
            'appears_on_timeline' => (bool) $this->appears_on_timeline,
            'is_draft' => (bool) $this->is_draft,
            'contact_person' => $this->contact_person,
            'contact_relationship' => $this->contact_relationship,
            'contact_method' => $this->contact_method,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'can' => [
                'update' => $gate?->allows('update', $this->resource) ?? false,
                'delete' => $gate?->allows('delete', $this->resource) ?? false,
                'flag' => $gate?->allows('flag', $this->resource) ?? false,
                'review' => $gate?->allows('review', $this->resource) ?? false,
            ],
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author?->id,
                'name' => $this->author?->name,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'shift' => $this->whenLoaded('shift', fn () => $this->shift ? [
                'id' => $this->shift->id,
                'starts_at' => $this->shift->starts_at?->toISOString(),
                'ends_at' => $this->shift->ends_at?->toISOString(),
            ] : null),
        ];
    }
}
