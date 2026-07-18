<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItKbInteraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'it_kb_article_id', 'user_id', 'it_ticket_id',
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
