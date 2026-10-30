<?php

namespace App\Domain\CompanyHub\Services;

class HubTemplates
{
    public const KEYS = ['story', 'leadership', 'guide', 'team', 'welcome', 'information', 'campaign', 'blank', 'event-recap', 'resource-collection'];

    /** Read-only starter metadata; choosing one never alters a saved snapshot. */
    public static function catalogue(): array
    {
        $plans = [
            ['blank', 'Blank page', 'page', 'file', 'Build your own arrangement from an empty page.'],
            ['story', 'Story', 'news', 'newspaper', 'Give a company update a clear introduction, image and story.'],
            ['welcome', 'Welcome & essentials', 'page', 'hand', 'Help colleagues find the people and practical answers they need.'],
            ['team', 'Team update', 'department', 'users', 'Share what your team is working on and who can help.'],
            ['guide', 'Knowledge guide', 'knowledge', 'book-open', 'Explain a process with supporting files and useful next steps.'],
            ['campaign', 'Campaign', 'campaign', 'flag', 'Give a shared initiative a purpose, progress and ways to take part.'],
            ['event-recap', 'Event recap', 'news', 'images', 'Share the moments and thank the people who made them possible.'],
            ['resource-collection', 'Resource collection', 'knowledge', 'library', 'Bring related guidance together in one easy starting point.'],
            ['leadership', 'Leadership note', 'news', 'message-square', 'Share priorities, progress and thanks with your people.'],
            ['information', 'Information page', 'page', 'file-text', 'Create a lasting home for useful information.'],
        ];

        return array_map(fn ($plan) => [
            'key' => $plan[0], 'label' => $plan[1], 'kind' => $plan[2], 'icon' => $plan[3], 'purpose' => $plan[4],
            'block_types' => array_column(array_merge(...array_column(self::sections($plan[0]), 'blocks')), 'type'),
        ], $plans);
    }

    public static function sections(string $template): array
    {
        $template = in_array($template, self::KEYS, true) ? $template : 'story';
        $text = fn ($id, $title) => ['id' => $id, 'type' => 'text', 'title' => $title, 'body' => ''];
        $links = fn ($id, $title) => ['id' => $id, 'type' => 'links', 'title' => $title, 'links' => []];

        return match ($template) {
            'blank' => [],
            'leadership' => [
                ['id' => 'priorities', 'title' => 'Our shared priorities', 'columns' => 1, 'blocks' => [$text('context', 'A note to our people'), $text('progress', 'What we are working towards'), $text('thanks', 'Thank you')]],
            ],
            'information' => [
                ['id' => 'introduction', 'title' => 'Introduction', 'columns' => 1, 'blocks' => [$text('about', 'About this page')]],
                ['id' => 'resources', 'title' => 'Resources', 'columns' => 1, 'blocks' => [['id' => 'resources', 'type' => 'resources', 'title' => 'Useful resources', 'limit' => 3]]],
            ],
            default => [['id' => $template, 'columns' => 1, 'blocks' => array_map(fn ($block) => [...$block, 'width' => 12], match ($template) {
                'welcome' => [
                    ['id' => 'people', 'type' => 'people', 'title' => 'People', 'mode' => 'cards'],
                    ['id' => 'services', 'type' => 'services', 'title' => 'Services'],
                    $links('links', 'Links'),
                ],
                'team' => [$text('introduction', 'Introduction'), ['id' => 'people', 'type' => 'people', 'title' => 'People', 'mode' => 'cards'], ['id' => 'resources', 'type' => 'resources', 'title' => 'Resources']],
                'guide' => [$text('introduction', 'Introduction'), ['id' => 'file', 'type' => 'file', 'title' => 'Files'], $links('links', 'Links')],
                'campaign' => [
                    ['id' => 'purpose', 'type' => 'notice', 'title' => 'Callout', 'body' => ''],
                    ['id' => 'campaign', 'type' => 'campaign', 'title' => 'Campaign', 'limit' => 1],
                    ['id' => 'events', 'type' => 'events', 'title' => 'Events'],
                ],
                'event-recap' => [['id' => 'image', 'type' => 'image', 'title' => 'Image'], $text('story', 'Text'), ['id' => 'recognition', 'type' => 'shoutouts', 'title' => 'Recognition']],
                'resource-collection' => [$text('introduction', 'Introduction'), ['id' => 'resources', 'type' => 'resources', 'title' => 'Resources'], $links('links', 'Links')],
                default => [$text('body', 'Introduction'), ['id' => 'image', 'type' => 'image', 'title' => 'Image'], $text('story', 'Text')],
            })]],
        };
    }
}
