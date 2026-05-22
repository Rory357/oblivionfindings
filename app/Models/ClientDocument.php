<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientDocument extends Model implements EmitsToTimeline
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'uploaded_by_user_id',
        'title',
        'category',
        'folder',
        'version',
        'effective_date',
        'expiry_date',
        'portal_visible',
        'notes',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'openai_file_id',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'portal_visible' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toTimelineEvent(): ?array
    {
        if (! $this->expiry_date) {
            return null;
        }

        $this->loadMissing('client');

        return [
            'type' => 'document_expiring',
            'occurred_at' => $this->expiry_date,
            'actor_user_id' => $this->uploaded_by_user_id,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Document expiry: '.$this->title,
            'body' => $this->notes,
            'meta' => array_filter([
                'category' => $this->category,
                'folder' => $this->folder,
                'expiry_date' => $this->expiry_date?->toDateString(),
                'portal_visible' => $this->portal_visible,
                'original_name' => $this->original_name,
            ], fn ($value) => $value !== null && $value !== ''),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $this->uploaded_by_user_id,
        ];
    }
}
