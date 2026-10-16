<?php

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubBlocks;
use App\Domain\CompanyHub\Services\HubContent;
use App\Models\User;
use Illuminate\Container\Container;

test('department blocks intersect their source page and current available teams without broadening an empty selection', function (?array $source, array $selected, array $available, array $expected) {
    $prior = Container::getInstance();
    $isolated = new Container;
    $access = Mockery::mock(HubAccess::class);
    $access->shouldReceive('assertEnter')->once();
    $isolated->instance(HubAccess::class, $access);
    Container::setInstance($isolated);
    try {
        $content = ['sections' => [['id' => 'support', 'columns' => 2, 'blocks' => [
            ['id' => 'finder', 'type' => 'people', 'title' => 'Our people', 'mode' => 'finder', 'team_ids' => $selected],
            ['id' => 'spaces', 'type' => 'teams', 'title' => 'Our spaces', 'team_ids' => $selected],
        ]]]];
        $feed = ['interests' => ['available_teams' => array_map(fn ($id) => ['id' => $id, 'name' => 'Team '.$id], $available)], 'pages' => [
            ['id' => 11, 'kind' => 'department', 'team_ids' => [1]],
            ['id' => 22, 'kind' => 'department', 'team_ids' => [2]],
            ['id' => 33, 'kind' => 'news', 'team_ids' => [1]],
            ['id' => 44, 'kind' => 'department', 'team_ids' => []],
        ]];
        $people = [['id' => 101, 'department_id' => 1], ['id' => 202, 'department_id' => 2]];
        $result = (new HubBlocks)->forUser(new User, $content, $feed, $people, sourceTeamIds: $source);
        expect(array_column($result['finder']['people'], 'department_id'))->toBe($expected)
            ->and(array_column($result['spaces']['teams'], 'id'))->toBe(array_map(fn ($id) => $id * 11, $expected))
            ->and($content['sections'][0]['blocks'][0]['team_ids'])->toBe($selected);
    } finally {
        Container::setInstance($prior);
        Mockery::close();
    }
})->with([
    'source team with no block filter' => [[1], [], [1, 2], [1]],
    'broad block cannot expand source' => [[1], [1, 2], [1, 2], [1]],
    'different block team returns no records' => [[1], [2], [1, 2], []],
    'unconnected department returns no records' => [[], [], [1, 2], []],
    'withdrawn source cannot reveal other teams' => [[1], [], [2], []],
    'withdrawn block filter cannot expand ordinary page' => [null, [1], [2], []],
]);

test('reader projection removes hidden section labels and blocks while preserving complete immutable author data', function () {
    $source = ['title' => 'A guide', 'team_ids' => [1, 2], 'sections' => [
        ['id' => 'hidden-section', 'title' => 'Secret section heading', 'columns' => 1, 'blocks' => [['id' => 'hidden', 'type' => 'text', 'title' => 'Private draft heading', 'body' => 'Private draft body', 'hidden' => 1]]],
        ['id' => 'people', 'columns' => 1, 'blocks' => [['id' => 'finder', 'type' => 'people', 'title' => 'People', 'mode' => 'finder', 'min_height' => 600, 'team_ids' => [1, 2]]]],
    ]];
    $reader = HubContent::readerContent($source, [1]);
    expect(array_column($reader['sections'], 'id'))->toBe(['people'])
        ->and($reader['sections'][0]['blocks'][0]['team_ids'])->toBe([1])
        ->and($reader['sections'][0]['blocks'][0]['min_height'])->toBe(600)
        ->and($reader['team_ids'])->toBe([1])
        ->and(HubContent::visibleBlocks($source)->pluck('id')->all())->toBe(['finder'])
        ->and($source['sections'][0]['title'])->toBe('Secret section heading')
        ->and($source['sections'][0]['blocks'][0]['body'])->toBe('Private draft body')
        ->and($source['sections'][1]['blocks'][0]['team_ids'])->toBe([1, 2]);
});
