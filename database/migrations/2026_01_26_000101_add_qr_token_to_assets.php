<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('qr_token', 64)->nullable()->unique()->after('asset_tag');
        });

        $ids = DB::table('assets')->whereNull('qr_token')->pluck('id');
        foreach ($ids as $id) {
            DB::table('assets')->where('id', $id)->update([
                'qr_token' => Str::uuid()->toString(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn('qr_token');
        });
    }
};
