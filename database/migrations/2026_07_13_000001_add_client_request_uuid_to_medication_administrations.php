<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'cma_client_request_uuid_unique';

    public function up(): void
    {
        if (! Schema::hasTable('client_medication_administrations')
            || Schema::hasColumn('client_medication_administrations', 'client_request_uuid')
        ) {
            return;
        }

        Schema::table('client_medication_administrations', function (Blueprint $table): void {
            $table->string('client_request_uuid', 64)->nullable();
            $table->unique('client_request_uuid', self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_medication_administrations')
            || ! Schema::hasColumn('client_medication_administrations', 'client_request_uuid')
        ) {
            return;
        }

        Schema::table('client_medication_administrations', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('client_request_uuid');
        });
    }
};
