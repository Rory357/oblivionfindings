<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinRecurringJournal extends Model
{
    use AuditableChanges, HasFactory;

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

    public function occurrences(): HasMany
    {
        return $this->hasMany(FinRecurringJournalOccurrence::class, 'recurring_journal_id');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
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
