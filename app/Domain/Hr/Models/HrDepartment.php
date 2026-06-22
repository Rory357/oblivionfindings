<?php

namespace App\Domain\Hr\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrDepartment extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'cost_centre',
        'description',
        'manager_user_id',
        'parent_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(HrEmployeeProfile::class, 'department_id');
    }

    /** Sites this department operates across (organisational footprint). */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'hr_department_site')
            ->withTimestamps()
            ->orderBy('sites.name');
    }

    /* ------------------------------------------------------------------ */
    /*  Hierarchy helpers (cycle-safe)                                     */
    /* ------------------------------------------------------------------ */

    /**
     * IDs of all descendant departments (excludes self). Iterative with a
     * visited-set so a pre-existing bad cycle can never infinite-loop.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $visited = [$this->id => true];
        $stack = static::query()->where('parent_id', $this->id)->pluck('id')->all();

        while ($stack !== []) {
            $id = (int) array_pop($stack);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $ids[] = $id;
            foreach (static::query()->where('parent_id', $id)->pluck('id')->all() as $childId) {
                $stack[] = (int) $childId;
            }
        }

        return $ids;
    }

    /** Active employees in this department and all its sub-departments. */
    public function rolledUpEmployeeCount(): int
    {
        return HrEmployeeProfile::query()
            ->whereIn('department_id', array_merge([$this->id], $this->descendantIds()))
            ->where('is_active', true)
            ->count();
    }

    /**
     * Would setting this department's parent to $parentId create a cycle?
     * Walks the proposed parent's ancestor chain (visited-set guarded).
     */
    public function wouldCreateCycle(?int $parentId): bool
    {
        if ($parentId === null) {
            return false;
        }
        if ($parentId === $this->id) {
            return true;
        }

        $seen = [];
        $cursor = $parentId;
        while ($cursor !== null) {
            if ($cursor === $this->id) {
                return true;
            }
            if (isset($seen[$cursor])) {
                break; // pre-existing cycle in the data — stop, no new cycle from us
            }
            $seen[$cursor] = true;
            $next = static::query()->where('id', $cursor)->value('parent_id');
            $cursor = $next !== null ? (int) $next : null;
        }

        return false;
    }
}
