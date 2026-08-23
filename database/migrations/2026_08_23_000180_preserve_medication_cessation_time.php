<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CESSATION_REQUEST_UNIQUE = 'mov_cessation_request_unique';

    public function up(): void
    {
        Schema::table('client_medications', function (Blueprint $table): void {
            $table->dateTime('ceased_at')->nullable()->change();
        });

        Schema::table('medication_order_versions', function (Blueprint $table): void {
            $table->dateTime('ceased_at')->nullable()->change();
            $table->string('cessation_request_key', 100)->nullable()->after('version_number');
            $table->char('cessation_payload_sha256', 64)->nullable()->after('cessation_request_key');
            $table->unique('cessation_request_key', self::CESSATION_REQUEST_UNIQUE);
        });
    }

    public function down(): void
    {
        $blockingTables = collect([
            'client_medications',
            'medication_order_versions',
        ])->filter(fn (string $table): bool => DB::table($table)
            ->whereNotNull('ceased_at')
            ->whereRaw('TIME(`ceased_at`) <> ?', ['00:00:00'])
            ->exists());

        if ($blockingTables->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot reduce medication cessation timestamps to dates while non-midnight evidence exists in: '
                .$blockingTables->implode(', ').'.',
            );
        }

        Schema::table('medication_order_versions', function (Blueprint $table): void {
            $table->dropUnique(self::CESSATION_REQUEST_UNIQUE);
            $table->dropColumn(['cessation_request_key', 'cessation_payload_sha256']);
        });

        Schema::table('client_medications', function (Blueprint $table): void {
            $table->date('ceased_at')->nullable()->change();
        });

        Schema::table('medication_order_versions', function (Blueprint $table): void {
            $table->date('ceased_at')->nullable()->change();
        });
    }
};
