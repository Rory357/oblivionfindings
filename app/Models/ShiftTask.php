<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Support\ShiftTaskSupport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftTask extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'shift_id',
        'label',
        'scheduled_time',
        'is_completed',
        'completed_at',
        'completed_by',
        'reminder_sent_at',
        'sort_order',
    ];

    protected $casts = [
        'scheduled_time' => 'string',
        'is_completed' => 'bool',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scheduledFor(): ?CarbonImmutable
    {
        $time = ShiftTaskSupport::normalizeTime($this->scheduled_time);
        if (! $time) {
            return null;
        }

        $shift = $this->relationLoaded('shift') ? $this->shift : $this->shift()->first();
        if (! $shift?->starts_at) {
            return null;
        }

        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $startsAt = CarbonImmutable::instance($shift->starts_at)->timezone($timezone);
        $scheduled = $startsAt->setTimeFromTimeString($time);

        return $scheduled->lt($startsAt) ? $scheduled->addDay() : $scheduled;
    }
}
