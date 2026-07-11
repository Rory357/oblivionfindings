<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_photos', 'storage_disk')) {
            Schema::table('client_photos', function (Blueprint $table): void {
                // Existing rows were written to the public disk. The separate
                // migration command copies and verifies their bytes before it
                // changes this metadata to local.
                $table->string('storage_disk', 30)
                    ->default('public')
                    ->after('uploaded_by_user_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('client_photos', 'storage_disk')) {
            return;
        }

        if (
            DB::table('client_photos')
                ->where('storage_disk', '!=', 'public')
                ->exists()
        ) {
            throw new RuntimeException(
                'Cannot drop client_photos.storage_disk while private client photo media remains. Restore and verify every blob on the public disk first.',
            );
        }

        Schema::table('client_photos', function (Blueprint $table): void {
            $table->dropColumn('storage_disk');
        });
    }
};
