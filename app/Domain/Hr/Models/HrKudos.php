<?php

namespace App\Domain\Hr\Models;

use App\Domain\Hr\Notifications\FeedReplyNotification;
use App\Domain\Hr\Notifications\RecognitionMentionNotification;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrRecognitionMedia;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Database\Factories\Hr\HrKudosFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrKudos extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrKudosFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'from_user_id',
        'to_user_id',
        'recipient_type',
        'to_department_id',
        'category',
        'impact',
        'message',
        'is_public',
        'feed_post_id',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $kudos): void {
            app(HrRecognitionMedia::class)->remove($kudos, entireRecognition: true);
            $replyIds = HrKudosReply::where('kudos_id', $kudos->id)->pluck('id');
            DB::table('notifications')->where(function ($query) use ($kudos, $replyIds): void {
                $query->where(fn ($query) => $query->where('type', FeedReplyNotification::class)->whereIn('data->kudos_reply_id', $replyIds))
                    ->orWhere(fn ($query) => $query->where('type', RecognitionMentionNotification::class)->where('data->kudos_id', $kudos->id));
            })->delete();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'to_department_id');
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'hr_kudos_recipients', 'kudos_id', 'user_id')->withTimestamps();
    }

    public function isTeamRecognition(): bool
    {
        return ($this->recipient_type ?? 'person') === 'team';
    }

    public function categoryLabel(): ?string
    {
        return $this->category === null || $this->category === '' ? null : (FeedService::KUDOS_CATEGORIES[$this->category] ?? $this->category);
    }

    public function impactKey(): string
    {
        return isset(FeedService::KUDOS_IMPACTS[$this->impact]) ? $this->impact : FeedService::LEGACY_IMPACT;
    }

    public function impactLabel(): string
    {
        return FeedService::KUDOS_IMPACTS[$this->impactKey()];
    }

    public function scopeReceivedBy(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $recipients) use ($userId): void {
            $recipients->where('to_user_id', $userId);
            if (Schema::hasTable('hr_kudos_recipients')) {
                $recipients->orWhereHas('recipients', fn (Builder $members) => $members->whereKey($userId));
            }
        });
    }

    public function feedPost(): BelongsTo
    {
        return $this->belongsTo(HrFeedPost::class, 'feed_post_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(HrKudosReaction::class, 'kudos_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(HrKudosReply::class, 'kudos_id')->visible();
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }
}
