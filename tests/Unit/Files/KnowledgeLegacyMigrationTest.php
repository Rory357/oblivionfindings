<?php

namespace Tests\Unit\Files;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

final class KnowledgeLegacyMigrationTest extends TestCase
{
    public function test_forward_repair_and_replay_preserve_publication_and_existing_relationship_values(): void
    {
        // Independent in-memory database: no application boot or working MySQL connection.
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $connection->getSchemaBuilder();
        Schema::swap($schema);
        try {
            $schema->create('it_kb_articles', function (Blueprint $table): void {
                $table->id();
                $table->text('body');
                $table->string('audience');
                $table->string('status');
                $table->timestamp('published_at');
                $table->unsignedBigInteger('lock_version');
            });
            $row = ['id' => 1, 'body' => 'Synthetic retained publication', 'audience' => 'it_agents', 'status' => 'published', 'published_at' => '2026-09-13 00:00:00', 'lock_version' => 7];
            $connection->table('it_kb_articles')->insert($row);
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_09_13_000003_complete_it_knowledge_relationship_storage.php';
            $migration->up();
            self::assertSame($row, (array) $connection->table('it_kb_articles')->first(array_keys($row)));
            self::assertNull($connection->table('it_kb_articles')->value('related_records'));
            $links = [['id' => 1, 'type' => 'document']];
            $connection->table('it_kb_articles')->update(['related_records' => json_encode($links)]);
            $migration->up();
            $migration->down();
            self::assertSame($row, (array) $connection->table('it_kb_articles')->first(array_keys($row)));
            self::assertEquals([['type' => 'document', 'id' => 1]], json_decode($connection->table('it_kb_articles')->value('related_records'), true));
        } finally {
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
