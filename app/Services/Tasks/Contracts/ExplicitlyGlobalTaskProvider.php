<?php

namespace App\Services\Tasks\Contracts;

/**
 * Marker for an organisation-wide provider whose owning module defines an
 * explicit global read permission instead of a Site-owned row scope.
 *
 * Generic roles and module availability are not enough: the named permissions
 * are re-checked by TaskProviderAuthorization when rows are projected.
 */
interface ExplicitlyGlobalTaskProvider
{
    /** @return non-empty-list<string> */
    public function globalViewPermissions(): array;
}
