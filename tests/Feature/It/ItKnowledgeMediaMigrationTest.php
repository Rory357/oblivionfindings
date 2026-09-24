<?php

use App\Models\ItKbArticle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('legacy installed revision storage receives relationships without changing existing publications or links', function () {
    $article = (new ItKbArticle)->forceFill([
        'title' => 'Synthetic preserved publication', 'slug' => 'migration-'.str()->uuid(),
        'category' => 'network', 'body' => 'Synthetic preserved content.', 'status' => 'published',
        'audience' => 'it_agents', 'lock_version' => 7, 'published_at' => now(),
    ]);
    $article->save();
    $id = $article->id;
    $columns = ['id', 'title', 'body', 'status', 'audience', 'published_at', 'updated_at', 'lock_version'];
    $before = (array) DB::table('it_kb_articles')->find($id, $columns);
    Schema::table('it_kb_articles', fn (Blueprint $table) => $table->dropColumn('related_records'));
    $migration = require database_path('migrations/2026_09_13_000003_complete_it_knowledge_relationship_storage.php');
    $migration->up();
    expect((array) DB::table('it_kb_articles')->find($id, $columns))->toBe($before)
        ->and(DB::table('it_kb_articles')->where('id', $id)->value('related_records'))->toBeNull();
    $links = json_encode([['type' => 'document', 'id' => $id]], JSON_THROW_ON_ERROR);
    DB::table('it_kb_articles')->where('id', $id)->update(['related_records' => $links]);
    $migration->up();
    $migration->down();
    expect(json_decode(DB::table('it_kb_articles')->where('id', $id)->value('related_records'), true))->toEqual(json_decode($links, true))
        ->and((array) DB::table('it_kb_articles')->find($id, $columns))->toBe($before);
});
