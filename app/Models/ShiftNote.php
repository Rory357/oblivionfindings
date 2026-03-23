<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'shift_id',
        'author_id',
        'note_type',
        'content',
        'is_private',
        'attachments',
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'attachments' => 'array',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
