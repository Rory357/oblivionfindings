<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PPE redesign — evidence attached to a PPE inventory item (certificates,
 * declarations of conformity, purchase invoices, disposal evidence).
 */
class PpeAttachment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'ppe_inventory_id',
        'uploaded_by',
        'disk',
        'original_name',
        'path',
        'mime',
        'size',
        'kind',
        'notes',
        'alt_text',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(PpeInventory::class, 'ppe_inventory_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
