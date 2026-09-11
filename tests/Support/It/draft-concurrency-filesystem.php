<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Shared workers join one guarded private test disk; they never clean it. */
function configureItDraftConcurrencyStorage(): string
{
    $token = (string) getenv('TEST_TOKEN');
    if (getenv('APP_ENV') !== 'testing' || preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1
        || DB::connection()->getDatabaseName() !== 'oblivion_it_support_test_'.$token) {
        throw new RuntimeException('Draft file concurrency requires the exact isolated schema/token.');
    }
    $parent = realpath(storage_path('framework/testing'));
    if (! is_string($parent)) {
        throw new RuntimeException('The guarded test storage parent must exist.');
    }
    $root = $parent.DIRECTORY_SEPARATOR.'it-draft-files-'.$token;
    if (is_link($root)) {
        throw new RuntimeException('The isolated private disk cannot be a symbolic link.');
    }
    if (! is_dir($root) && ! mkdir($root)) {
        throw new RuntimeException('The isolated private disk could not be created.');
    }
    $resolved = realpath($root);
    if (! is_string($resolved) || strcasecmp($resolved, $root) !== 0) {
        throw new RuntimeException('The isolated private disk escaped its exact test directory.');
    }
    config(['filesystems.disks.private' => ['driver' => 'local', 'root' => $resolved, 'throw' => true]]);
    Storage::forgetDisk('private');

    return $resolved;
}

/** Only the standalone parent calls cleanup, after every worker has settled. */
function removeItDraftConcurrencyStorage(string $root): void
{
    $token = (string) getenv('TEST_TOKEN');
    $parent = realpath(storage_path('framework/testing'));
    if (preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1 || ! is_string($parent)) {
        throw new RuntimeException('The isolated private disk cleanup guard is unavailable.');
    }
    if (! is_dir($root)) {
        return;
    }
    $resolved = realpath($root);
    $expected = $parent.DIRECTORY_SEPARATOR.'it-draft-files-'.$token;
    if (is_link($root) || ! is_string($resolved) || strcasecmp($resolved, $expected) !== 0) {
        throw new RuntimeException('Refusing cleanup outside the exact isolated private test directory.');
    }
    if (! app(Filesystem::class)->deleteDirectory($resolved)) {
        throw new RuntimeException('The isolated private disk cleanup was not confirmed.');
    }
}
