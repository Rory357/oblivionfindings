<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'employee_id',
        'job_title',
        'department',
        'hire_date',
        'termination_date',
        'work_phone',
        'mobile_phone',
        'status',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'notes',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'termination_date' => 'date',
    ];

    /**
     * The user account associated with this staff member
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Shifts assigned to this staff member
     */
    public function shifts()
    {
        return $this->hasMany(Shift::class, 'user_id', 'user_id');
    }

    /**
     * Timesheets for this staff member
     */
    public function timesheets()
    {
        return $this->hasMany(Timesheet::class, 'user_id', 'user_id');
    }

    /**
     * Clients assigned to this staff member
     */
    public function assignedClients()
    {
        return $this->belongsToMany(Client::class, 'client_user', 'user_id', 'client_id');
    }

    /**
     * Scope: Active staff only
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: By department
     */
    public function scopeByDepartment($query, string $department)
    {
        return $query->where('department', $department);
    }

    /**
     * Get full name from user
     */
    public function getNameAttribute(): ?string
    {
        return $this->user?->name;
    }

    /**
     * Get email from user
     */
    public function getEmailAttribute(): ?string
    {
        return $this->user?->email;
    }

    /**
     * Check if staff is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if staff can login (has user account)
     */
    public function canLogin(): bool
    {
        return $this->user !== null && $this->user->approved_at !== null;
    }
}
