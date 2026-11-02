<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * C2.6, the Chat message model (docs/chat-experience/c2.6-message-model.md): storage for formatted
 * text, threads, priority, sharing one message, scheduled sends, polls, stickers, calls, system
 * messages, care cards and link previews. Every new column is empty or at its default for existing
 * messages, so today's Chat reads them exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The tables the new message columns point at come first.
        if (! Schema::hasTable('chat_stickers')) {
            Schema::create('chat_stickers', function (Blueprint $table): void {
                $table->id();
                // Oblivion's own set and the organisation's uploaded library. No outside GIF service.
                $table->string('set_key', 40);
                $table->string('name', 100);
                $table->string('storage_path');
                $table->string('mime_type', 120);
                $table->char('sha256', 64);
                $table->unsignedSmallInteger('width');
                $table->unsignedSmallInteger('height');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
                $table->unique(['set_key', 'sha256']);
                $table->index(['set_key', 'archived_at']);
            });
        }
        if (! Schema::hasTable('chat_scheduled_messages')) {
            Schema::create('chat_scheduled_messages', function (Blueprint $table): void {
                // Not a message until it is sent; it then becomes a normal message that points back here.
                $table->uuid('id')->primary();
                $table->foreignId('conversation_id')->constrained('ops_conversations')->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->uuid('mutation_id');
                // Encrypted like a private draft. Cleared once the message is sent or cancelled.
                $table->longText('encrypted_payload')->nullable();
                $table->timestamp('send_at')->nullable();
                // "At the start of my next shift", resolved against the roster when the shift is known.
                $table->boolean('at_next_shift')->default(false);
                $table->string('state', 20)->default('waiting');
                $table->foreignId('message_id')->nullable()->constrained('ops_messages')->nullOnDelete();
                $table->string('error_code', 50)->nullable();
                $table->timestamps();
                $table->unique(['sender_id', 'mutation_id']);
                $table->index(['state', 'send_at']);
                $table->index(['conversation_id', 'sender_id', 'state']);
            });
        }
        if (! Schema::hasTable('chat_thread_states')) {
            Schema::create('chat_thread_states', function (Blueprint $table): void {
                // Each person's state for one thread, keyed by the message the thread hangs from.
                $table->id();
                $table->foreignId('root_message_id')->constrained('ops_messages')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('following')->default(false);
                $table->boolean('muted')->default(false);
                $table->foreignId('last_read_message_id')->nullable()->constrained('ops_messages')->nullOnDelete();
                $table->timestamps();
                $table->unique(['root_message_id', 'user_id']);
                $table->index(['user_id', 'following']);
            });
        }
        if (! Schema::hasTable('chat_message_confirmations')) {
            Schema::create('chat_message_confirmations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('message_id')->constrained('ops_messages')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('confirmed_at');
                $table->unique(['message_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('chat_polls')) {
            Schema::create('chat_polls', function (Blueprint $table): void {
                // One poll per poll message.
                $table->id();
                $table->foreignId('message_id')->unique()->constrained('ops_messages')->cascadeOnDelete();
                $table->string('question', 300);
                $table->boolean('allows_multiple')->default(false);
                $table->boolean('anonymous')->default(false);
                $table->timestamp('closes_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('chat_poll_options')) {
            Schema::create('chat_poll_options', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('poll_id')->constrained('chat_polls')->cascadeOnDelete();
                $table->unsignedSmallInteger('position');
                $table->string('label', 150);
                $table->timestamps();
                $table->unique(['poll_id', 'position']);
            });
        }
        if (! Schema::hasTable('chat_poll_votes')) {
            Schema::create('chat_poll_votes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('poll_id')->constrained('chat_polls')->cascadeOnDelete();
                $table->foreignId('option_id')->constrained('chat_poll_options')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['option_id', 'user_id']);
                $table->index(['poll_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('chat_link_previews')) {
            Schema::create('chat_link_previews', function (Blueprint $table): void {
                // One row per address, fetched by the server; the reader's browser never contacts the site.
                $table->id();
                $table->char('url_sha256', 64)->unique();
                $table->text('url');
                $table->text('final_url')->nullable();
                $table->string('site_name', 200)->nullable();
                $table->string('title', 300)->nullable();
                $table->string('description', 1000)->nullable();
                $table->string('image_path')->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('error_code', 50)->nullable();
                $table->timestamp('fetched_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'expires_at']);
            });
        }
        if (! Schema::hasTable('chat_message_links')) {
            Schema::create('chat_message_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('message_id')->constrained('ops_messages')->cascadeOnDelete();
                $table->foreignId('link_preview_id')->constrained('chat_link_previews')->cascadeOnDelete();
                $table->unsignedSmallInteger('position');
                // The sender can hide a preview.
                $table->boolean('hidden')->default(false);
                $table->timestamps();
                $table->unique(['message_id', 'position']);
            });
        }
        if (! Schema::hasTable('chat_message_cards')) {
            Schema::create('chat_message_cards', function (Blueprint $table): void {
                // Which Oblivion record a message links to, type and id only. Permission is checked on
                // every view and the card never stores the record's details.
                $table->id();
                $table->foreignId('message_id')->constrained('ops_messages')->cascadeOnDelete();
                $table->string('card_type', 40);
                $table->string('record_id', 64);
                $table->unsignedSmallInteger('position');
                $table->timestamps();
                $table->unique(['message_id', 'position']);
                $table->index(['card_type', 'record_id']);
            });
        }

        Schema::table('ops_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('ops_messages', 'body_document')) {
                // Formatted text in Oblivion's own document format. content stays the plain-text copy.
                $table->json('body_document')->nullable()->after('content');
            }
            if (! Schema::hasColumn('ops_messages', 'thread_root_id')) {
                // The message a thread hangs from; reply_to_id stays the quoted reply.
                $table->foreignId('thread_root_id')->nullable()->constrained('ops_messages')->nullOnDelete();
                $table->index(['conversation_id', 'thread_root_id', 'id'], 'ops_messages_thread_index');
            }
            if (! Schema::hasColumn('ops_messages', 'priority')) {
                // 'normal', 'important' or 'urgent'. Urgent never overrides Do Not Disturb.
                $table->string('priority', 9)->default('normal');
            }
            if (! Schema::hasColumn('ops_messages', 'confirm_requested')) {
                $table->boolean('confirm_requested')->default(false);
            }
            if (! Schema::hasColumn('ops_messages', 'shared_from_message_id')) {
                $table->foreignId('shared_from_message_id')->nullable()->constrained('ops_messages')->nullOnDelete();
            }
            if (! Schema::hasColumn('ops_messages', 'call_id')) {
                $table->foreignUuid('call_id')->nullable()->constrained('chat_calls')->nullOnDelete();
            }
            if (! Schema::hasColumn('ops_messages', 'sticker_id')) {
                $table->foreignId('sticker_id')->nullable()->constrained('chat_stickers')->nullOnDelete();
            }
            if (! Schema::hasColumn('ops_messages', 'system_event')) {
                // A fixed code. system_params holds ids only, never names, so names are looked up when shown.
                $table->string('system_event', 40)->nullable();
            }
            if (! Schema::hasColumn('ops_messages', 'system_params')) {
                $table->json('system_params')->nullable();
            }
            if (! Schema::hasColumn('ops_messages', 'scheduled_message_id')) {
                $table->foreignUuid('scheduled_message_id')->nullable()->constrained('chat_scheduled_messages')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ops_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('ops_messages', 'thread_root_id')) {
                $table->dropIndex('ops_messages_thread_index');
            }
            foreach (['thread_root_id', 'shared_from_message_id', 'call_id', 'sticker_id', 'scheduled_message_id'] as $column) {
                if (Schema::hasColumn('ops_messages', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            $columns = array_values(array_filter(['body_document', 'priority', 'confirm_requested', 'system_event', 'system_params'],
                fn (string $column) => Schema::hasColumn('ops_messages', $column)));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
        Schema::dropIfExists('chat_message_cards');
        Schema::dropIfExists('chat_message_links');
        Schema::dropIfExists('chat_link_previews');
        Schema::dropIfExists('chat_poll_votes');
        Schema::dropIfExists('chat_poll_options');
        Schema::dropIfExists('chat_polls');
        Schema::dropIfExists('chat_message_confirmations');
        Schema::dropIfExists('chat_thread_states');
        Schema::dropIfExists('chat_scheduled_messages');
        Schema::dropIfExists('chat_stickers');
    }
};
