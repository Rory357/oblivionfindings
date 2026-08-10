<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_security_desktop_release_fixture_packs', function (Blueprint $table): void {
            $table->id();
            $table->string('pack_key', 100)->unique();
            $table->char('release_revision', 40);
            $table->string('state', 30);
            $table->json('manifest');
            $table->char('manifest_sha256', 64);
            $table->timestamp('prepared_at');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'release_revision'], 'it_sec_desktop_fixture_state_revision_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_security_desktop_release_fixture_packs');
    }
};
