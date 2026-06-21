<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RosterTemplateShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'roster_template_id',
        'client_id',
        'user_id',
        'service_context_id',
        'day_of_week',
        'start_time',
        'end_time',
        'shift_type',
        'is_sleepover',
        'is_on_call',
        'is_lone_worker',
        'expected_break_minutes',
        'required_skills',
        'location',
        'notes',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_sleepover' => 'boolean',
        'is_on_call' => 'boolean',
        'is_lone_worker' => 'boolean',
        'expected_break_minutes' => 'integer',
        'required_skills' => 'array',
    ];

    public function template()
    {
        return $this->belongsTo(RosterTemplate::class, 'roster_template_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }
}
