<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file uploaded as proof that an action is done.
 *
 * `path` and `disk` are storage internals: they are hidden from every
 * serialisation and only used by the authorised download route.
 */
class ActionItemEvidence extends Model
{
    protected $table = 'governance_action_evidence';

    protected $fillable = [
        'action_item_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected $hidden = [
        'disk',
        'path',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function actionItem(): BelongsTo
    {
        return $this->belongsTo(ActionItem::class, 'action_item_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * What a page may show about the file: its name, type, size, who added it
     * and an authorised download link — never where it is stored.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => (int) $this->id,
            'original_name' => (string) $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'uploaded_by_name' => $this->uploadedBy?->name,
            'download_url' => "/governance/actions/{$this->action_item_id}/evidence/{$this->id}/download",
        ];
    }
}
