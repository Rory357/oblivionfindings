<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubBlocks;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $departments = Mockery::mock(Builder::class);
    $departments->shouldReceive('pluck')->with('id')->andReturn(collect([1, 2]));
    $calendar = Mockery::mock();
    $calendar->shouldReceive('visibleDepartmentsQuery')->andReturn($departments);
    $this->app->instance(HrCalendarAccessService::class, $calendar);
});

function blockPresentationContent(array $blocks, bool $hidden = false): array
{
    $content = HubContent::defaults('Our resources');
    $content['sections'] = [['id' => 'resources', 'columns' => 2, 'hidden' => $hidden, 'blocks' => $blocks]];

    return $content;
}

function validateBlockPresentation(array $content): array
{
    return app(HubContent::class)->validate($content, new User, (new HubPage)->setRawAttributes(['kind' => 'page']));
}

test('resource and team presentation survives canonical snapshot and reader roundtrips', function (string $type, string $layout) {
    $block = ['id' => 'selected', 'type' => $type, 'title' => 'Useful '.$type, 'layout' => $layout, 'team_ids' => [1], 'limit' => 2];
    $content = blockPresentationContent([$block]);
    $saved = validateBlockPresentation($content);
    $restored = validateBlockPresentation(json_decode(json_encode($saved, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

    expect($saved['sections'])->toBe($content['sections'])
        ->and($restored)->toBe($saved)
        ->and(HubContent::readerContent($restored)['sections'][0]['blocks'][0])->toBe($block)
        ->and($content['sections'][0]['blocks'][0])->toBe($block);
})->with([
    'resource cards' => ['resources', 'cards'],
    'resource list' => ['resources', 'list'],
    'team cards' => ['teams', 'cards'],
    'team list' => ['teams', 'list'],
]);

test('presentation stays type specific and hidden content cannot bypass its source or URL validation', function () {
    foreach (['resources', 'teams'] as $type) {
        foreach (['mosaic', 'equal', 'single', 'grid', null, ['cards']] as $layout) {
            $content = blockPresentationContent([['id' => 'selected', 'type' => $type, 'title' => 'Hidden source', 'layout' => $layout]], true);
            expect(fn () => validateBlockPresentation($content))->toThrow(ValidationException::class);
        }
    }
    foreach (['video', 'file', 'notices', 'people', 'services', 'events', 'campaign'] as $type) {
        foreach (['cards', 'list'] as $layout) {
            expect(fn () => validateBlockPresentation(blockPresentationContent([['id' => 'other', 'type' => $type, 'title' => 'Other block', 'layout' => $layout]])))->toThrow(ValidationException::class);
        }
    }
    foreach (['resources', 'teams'] as $type) {
        expect(fn () => validateBlockPresentation(blockPresentationContent([[
            'id' => 'private', 'type' => $type, 'title' => 'Private source', 'layout' => 'list', 'team_ids' => [3], 'hidden' => true,
        ]], true)))->toThrow(ValidationException::class);
    }
    expect(fn () => validateBlockPresentation(blockPresentationContent([[
        'id' => 'unsafe', 'type' => 'video', 'title' => 'Hidden video', 'url' => 'javascript:alert(1)', 'body' => 'A description',
    ]], true)))->toThrow(ValidationException::class);
});

test('omitted legacy presentation descriptions and notice counts retain their stored shape', function () {
    $blocks = array_map(fn ($type) => ['id' => $type, 'type' => $type, 'title' => 'Existing '.$type], ['resources', 'teams', 'video', 'file', 'notices']);
    $content = blockPresentationContent($blocks);
    $saved = validateBlockPresentation($content);

    expect($saved['sections'][0]['blocks'])->toBe($blocks)
        ->and(HubContent::readerContent($saved)['sections'][0]['blocks'])->toBe($blocks);
    foreach ($saved['sections'][0]['blocks'] as $block) {
        expect($block)->not->toHaveKey('layout')->not->toHaveKey('body')->not->toHaveKey('limit');
    }
});

test('video and file descriptions reuse bounded authored body and survive hidden snapshot roundtrips', function () {
    foreach (['video', 'file'] as $type) {
        foreach (['Useful context for this media.', '', null, str_repeat('a', 20000)] as $body) {
            $block = ['id' => 'media', 'type' => $type, 'title' => 'Reference media', 'body' => $body];
            $content = blockPresentationContent([$block]);
            $saved = validateBlockPresentation($content);
            expect($saved['sections'][0]['blocks'][0])->toBe($block)
                ->and(HubContent::readerContent($saved)['sections'][0]['blocks'][0]['body'])->toBe($body);
            $hidden = validateBlockPresentation([...$content, 'sections' => [[...$content['sections'][0], 'hidden' => true]]]);
            expect($hidden['sections'][0]['blocks'][0])->toBe($block)
                ->and(HubContent::readerContent($hidden)['sections'])->toBe([]);
        }
        foreach ([str_repeat('a', 20001), ['description' => 'Unsafe object']] as $body) {
            expect(fn () => validateBlockPresentation(blockPresentationContent([[
                'id' => 'media', 'type' => $type, 'title' => 'Reference media', 'body' => $body,
            ]], true)))->toThrow(ValidationException::class);
        }
    }
});

test('notice item limits retain nullable legacy fallback and reject unbounded or malformed counts', function () {
    foreach ([1, 6, 12, null] as $limit) {
        $block = ['id' => 'notices', 'type' => 'notices', 'title' => 'Important updates', 'limit' => $limit];
        $saved = validateBlockPresentation(blockPresentationContent([$block]));
        expect($saved['sections'][0]['blocks'][0])->toBe($block)
            ->and(HubContent::readerContent($saved)['sections'][0]['blocks'][0]['limit'])->toBe($limit);
    }
    foreach ([0, 13, 1.5, 'many', [3]] as $limit) {
        expect(fn () => validateBlockPresentation(blockPresentationContent([[
            'id' => 'notices', 'type' => 'notices', 'title' => 'Hidden updates', 'limit' => $limit,
        ]], true)))->toThrow(ValidationException::class);
    }
});

test('cards and list keep the same authorised dynamic source projection default limit and hidden boundary', function () {
    $access = Mockery::mock(HubAccess::class);
    $access->shouldReceive('assertEnter');
    $this->app->instance(HubAccess::class, $access);
    $resources = array_map(fn ($id) => [
        'id' => $id, 'kind' => $id % 2 === 0 ? 'knowledge' : 'page', 'title' => 'Resource '.$id,
        'team_ids' => [1], 'published_at' => sprintf('2026-10-%02dT12:00:00+13:00', $id),
    ], range(1, 6));
    $teams = array_map(fn ($id) => [
        'id' => $id, 'kind' => 'department', 'title' => 'Shared team '.$id,
        'team_ids' => [1], 'published_at' => '2026-10-14T12:00:00+13:00',
    ], range(11, 16));
    $feed = ['interests' => ['available_teams' => [['id' => 1], ['id' => 2]]], 'pages' => [
        ...$resources, ...$teams,
        ['id' => 20, 'kind' => 'knowledge', 'title' => 'Other authorised team', 'team_ids' => [2], 'published_at' => '2026-10-15T12:00:00+13:00'],
        ['id' => 21, 'kind' => 'department', 'title' => 'Unavailable team', 'team_ids' => [3], 'published_at' => '2026-10-15T12:00:00+13:00'],
    ]];
    $previous = null;
    foreach ([null, 'cards', 'list'] as $layout) {
        $blocks = array_map(fn ($type) => ['id' => $type, 'type' => $type, 'title' => 'Current '.$type, 'team_ids' => [1, 2]], ['resources', 'teams']);
        if ($layout !== null) {
            foreach ($blocks as &$block) {
                $block['layout'] = $layout;
            }
            unset($block);
        }
        $content = validateBlockPresentation(blockPresentationContent($blocks));
        $projection = app(HubBlocks::class)->forUser(new User, $content, $feed, sourceTeamIds: [1]);
        expect(array_column($projection['resources']['resources'], 'id'))->toBe([6, 5, 4, 3])
            ->and(array_column($projection['teams']['teams'], 'id'))->toBe([11, 12, 13, 14]);
        if ($previous !== null) {
            expect($projection)->toBe($previous);
        }
        $previous = $projection;
        $content['sections'][0]['hidden'] = true;
        expect(app(HubBlocks::class)->forUser(new User, $content, $feed, sourceTeamIds: [1]))->toBe([])
            ->and(app(HubBlocks::class)->forUser(new User, $content, $feed, includeHidden: true, sourceTeamIds: [1]))->toBe($projection);
    }
});
