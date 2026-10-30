<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * A canonical reply by an authorised reader of the recognition audience.
 */
class HrKudosReply extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'kudos_id',
        'user_id',
        'body',
        'parent_id',
    ];

    protected $casts = ['author_removed_at' => 'datetime', 'edited_at' => 'datetime', 'hidden_at' => 'datetime', 'version' => 'integer'];

    public function scopeVisible(Builder $query): Builder
    {
        $query->whereNotNull('body');
        if (Schema::hasColumn($this->getTable(), 'author_removed_at')) {
            $query->whereNull('author_removed_at');
        }
        if (Schema::hasColumn($this->getTable(), 'hidden_at')) {
            $query->whereNull('hidden_at');
        }

        return $query;
    }

    public function kudos(): BelongsTo
    {
        return $this->belongsTo(HrKudos::class, 'kudos_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
