<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientSleepEntry extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyOrganizationStorageContext;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'slept_at',
        'hours_slept',
        'quality',
        'interruptions',
        'settled_by',
        'woke_at',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'slept_at' => 'date',
        'hours_slept' => 'decimal:1',
        'interruptions' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
