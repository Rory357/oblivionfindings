<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A private, encrypted unfinished form, never a task or a care record. */
class ShiftTaskDraft extends Model
{
    protected $fillable = ['shift_id', 'user_id', 'content', 'version'];

    protected $casts = ['content' => 'encrypted:array', 'version' => 'integer'];
}
