<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffAvailability extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'user_id',
        'day_of_week',
        'starts_at',
        'ends_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
