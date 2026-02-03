<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteEvidencePack extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'stay_id',
        'status',
        'summary',
        'items',
        'sealed_at',
        'sealed_by_user_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'items' => 'array',
        'sealed_at' => 'datetime',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function sealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sealed_by_user_id');
    }
}
