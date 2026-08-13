<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationCompetencyExemption extends Model
{
    use AuditableChanges, HasFactory;

    public const SCOPE_ADMINISTRATION = 'medication_administration';

    protected $fillable = [
        'user_id',
        'site_id',
        'scope',
        'reason',
        'approved_by',
        'approved_at',
        'starts_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
