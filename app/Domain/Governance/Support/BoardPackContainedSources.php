<?php

declare(strict_types=1);

namespace App\Domain\Governance\Support;

use App\Domain\Governance\Models\BoardPack;
use Illuminate\Support\Facades\DB;

/**
 * The typed contained-source contract for a board pack.
 *
 * A pack's audience is bounded by the records it embeds. Rather than
 * matching untyped manifest JSON text (where hidden paper 2 collides with
 * safe paper 2999), every embedded record is normalised once into a typed
 * (source_type, integer source_id) row in `board_pack_contained_sources`,
 * plus a derived `contains_confidential_agenda` flag. Discovery
 * (BoardPackAccessService::visibleQuery) and single-record checks
 * (canView / download) both read this one index.
 *
 * Manifest ids that cannot be normalised to a positive integer are recorded
 * as source_id 0, which never matches a real record and therefore always
 * denies non-managers (fail closed).
 */
final class BoardPackContainedSources
{
    public const RESOLUTION = 'resolution';

    public const GOVERNANCE_DOCUMENT = 'governance_document';

    /** Section keys in the manifest that embed records of each source type. */
    private const SECTION_TYPES = [
        'resolutions' => self::RESOLUTION,
        'supporting_documents' => self::GOVERNANCE_DOCUMENT,
    ];

    /**
     * @return list<array{source_type: string, source_id: int}>
     */
    public static function extract(mixed $manifest): array
    {
        $manifest = self::decode($manifest);
        $sources = [];

        foreach (self::contentBlocks($manifest) as $content) {
            foreach (self::SECTION_TYPES as $section => $type) {
                foreach (self::sectionItems($content[$section] ?? null) as $item) {
                    $raw = is_array($item) ? ($item['id'] ?? null) : (is_object($item) ? ($item->id ?? null) : null);
                    if ($raw === null || $raw === '') {
                        continue;
                    }
                    $sources[$type.':'.self::normaliseId($raw)] = [
                        'source_type' => $type,
                        'source_id' => self::normaliseId($raw),
                    ];
                }
            }
        }

        // Builder manifest entries reference papers as "res_{id}".
        $manifestSections = $manifest['manifest_sections'] ?? null;
        if (is_array($manifestSections)) {
            foreach ($manifestSections as $entry) {
                $entryId = is_array($entry) ? ($entry['id'] ?? null) : null;
                if (is_string($entryId) && preg_match('/^res_(.+)$/', $entryId, $match) === 1) {
                    $id = self::normaliseId($match[1]);
                    $sources[self::RESOLUTION.':'.$id] = ['source_type' => self::RESOLUTION, 'source_id' => $id];
                }
            }
        }

        return array_values($sources);
    }

    public static function containsConfidentialAgenda(mixed $manifest): bool
    {
        foreach (self::contentBlocks(self::decode($manifest)) as $content) {
            foreach (self::sectionItems($content['agenda'] ?? null) as $item) {
                $flag = is_array($item) ? ($item['is_confidential'] ?? null) : (is_object($item) ? ($item->is_confidential ?? null) : null);
                if (! empty($flag) && $flag !== 'false') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Rebuild the typed index for a persisted pack. The pack is only marked
     * indexed after its rows are written, so a failed sync leaves it hidden
     * from non-managers rather than exposed.
     */
    public static function sync(BoardPack $pack): void
    {
        if (! $pack->exists) {
            return;
        }

        $sources = self::extract($pack->document_manifest);
        $confidentialAgenda = self::containsConfidentialAgenda($pack->document_manifest);
        $indexedAt = now();

        DB::transaction(function () use ($pack, $sources, $confidentialAgenda, $indexedAt): void {
            DB::table('board_pack_contained_sources')->where('board_pack_id', $pack->getKey())->delete();

            if ($sources !== []) {
                DB::table('board_pack_contained_sources')->insert(array_map(
                    fn (array $source): array => ['board_pack_id' => $pack->getKey()] + $source,
                    $sources,
                ));
            }

            DB::table($pack->getTable())->where($pack->getKeyName(), $pack->getKey())->update([
                'contains_confidential_agenda' => $confidentialAgenda,
                'contained_sources_indexed_at' => $indexedAt,
            ]);
        });

        $pack->forceFill([
            'contains_confidential_agenda' => $confidentialAgenda,
            'contained_sources_indexed_at' => $indexedAt,
        ]);
        $pack->syncOriginalAttributes(['contains_confidential_agenda', 'contained_sources_indexed_at']);
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $manifest): array
    {
        if (is_string($manifest)) {
            $manifest = json_decode($manifest, true);
        }

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * Current builder packs use `content_sections`; legacy packs use `content`.
     * Both are inspected so no historical shape escapes the contract.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private static function contentBlocks(array $manifest): array
    {
        $blocks = [];
        foreach (['content_sections', 'content'] as $key) {
            if (isset($manifest[$key]) && is_array($manifest[$key])) {
                $blocks[] = $manifest[$key];
            }
        }

        return $blocks;
    }

    /**
     * Sections are either `['items' => [...]]` (builder) or a flat list.
     *
     * @return list<mixed>
     */
    private static function sectionItems(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        if (array_key_exists('items', $section)) {
            return is_array($section['items']) ? array_values($section['items']) : [];
        }

        if (array_key_exists('id', $section)) {
            return [$section];
        }

        return array_is_list($section) ? $section : [];
    }

    private static function normaliseId(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : 0;
        }

        if (is_string($raw) && preg_match('/^[1-9][0-9]{0,17}$/', $raw) === 1) {
            return (int) $raw;
        }

        return 0;
    }
}
