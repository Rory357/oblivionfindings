<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_webhooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('url');
            $table->string('secret');
            $table->json('events'); // array of event names like ['leave.submitted', 'employee.created']
            $table->boolean('is_active')->default(true);
            $table->datetime('last_triggered_at')->nullable();
            $table->integer('failure_count')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_webhooks');
    }
};
