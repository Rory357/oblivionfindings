<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientFinancialDiscrepancy extends Model
{
    use HasFactory, SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $table = 'client_financial_discrepancies';

    protected $fillable = [
        'client_id',
        'description',
        'amount',
        'status',
        'raised_at',
        'raised_by',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raised_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
