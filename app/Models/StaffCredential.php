<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffCredential extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'user_id',
        'type',
        'issuer',
        'issued_at',
        'expires_at',
        'reference',
        'notes',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'expires_at' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
