<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FamilyNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'created_by',
        'title',
        'description',
        'note_type',
        'priority',
        'status',
        'due_date',
        'due_time',
        'completed_at',
        'completed_by',
        'assigned_to_shift_id',
        'staff_response',
        'staff_responded_by',
        'staff_responded_at',
        'visibility',
        'is_recurring',
        'recurrence_rule',
        'meta',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'staff_responded_at' => 'datetime',
        'is_recurring' => 'boolean',
        'meta' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'assigned_to_shift_id');
    }

    public function staffResponder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_responded_by');
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeWithDueDate($query)
    {
        return $query->whereNotNull('due_date');
    }

    public function scopeTodos($query)
    {
        return $query->where('note_type', 'todo');
    }

    public function scopeNotes($query)
    {
        return $query->where('note_type', 'note');
    }
}
