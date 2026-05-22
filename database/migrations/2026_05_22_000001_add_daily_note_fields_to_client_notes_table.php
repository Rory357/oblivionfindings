<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('client_notes', 'category')) {
                $table->string('category')->nullable()->after('type')->index();
            }
            if (! Schema::hasColumn('client_notes', 'behaviour_tags')) {
                $table->json('behaviour_tags')->nullable()->after('mood_rating');
            }
            if (! Schema::hasColumn('client_notes', 'concerns_flags')) {
                $table->json('concerns_flags')->nullable()->after('behaviour_tags');
            }
            if (! Schema::hasColumn('client_notes', 'follow_up_action')) {
                $table->string('follow_up_action')->nullable()->after('concerns_flags');
            }
            if (! Schema::hasColumn('client_notes', 'follow_up_due_at')) {
                $table->dateTime('follow_up_due_at')->nullable()->after('follow_up_action')->index();
            }
            if (! Schema::hasColumn('client_notes', 'follow_up_completed_at')) {
                $table->dateTime('follow_up_completed_at')->nullable()->after('follow_up_due_at');
            }
            if (! Schema::hasColumn('client_notes', 'appears_on_timeline')) {
                $table->boolean('appears_on_timeline')->default(true)->after('follow_up_completed_at');
            }
            if (! Schema::hasColumn('client_notes', 'is_draft')) {
                $table->boolean('is_draft')->default(false)->after('appears_on_timeline')->index();
            }
            if (! Schema::hasColumn('client_notes', 'contact_person')) {
                $table->string('contact_person')->nullable()->after('is_draft');
            }
            if (! Schema::hasColumn('client_notes', 'contact_relationship')) {
                $table->string('contact_relationship')->nullable()->after('contact_person');
            }
            if (! Schema::hasColumn('client_notes', 'contact_method')) {
                $table->string('contact_method')->nullable()->after('contact_relationship');
            }
        });

        Schema::table('client_notes', function (Blueprint $table) {
            $table->index(['client_id', 'is_flagged', 'reviewed_at'], 'client_notes_review_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            $table->dropIndex('client_notes_review_queue_idx');

            foreach ([
                'contact_method',
                'contact_relationship',
                'contact_person',
                'is_draft',
                'appears_on_timeline',
                'follow_up_completed_at',
                'follow_up_due_at',
                'follow_up_action',
                'concerns_flags',
                'behaviour_tags',
                'category',
            ] as $column) {
                if (Schema::hasColumn('client_notes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
