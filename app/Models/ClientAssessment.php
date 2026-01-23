<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;

class ClientAssessment extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'created_by_user_id',
        'type',
        'score',
        'notes',
        'assessed_at',
        'next_review_at',
    ];

    protected $casts = [
        'assessed_at' => 'date',
        'next_review_at' => 'date',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
