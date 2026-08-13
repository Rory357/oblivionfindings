<?php

namespace App\Services\Tasks\Contracts;

use App\Models\User;

/**
 * A composite provider may emit records from more than one underlying model.
 *
 * The provider key still owns list filtering, while aliases provide an
 * unambiguous persisted/action identity for detail, following and escalation.
 */
interface ProvidesTaskSourceAliases
{
    /**
     * @return string[]
     */
    public function sourceAliases(): array;

    /**
     * Resolve the old provider-level identity without using presentation
     * state such as open/done filters. This must remain stable for the
     * lifetime of the underlying records.
     */
    public function legacySourceAliasForId(User $user, int $id): ?string;
}
