<?php

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubBlocks;
use App\Models\User;
use Illuminate\Container\Container;

test('campaign blocks use the complete authorised pool and intersect source teams without broadening', function (?array $source, array $selected, array $available, ?int $limit, array $expected) {
    $prior = Container::getInstance();
    $isolated = new Container;
    $access = Mockery::mock(HubAccess::class);
    $access->shouldReceive('assertEnter')->once();
    $isolated->instance(HubAccess::class, $access);
    Container::setInstance($isolated);
    try {
        $block = ['id' => 'campaign', 'type' => 'campaign', 'title' => 'Shared progress', 'team_ids' => $selected];
        if ($limit !== null) {
            $block['limit'] = $limit;
        }
        $content = ['sections' => [['id' => 'initiatives', 'columns' => 1, 'blocks' => [$block]]]];
        $campaign = fn ($id, $teams, $date) => ['id' => $id, 'kind' => 'campaign', 'slug' => 'initiative-'.$id, 'team_ids' => $teams,
            'published_at' => $date, 'campaign_progress' => ['total' => 3, 'done' => 1, 'ends_on' => '2026-11-30']];
        $pool = [$campaign(22, [2], '2026-10-13'), ['id' => 99, 'kind' => 'news', 'team_ids' => [1]],
            $campaign(33, [], '2026-10-12'), $campaign(11, [1], '2026-10-14')];
        $feed = ['pages' => [], 'interests' => ['available_teams' => array_map(fn ($id) => ['id' => $id], $available)]];
        $result = (new HubBlocks)->forUser(new User, $content, $feed, [], sourceTeamIds: $source, publishedPages: $pool);
        expect(array_column($result['campaign']['campaigns'], 'id'))->toBe($expected)->and($content['sections'][0]['blocks'][0])->toBe($block);
        foreach ($result['campaign']['campaigns'] as $item) {
            expect($item['campaign_progress'])->toBe(['total' => 3, 'done' => 1, 'ends_on' => '2026-11-30'])
                ->and($item['slug'])->toBe('initiative-'.$item['id']);
        }
    } finally {
        Container::setInstance($prior);
        Mockery::close();
    }
})->with([
    'one by default' => [null, [], [1, 2], null, [11]],
    'explicit full pool' => [null, [], [1, 2], 12, [11, 22, 33]],
    'source team only' => [[1], [], [1, 2], 12, [11]],
    'source cannot broaden' => [[1], [1, 2], [1, 2], 12, [11]],
    'different block team' => [[1], [2], [1, 2], 12, []],
    'unconnected department' => [[], [], [1, 2], 12, []],
    'withdrawn source team' => [[1], [], [2], 12, []],
    'withdrawn ordinary block team' => [null, [1], [2], 12, []],
]);
