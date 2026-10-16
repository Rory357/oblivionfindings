<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Domain\CompanyHub\Services\HubFeatured;
use App\Domain\CompanyHub\Services\HubKnowledge;
use App\Domain\CompanyHub\Services\HubReaderMetadata;
use App\Domain\CompanyHub\Services\HubServices;
use App\Models\User;

test('reading estimate counts visible own prose and captions without hidden or other story text', function () {
    $content = ['sections' => [['blocks' => [
        ['type' => 'text', 'body' => str_repeat('word ', 180)],
        ['type' => 'image', 'body' => '<b>Caption.</b>'],
        ['type' => 'text', 'body' => str_repeat('private ', 2000), 'hidden' => true],
        ['type' => 'featured', 'body' => str_repeat('another-story ', 2000)],
        ['type' => 'services', 'body' => str_repeat('dynamic-service ', 2000)],
    ]]]];
    expect(HubReaderMetadata::readMinutes($content))->toBe(2)
        ->and(HubReaderMetadata::readMinutes(['sections' => []]))->toBe(1);
});

test('review due today is distinct from overdue while missing review dates never warn', function (?string $date, bool $expected) {
    expect(HubReaderMetadata::overdue($date, '2026-10-14'))->toBe($expected);
})->with([[null, false], ['', false], ['2026-10-13', true], ['2026-10-14', false], ['2026-10-15', false]]);

test('metadata uses only canonical names teams and topics with distinct revision author and current owner', function () {
    $page = (new HubPage)->setRawAttributes(['kind' => 'news', 'owner_id' => 1]);
    $release = (new HubRevision)->setRawAttributes(['created_by' => 2]);
    $fields = HubReaderMetadata::fields($page, $release, [
        'team_ids' => [1, 99], 'topics' => ['Learning', 'Private taxonomy'], 'sections' => [], 'comments_enabled' => false,
    ], [['id' => 1, 'name' => 'Current learning team']], [1 => 'Current owner', 2 => 'Current author']);
    expect($fields['owner'])->toBe('Current owner')->and($fields['owner_url'])->toBe('/company-hub/people/1')->and($fields['author'])->toBe('Current author')
        ->and($fields['team_ids'])->toBe([1])->and($fields['team_names'])->toBe(['Current learning team'])
        ->and($fields['topics'])->toBe(['Learning'])->and($fields['show_in_news'])->toBeTrue();
    $fields = HubReaderMetadata::fields($page, $release, ['sections' => [], 'show_in_news' => false], [], []);
    expect($fields['owner'])->toBe('Company team')->and($fields['owner_url'])->toBeNull()->and($fields['author'])->toBe('Company team')->and($fields['show_in_news'])->toBeFalse();
});

test('resource and newsroom search cover public ownership authors teams topics and inherited collection metadata', function (string $query) {
    expect(HubReaderMetadata::matches([
        'title' => 'Guide', 'summary' => 'Practical information', 'category' => 'Our company', 'owner' => 'Current owner',
        'author' => 'Published author', 'team_names' => ['Learning team'], 'topics' => ['Wellbeing'], 'collection' => 'First week', 'resource_type' => 'Process',
    ], $query))->toBeTrue();
})->with(['current owner', 'published author', 'learning team', 'wellbeing', 'first week', 'process']);

test('related resources can match topics without a team and rank topic overlap above team only', function () {
    $candidate = fn ($id, $topics, $teams, $kind = 'knowledge') => ['id' => $id, 'kind' => $kind, 'topics' => $topics, 'team_ids' => $teams, 'published_at' => '2026-10-14T12:00:00+13:00'];
    $pool = [
        $candidate(10, ['Learning'], [1]), $candidate(11, [], [1]), $candidate(12, ['Learning'], []),
        $candidate(13, ['Learning', 'Wellbeing'], []), $candidate(14, ['Learning'], [1]),
        $candidate(15, ['Learning', 'Wellbeing'], [1], 'news'), $candidate(16, ['Private taxonomy'], []),
    ];
    expect(array_column(HubKnowledge::related(10, ['Learning', 'Wellbeing'], [1], $pool), 'id'))->toBe([13, 14, 12])
        ->and(array_column(HubKnowledge::related(10, ['Learning'], [], $pool), 'id'))->toBe([14, 13, 12]);
});

test('news discovery off preserves its catalog entry but excludes it from featured and news eligible pools', function () {
    $pool = [['id' => 1, 'kind' => 'news', 'title' => 'News', 'summary' => '', 'cover_url' => null],
        ['id' => 2, 'kind' => 'news', 'show_in_news' => false, 'title' => 'Off news', 'summary' => '', 'cover_url' => null]];
    expect(array_column(HubFeatured::ordered($pool), 'id'))->toBe([1])
        ->and(HubFeatured::resolve(['type' => 'featured', 'story_id' => 2, 'title' => 'Retained', 'body' => 'Retained'], $pool)['lead']['title'])->toBe('News')
        ->and(count($pool))->toBe(2);
});

test('service discovery preserves canonical handoff icons and limits catalogue icons to truthful computer projection', function () {
    $actor = new User;
    $services = Mockery::mock(HubServices::class)->makePartial();
    $services->shouldReceive('catalogue')->once()->with($actor, '')->andReturn([
        ['id' => 1, 'name' => 'Equipment', 'description' => 'Current equipment', 'category' => 'Hardware', 'team_ids' => [99], 'form_schema' => ['secret' => true], 'icon' => 'untrusted-icon'],
    ]);
    $services->shouldReceive('handoffs')->once()->with($actor, '')->andReturn([
        ['id' => 'leave', 'name' => 'Leave', 'description' => 'Time away', 'category' => 'People & Culture', 'icon' => 'leave', 'url' => '/hr/my/leave'],
    ]);
    $result = $services->search($actor, '');
    expect($result[0]['icon'])->toBe('computer')->and($result[1]['icon'])->toBe('leave')
        ->and(array_keys($result[0]))->toBe(['id', 'name', 'description', 'category', 'icon'])
        ->and($result[1]['url'])->toBe('/hr/my/leave');
    Mockery::close();
});
