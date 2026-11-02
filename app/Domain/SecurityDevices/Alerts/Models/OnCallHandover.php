<?php

namespace App\Domain\SecurityDevices\Alerts\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A handover note between the people on IT on call (S&D drawing pg-sdr-13d). */
final class OnCallHandover extends Model
{
    protected $table = 'security_device_on_call_handovers';

    protected $fillable = ['from_user_id', 'to_user_id', 'still_open', 'coming_up', 'good_to_know'];

    /** @return BelongsTo<User, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
