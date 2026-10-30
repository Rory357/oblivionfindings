<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffAvailability extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'ends_next_day',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'ends_next_day' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
