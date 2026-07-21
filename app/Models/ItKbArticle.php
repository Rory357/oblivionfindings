<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A knowledge-base article (§P.7). Agents author and publish; requesters read
 * the published ones and vote "was this helpful?". Categories reuse the ticket
 * taxonomy so deflection can match a ticket's category.
 */
class ItKbArticle extends Model
{
    use HasFactory;

    /** Reuses the ticket categories (§P.7). */
    public const CATEGORIES = ItTicket::CATEGORIES;

    public const STATUSES = ['draft', 'in_review', 'published', 'retired'];

    public const AUDIENCES = ['all_staff', 'specific_sites', 'it_agents'];

    protected $fillable = [
        'tenant_id',
        'title',
        'slug',
        'category',
        'body',
        'status',
        'audience',
        'site_scope',
        'author_user_id',
        'owner_user_id',
        'reviewed_by_user_id',
        'related_service_id',
        'review_due_at',
        'review_started_at',
        'published_at',
        'retired_at',
        'retirement_reason',
        'view_count',
        'helpful_yes',
        'helpful_no',
        'deflection_count',
    ];

    protected $casts = [
        'view_count' => 'integer',
        'helpful_yes' => 'integer',
        'helpful_no' => 'integer',
        'deflection_count' => 'integer',
        'site_scope' => 'array',
        'review_due_at' => 'date',
        'review_started_at' => 'datetime',
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ItService::class, 'related_service_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(ItKbInteraction::class, 'it_kb_article_id');
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /** Helpful score 0–100, or null when it has never been voted on. */
    public function helpfulPercent(): ?int
    {
        $total = (int) $this->helpful_yes + (int) $this->helpful_no;

        return $total > 0 ? (int) round(($this->helpful_yes / $total) * 100) : null;
    }

    /**
     * An application-unique slug from a title, appending -2/-3/… on collision.
     * Pass $ignoreId when re-slugging an existing row so it doesn't clash
     * with itself.
     */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'article';
        $slug = $base;
        $n = 1;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $n++;
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
