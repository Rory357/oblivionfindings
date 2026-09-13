<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('governance_voting_profiles')) {
            Schema::create('governance_voting_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('governing_body')->default('board'); // board, committee
                $table->foreignId('board_committee_id')->nullable()->constrained('board_committees')->nullOnDelete();
                $table->string('legal_form')->default('charitable_trust'); // charitable_trust, incorporated_society, company
                $table->string('governing_document_reference')->nullable();
                $table->string('governing_document_version')->nullable();
                $table->string('quorum_mode')->default('majority_floor_plus_one'); // majority_floor_plus_one, percentage, fixed_count
                $table->string('quorum_formula')->default('floor(N/2)+1');
                $table->string('ordinary_threshold_formula')->default('for > against of valid votes cast');
                $table->string('unanimous_denominator_formula')->default('assent from all entitled voters');
                $table->boolean('written_voting_permitted')->default(false);
                $table->boolean('written_unanimity_required')->default(true);
                $table->string('recusal_policy')->default('exclude_from_presence_and_tally_without_reducing_N');
                $table->boolean('is_active')->default(false);
                $table->foreignId('approved_by_resolution_id')->nullable()->constrained('resolutions')->nullOnDelete();
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('effective_from')->nullable();
                $table->timestamp('effective_to')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['governing_body', 'is_active']);
                $table->index(['board_committee_id', 'is_active']);
            });
        }

        if (!Schema::hasColumn('board_members', 'has_voting_seat')) {
            Schema::table('board_members', function (Blueprint $table) {
                $table->boolean('has_voting_seat')->nullable()->after('board_role');
            });
        }

        if (!Schema::hasColumn('committee_memberships', 'has_voting_seat')) {
            Schema::table('committee_memberships', function (Blueprint $table) {
                $table->boolean('has_voting_seat')->nullable()->after('role');
            });
        }

        if (!Schema::hasColumn('resolutions', 'board_committee_id')) {
            Schema::table('resolutions', function (Blueprint $table) {
                $table->foreignId('board_committee_id')->nullable()->after('governance_meeting_id')->constrained('board_committees')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('resolutions', 'voting_profile_id')) {
            Schema::table('resolutions', function (Blueprint $table) {
                $table->foreignId('voting_profile_id')->nullable()->after('board_committee_id')->constrained('governance_voting_profiles')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('resolutions', 'voting_profile_id')) {
            Schema::table('resolutions', function (Blueprint $table) {
                $table->dropForeign(['voting_profile_id']);
                $table->dropColumn('voting_profile_id');
            });
        }

        if (Schema::hasColumn('resolutions', 'board_committee_id')) {
            Schema::table('resolutions', function (Blueprint $table) {
                $table->dropForeign(['board_committee_id']);
                $table->dropColumn('board_committee_id');
            });
        }

        if (Schema::hasColumn('committee_memberships', 'has_voting_seat')) {
            Schema::table('committee_memberships', function (Blueprint $table) {
                $table->dropColumn('has_voting_seat');
            });
        }

        if (Schema::hasColumn('board_members', 'has_voting_seat')) {
            Schema::table('board_members', function (Blueprint $table) {
                $table->dropColumn('has_voting_seat');
            });
        }

        Schema::dropIfExists('governance_voting_profiles');
    }
};
