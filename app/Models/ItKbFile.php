<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An immutable private file version belonging to one canonical document. */
class ItKbFile extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['path', 'sha256'];

    protected $casts = ['site_scope' => 'array', 'created_at' => 'datetime', 'version' => 'integer', 'size' => 'integer', 'expected_version' => 'integer', 'replaces_file_id' => 'integer'];
}
