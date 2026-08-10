<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItProblem extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'ticket_id',
        'impact_summary',
        'root_cause',
        'workaround',
        'corrective_action',
        'known_error_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'known_error_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
