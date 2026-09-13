<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogue drafts retain item, immutable version and the original
        // request UUID in the existing actor/purpose/context unique boundary.
        Schema::table('it_ticket_drafts', function (Blueprint $table): void {
            $table->string('context_key', 128)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('it_ticket_drafts')->whereRaw('CHAR_LENGTH(context_key) > 64')->exists()) {
            throw new RuntimeException('Retained draft bindings require the wider context. Use a reviewed forward repair.');
        }
        Schema::table('it_ticket_drafts', function (Blueprint $table): void {
            $table->string('context_key', 64)->change();
        });
    }
};
