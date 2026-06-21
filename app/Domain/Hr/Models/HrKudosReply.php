<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a kudos reply thread. Posted by either party (giver or
 * receiver), so the conversation closes the loop both ways.
 */
class HrKudosReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'kudos_id',
        'user_id',
        'body',
    ];

    public function kudos(): BelongsTo
    {
        return $this->belongsTo(HrKudos::class, 'kudos_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
