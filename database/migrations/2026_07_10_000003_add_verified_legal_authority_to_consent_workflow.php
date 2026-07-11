<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('next_of_kins', function (Blueprint $table) {
            $table->string('legal_authority_type')->nullable()->after('relationship');
            $table->timestamp('legal_authority_verified_at')->nullable()->after('legal_authority_type');
            $table->foreignId('legal_authority_verified_by_user_id')
                ->nullable()
                ->after('legal_authority_verified_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('legal_authority_expires_at')
                ->nullable()
                ->after('legal_authority_verified_by_user_id');
        });

        Schema::table('consent_requests', function (Blueprint $table) {
            $table->foreignId('authority_next_of_kin_id')
                ->nullable()
                ->after('recipient_relationship')
                ->constrained('next_of_kins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $hasBoundRequests = Schema::hasColumn('consent_requests', 'authority_next_of_kin_id')
            && DB::table('consent_requests')->whereNotNull('authority_next_of_kin_id')->exists();

        $hasVerifiedAuthorities = Schema::hasColumn('next_of_kins', 'legal_authority_type')
            && DB::table('next_of_kins')->where(function ($query) {
                $query->whereNotNull('legal_authority_type')
                    ->orWhereNotNull('legal_authority_verified_at')
                    ->orWhereNotNull('legal_authority_verified_by_user_id')
                    ->orWhereNotNull('legal_authority_expires_at');
            })->exists();

        if ($hasBoundRequests || $hasVerifiedAuthorities) {
            throw new RuntimeException(
                'Cannot roll back verified legal authority columns while authority records or consent bindings are populated.',
            );
        }

        Schema::table('consent_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('authority_next_of_kin_id');
        });

        Schema::table('next_of_kins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_authority_verified_by_user_id');
            $table->dropColumn([
                'legal_authority_type',
                'legal_authority_verified_at',
                'legal_authority_expires_at',
            ]);
        });
    }
};
