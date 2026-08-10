<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItKbInteraction extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'it_kb_article_id', 'user_id', 'it_ticket_id',
        'event_type', 'source', 'context', 'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(ItKbArticle::class, 'it_kb_article_id');
    }
}
