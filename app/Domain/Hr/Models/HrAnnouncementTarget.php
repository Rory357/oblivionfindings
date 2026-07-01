<?php

namespace App\Domain\Hr\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAnnouncementTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'announcement_id',
        'type',   // all|site|department|role|user
        'value',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(HrAnnouncement::class, 'announcement_id');
    }
}
