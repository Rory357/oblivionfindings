<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientRespiteAllocation extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'organization_id',
        'period_start',
        'period_end',
        'nights_allocated',
        'funding_source',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'nights_allocated' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
