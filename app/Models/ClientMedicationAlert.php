<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientMedicationAlert extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'type',
        'title',
        'detail',
        'prompt_on_open',
        'enabled',
        'created_by',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'prompt_on_open' => 'boolean',
        'enabled' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function resolve(int $userId): void
    {
        $this->forceFill([
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'enabled' => false,
        ])->save();
    }
}
