<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftSeries extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $table = 'shift_series';

    protected $fillable = [
        'client_id',
        'user_id',
        'start_date',
        'end_date',
        'timezone',
        'by_weekday',
        'starts_time',
        'ends_time',
        'location',
        'notes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'by_weekday' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'shift_series_id');
    }
}
