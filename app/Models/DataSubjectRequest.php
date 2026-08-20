<?php

namespace App\Models;

use App\Domain\Privacy\Services\StatutoryDueDate;
use App\Models\Concerns\AuditableChanges;
use App\Services\References\ReferenceNumberGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DataSubjectRequest extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'reference_number',
        'request_type',
        'client_id',
        'user_id',
        'subject_name',
        'subject_email',
        'request_details',
        'specific_data_requested',
        'identity_verified',
        'identity_verified_at',
        'verified_by_user_id',
        'verification_method',
        'status',
        'received_at',
        'due_date',
        'extension_requested',
        'extended_due_date',
        'extension_reason',
        'assigned_to_user_id',
        'assigned_at',
        'completed_at',
        'completed_by_user_id',
        'completion_notes',
        'refused_at',
        'refused_by_user_id',
        'export_path',
        'export_generated_at',
        'export_accessed_at',
        'erasure_confirmed',
        'data_erased',
        'data_retained',
        'rejection_reason',
        'rejection_legal_basis',
        'communications',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'specific_data_requested' => 'array',
        'identity_verified_at' => 'datetime',
        'received_at' => 'datetime',
        'due_date' => 'date',
        'extended_due_date' => 'date',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'refused_at' => 'datetime',
        'export_generated_at' => 'datetime',
        'export_accessed_at' => 'datetime',
        'extension_requested' => 'boolean',
        'erasure_confirmed' => 'boolean',
        'data_erased' => 'array',
        'data_retained' => 'array',
        'communications' => 'array',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($request) {
            if (empty($request->reference_number)) {
                $request->reference_number = static::generateReferenceNumber();
            }
            if (empty($request->received_at)) {
                $request->received_at = now();
            }
            if (empty($request->due_date)) {
                // Privacy Act 2020 IPP 6 — respond within 20 working days
                // (skipping weekends + NZ public holidays).
                $request->due_date = app(StatutoryDueDate::class)->dueFrom($request->received_at);
            }
        });
    }

    /**
     * Generate a unique reference number.
     */
    public static function generateReferenceNumber(): string
    {
        return app(ReferenceNumberGenerator::class)->next('DSR');
    }

    /**
     * Client (if applicable).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * User (if applicable).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * User who verified identity.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * User assigned to process the request.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * User who completed the request.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * User who refused the request.
     */
    public function refusedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refused_by_user_id');
    }

    /**
     * Data exports.
     */
    public function dataExports(): HasMany
    {
        return $this->hasMany(DataExport::class);
    }

    /**
     * Evidence / response-pack documents (identity verification, disclosed records).
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(PrivacyAttachment::class, 'attachable')->latest();
    }

    /**
     * Scope: Open requests.
     */
    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', ['completed', 'rejected', 'withdrawn']);
    }

    /**
     * Scope: Overdue requests.
     */
    public function scopeOverdue($query)
    {
        return $query->whereNotIn('status', ['completed', 'rejected', 'withdrawn'])
            ->where(function ($q) {
                $q->where(function ($subQ) {
                    $subQ->whereNull('extended_due_date')
                        ->where('due_date', '<', now());
                })
                    ->orWhere(function ($subQ) {
                        $subQ->whereNotNull('extended_due_date')
                            ->where('extended_due_date', '<', now());
                    });
            });
    }

    /**
     * Check if request is overdue.
     */
    public function isOverdue(): bool
    {
        if (in_array($this->status, ['completed', 'rejected', 'withdrawn'])) {
            return false;
        }

        $deadline = $this->extended_due_date ?: $this->due_date;

        return $deadline && $deadline->isPast();
    }

    /**
     * Get days remaining.
     */
    public function daysRemaining(): int
    {
        $deadline = $this->extended_due_date ?: $this->due_date;

        if (! $deadline) {
            return 0;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false));
    }
}
