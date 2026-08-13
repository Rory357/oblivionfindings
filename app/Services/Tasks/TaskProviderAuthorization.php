<?php

namespace App\Services\Tasks;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * The only provider row-loading boundary.
 *
 * Site-scoped providers supply the owning module's canonical SQL scope. Global
 * providers supply the explicit organisation-wide permission keys. In both
 * cases authorization happens before get(), projection, aggregation or counts.
 */
final class TaskProviderAuthorization
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(Builder<TModel>, User): Builder<TModel>  $applyCanonicalScope
     * @param  Closure(TModel): TaskItem  $project
     * @return TaskItem[]
     */
    public function siteScoped(
        User $actor,
        bool $moduleAuthorized,
        Builder $query,
        Closure $applyCanonicalScope,
        Closure $project,
    ): array {
        if (! $moduleAuthorized) {
            return [];
        }

        $scoped = $applyCanonicalScope($query, $actor);
        if (! $scoped instanceof Builder) {
            throw new LogicException('A task provider Site scope must return an Eloquent builder.');
        }

        return $scoped->get()->map($project)->all();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  non-empty-list<string>  $permissionKeys
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): TaskItem  $project
     * @return TaskItem[]
     */
    public function explicitlyGlobal(
        User $actor,
        array $permissionKeys,
        Builder $query,
        Closure $project,
    ): array {
        if ($permissionKeys === []
            || ! collect($permissionKeys)->contains(fn (string $permission): bool => $actor->canDo($permission))) {
            return [];
        }

        return $query->get()->map($project)->all();
    }
}
