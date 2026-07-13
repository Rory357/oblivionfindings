<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'client_incidents_report_request_uuid_unique';

    public function up(): void
    {
        if (! Schema::hasTable('client_incidents')
            || Schema::hasColumn('client_incidents', 'report_request_uuid')
        ) {
            return;
        }

        Schema::table('client_incidents', function (Blueprint $table): void {
            $table->uuid('report_request_uuid')->nullable()->after('reference_number');
            $table->unique('report_request_uuid', self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_incidents')
            || ! Schema::hasColumn('client_incidents', 'report_request_uuid')
        ) {
            return;
        }

        Schema::table('client_incidents', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('report_request_uuid');
        });
    }
};
