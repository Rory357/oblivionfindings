<?php

namespace App\Domain\CompanyHub\Models;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class HubEventDraft extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'version' => 'integer', 'base_edit_version' => 'integer',
        'submitted_at' => 'datetime', 'published_at' => 'datetime', 'archived_at' => 'datetime',
        'publish_at' => 'datetime', 'scheduled_at' => 'datetime', 'schedule_blocked_at' => 'datetime', 'scheduled_files' => 'array'];

    public function event()
    {
        return $this->belongsTo(HrCalendarEvent::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function files()
    {
        return $this->hasMany(HubEventDraftFile::class, 'draft_id');
    }
}
