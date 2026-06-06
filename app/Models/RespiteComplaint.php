<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteComplaint extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'stay_id',
        'client_id',
        'source',
        'received_at',
        'nature',
        'details',
        'acknowledged_at',
        'resolution',
        'escalated_to_hdc',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
