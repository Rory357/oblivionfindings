<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renamePermission(
            'integrations.manage_tenant_secrets',
            'integrations.manage_secrets',
            'Manage application integration API keys',
        );
    }

    public function down(): void
    {
        $this->renamePermission(
            'integrations.manage_secrets',
            'integrations.manage_tenant_secrets',
            'Manage legacy integration API keys',
        );
    }

    private function renamePermission(string $from, string $to, string $description): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::transaction(function () use ($from, $to, $description): void {
            $source = DB::table('permissions')->where('key', $from)->first();
            if ($source === null) {
                return;
            }

            $target = DB::table('permissions')->where('key', $to)->first();
            if ($target !== null) {
                throw new LogicException(
                    "Cannot rename permission {$from} to {$to}: both keys exist. "
                    .'Reconcile their role grants and explicit user overrides before retrying so a denial cannot be lost.'
                );
            }

            DB::table('permissions')
                ->where('id', $source->id)
                ->update(['key' => $to, 'description' => $description]);
        });
    }
};
