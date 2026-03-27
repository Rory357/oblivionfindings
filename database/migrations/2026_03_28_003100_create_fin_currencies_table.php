<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_currencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->char('code', 3);
            $table->string('name');
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->decimal('exchange_rate', 14, 6)->default(1.000000);
            $table->dateTime('rate_updated_at')->nullable();
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code'], 'fin_curr_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_currencies');
    }
};
