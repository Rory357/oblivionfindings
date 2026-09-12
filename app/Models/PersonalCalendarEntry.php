<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Private planning entries. Ownership is never inferred from a role or site. */
class PersonalCalendarEntry extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'start_at' => 'datetime', 'end_at' => 'datetime', 'all_day' => 'boolean', 'version' => 'integer',
    ];

    public static function canUse(User $user): bool
    {
        return User::query()->staff()->whereKey($user->id)->whereNotNull('approved_at')->exists();
    }

    public function payload(): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'title' => $this->title,
            'description' => $this->description, 'location' => $this->location,
            'start_at' => $this->start_at->copy()->utc()->toIso8601String(),
            'end_at' => $this->end_at?->copy()->utc()->toIso8601String(),
            'all_day' => $this->all_day, 'status' => $this->status, 'version' => $this->version,
        ];
    }

    public function calendarEvent(): array
    {
        return [
            'id' => 'personal-'.$this->id, 'title' => $this->title,
            'start' => $this->payload()['start_at'], 'end' => $this->payload()['end_at'],
            'allDay' => $this->all_day,
            'extendedProps' => [
                'type' => 'personal_'.$this->kind, 'status' => $this->status,
                'location' => $this->location, 'description' => $this->description,
                'personal_entry' => $this->payload(),
                'link' => '/my-calendar?entry='.$this->id,
            ],
        ];
    }
}
