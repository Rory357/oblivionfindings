<?php

/*
 * Every test database starts from database/schema/mysql-schema.sql and then
 * runs the migrations the dump doesn't have. On 31 Oct 2026 the dump was 462
 * migrations behind, and that catch-up cost every fresh test database about
 * four minutes. `composer test:prepare` brings it forward without changing
 * the rows tests start with.
 */
it('keeps the schema dump within 50 migrations of the migration files', function (): void {
    $root = dirname(__DIR__, 3);
    $dump = (string) file_get_contents($root.'/database/schema/mysql-schema.sql');
    preg_match_all("/^INSERT INTO `migrations` .*?VALUES \\(\\d+,'([^']+)'/m", $dump, $matches);

    $files = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob($root.'/database/migrations/*.php') ?: [],
    );
    $behind = array_values(array_diff($files, $matches[1]));

    expect($matches[1])->not->toBeEmpty()
        ->and(count($behind))->toBeLessThanOrEqual(50, sprintf(
            'The schema dump is %d migrations behind database/migrations. Bring it forward: composer test:prepare',
            count($behind),
        ));
});
