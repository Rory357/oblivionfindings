<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrICalToken extends Model
{
    // Eloquent would otherwise snake "HrICalToken" to `hr_i_cal_tokens`; the
    // migration creates `hr_ical_tokens`.
    protected $table = 'hr_ical_tokens';

    public $timestamps = false;

    protected $fillable = ['user_id', 'token'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
