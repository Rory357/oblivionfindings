<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_attachments', function (Blueprint $table): void {
            $table->string('catalogue_field_key', 80)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('it_attachments')->whereNotNull('catalogue_field_key')->exists()) {
            throw new RuntimeException('Catalogue attachment audiences must be reconciled before removing their field evidence.');
        }
        Schema::table('it_attachments', function (Blueprint $table): void {
            $table->dropColumn('catalogue_field_key');
        });
    }
};
