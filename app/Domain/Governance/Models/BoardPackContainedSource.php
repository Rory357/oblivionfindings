<?php

declare(strict_types=1);

namespace App\Domain\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One typed record embedded in a board pack. Rows are derived from the pack
 * manifest by BoardPackContainedSources and are never edited directly.
 */
class BoardPackContainedSource extends Model
{
    public $timestamps = false;

    protected $table = 'board_pack_contained_sources';

    protected $fillable = [
        'board_pack_id',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'board_pack_id' => 'integer',
        'source_id' => 'integer',
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(BoardPack::class, 'board_pack_id');
    }
}
