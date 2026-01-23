<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;

class ClientSupportPlan extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'updated_by_user_id',
        'goals',
        'routines',
        'preferences',
        'communication_needs',
        'cultural_needs',
        'risk_notes',
        'reviewed_at',
        'next_review_at',
    ];

    protected $casts = [
        'reviewed_at' => 'date',
        'next_review_at' => 'date',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
