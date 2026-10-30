<?php

use App\Services\Chat\ChatInboxWindow;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

it('pages priority identities without assuming decreasing conversation ids', function () {
    $ids = [...range(101, 130), 7, 99];
    $window = new ChatInboxWindow;
    $revision = str_repeat('a', 64);
    $first = $window->page($ids, [], $revision);
    expect($first['ids'])->toBe(range(101, 130))->and($first['next_before'])->toBe(130);
    expect($window->page($ids, ['before' => 130, 'revision' => $revision], $revision)['ids'])->toBe([7, 99]);
    $refresh = $window->page($ids, ['through' => 130, 'minimum' => 30], $revision);
    expect($refresh['next_before'])->toBeNull()->and($refresh['older_before'])->toBe(130);
});

it('retains the loaded window when its former priority boundary moves or leaves', function () {
    $window = new ChatInboxWindow;
    $ids = range(1, 65);
    $first = $window->page($ids, ['through' => 5, 'minimum' => 45], 'revision');
    $tail = $window->page($ids, ['through' => 5, 'minimum' => 45, 'before' => 30], 'revision');
    expect($first['ids'])->toHaveCount(30)->and($tail['ids'])->toBe(range(31, 45))->and($tail['older_before'])->toBe(45);
    expect($window->page($ids, ['through' => 999, 'minimum' => 30], 'revision')['ids'])->toBe(range(1, 30));
});

it('rejects a changed queue revision or a missing page anchor', function () {
    $window = new ChatInboxWindow;
    expect(fn () => $window->page([1, 2], ['revision' => 'old'], 'new'))->toThrow(ConflictHttpException::class);
    expect(fn () => $window->page([1, 2], ['before' => 3], 'new'))->toThrow(ConflictHttpException::class);
    expect($window->page([], ['through' => 3], 'new')['scan_floor'])->toBeNull();
});
