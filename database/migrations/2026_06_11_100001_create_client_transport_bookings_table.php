<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_transport_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->string('destination')->nullable();
            $table->dateTime('scheduled_at');
            $table->string('vehicle')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('escort_required')->default(false);
            $table->boolean('return_trip')->default(false);
            $table->string('status', 30)->default('requested'); // requested|confirmed|completed|cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'scheduled_at']);
        });

        Schema::table('client_onboarding_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('client_onboarding_steps', 'category')) {
                $table->string('category', 60)->nullable()->after('step_name');
            }
            if (! Schema::hasColumn('client_onboarding_steps', 'assigned_to')) {
                $table->foreignId('assigned_to')->nullable()->after('completed_by')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_transport_bookings');

        Schema::table('client_onboarding_steps', function (Blueprint $table) {
            if (Schema::hasColumn('client_onboarding_steps', 'assigned_to')) {
                $table->dropConstrainedForeignId('assigned_to');
            }
            if (Schema::hasColumn('client_onboarding_steps', 'category')) {
                $table->dropColumn('category');
            }
        });
    }
};
