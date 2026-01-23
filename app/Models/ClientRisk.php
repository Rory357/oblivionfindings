<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientRisk extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'label',
        'severity',
        'controls',
        'review_date',
        'active',
    ];

    protected $casts = [
        'review_date' => 'date',
        'active' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
