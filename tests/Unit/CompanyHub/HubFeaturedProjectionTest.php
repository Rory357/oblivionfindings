<?php

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubBlocks;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubFeatured;
use App\Models\User;
use Illuminate\Container\Container;

function featuredProjectionPool(): array
{
    return array_map(fn ($id) => [
        'id' => $id, 'kind' => 'news', 'slug' => 'story-'.$id, 'title' => 'Canonical story '.$id,
        'summary' => 'Canonical summary '.$id, 'cover_url' => '/company-hub/media/'.($id * 10),
        'category' => $id === 4 ? 'Learning' : 'Company news', 'team_ids' => [$id === 4 ? 2 : 1],
        'published_at' => '2026-10-14T12:00:00+13:00',
    ], [1, 2, 3, 4]);
}

function projectEditorialBlocks(array $content, ?array $source = null, array $available = [1, 2], bool $includeHidden = false): array
{
    $prior = Container::getInstance();
    $isolated = new Container;
    $access = Mockery::mock(HubAccess::class);
    $access->shouldReceive('assertEnter')->once();
    $isolated->instance(HubAccess::class, $access);
    Container::setInstance($isolated);
    try {
        return (new HubBlocks)->forUser(new User, $content, [
            'interests' => ['available_teams' => array_map(fn ($id) => ['id' => $id, 'name' => 'Team '.$id], $available)],
            'pages' => [...featuredProjectionPool(), ['id' => 5, 'kind' => 'knowledge', 'title' => 'Guide', 'category' => 'Company news', 'team_ids' => [1], 'published_at' => '2026-10-14T12:00:00+13:00']],
        ], includeHidden: $includeHidden, sourceTeamIds: $source);
    } finally {
        Container::setInstance($prior);
        Mockery::close();
    }
}

test('featured overrides belong only to an explicit currently eligible lead and automatic fallback keeps no retained metadata or media', function (?int $selected, ?array $source, int $expected) {
    $block = ['id' => 'lead', 'type' => 'featured', 'title' => 'Retained private heading', 'body' => 'Retained private summary', 'story_id' => $selected, 'media_id' => 999, 'alt' => 'Retained private alternative text'];
    $content = ['sections' => [['id' => 'featured', 'blocks' => [$block]]]];
    $projected = HubFeatured::readerContent($content, featuredProjectionPool(), $source)['sections'][0]['blocks'][0];
    $feature = HubFeatured::resolve($block, featuredProjectionPool(), $source);
    expect($feature['lead']['id'])->toBe($expected)
        ->and($feature['lead']['title'])->toBe('Canonical story '.$expected)
        ->and($feature['lead']['cover_url'])->toBe('/company-hub/media/'.($expected * 10))
        ->and($projected['story_id'])->toBe($expected)->and($projected['title'])->toBe('Canonical story '.$expected)
        ->and($projected['body'])->toBe('Canonical summary '.$expected)->and($projected['media_id'])->toBeNull()->and($projected['alt'])->toBe('')
        ->and($content['sections'][0]['blocks'][0])->toBe($block);
})->with([
    'automatic selection' => [null, null, 4],
    'unavailable selection' => [99, null, 4],
    'selection outside department source' => [4, [1], 3],
]);

test('explicit featured overrides retain an authorised source and single renders only its lead', function () {
    $block = ['id' => 'lead', 'type' => 'featured', 'title' => 'Our chosen heading', 'body' => 'Our chosen message', 'story_id' => 2, 'media_id' => 99, 'alt' => 'Our photo', 'layout' => 'single'];
    $result = HubFeatured::resolve($block, featuredProjectionPool());
    expect($result['lead']['id'])->toBe(2)->and($result['lead']['title'])->toBe('Our chosen heading')
        ->and($result['lead']['summary'])->toBe('Our chosen message')->and($result['lead']['cover_url'])->toBe('/company-hub/media/99')
        ->and($result['supporting'])->toBe([]);
    $reader = HubFeatured::readerContent(['sections' => [['blocks' => [$block]]]], featuredProjectionPool());
    expect($reader['sections'][0]['blocks'][0]['media_id'])->toBe(99)->and($reader['sections'][0]['blocks'][0]['alt'])->toBe('Our photo');
    $block['title'] = '';
    $block['body'] = '';
    $block['layout'] = 'equal';
    expect(HubFeatured::resolve($block, featuredProjectionPool())['lead']['title'])->toBe('Canonical story 2')
        ->and(array_column(HubFeatured::resolve($block, featuredProjectionPool())['supporting'], 'id'))->toBe([4, 3]);
});

test('featured empty state removes all stale overrides and implicit department scope never broadens', function () {
    $source = ['sections' => [['blocks' => [['id' => 'feature', 'type' => 'featured', 'title' => 'Private', 'body' => 'Private summary', 'story_id' => 99, 'media_id' => 123, 'alt' => 'Private alt']]]]];
    foreach ([[], [77]] as $scope) {
        $result = HubFeatured::readerContent($source, featuredProjectionPool(), $scope)['sections'][0]['blocks'][0];
        expect($result['story_id'])->toBeNull()->and($result['title'])->toBe('')->and($result['body'])->toBe('')->and($result['media_id'])->toBeNull()
            ->and(HubFeatured::resolve($source['sections'][0]['blocks'][0], featuredProjectionPool(), $scope))->toBe(['lead' => null, 'supporting' => []]);
    }
});

test('news dedupes rendered feature tiles only and falls back within the selected category and team', function (string $layout, array $expected) {
    $content = ['sections' => [['blocks' => [
        ['id' => 'feature', 'type' => 'featured', 'title' => '', 'story_id' => 2, 'layout' => $layout],
        ['id' => 'news', 'type' => 'news', 'title' => 'News', 'limit' => 12],
        ['id' => 'filtered', 'type' => 'news', 'title' => 'Learning', 'category' => 'Learning', 'team_ids' => [2]],
    ]]]];
    $result = projectEditorialBlocks($content);
    expect(array_column($result['news']['news'], 'id'))->toBe($expected)
        ->and(array_column($result['filtered']['news'], 'id'))->toBe([4]);
})->with(['single reserves only lead' => ['single', [4, 3, 1]], 'mosaic reserves actual supporters' => ['mosaic', [1]]]);

test('hidden featured blocks have editor projection without affecting visible news and never reach a reader', function () {
    $content = ['sections' => [['blocks' => [
        ['id' => 'feature', 'type' => 'featured', 'title' => '', 'story_id' => 2, 'hidden' => true],
        ['id' => 'news', 'type' => 'news', 'title' => 'News', 'limit' => 12],
    ]]]];
    $reader = projectEditorialBlocks($content);
    $editor = projectEditorialBlocks($content, includeHidden: true);
    expect(array_keys($reader))->toBe(['news'])->and(array_column($reader['news']['news'], 'id'))->toBe([4, 3, 2, 1])
        ->and($editor['feature']['featured']['lead']['id'])->toBe(2)->and(array_column($editor['news']['news'], 'id'))->toBe([4, 3, 2, 1])
        ->and(HubContent::visibleBlocks($content)->pluck('id')->all())->toBe(['news']);
});

test('featured children of hidden sections retain preview data without displacing visible news or altering independent child visibility', function () {
    $content = ['sections' => [
        ['id' => 'hidden-section', 'hidden' => true, 'blocks' => [
            ['id' => 'section-feature', 'type' => 'featured', 'title' => '', 'story_id' => 2, 'layout' => 'single', 'hidden' => false],
            ['id' => 'child-feature', 'type' => 'featured', 'title' => '', 'story_id' => 4, 'layout' => 'single', 'hidden' => true],
        ]],
        ['id' => 'visible-section', 'blocks' => [
            ['id' => 'visible-feature', 'type' => 'featured', 'title' => '', 'story_id' => 3, 'layout' => 'single'],
            ['id' => 'news', 'type' => 'news', 'title' => 'News', 'limit' => 12],
        ]],
    ]];
    $snapshot = $content;
    $reader = projectEditorialBlocks($content);
    $editor = projectEditorialBlocks($content, includeHidden: true);
    expect(array_keys($reader))->toBe(['visible-feature', 'news'])->and(array_column($reader['news']['news'], 'id'))->toBe([4, 2, 1])
        ->and($editor['section-feature']['featured']['lead']['id'])->toBe(2)->and($editor['child-feature']['featured']['lead']['id'])->toBe(4)
        ->and(array_column($editor['news']['news'], 'id'))->toBe([4, 2, 1])->and($content)->toBe($snapshot);
    $content['sections'][0]['hidden'] = false;
    $shown = projectEditorialBlocks($content, includeHidden: true);
    expect(array_column($shown['news']['news'], 'id'))->toBe([4, 1])
        ->and($content['sections'][0]['blocks'][0]['hidden'])->toBeFalse()->and($content['sections'][0]['blocks'][1]['hidden'])->toBeTrue();
});

test('news and resources intersect block and source teams including withdrawn filters', function (?array $source, array $selected, array $available, array $news, array $resources) {
    $content = ['sections' => [['blocks' => [
        ['id' => 'news', 'type' => 'news', 'title' => 'News', 'team_ids' => $selected, 'limit' => 12],
        ['id' => 'resources', 'type' => 'resources', 'title' => 'Resources', 'team_ids' => $selected],
    ]]]];
    $result = projectEditorialBlocks($content, $source, $available);
    expect(array_column($result['news']['news'], 'id'))->toBe($news)->and(array_column($result['resources']['resources'], 'id'))->toBe($resources);
})->with([
    'ordinary page has no selection' => [null, [], [1, 2], [4, 3, 2, 1], [5]],
    'selected team' => [null, [2], [1, 2], [4], []],
    'source restricts broad block' => [[1], [1, 2], [1, 2], [3, 2, 1], [5]],
    'different source and block' => [[1], [2], [1, 2], [], []],
    'unconnected source' => [[], [], [1, 2], [], []],
    'withdrawn selection stays empty' => [null, [1], [2], [], []],
]);
