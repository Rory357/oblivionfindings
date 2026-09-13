<?php

namespace App\Services;

use App\Models\SsoGroupMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class SsoGroupMappingLockService
{
    public const MUTEX_KEY = 'internal.sso.publication_mutex';

    /**
     * Acquire a stable transaction-scoped record lock before the mapping set
     * and User/Role evidence. Empty-range gap locks can coexist in MySQL, so
     * the mapping range alone cannot serialize an unconfigured application.
     *
     * @return Collection<int, SsoGroupMapping>
     */
    public function lockMappingSet(): Collection
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('SSO group mappings must be locked in the governing transaction.');
        }

        // The unique key makes first-use insertion and later no-op updates
        // acquire the same exclusive record lock. Existing value/timestamps
        // remain untouched; this is internal coordination, never SSO config.
        DB::table('app_settings')->upsert([
            ['key' => self::MUTEX_KEY, 'value' => null, 'created_at' => now(), 'updated_at' => now()],
        ], ['key'], ['key']);

        return SsoGroupMapping::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (SsoGroupMapping $mapping): int => (int) $mapping->id);
    }
}
