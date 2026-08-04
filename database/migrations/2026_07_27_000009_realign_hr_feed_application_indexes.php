<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex(
            'hr_feed_posts',
            'hr_feed_posts_type_created_idx',
            fn (Blueprint $table) => $table->index(
                ['post_type', 'created_at'],
                'hr_feed_posts_type_created_idx',
            ),
        );
        $this->addIndex(
            'hr_feed_posts',
            'hr_feed_posts_audience_created_idx',
            fn (Blueprint $table) => $table->index(
                ['target_audience', 'target_value', 'created_at'],
                'hr_feed_posts_audience_created_idx',
            ),
        );
        $this->addIndex(
            'hr_kudos',
            'hr_kudos_recipient_created_idx',
            fn (Blueprint $table) => $table->index(
                ['to_user_id', 'created_at'],
                'hr_kudos_recipient_created_idx',
            ),
        );
        $this->addIndex(
            'hr_kudos',
            'hr_kudos_sender_created_idx',
            fn (Blueprint $table) => $table->index(
                ['from_user_id', 'created_at'],
                'hr_kudos_sender_created_idx',
            ),
        );

        foreach ([
            ['hr_feed_posts', 'hr_feed_posts_tenant_id_index'],
            ['hr_feed_posts', 'hr_feed_posts_tenant_id_post_type_created_at_index'],
            ['hr_kudos', 'hr_kudos_tenant_id_index'],
            ['hr_kudos', 'hr_kudos_tenant_id_to_user_id_index'],
            ['hr_kudos_reactions', 'hr_kudos_reactions_tenant_id_index'],
            ['hr_kudos_reactions', 'hr_kudos_reactions_tenant_id_kudos_id_index'],
            ['hr_kudos_replies', 'hr_kudos_replies_tenant_id_index'],
            ['hr_feed_reactions', 'hr_feed_reactions_tenant_id_index'],
            ['hr_feed_replies', 'hr_feed_replies_tenant_id_index'],
            ['hr_feed_attachments', 'hr_feed_attachments_tenant_id_index'],
        ] as [$table, $index]) {
            $this->dropIndex($table, $index);
        }
    }

    public function down(): void
    {
        $this->addIndex(
            'hr_feed_posts',
            'hr_feed_posts_tenant_id_index',
            fn (Blueprint $table) => $table->index(['tenant_id'], 'hr_feed_posts_tenant_id_index'),
        );
        $this->addIndex(
            'hr_feed_posts',
            'hr_feed_posts_tenant_id_post_type_created_at_index',
            fn (Blueprint $table) => $table->index(
                ['tenant_id', 'post_type', 'created_at'],
                'hr_feed_posts_tenant_id_post_type_created_at_index',
            ),
        );
        $this->addIndex(
            'hr_kudos',
            'hr_kudos_tenant_id_index',
            fn (Blueprint $table) => $table->index(['tenant_id'], 'hr_kudos_tenant_id_index'),
        );
        $this->addIndex(
            'hr_kudos',
            'hr_kudos_tenant_id_to_user_id_index',
            fn (Blueprint $table) => $table->index(
                ['tenant_id', 'to_user_id'],
                'hr_kudos_tenant_id_to_user_id_index',
            ),
        );
        $this->addIndex(
            'hr_kudos_reactions',
            'hr_kudos_reactions_tenant_id_index',
            fn (Blueprint $table) => $table->index(['tenant_id'], 'hr_kudos_reactions_tenant_id_index'),
        );
        $this->addIndex(
            'hr_kudos_reactions',
            'hr_kudos_reactions_tenant_id_kudos_id_index',
            fn (Blueprint $table) => $table->index(
                ['tenant_id', 'kudos_id'],
                'hr_kudos_reactions_tenant_id_kudos_id_index',
            ),
        );
        foreach ([
            ['hr_kudos_replies', 'hr_kudos_replies_tenant_id_index'],
            ['hr_feed_reactions', 'hr_feed_reactions_tenant_id_index'],
            ['hr_feed_replies', 'hr_feed_replies_tenant_id_index'],
            ['hr_feed_attachments', 'hr_feed_attachments_tenant_id_index'],
        ] as [$table, $index]) {
            $this->addIndex(
                $table,
                $index,
                fn (Blueprint $blueprint) => $blueprint->index(['tenant_id'], $index),
            );
        }

        $this->dropIndex('hr_kudos', 'hr_kudos_sender_created_idx');
        $this->dropIndex('hr_kudos', 'hr_kudos_recipient_created_idx');
        $this->dropIndex('hr_feed_posts', 'hr_feed_posts_audience_created_idx');
        $this->dropIndex('hr_feed_posts', 'hr_feed_posts_type_created_idx');
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
    }
};
