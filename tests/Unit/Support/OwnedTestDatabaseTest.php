<?php

use Tests\Support\OwnedTestDatabase;

it('accepts only approved disposable bases with the exact owner PID', function (string $database): void {
    expect(OwnedTestDatabase::isOwnedBy($database, 1234))->toBeTrue();
})->with([
    'canonical CI' => ['oblivion_findings_codex_test_1234'],
    'canonical local acceptance' => ['oblivion_findings_codex_test_packpaper_1234'],
    'existing workforce base' => ['oblivion_workforce_fixture_1234'],
    'existing bounded workforce characters' => ['oblivion_workforce_Fixture_09_1234'],
]);

it('rejects bare live foreign malformed and wrong-owner database names', function (string $database, int $owner): void {
    expect(OwnedTestDatabase::isOwnedBy($database, $owner))->toBeFalse();
})->with([
    'bare CI base' => ['oblivion_findings_codex_test', 1234],
    'bare local base' => ['oblivion_findings_codex_test_packpaper', 1234],
    'bare workforce base' => ['oblivion_workforce_fixture', 1234],
    'live application name' => ['oblivion_findings', 1234],
    'live name with PID' => ['oblivion_findings_1234', 1234],
    'arbitrary test base' => ['another_test_1234', 1234],
    'unapproved short local base' => ['packpaper_1234', 1234],
    'unapproved CI suffix' => ['oblivion_findings_codex_test_other_1234', 1234],
    'wrong CI owner' => ['oblivion_findings_codex_test_1235', 1234],
    'wrong local owner' => ['oblivion_findings_codex_test_packpaper_1235', 1234],
    'wrong workforce owner' => ['oblivion_workforce_fixture_1235', 1234],
    'leading-zero owner suffix' => ['oblivion_findings_codex_test_01234', 1234],
    'duplicate CI owner suffix' => ['oblivion_findings_codex_test_1234_1234', 1234],
    'zero owner' => ['oblivion_findings_codex_test_0', 0],
    'negative owner' => ['oblivion_findings_codex_test_-1', -1],
    'trailing newline' => ["oblivion_findings_codex_test_1234\n", 1234],
    'trailing blank' => ['oblivion_findings_codex_test_1234 ', 1234],
    'schema-qualified name' => ['other.oblivion_findings_codex_test_1234', 1234],
    'unapproved workforce characters' => ['oblivion_workforce_fixture-name_1234', 1234],
    'case-altered base' => ['Oblivion_findings_codex_test_1234', 1234],
]);
