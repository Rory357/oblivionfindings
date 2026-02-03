<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteLinkedRef extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'ref_type',
        'ref_id',
        'relation',
        'meta',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
