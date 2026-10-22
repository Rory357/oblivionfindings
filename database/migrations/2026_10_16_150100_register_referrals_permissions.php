<?php

use App\Domain\Referrals\ReferralPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (ReferralPermissions::definitions() as $definition) {
            DB::table('permissions')->insertOrIgnore([...$definition, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Keep explicit role grants on rollback; removing a feature must not silently rewrite access policy.
    }
};
