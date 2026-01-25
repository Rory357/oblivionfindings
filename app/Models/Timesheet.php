<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Timesheet extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'user_id',
        'client_id',
        'shift_id',
        'work_date',
        'starts_at',
        'ends_at',
        'break_minutes',
        'notes',
        'status',
        'submitted_at',
        'submitted_by',
        'created_by',
        'approved_by',
        'approved_at',
        'decision_notes',
        'returned_at',
        'returned_by',
        'returned_notes',
    ];

    protected $casts = [
        'work_date' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function returner()
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
