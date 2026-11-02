<?php

namespace App\Domain\SecurityDevices\Terminal\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line typed into a Terminal session ("in", kept as its history) or one piece of what the
 * device sent back ("out", kept only while the session is open and an hour after).
 *
 * @property int $session_id
 * @property string $direction in | out
 * @property int $seq
 * @property string $text
 */
final class TerminalLine extends Model
{
    public $timestamps = false;

    protected $table = 'security_device_terminal_lines';

    protected $fillable = ['session_id', 'direction', 'seq', 'text', 'at'];

    protected $casts = [
        'seq' => 'integer',
        'at' => 'immutable_datetime',
    ];
}
