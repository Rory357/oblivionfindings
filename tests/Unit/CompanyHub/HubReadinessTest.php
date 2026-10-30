<?php

use App\Domain\CompanyHub\Services\HubReadiness;

test('family30 readiness requires complete visible authored content while hidden unfinished content stays retainable', function (array $block, string $code) {
    $content = ['title' => 'Useful information', 'sections' => [['id' => 'section', 'blocks' => [['id' => 'block', 'title' => 'Visible content', ...$block]]]]];
    $issues = HubReadiness::issues($content);
    expect(array_column($issues, 'code'))->toContain($code)->and($issues[0]['section_id'])->toBe('section')
        ->and($issues[0]['block_id'])->toBe('block');
    $content['sections'][0]['blocks'][0]['hidden'] = true;
    expect(HubReadiness::issues($content))->toBe([]);
})->with([
    'text' => [['type' => 'text', 'body' => '  '], 'block_body'],
    'callout' => [['type' => 'notice'], 'block_body'],
    'image' => [['type' => 'image'], 'block_media'],
    'file' => [['type' => 'file', 'media_id' => null], 'block_media'],
    'video' => [['type' => 'video', 'url' => ''], 'block_video'],
    'button' => [['type' => 'button', 'action_label' => 'Explore'], 'block_action'],
    'empty links' => [['type' => 'links', 'links' => []], 'block_links'],
    'partial link' => [['type' => 'links', 'links' => [['id' => 'link', 'url' => '/company-hub']]], 'block_links'],
    'poll question' => [['type' => 'poll', 'options' => ['Yes', 'No']], 'block_poll'],
    'poll single answer' => [['type' => 'poll', 'question' => 'Which option?', 'options' => ['Yes']], 'block_poll'],
    'poll repeated answers' => [['type' => 'poll', 'question' => 'Which option?', 'options' => ['Yes', ' Yes ']], 'block_poll'],
    'poll unfinished answer' => [['type' => 'poll', 'question' => 'Which option?', 'options' => ['Yes', null]], 'block_poll'],
    'heading' => [['type' => 'text', 'title' => '', 'body' => 'Complete content.'], 'block_heading'],
]);

test('family30 readiness keeps deliberately automatic blocks valid without any selected or populated source', function () {
    $types = ['featured', 'news', 'resources', 'people', 'teams', 'services', 'campaign', 'events', 'shoutouts', 'celebrations', 'notices'];
    $content = ['title' => 'Our company', 'sections' => [['id' => 'automatic', 'blocks' => array_map(fn ($type) => [
        'id' => $type, 'type' => $type, 'title' => $type === 'featured' ? '' : 'Current information',
        ...($type === 'featured' ? ['body' => '', 'story_id' => null] : []),
    ], $types)]]];
    $snapshot = $content;
    expect(HubReadiness::issues($content))->toBe([])->and($content)->toBe($snapshot);
});

test('family30 readiness accepts complete authored blocks without inventing a client configured flag', function () {
    $blocks = [
        ['type' => 'text', 'body' => 'Useful authored content.'], ['type' => 'notice', 'body' => 'A useful reminder.'],
        ['type' => 'image', 'media_id' => 1], ['type' => 'file', 'media_id' => 2],
        ['type' => 'video', 'url' => 'https://player.vimeo.com/video/123'],
        ['type' => 'button', 'action_label' => 'Explore', 'url' => '/company-hub'],
        ['type' => 'links', 'links' => [['id' => 'link', 'title' => 'Explore', 'url' => '/company-hub']]],
        ['type' => 'poll', 'question' => 'Which option?', 'options' => ['First', 'Second']],
    ];
    $content = ['title' => 'A clear title', 'sections' => [['id' => 'complete', 'blocks' => array_map(fn ($block, $index) => [
        'id' => 'block-'.$index, 'title' => 'Useful information', ...$block,
    ], $blocks, array_keys($blocks))]]];
    expect(HubReadiness::issues($content))->toBe([]);
});

test('family30 readiness checks the page title and partially configured hero separately from hidden blocks', function () {
    $content = ['title' => ' ', 'hero' => ['style' => 'image', 'action_label' => 'Explore', 'action_url' => ''], 'sections' => []];
    expect(array_column(HubReadiness::issues($content), 'code'))->toBe(['page_title', 'hero_image', 'hero_action']);
    $content['title'] = 'Our company';
    $content['cover_id'] = 1;
    $content['hero']['action_url'] = '/company-hub';
    expect(HubReadiness::issues($content))->toBe([]);
});
