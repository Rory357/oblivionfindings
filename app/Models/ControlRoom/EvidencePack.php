<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidencePack extends Model
{
    protected $table = 'control_room_evidence_packs';

    protected $fillable = [
        'alert_id',
        'playbook_run_id',
        'title',
        'status',
        'items',
        'item_count',
        'export_path',
        'exported_at',
        'exported_by_user_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'items' => 'array',
        'exported_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function playbookRun(): BelongsTo
    {
        return $this->belongsTo(PlaybookRun::class, 'playbook_run_id');
    }

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function evidenceItems(): HasMany
    {
        return $this->hasMany(EvidenceItem::class, 'evidence_pack_id');
    }
}
