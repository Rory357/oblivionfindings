<?php

use App\Domain\CompanyHub\Services\HubDraft;
use App\Domain\CompanyHub\Services\HubReadiness;
use App\Domain\CompanyHub\Services\HubTemplates;

test('approved starter plans retain the exact block sequence without fictional authored content', function (string $key, string $kind, array $types) {
    $plan = collect(HubTemplates::catalogue())->firstWhere('key', $key);
    $sections = HubTemplates::sections($key);
    $blocks = collect($sections)->flatMap(fn ($section) => $section['blocks']);

    expect($plan['kind'])->toBe($kind)->and($plan['block_types'])->toBe($types)
        ->and($blocks->pluck('type')->all())->toBe($types)
        ->and($blocks->pluck('width')->unique()->all())->toBe($types === [] ? [] : [12]);
    foreach ($blocks as $block) {
        expect($block['body'] ?? '')->toBe('')->and($block['media_id'] ?? null)->toBeNull()
            ->and($block['links'] ?? [])->toBe([]);
    }
    expect(HubReadiness::issues(['title' => 'A useful page', 'sections' => $sections]))->toHaveCount($types === [] ? 0 : match ($key) {
        'story' => 3, 'welcome', 'team', 'campaign' => 1, 'guide' => 3, 'event-recap', 'resource-collection' => 2,
    });
})->with([
    ['blank', 'page', []], ['story', 'news', ['text', 'image', 'text']],
    ['welcome', 'page', ['people', 'services', 'links']], ['team', 'department', ['text', 'people', 'resources']],
    ['guide', 'knowledge', ['text', 'file', 'links']], ['campaign', 'campaign', ['notice', 'campaign', 'events']],
    ['event-recap', 'news', ['image', 'text', 'shoutouts']], ['resource-collection', 'knowledge', ['text', 'resources', 'links']],
]);

test('canonical starter metadata keeps legacy keys compositions and independent reset choices', function () {
    expect(HubTemplates::sections('leadership'))->toBe([
        ['id' => 'priorities', 'title' => 'Our shared priorities', 'columns' => 1, 'blocks' => [
            ['id' => 'context', 'type' => 'text', 'title' => 'A note to our people', 'body' => ''],
            ['id' => 'progress', 'type' => 'text', 'title' => 'What we are working towards', 'body' => ''],
            ['id' => 'thanks', 'type' => 'text', 'title' => 'Thank you', 'body' => ''],
        ]],
    ])->and(HubTemplates::sections('information'))->toBe([
        ['id' => 'introduction', 'title' => 'Introduction', 'columns' => 1, 'blocks' => [['id' => 'about', 'type' => 'text', 'title' => 'About this page', 'body' => '']]],
        ['id' => 'resources', 'title' => 'Resources', 'columns' => 1, 'blocks' => [['id' => 'resources', 'type' => 'resources', 'title' => 'Useful resources', 'limit' => 3]]],
    ])->and(HubTemplates::sections('unknown legacy choice'))->toBe(HubTemplates::sections('story'));

    $catalogue = HubTemplates::catalogue();
    expect(array_column($catalogue, 'key'))->toHaveCount(count(HubTemplates::KEYS));
    foreach ($catalogue as $plan) {
        expect($plan)->toHaveKeys(['key', 'label', 'kind', 'icon', 'purpose', 'block_types'])
            ->and($plan)->not->toHaveKeys(['campaign_progress', 'media_id', 'story_id', 'count']);
    }
    $resetChoices = HubDraft::starterLayouts('page');
    expect(array_column($resetChoices, 'key'))->toBe(array_column($catalogue, 'key'))
        ->and(HubDraft::starterLayouts('home')[0]['key'])->toBe('home');
    $resetChoices[1]['sections'][0]['blocks'][0]['body'] = 'An unsaved local change';
    expect(HubTemplates::sections('story')[0]['blocks'][0]['body'])->toBe('');
});
