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
        'day_of_week',
        'start_time',
        'end_time',
        'required_skills',
        'location',
        'service_context_id',
    ];

    protected $casts = [
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
}
