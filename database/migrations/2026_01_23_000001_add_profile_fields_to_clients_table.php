<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Basic demographic + contact profile fields
            $table->date('date_of_birth')->nullable()->after('last_name');
            $table->string('preferred_name')->nullable()->after('date_of_birth');
            $table->string('gender')->nullable()->after('preferred_name');

            $table->string('phone')->nullable()->after('status');
            $table->string('email')->nullable()->after('phone');

            $table->string('address_line_1')->nullable()->after('email');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('suburb')->nullable()->after('address_line_2');
            $table->string('city')->nullable()->after('suburb');
            $table->string('postcode')->nullable()->after('city');

            // Funding (kept generic so it fits NZ/AU models)
            $table->string('funding_type')->nullable()->after('postcode');
            $table->text('funding_notes')->nullable()->after('funding_type');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth',
                'preferred_name',
                'gender',
                'phone',
                'email',
                'address_line_1',
                'address_line_2',
                'suburb',
                'city',
                'postcode',
                'funding_type',
                'funding_notes',
            ]);
        });
    }
};
