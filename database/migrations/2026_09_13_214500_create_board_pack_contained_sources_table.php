<?php

declare(strict_types=1);

use App\Domain\Governance\Support\BoardPackContainedSources;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GOV-R06: replace untyped manifest JSON text matching with a typed
 * contained-source index shared by board pack discovery and single-record
 * access, and backfill it for every existing pack (including soft-deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('board_pack_contained_sources')) {
            Schema::create('board_pack_contained_sources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('board_pack_id')->constrained('board_packs')->cascadeOnDelete();
                $table->string('source_type', 40);
                $table->unsignedBigInteger('source_id');

                $table->unique(['board_pack_id', 'source_type', 'source_id'], 'bp_contained_sources_unique');
                $table->index(['source_type', 'source_id'], 'bp_contained_sources_lookup');
            });
        }

        Schema::table('board_packs', function (Blueprint $table) {
            if (! Schema::hasColumn('board_packs', 'contains_confidential_agenda')) {
                $table->boolean('contains_confidential_agenda')->default(false);
            }
            if (! Schema::hasColumn('board_packs', 'contained_sources_indexed_at')) {
                $table->timestamp('contained_sources_indexed_at')->nullable();
            }
        });

        DB::table('board_packs')
            ->select(['id', 'document_manifest'])
            ->chunkById(200, function ($packs): void {
                foreach ($packs as $pack) {
                    $sources = BoardPackContainedSources::extract($pack->document_manifest);

                    DB::transaction(function () use ($pack, $sources): void {
                        DB::table('board_pack_contained_sources')->where('board_pack_id', $pack->id)->delete();

                        if ($sources !== []) {
                            DB::table('board_pack_contained_sources')->insert(array_map(
                                fn (array $source): array => ['board_pack_id' => $pack->id] + $source,
                                $sources,
                            ));
                        }

                        DB::table('board_packs')->where('id', $pack->id)->update([
                            'contains_confidential_agenda' => BoardPackContainedSources::containsConfidentialAgenda($pack->document_manifest),
                            'contained_sources_indexed_at' => now(),
                        ]);
                    });
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_pack_contained_sources');

        Schema::table('board_packs', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['contains_confidential_agenda', 'contained_sources_indexed_at'],
                fn (string $column): bool => Schema::hasColumn('board_packs', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
