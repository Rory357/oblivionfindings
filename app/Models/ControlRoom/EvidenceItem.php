<?php

namespace App\Models\ControlRoom;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceItem extends Model
{
    protected $table = 'control_room_evidence_items';

    protected $fillable = [
        'evidence_pack_id',
        'type',
        'title',
        'description',
        'storage_path',
        'mime_type',
        'file_size',
        'external_system',
        'external_ref',
        'metadata',
        'captured_at',
        'captured_by_user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'captured_at' => 'datetime',
    ];

    public function evidencePack(): BelongsTo
    {
        return $this->belongsTo(EvidencePack::class, 'evidence_pack_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
