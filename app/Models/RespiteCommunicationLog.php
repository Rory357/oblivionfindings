<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteCommunicationLog extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'stay_id',
        'channel',
        'participants',
        'summary',
        'occurred_at',
        'evidence',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'participants' => 'array',
        'occurred_at' => 'datetime',
        'evidence' => 'array',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }
}
