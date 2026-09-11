<?php

use App\Domain\It\Exceptions\ItInboundHeaderException;
use App\Domain\It\Services\ItEmailMessageIdentifiers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_inbound_emails', function (Blueprint $table): void {
            $table->char('message_identity_hash', 64)->nullable()->index();
            $table->char('identity_claim_key', 64)->nullable()->unique();
            $table->char('message_content_hash', 64)->nullable();
            $table->text('normalized_message_id')->nullable();
            $table->text('parent_message_ids')->nullable();
            $table->text('reference_message_ids')->nullable();
        });
        // Add lookup evidence only. Never delete/coalesce legacy records or pretend their
        // 500-character previews prove an exact original message. Legacy collisions stay visible.
        $parser = new ItEmailMessageIdentifiers;
        DB::table('it_inbound_emails')->whereNotNull('message_id')->orderBy('id')->chunkById(250, function ($rows) use ($parser): void {
            foreach ($rows as $row) {
                try {
                    $id = $parser->messageId($row->message_id);
                    if ($id !== null) {
                        DB::table('it_inbound_emails')->where('id', $row->id)
                            ->update(['message_identity_hash' => $parser->hash($id)]);
                    }
                } catch (ItInboundHeaderException) {
                    // Malformed/truncated historical evidence is preserved, not guessed.
                }
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::table('it_inbound_emails', function (Blueprint $table): void {
            $table->dropUnique(['identity_claim_key']);
            $table->dropIndex(['message_identity_hash']);
            $table->dropColumn(['message_identity_hash', 'identity_claim_key', 'message_content_hash',
                'normalized_message_id', 'parent_message_ids', 'reference_message_ids']);
        });
    }
};
