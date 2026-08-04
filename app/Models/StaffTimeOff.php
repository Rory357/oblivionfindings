<?php

namespace App\Models;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffTimeOff extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'hr_leave_request_id',
        'user_id',
        'starts_at',
        'ends_at',
        'type',
        'period',
        'label',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected $attributes = [
        'period' => 'full_day',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The HR leave request this projection was created from (when type === 'leave').
     */
    public function leaveRequest()
    {
        return $this->belongsTo(HrLeaveRequest::class, 'hr_leave_request_id');
    }
}
