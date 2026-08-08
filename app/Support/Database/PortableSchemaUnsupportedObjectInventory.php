<?php

namespace App\Support\Database;

final class PortableSchemaUnsupportedObjectInventory
{
    /**
     * Audited against every migration path used by Laravel's Migrator.
     *
     * @var array<string, array{trigger?: int, view?: int, procedure?: int, function?: int}>
     */
    public const MANIFEST = [
        '2026_08_06_000041_retain_device_relationship_history' => [
            'trigger' => 3,
        ],
        '2026_08_06_000047_enforce_monitoring_evidence_lifecycle' => [
            'trigger' => 13,
        ],
    ];

    /**
     * @param  array<string, array<int, array{path: string, source: string}>>  $migrationSources
     * @return array{
     *     blockers: array<int, string>,
     *     discovered: array<string, array<string, int>>,
     *     manifest: array<string, array<string, int>>
     * }
     */
    public function audit(array $migrationSources): array
    {
        $discovered = [];
        $duplicateSources = [];

        foreach ($migrationSources as $migration => $sources) {
            if (count($sources) !== 1) {
                $duplicateSources[] = $migration;

                continue;
            }

            $objects = $this->schemaObjectCounts($sources[0]['source']);
            if ($objects !== []) {
                $discovered[$migration] = $objects;
            }
        }

        ksort($discovered);
        sort($duplicateSources);
        $manifest = self::MANIFEST;
        ksort($manifest);
        foreach ($manifest as &$objects) {
            ksort($objects);
        }
        unset($objects);
        $blockers = [];

        if ($duplicateSources !== []) {
            $blockers[] = 'duplicate migration source files ['.implode(', ', $duplicateSources).']';
        }

        $missingManifestSources = array_values(array_diff(array_keys($manifest), array_keys($migrationSources)));
        if ($missingManifestSources !== []) {
            $blockers[] = 'manifest migrations without source files ['.implode(', ', $missingManifestSources).']';
        }

        $unmanifested = array_values(array_diff(array_keys($discovered), array_keys($manifest)));
        if ($unmanifested !== []) {
            $blockers[] = 'unsupported schema-object migrations missing from manifest ['.implode(', ', $unmanifested).']';
        }

        $countMismatches = [];
        foreach ($manifest as $migration => $expectedObjects) {
            if (($discovered[$migration] ?? null) !== $expectedObjects) {
                $countMismatches[] = $migration;
            }
        }

        if ($countMismatches !== []) {
            $blockers[] = 'manifest object counts do not match migration sources ['.implode(', ', $countMismatches).']';
        }

        return [
            'blockers' => $blockers,
            'discovered' => $discovered,
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function migrationRepositoryBlockers(
        string $migrationTable,
        string $tablePrefix,
        bool $repositoryExists,
    ): array {
        $blockers = [];

        if ($tablePrefix !== '') {
            $blockers[] = 'connection table prefixes are unsupported';
        }

        if (str_contains($migrationTable, '.')) {
            $blockers[] = 'schema-qualified migration repositories are unsupported';
        } elseif (preg_match('/^[A-Za-z0-9_$]+$/D', $migrationTable) !== 1) {
            $blockers[] = 'the configured migration repository identifier is unsupported';
        }

        if (! $repositoryExists) {
            $blockers[] = 'the configured migration repository is missing or cannot be verified';
        }

        return $blockers;
    }

    /**
     * @return array<string, int>
     */
    public function schemaObjectCounts(string $source): array
    {
        $withoutPhpComments = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $withoutPhpComments .= is_array($token) ? $token[1] : $token;
        }

        // Join adjacent PHP string literals while retaining SQL whitespace and
        // word boundaries. This recognises 'TRI'.'GGER' without mistaking
        // domain words such as "triggered", "viewed", or "functionality".
        $joinedLiterals = preg_replace('/([\'"])\s*\.\s*([\'"])/', '', $withoutPhpComments)
            ?? $withoutPhpComments;
        $withoutSqlBlockComments = preg_replace('/\/\*.*?\*\//s', ' ', $joinedLiterals)
            ?? $joinedLiterals;
        $normalised = preg_replace('/\s+/', ' ', $withoutSqlBlockComments) ?? $withoutSqlBlockComments;
        $pattern = '/\bCREATE\s+(?:OR\s+REPLACE\s+)?'
            .'(?:(?:ALGORITHM\s*=\s*[^\s]+\s+)|(?:DEFINER\s*=\s*[^\s]+\s+)|(?:SQL\s+SECURITY\s+(?:DEFINER|INVOKER)\s+))*'
            .'(TRIGGER|VIEW|PROCEDURE|(?:AGGREGATE\s+)?FUNCTION)\b/i';
        preg_match_all($pattern, $normalised, $matches);
        $types = array_map(
            fn (string $type): string => str_ends_with(strtoupper($type), 'FUNCTION') ? 'function' : strtolower($type),
            $matches[1] ?? [],
        );
        $counts = array_count_values($types);
        ksort($counts);

        return $counts;
    }
}
