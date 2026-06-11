<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'room_id')) {
                $table->foreignId('room_id')
                    ->nullable()
                    ->after('site_id')
                    ->constrained('site_house_rooms')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('clients', 'sleep_target_hours')) {
                $table->decimal('sleep_target_hours', 3, 1)
                    ->nullable()
                    ->after('sleep_preferences');
            }
        });

        if (! Schema::hasTable('client_meal_logs')) {
            Schema::create('client_meal_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->string('meal_type', 40);
                $table->string('status', 40);
                $table->dateTime('occurred_at')->index();
                $table->string('portion_note')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['client_id', 'occurred_at']);
            });
        }

        if (! Schema::hasTable('client_sleep_entries')) {
            Schema::create('client_sleep_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->date('slept_at')->index();
                $table->decimal('hours_slept', 4, 1);
                $table->string('quality', 40)->nullable();
                $table->unsignedSmallInteger('interruptions')->nullable();
                $table->string('settled_by', 5)->nullable();
                $table->string('woke_at', 5)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['client_id', 'slept_at']);
            });
        }

        if (! Schema::hasTable('client_respite_allocations')) {
            Schema::create('client_respite_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->date('period_start');
                $table->date('period_end');
                $table->unsignedSmallInteger('nights_allocated');
                $table->string('funding_source')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['client_id', 'period_start', 'period_end'], 'client_respite_alloc_period_unique');
                $table->index(['client_id', 'period_start', 'period_end'], 'client_respite_alloc_period_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_respite_allocations');
        Schema::dropIfExists('client_sleep_entries');
        Schema::dropIfExists('client_meal_logs');

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'room_id')) {
                $table->dropConstrainedForeignId('room_id');
            }

            if (Schema::hasColumn('clients', 'sleep_target_hours')) {
                $table->dropColumn('sleep_target_hours');
            }
        });
    }
};
