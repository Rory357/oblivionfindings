<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinRecurringJournal extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_recurring_journals';

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'frequency',
        'next_run_date',
        'last_run_date',
        'template_lines',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'next_run_date' => 'date',
        'last_run_date' => 'date',
        'template_lines' => 'array',
        'is_active' => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDue($query)
    {
        return $query->where('is_active', true)
            ->where('next_run_date', '<=', now()->toDateString());
    }
}
