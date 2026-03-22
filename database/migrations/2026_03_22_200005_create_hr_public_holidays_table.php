<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_public_holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->date('date');
            $table->string('region')->nullable(); // e.g., 'auckland', 'wellington', 'national'
            $table->boolean('is_national')->default(true);
            $table->integer('year');
            $table->timestamps();

            $table->index(['year', 'date']);
        });

        // Seed NZ 2026 public holidays
        $holidays = [
            ['name' => "New Year's Day",        'date' => '2026-01-01', 'is_national' => true, 'year' => 2026],
            ['name' => "Day after New Year's",   'date' => '2026-01-02', 'is_national' => true, 'year' => 2026],
            ['name' => 'Waitangi Day',           'date' => '2026-02-06', 'is_national' => true, 'year' => 2026],
            ['name' => 'Good Friday',            'date' => '2026-04-03', 'is_national' => true, 'year' => 2026],
            ['name' => 'Easter Monday',          'date' => '2026-04-06', 'is_national' => true, 'year' => 2026],
            ['name' => 'ANZAC Day',              'date' => '2026-04-25', 'is_national' => true, 'year' => 2026],
            ['name' => "King's Birthday",        'date' => '2026-06-01', 'is_national' => true, 'year' => 2026],
            ['name' => 'Matariki',               'date' => '2026-07-10', 'is_national' => true, 'year' => 2026],
            ['name' => 'Labour Day',             'date' => '2026-10-26', 'is_national' => true, 'year' => 2026],
            ['name' => 'Christmas Day',          'date' => '2026-12-25', 'is_national' => true, 'year' => 2026],
            ['name' => 'Boxing Day',             'date' => '2026-12-26', 'is_national' => true, 'year' => 2026],
        ];

        $now = now();
        foreach ($holidays as $holiday) {
            DB::table('hr_public_holidays')->insert(array_merge($holiday, [
                'region' => 'national',
                'tenant_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_public_holidays');
    }
};
