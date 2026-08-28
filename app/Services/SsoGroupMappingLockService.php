<?php

namespace App\Services;

use App\Models\SsoGroupMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class SsoGroupMappingLockService
{
    /**
     * Acquire the stable SSO mapping publication mutex. Locking the complete
     * primary-key range (including its terminal insert gap under MySQL
     * REPEATABLE READ) serializes mapping creation and edits with role-sync
     * readers before either side acquires User or Role evidence.
     *
     * @return Collection<int, SsoGroupMapping>
     */
    public function lockMappingSet(): Collection
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('SSO group mappings must be locked in the governing transaction.');
        }

        return SsoGroupMapping::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (SsoGroupMapping $mapping): int => (int) $mapping->id);
    }
}
