<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftTask extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'shift_id',
        'label',
        'is_completed',
        'completed_at',
        'completed_by',
        'sort_order',
    ];

    protected $casts = [
        'is_completed' => 'bool',
        'completed_at' => 'datetime',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
